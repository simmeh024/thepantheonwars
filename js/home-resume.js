// Homepage "jump back in" strip.
//
// The homepage renders identically for a first-time visitor and for someone
// who has been reading for a year, has unread topics, a finished operation and
// an unclaimed research protocol. All of that already exists in the database
// and none of it reached the page they actually land on; api/home-resume.php
// is the one bounded request that answers "what was I doing?".
//
// Three constraints shaped this file:
//
//  * It must never touch the hero's render budget. The homepage is the LCP
//    page (see the LCP notes in CLAUDE.md), so this script is loaded late by
//    index.html's own deferred-enhancement block and does its own work behind
//    requestIdleCallback on top of that.
//
//  * It must not paint for a signed-out visitor, and must not paint a
//    signed-out state for a signed-in one. window.PW_AUTH.loggedIn starts
//    false and only means anything once .resolved is true -- reading it early
//    is exactly the bug that used to flash a Log In panel on every gated page.
//    boot() is therefore inert until the session settles.
//
//  * A member with nothing waiting sees nothing at all. A strip reading
//    "0 unread, 0 operations, 0 protocols" is worse than no strip: it turns
//    an empty state into a row of failures.

(function () {
  var host = document.getElementById('home-resume');
  if (!host) return;

  var loaded = false;

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function render(data) {
    var items = (data && data.items) || [];
    if (!items.length) { host.hidden = true; return; }

    // First name only. "Welcome back, Commander Vothrayne of the Ninth" is a
    // display name, and the greeting is not the point of the strip.
    var name = String(data.display_name || '').split(/\s+/)[0];

    host.innerHTML =
      '<div class="container">' +
        '<div class="home-resume-head">' +
          '<span class="home-resume-kicker">Jump back in</span>' +
          (name ? '<h2>Welcome back, ' + escapeHtml(name) + '.</h2>' : '<h2>Welcome back.</h2>') +
        '</div>' +
        '<div class="home-resume-cards">' +
          items.map(function (item, index) {
            return '<a class="home-resume-card home-resume-card--' + escapeHtml(item.key) + '"' +
              ' href="' + escapeHtml(item.href) + '"' +
              // Staggered arrival, driven by a custom property rather than an
              // inline animation-delay so the reduced-motion rule can switch
              // the whole thing off in one place.
              ' style="--resume-index:' + index + '">' +
              '<span class="home-resume-card-value">' + escapeHtml(item.value) + '</span>' +
              '<span class="home-resume-card-label">' + escapeHtml(item.label) + '</span>' +
              '<span class="home-resume-card-detail">' + escapeHtml(item.detail) + '</span>' +
            '</a>';
          }).join('') +
        '</div>' +
      '</div>';
    host.hidden = false;
  }

  function load() {
    if (loaded) return;
    loaded = true;
    fetch('/api/home-resume.php', { credentials: 'same-origin' })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (data) {
        if (!data || !data.ok || !data.signed_in) { host.hidden = true; return; }
        render(data);
      })
      .catch(function () {
        // The homepage is complete without this. A failed strip is simply
        // absent rather than an error message on the landing page.
        host.hidden = true;
      });
  }

  function boot() {
    var auth = window.PW_AUTH;
    if (!auth || !auth.resolved) return;
    if (!auth.loggedIn) { host.hidden = true; return; }
    if ('requestIdleCallback' in window) window.requestIdleCallback(load, { timeout: 2000 });
    else window.setTimeout(load, 400);
  }

  // Both paths, for the same reason js/messages.js needs both: the session may
  // resolve before this script runs (in which case the event has already
  // fired and will not fire again), or after it (in which case the direct call
  // is inert and the listener does the work).
  document.addEventListener('pw-auth-ready', boot);
  boot();
}());
