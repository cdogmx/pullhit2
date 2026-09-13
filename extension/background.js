/**
 * CardFoo sold agent — the browser half.
 *
 * eBay serves completed listings only to a signed-in session, so these pages
 * cannot be fetched from the server at any price. This runs in the browser that
 * is already signed in, takes a queue of searches from CardFoo, and hands back
 * the HTML.
 *
 * It navigates a real tab rather than calling fetch(). That is deliberate and
 * was measured: a plain HTTP client from this same machine and IP is refused
 * with a 403 before eBay even considers who is asking, so the request has to be
 * a genuine navigation from a real browser to look like one.
 *
 * It also paces itself and stops the moment eBay shows a wall. The point is a
 * steady trickle of real comps from an idle machine, not to see how fast this
 * can go before the account is noticed.
 */

const DEFAULTS = {
  apiBase: 'https://cardfoo.com',
  token: '',
  enabled: false,
  // Seconds between page loads. Jittered ±40% so the interval is not a metronome.
  delaySeconds: 25,
  // Stop for the day after this many pages, whatever the queue says.
  dailyCap: 400,
  // Small on purpose. Priority only helps if the agent asks again soon: a card
  // someone just opened is queued above the routine work, but it still has to
  // wait out whatever batch is already in hand.
  batchSize: 2,
};

const state = {
  running: false,
  tabId: null,
  current: null,
  lastError: null,
  lastResultAt: null,
  today: null,
  doneToday: 0,
  compsToday: 0,
  // Set when eBay refuses us; cleared only by the user toggling back on.
  pausedReason: null,
};

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const jitter = (seconds) => seconds * 1000 * (0.6 + Math.random() * 0.8);

async function settings() {
  const stored = await browser.storage.local.get(Object.keys(DEFAULTS));
  return { ...DEFAULTS, ...stored };
}

/** Reset the daily counters when the date rolls over. */
function rollDay() {
  const today = new Date().toDateString();
  if (state.today !== today) {
    state.today = today;
    state.doneToday = 0;
    state.compsToday = 0;
  }
}

