/**
 * Reads the page the background script navigated to and reports what it is.
 *
 * The distinction that matters is between "eBay answered with results" and
 * "eBay answered with a wall". Both are HTTP 200, and treating the second as
 * the first would tell CardFoo a card has no comps when really we were refused
 * — which is exactly how an earlier outage got misdiagnosed. So the wall is
 * identified by the page it actually is (title, captcha URL), never by looking
 * for sign-in links, which appear in the nav of every ordinary eBay page.
 */
const WALLS = ['Security Measure', 'Sign in or Register', 'Error Page'];

browser.runtime.onMessage.addListener((msg) => {
  if (msg.type !== 'cardfoo:scrape') {
    return undefined;
  }

  const title = document.title || '';
  const refused =
    WALLS.some((wall) => title.includes(wall)) ||
    location.pathname.startsWith('/splashui/captcha') ||
    location.hostname === 'signin.ebay.com';

  if (refused) {
    return Promise.resolve({ ready: true, refused: true, title, html: '' });
  }

  // Results are rendered by the time the list container exists; without it the
  // page is still settling and the background script should ask again.
  const settled =
    document.readyState === 'complete' &&
    document.querySelector('.srp-results, .s-card, .srp-save-null-search__heading');

  if (!settled) {
    return Promise.resolve({ ready: false });
  }

  return Promise.resolve({
    ready: true,
    refused: false,
    title,
    html: document.documentElement.outerHTML,
  });
});
