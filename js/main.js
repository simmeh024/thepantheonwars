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

function initMain() {
  // Public navigation polish: mark the actual route (including dropdown
  // destinations), and enrich the two discovery-oriented menus without
  // duplicating navigation markup on every page.
  (function enhancePublicNavigation() {
    var nav = document.querySelector('.main-nav');
    if (!nav) return;
    var normalizePath = function (value) {
      var path = new URL(value, location.origin).pathname.replace(/\/index\.html$/, '/');
      return path === '/' ? '/' : path.replace(/\/$/, '');
    };
    var currentPath = normalizePath(location.pathname);
    Array.prototype.forEach.call(nav.querySelectorAll('a[href]'), function (link) {
      if (normalizePath(link.getAttribute('href')) !== currentPath) return;
      link.classList.add('active');
      var group = link.closest('.nav-item.has-dropdown');
      if (group) group.classList.add('nav-current');
    });
    var routeGroups = {
      'The Universe': ['/books.html', '/chapter-one.html', '/worlds.html', '/overlord.html', '/overlords.html', '/known-figures.html', '/soundtracks.html'],
      'News': ['/news.html', '/dev-dispatches.html', '/dev-metrics.html'],
      'Community': ['/community.html', '/member.html', '/memberlist.html', '/profile.html', '/reputation.html', '/notifications.html', '/quiz.html']
    };
    Array.prototype.forEach.call(nav.querySelectorAll('.nav-item.has-dropdown'), function (item) {
      var navLabel = item.querySelector('.nav-parent');
      var name = navLabel ? navLabel.childNodes[0].textContent.trim() : '';
      if (routeGroups[name] && routeGroups[name].indexOf(currentPath) !== -1) item.classList.add('nav-current');
    });

    var panels = {
      'The Universe': {
        eyebrow: 'Explore the Pantheon',
        text: 'Follow the worlds, their rulers, and the stories that bind them.',
        watermark: 'VEIL',
        links: {
          'The Books': { eyebrow: 'The Books', text: 'Begin with the novels and follow the fractures they leave behind.', watermark: 'BOOKS' },
          'The Worlds': { eyebrow: 'The Worlds', text: 'Trace the realms beyond the Veil and the forces that shape them.', watermark: 'WORLDS' },
          'The Overlords': { eyebrow: 'The Overlords', text: 'Meet the powers whose influence reaches across every world.', watermark: 'OVERLORDS' },
          'Known Figures': { eyebrow: 'Known Figures', text: 'Field records on the names the Overcode still has trouble accounting for.', watermark: 'FIGURES' },
          'The Soundtracks': { eyebrow: 'The Soundtracks', text: 'Listen to the score and atmosphere behind the Pantheon Wars.', watermark: 'SOUND' }
        }
      },
      'News': {
        eyebrow: 'Follow the record',
        text: 'Read public updates and the development record behind the world.',
        watermark: 'RECORD',
        links: {
          'Latest News': { eyebrow: 'Latest News', text: 'The newest announcements and public messages from the Pantheon.', watermark: 'NEWS' },
          'Development Dispatches': { eyebrow: 'Development Dispatches', text: 'A reader-friendly chronicle of the work shaping the site.', watermark: 'DISPATCH' }
        }
      },
      'Community': {
        eyebrow: 'Enter Nexus Veil',
        text: 'Meet fellow readers, exchange theories, and shape the conversation.',
        watermark: 'NEXUS',
        links: {
          'Nexus Veil (Forum)': { eyebrow: 'Nexus Veil', text: 'Meet fellow readers, exchange theories, and shape the conversation.', watermark: 'NEXUS' },
          'Member List': { eyebrow: 'Member List', text: 'Discover the readers, theorists, and creators gathered in the Veil.', watermark: 'MEMBERS' },
          'Quiz': { eyebrow: 'Pantheon Quiz', text: 'Find the world, allegiance, and resonance that best answer your call.', watermark: 'ORACLE' }
        }
      }
    };
    Array.prototype.forEach.call(nav.querySelectorAll('.nav-item.has-dropdown'), function (item) {
      var parent = item.querySelector('.nav-parent');
      var dropdown = item.querySelector('.nav-dropdown');
      var label = parent ? parent.childNodes[0].textContent.trim() : '';
      var panel = panels[label];
      if (!panel || !dropdown || dropdown.dataset.enhanced) return;
      dropdown.dataset.enhanced = 'true';
      dropdown.classList.add('nav-dropdown-rich');
      var links = document.createElement('div');
      links.className = 'nav-dropdown-links';
      Array.prototype.slice.call(dropdown.querySelectorAll(':scope > a')).forEach(function (link) {
        links.appendChild(link);
      });
      var aside = document.createElement('div');
      aside.className = 'nav-dropdown-aside';
      var eyebrow = document.createElement('span');
      eyebrow.className = 'nav-dropdown-eyebrow';
      var copy = document.createElement('span');
      copy.className = 'nav-dropdown-copy';
      aside.appendChild(eyebrow);
      aside.appendChild(copy);
      var setPanelCopy = function (details) {
        eyebrow.textContent = details.eyebrow;
        copy.textContent = details.text;
        aside.dataset.watermark = details.watermark || '';
      };
      setPanelCopy(panel);
      Array.prototype.forEach.call(links.querySelectorAll('a'), function (link) {
        var details = panel.links[link.textContent.trim()] || panel;
        link.addEventListener('pointerenter', function () { setPanelCopy(details); });
        link.addEventListener('focus', function () { setPanelCopy(details); });
      });
      dropdown.appendChild(links);
      dropdown.appendChild(aside);
    });
  })();

  // Visitor Statistics is non-critical telemetry. Run it when the browser is
  // idle so it cannot compete with the hero, styles, or authentication state
  // during the initial render.
  (function trackPageView() {
    var send = function () {
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
            query_string: location.search || null,
            referrer: document.referrer,
            visitor_id: vid,
          }),
        }).catch(function () {});
      } catch (e) {}
    };
    if ('requestIdleCallback' in window) window.requestIdleCallback(send, { timeout: 3000 });
    else setTimeout(send, 1500);
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
  var isMobileViewport = window.matchMedia && window.matchMedia('(max-width: 780px)').matches;
  var heroEl = document.querySelector('.hero');
  var heroBackgroundLayer = document.querySelector('.hero-bg');
  if (!isMobileViewport && heroEl && heroBackgroundLayer) {
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
  if (!isMobileViewport && logoEl) {
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

  // Newsletter forms — mailing-list subscription is now a real member-account
  // attribute (users.newsletter_subscribed, default on), not a separate
  // anonymous-email capture. Submitting sends the visitor straight to Create
  // Account with their typed email prefilled, rather than showing a fake
  // confirmation that used to send the address nowhere.
  document.querySelectorAll('.newsletter-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var emailInput = form.querySelector('input[type="email"]');
      var email = emailInput ? emailInput.value.trim() : '';
      if (window.openAuthModal) {
        window.openAuthModal('register');
        var regEmail = document.getElementById('reg-email');
        if (regEmail && email) regEmail.value = email;
      }
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
}

// Index loads this non-critical enhancement bundle after the first mobile
// paint. Keep the normal DOM-ready path for every other page, while allowing
// that delayed homepage load to initialise immediately rather than missing an
// event that has already fired.
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initMain);
else initMain();