async function api(path, options = {}) {
  const { apiBase, token } = await settings();
  const res = await fetch(`${apiBase.replace(/\/$/, '')}/api/agent/${path}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
      ...(options.headers || {}),
    },
    // Our own site: never send the user's CardFoo cookies with these calls, the
    // token is the whole authority the agent has.
    credentials: 'omit',
  });

  if (!res.ok) {
    // Say what is actually wrong. "claim → HTTP 401" sends you looking at the
    // queue; the problem is always the token field two inches above it.
    if (res.status === 401) {
      throw new Error('Agent token rejected — re-paste SCRAPE_AGENT_TOKEN in Settings.');
    }

    if (res.status === 503) {
      throw new Error('CardFoo has no SCRAPE_AGENT_TOKEN set for this environment.');
    }

    if (res.status === 404) {
      throw new Error(`No agent endpoint at ${apiBase} — check the CardFoo URL.`);
    }

    throw new Error(`${path} failed: HTTP ${res.status}`);
  }

  return res.json();
}

/** A tab we own and reuse, so we never navigate away from the user's own tabs. */
async function workerTab() {
  if (state.tabId !== null) {
    try {
      return await browser.tabs.get(state.tabId);
    } catch {
      state.tabId = null; // user closed it
    }
  }

  const tab = await browser.tabs.create({ url: 'about:blank', active: false });
  state.tabId = tab.id;
  return tab;
}

/** Navigate the worker tab and return what the content script found. */
async function loadPage(url) {
  const tab = await workerTab();
  await browser.tabs.update(tab.id, { url });

  // Wait for the content script to report, with a ceiling — a page that never
  // settles must not wedge the loop.
  const deadline = Date.now() + 45000;

  while (Date.now() < deadline) {
    await sleep(1000);

    try {
      const reply = await browser.tabs.sendMessage(tab.id, { type: 'cardfoo:scrape' });
      if (reply && reply.ready) {
        return reply;
      }
    } catch {
      // Content script not injected yet (still navigating) — keep waiting.
    }
  }

  throw new Error('timed out waiting for the page');
}

async function runOne(job) {
  state.current = job.label || job.url;

  let page;

  try {
    page = await loadPage(job.url);
  } catch (e) {
    await api('result', {
      method: 'POST',
      body: JSON.stringify({ id: job.id, status: 'failed', note: String(e.message).slice(0, 200) }),
    });
    return { paused: false };
  }

  if (page.refused) {
    // eBay showed a wall. Report it as a block so the server does not record
    // "no comps", and stop — pushing through is what gets an account flagged.
    await api('result', {
      method: 'POST',
      body: JSON.stringify({ id: job.id, status: 'blocked', note: page.title.slice(0, 200) }),
    });
    return { paused: true, reason: page.title || 'eBay showed a wall' };
  }

  const result = await api('result', {
    method: 'POST',
    body: JSON.stringify({ id: job.id, html: page.html }),
  });

  state.doneToday += 1;
  state.compsToday += result.comps || 0;
  state.lastResultAt = new Date().toISOString();

  return { paused: Boolean(result.should_pause), reason: 'the server rejected the page as a wall' };
}

async function loop() {
  if (state.running) {
    return;
  }

  state.running = true;
  state.lastError = null;

  try {
    while (true) {
      const config = await settings();

      if (!config.enabled || state.pausedReason) {
        break;
      }

      rollDay();

      if (state.doneToday >= config.dailyCap) {
        state.pausedReason = `daily cap of ${config.dailyCap} reached`;
        break;
      }

      let batch;

      try {
        batch = await api('claim', {
          method: 'POST',
          body: JSON.stringify({ limit: config.batchSize, agent: 'firefox' }),
        });
      } catch (e) {
        // Server unreachable or token wrong — wait rather than spin.
        state.lastError = e.message;
        await sleep(60000);
        continue;
      }

      if (!batch.jobs.length) {
        state.current = null;
        await sleep(120000); // queue empty; check back later
        continue;
      }

      for (const job of batch.jobs) {
        const fresh = await settings();
        if (!fresh.enabled) {
          break;
        }

        let outcome;

        try {
          outcome = await runOne(job);
        } catch (e) {
          state.lastError = e.message;
          outcome = { paused: false };
        }

        if (outcome.paused) {
          state.pausedReason = outcome.reason;
          break;
        }

        await sleep(jitter(config.delaySeconds));
      }
    }
  } finally {
    state.running = false;
    state.current = null;

    // Leave the user's browser as we found it.
    if (state.tabId !== null) {
      try {
        await browser.tabs.remove(state.tabId);
      } catch {
        /* already gone */
      }
      state.tabId = null;
    }
  }
}

browser.runtime.onMessage.addListener(async (msg) => {
  if (msg.type === 'cardfoo:state') {
    rollDay();
    const config = await settings();
    return {
      ...state,
      enabled: config.enabled,
      apiBase: config.apiBase,
      hasToken: Boolean(config.token),
      delaySeconds: config.delaySeconds,
      dailyCap: config.dailyCap,
    };
  }

  if (msg.type === 'cardfoo:toggle') {
    await browser.storage.local.set({ enabled: msg.enabled });

    if (msg.enabled) {
      state.pausedReason = null; // an explicit restart clears a pause
      loop();
    }

    return { ok: true };
  }

  if (msg.type === 'cardfoo:status') {
    // Proxied through here because the popup has no token of its own.
    try {
      return await api('status');
    } catch (e) {
      return { error: e.message };
    }
  }

  if (msg.type === 'cardfoo:save') {
    await browser.storage.local.set(msg.settings);
    return { ok: true };
  }

  return undefined;
});

// Resume after a browser restart if it was left switched on.
settings().then((config) => {
  if (config.enabled) {
    loop();
  }
});
