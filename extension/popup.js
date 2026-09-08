const $ = (id) => document.getElementById(id);

async function refresh() {
  const state = await browser.runtime.sendMessage({ type: 'cardfoo:state' });

  $('toggle').textContent = state.enabled ? 'Stop' : 'Start';
  $('toggle').className = state.enabled ? '' : 'off';

  $('state').textContent = state.pausedReason
    ? 'paused'
    : state.enabled
      ? (state.running ? 'running' : 'starting…')
      : 'off';
  $('state').className = state.pausedReason ? 'warn' : '';

  $('current').textContent = state.pausedReason || state.lastError || state.current || '—';
  $('current').className = (state.pausedReason || state.lastError) ? 'warn' : 'muted';

  $('done').textContent = state.doneToday ?? 0;
  $('comps').textContent = state.compsToday ?? 0;

  for (const key of ['apiBase', 'delaySeconds', 'dailyCap']) {
    if (document.activeElement !== $(key)) {
      $(key).value = state[key];
    }
  }
  if (!state.hasToken) {
    $('token').placeholder = 'required — paste SCRAPE_AGENT_TOKEN';
  }

  // Queue depth comes from the server; the background script holds the token.
  const status = await browser.runtime.sendMessage({ type: 'cardfoo:status' });
  $('outstanding').textContent = status && !status.error ? status.outstanding : '—';
}

$('toggle').addEventListener('click', async () => {
  const state = await browser.runtime.sendMessage({ type: 'cardfoo:state' });
  await browser.runtime.sendMessage({ type: 'cardfoo:toggle', enabled: !state.enabled });
  setTimeout(refresh, 250);
});

for (const key of ['apiBase', 'token', 'delaySeconds', 'dailyCap']) {
  $(key).addEventListener('change', async () => {
    const value = ['delaySeconds', 'dailyCap'].includes(key) ? Number($(key).value) : $(key).value.trim();
    if (key === 'token' && value === '') return;
    await browser.runtime.sendMessage({ type: 'cardfoo:save', settings: { [key]: value } });
  });
}

refresh();
setInterval(refresh, 2000);
