// The Pantheon Wars — site interactivity

// City/harbor cross-section layer accordions + map lightbox viewers (Worlds
// page). Exposed on window because worlds.js injects this markup from
// api/worlds.php *after* DOMContentLoaded has already fired once, so it has
// to call this again itself once the fetched markup is in the DOM.
function wireWorldInteractions() {
  document.querySelectorAll('.city-layer').forEach(function (layer) {
    var toggleBtn = layer.querySelector('.layer-toggle');
    if (!toggleBtn || toggleBtn.dataset.wired) return;
    toggleBtn.dataset.wired = '1';
    toggleBtn.addEventListener('click', function () {
      var wasOpen = layer.classList.contains('open');
      var group = layer.closest('.city-stack, .harbor-row');
      if (group) {
        group.querySelectorAll('.city-layer.open').forEach(function (other) {
          if (other !== layer) other.classList.remove('open');
        });
      }
      layer.classList.toggle('open', !wasOpen);
    });
  });

  document.querySelectorAll('.map-thumb-btn').forEach(function (btn) {
    if (btn.dataset.wired) return;
    var lightbox = document.getElementById(btn.getAttribute('data-lightbox'));
    if (!lightbox) return;
    btn.dataset.wired = '1';
    btn.addEventListener('click', function () { lightbox.hidden = false; });
  });
  document.querySelectorAll('.map-lightbox').forEach(function (lightbox) {
    if (lightbox.dataset.wired) return;
    lightbox.dataset.wired = '1';
    var close = function () { lightbox.hidden = true; };
    lightbox.querySelector('.map-lightbox-close').addEventListener('click', close);
    lightbox.querySelector('.map-lightbox-backdrop').addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !lightbox.hidden) close();
    });
  });
}
window.wireWorldInteractions = wireWorldInteractions;

document.addEventListener('DOMContentLoaded', function () {
  // Visitor Statistics beacon (admin console): fire-and-forget page-view
  // ping, backing the admin's "Visitor Statistics" page. Never blocks or
  // delays the rest of page load -- any failure here (offline, ad blocker,
  // etc.) is silently swallowed since it's non-critical telemetry.
  (function trackPageView() {
    try {
      var match = document.cookie.match(/(?:^|; )pw_vid=([^;]+)/);
      var vid = match ? match[1] : null;
      if (!vid) {
        vid = crypto.randomUUID();
        var expires = new Date(Date.now() + 365 * 24 * 60 * 60 * 1000).toUTCString();
        document.cookie = 'pw_vid=' + vid + '; expires=' + expires + '; path=/; SameSite=Lax';
      }
      fetch('/api/track-visit.php', {
        method: 'POST',
        keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          path: location.pathname,
          referrer: document.referrer,
          visitor_id: vid,
        }),
      }).catch(function () {});
    } catch (e) {}
  })();

  // Mobile nav toggle
  var toggle = document.querySelector('.nav-toggle');
  var nav = document.querySelector('.main-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      nav.classList.toggle('open');
    });
    nav.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () { nav.classList.remove('open'); });
    });
  }

  // Collapsible footer "Explore" list (mobile only — desktop's side-by-side
  // columns don't need it, so the CSS only applies the collapsed state under
  // 700px; on wider screens this toggle is a no-op).
  document.querySelectorAll('.footer-toggle').forEach(function (toggleEl) {
    var listId = toggleEl.getAttribute('aria-controls');
    var list = listId && document.getElementById(listId);
    if (!list) return;
    var setState = function (expanded) {
      toggleEl.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      list.classList.toggle('collapsed', !expanded);
    };
    toggleEl.addEventListener('click', function () {
      setState(toggleEl.getAttribute('aria-expanded') !== 'true');
    });
    toggleEl.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        setState(toggleEl.getAttribute('aria-expanded') !== 'true');
      }
    });
  });

  // Header shadow on scroll
  var header = document.querySelector('.site-header');
  if (header) {
    window.addEventListener('scroll', function () {
      if (window.scrollY > 10) header.style.boxShadow = '0 8px 24px rgba(0,0,0,0.5)';
      else header.style.boxShadow = 'none';
    });
  }

  // Random glitch bursts — hero image (homepage only)
  var heroEl = document.querySelector('.hero');
  var heroBackgroundLayer = document.querySelector('.hero-bg');
  if (heroEl && heroBackgroundLayer) {
    (function scheduleHeroGlitch() {
      // Reuse the visible hero image. A late duplicate background became a
      // new LCP candidate in Chrome, so this effect must not reveal a second
      // large image element.
      var delay = 15000 + Math.random() * 10000;
      setTimeout(function () {
        heroEl.classList.add('is-glitching');
        heroBackgroundLayer.classList.add('is-glitching');
        setTimeout(function () {
          heroEl.classList.remove('is-glitching');
          heroBackgroundLayer.classList.remove('is-glitching');
        }, 350 + Math.random() * 150);
        scheduleHeroGlitch();
      }, delay);
    })();
  }

  // Random glitch bursts — nav logo (every page)
  var logoEl = document.querySelector('.logo');
  if (logoEl) {
    (function scheduleLogoGlitch() {
      var delay = 6000 + Math.random() * 10000;
      setTimeout(function () {
        logoEl.classList.add('is-glitching');
        setTimeout(function () {
          logoEl.classList.remove('is-glitching');
        }, 300 + Math.random() * 150);
        scheduleLogoGlitch();
      }, delay);
    })();
  }

  // Newsletter forms — client-side only.
  // NOTE for Pascal: this currently just shows a confirmation message and does not
  // send the email anywhere. To actually collect subscribers, sign up for an email
  // service (e.g. Buttondown, ConvertKit, Mailchimp) and point each <form> at the
  // endpoint it gives you, or wire this fetch call to it.
  document.querySelectorAll('.newsletter-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var email = form.querySelector('input[type="email"]').value.trim();
      var confirm = form.parentElement.querySelector('.confirm');
      if (!email) return;
      if (confirm) {
        confirm.textContent = 'You are bound to the Pantheon now. Watch your inbox, ' + email + '.';
        confirm.classList.add('show');
      }
      form.reset();
    });
  });

  // City/harbor cross-section layers + map lightbox (Worlds page). No-op on
  // pages that don't have this markup yet (e.g. worlds.html before its
  // fetched content loads) -- wireWorldInteractions() itself just finds
  // nothing via querySelectorAll in that case, and worlds.js calls it again
  // once the markup exists.
  wireWorldInteractions();

  // Planetary two-scene view (Worlds > High Hammer) — arrow flips between scenes.
  document.querySelectorAll('.planet-view').forEach(function (view) {
    var arrows = view.querySelectorAll('.scene-arrow');
    arrows.forEach(function (btn) {
      btn.addEventListener('click', function () {
        view.querySelectorAll('.planet-scene').forEach(function (scene) {
          scene.classList.toggle('active');
        });
      });
    });
  });
});
