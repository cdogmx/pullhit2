# CardFoo Sold Agent (Firefox)

eBay stopped serving completed listings to anyone who is not signed in. Verified
on 2026-09-08: `LH_Sold` / `LH_Complete` return a captcha splash or a sign-in
wall to Oxylabs, to a plain HTTP client on a residential IP, **and** to a real
headful browser with a cold profile — while the same keywords without those
filters come back whole every time. So the fetch has to happen in a browser that
is already signed in to eBay, which is what this extension is.

The server keeps the queue, parses the HTML and computes the prices. The
extension only fetches pages and hands them back. It is a transport, and it is
never trusted to say what a card sold for.

## Install

1. Firefox → `about:debugging#/runtime/this-firefox` → **Load Temporary Add-on**
   → pick `extension/manifest.json`.
2. Click the toolbar icon → **Settings**:
   - **CardFoo URL** — `https://cardfoo.com` (or `http://pullhit2.test` locally)
   - **Agent token** — the value of `SCRAPE_AGENT_TOKEN` from `.env`
3. Make sure you are **signed in to eBay** in this browser.
4. Press **Start**.

A temporary add-on is removed when Firefox restarts. To keep it, sign the
package with `web-ext sign` and install the resulting `.xpi`.

## Filling the queue

```bash
php artisan ebay:enqueue-sold --limit=300          # stalest valued cards first
php artisan ebay:enqueue-sold --limit=50 --priority=10   # jump the queue
php artisan ebay:enqueue-sold --truncate           # clear what has not started
```

Cards with no market value are skipped unless you pass `--include-unvalued` — a
card nobody has ever priced is the worst use of a scarce fetch.

## What it does while running

Opens one background tab it owns, navigates it through the queue, and closes it
when you stop. It navigates rather than calling `fetch()` because a plain HTTP
request from this same machine is refused with a 403 before eBay even considers
who is asking — only a real navigation looks like one.

Between pages it waits `delaySeconds` (default 25) with ±40% jitter, and it
stops for the day at `dailyCap` (default 400).

## When it stops on its own

- **A wall.** If eBay answers with a captcha or sign-in page the agent reports
  that page as *blocked*, stops, and shows the reason. It does not retry into it.
  The server records "we were refused", never "this card has no comps" — that
  distinction is the whole reason the endpoint inspects the page itself.
- **Daily cap reached.**
- **Signed out.** Sign back in to eBay, then press Start again.

Pressing **Start** clears a pause.

## Worth knowing before you leave it running

It works by using your signed-in eBay account, and automated querying is
something eBay's User Agreement prohibits. eBay is running Akamai Bot Manager and
Radware in front of these pages, so treat the pacing as the safety margin it is —
a slower `delaySeconds` and a lower `dailyCap` look more like ordinary browsing.
If your eBay account matters to you (EPN affiliate revenue is tied to the same
identity), the sanctioned route is the eBay **Marketplace Insights API**, which
serves the same sold data without any of this.

## Checking on it from the server

```bash
php artisan ebay:sold-status          # breaker, budget, freshness
php artisan ebay:sold-status --probe  # is eBay gating us right now?
```
