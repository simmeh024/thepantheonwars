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

  initWeatherWidget();
}

/* ---------------------------------------------------------------------------
   Header weather widget

   A compact bar in the header showing one world's current conditions, pointable
   at any unlocked world, linking through to that world's record.

   Built here in JS rather than as markup, because the header is hand-duplicated
   across 26 public pages -- the same reason js/members.js renders the
   authenticated profile chip itself instead of every page carrying a copy.
--------------------------------------------------------------------------- */

var PW_WEATHER_CACHE_KEY = 'pw_weather_glance';
var PW_WEATHER_CHOICE_KEY = 'pw_weather_world';
var PW_WEATHER_DEFAULT_SLUG = 'neoh';
// The current condition and temperature are admin-authored and only change when
// Weather Control is saved; the rest of the forecast is deterministic for a
// whole UTC day. Half an hour therefore bounds staleness without re-fetching on
// every page view -- roughly one request per visitor per half hour, not one per
// page, per the initial-load request discipline this codebase already follows.
var PW_WEATHER_TTL_MS = 30 * 60 * 1000;

function weatherIconSvg(icon) {
  // Same five-icon vocabulary as the World Record's weather card
  // (js/world-detail.js), so the header and the record never disagree.
  var paths = {
    'acid-rain': '<path d="M13 35h34a10 10 0 0 0 1-20 16 16 0 0 0-30-3 12 12 0 0 0-5 23z"/><path d="m20 43-4 9m15-9-4 9m15-9-4 9"/><path d="M18 55h22"/>',
    storm: '<path d="M13 34h34a10 10 0 0 0 1-20 16 16 0 0 0-30-3 12 12 0 0 0-5 23z"/><path d="m34 38-8 11h7l-4 10 13-15h-8z"/>',
    smog: '<path d="M13 31h34a10 10 0 0 0 1-20 16 16 0 0 0-30-3 12 12 0 0 0-5 23z"/><path d="M10 40h36M18 47h34M8 54h31"/>',
    clear: '<circle cx="32" cy="32" r="11"/><path d="M32 8v8m0 32v8M8 32h8m32 0h8M15 15l6 6m22 22 6 6m0-34-6 6M21 43l-6 6"/>',
    overcast: '<path d="M13 37h34a10 10 0 0 0 1-20 16 16 0 0 0-30-3 12 12 0 0 0-5 23z"/>'
  };
  return '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    + (paths[icon] || paths.overcast) + '</svg>';
}

function weatherEscape(value) {
  return String(value == null ? '' : value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function initWeatherWidget() {
  var utility = document.querySelector('.nav-utility');
  if (!utility || document.getElementById('pw-weather-widget')) return;

  var worlds = [];
  var activeSlug = null;
  var open = false;

  // --- markup ---------------------------------------------------------------
  var root = document.createElement('div');
  root.className = 'pw-weather';
  root.id = 'pw-weather-widget';
  root.hidden = true;   // stays hidden until there is something real to show
  root.innerHTML =
    '<a class="pw-weather-bar" href="#" aria-label="World weather">'
    + '<span class="pw-weather-icon"></span>'
    + '<span class="pw-weather-temp"></span>'
    + '<span class="pw-weather-condition"></span>'
    + '</a>'
    + '<button type="button" class="pw-weather-toggle" aria-expanded="false" aria-haspopup="true" aria-label="Choose a world">'
    + '<span class="pw-weather-caret" aria-hidden="true">&#8964;</span>'
    + '</button>'
    + '<div class="pw-weather-menu" role="menu" hidden></div>';
  // Appended, so the pill sits at the far right of the nav bar, after the
  // profile chip and the notification bell.
  utility.appendChild(root);

  var barEl = root.querySelector('.pw-weather-bar');
  var iconEl = root.querySelector('.pw-weather-icon');
  var tempEl = root.querySelector('.pw-weather-temp');
  var condEl = root.querySelector('.pw-weather-condition');
  var toggleEl = root.querySelector('.pw-weather-toggle');
  var menuEl = root.querySelector('.pw-weather-menu');

  // --- preference -----------------------------------------------------------

  function storedChoice() {
    // A signed-in member's choice follows them across devices; a guest's is
    // per-browser. PW_AUTH may not have resolved yet on first paint, which is
    // why render() runs again on pw-auth-ready.
    var auth = window.PW_AUTH;
    if (auth && auth.loggedIn && auth.user && auth.user.weather_world_slug) {
      return auth.user.weather_world_slug;
    }
    try { return window.localStorage.getItem(PW_WEATHER_CHOICE_KEY); } catch (e) { return null; }
  }

  function rememberChoice(slug) {
    try { window.localStorage.setItem(PW_WEATHER_CHOICE_KEY, slug); } catch (e) {}
    var auth = window.PW_AUTH;
    if (!auth || !auth.loggedIn || !auth.csrf) return;
    if (auth.user) auth.user.weather_world_slug = slug;
    fetch('/api/weather-widget/select.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ slug: slug, csrf: auth.csrf })
    }).catch(function () {
      // The localStorage copy above already holds the choice for this browser.
    });
  }

  function resolveWorld() {
    if (!worlds.length) return null;
    var wanted = activeSlug || storedChoice() || PW_WEATHER_DEFAULT_SLUG;
    var found = null;
    worlds.forEach(function (world) { if (world.slug === wanted) found = world; });
    // A stored world that has since been locked, or a default that is not
    // unlocked yet, falls back to the first available one rather than to
    // nothing.
    if (!found) {
      worlds.forEach(function (world) { if (!found && world.slug === PW_WEATHER_DEFAULT_SLUG) found = world; });
    }
    return found || worlds[0];
  }

  // --- rendering ------------------------------------------------------------

  function render() {
    var world = resolveWorld();
    if (!world) { root.hidden = true; return; }

    var current = world.current || {};
    iconEl.innerHTML = weatherIconSvg(current.icon);
    // The unit is its own element so the compact bar can drop it. Degrees alone
    // are unambiguous on a weather bar, and it buys ~10px -- which decides
    // whether the widget survives beside a signed-in profile chip at all.
    tempEl.innerHTML = (current.temperature_c != null ? weatherEscape(current.temperature_c) : '--')
      + '°<span class="pw-weather-temp-unit">C</span>';
    condEl.textContent = current.condition || '';
    barEl.href = 'world.html?slug=' + encodeURIComponent(world.slug);
    barEl.setAttribute('aria-label', world.name + ': ' + (current.condition || 'conditions')
      + ', ' + (current.temperature_c != null ? current.temperature_c + ' degrees' : 'unknown') + '. Open the World Record.');
    barEl.title = world.name + ' — ' + (world.location || '');

    // Bare "R, G, B" from World Control, so one value drives both the solid
    // accent and its translucent glow. Removed rather than set empty when
    // absent: an empty custom property still counts as set and would defeat
    // the var() fallback.
    if (world.accent) root.style.setProperty('--pw-weather-accent', world.accent);
    else root.style.removeProperty('--pw-weather-accent');

    renderMenu(world.slug);
    root.hidden = false;
    fitToHeader();
  }

  // Give up space only when the header actually runs out of it.
  //
  // A viewport breakpoint cannot do this job: .nav-inner is capped at
  // max-width 1180px, so the room left over never grows past ~154px however
  // wide the screen gets -- and that is exactly what the full bar wants. The
  // amount left also depends on whether the visitor is signed in, since the
  // profile chip is far wider than a "Login" link, which no media query can
  // distinguish.
  //
  // So measure the symptom instead. When the header runs out of room its links
  // wrap and .nav-inner grows taller, so compare against its height with the
  // widget removed: drop the condition text first, and only hide the widget
  // outright if even the compact bar does not fit.
  function fitToHeader() {
    var inner = root.closest('.nav-inner');
    if (!inner || !worlds.length) return;
    root.hidden = false;

    // Height of the header with the widget taken out of the flow.
    var previous = root.style.display;
    root.style.display = 'none';
    var baseline = inner.getBoundingClientRect().height;
    root.style.display = previous;

    var utility = root.parentElement;

    // Two separate failure modes, and neither alone is enough:
    //  - the header grew taller, i.e. the nav links wrapped to make room;
    //  - the header runs past its own content box, which happens between the
    //    780px breakpoint and roughly 1090px where the desktop nav is still
    //    shown but no longer fits. That band overruns even without this widget,
    //    so the widget stands down there rather than adding to it.
    //
    // The width test compares .nav-utility's right edge against the content
    // box, NOT inner.scrollWidth: the nav's mega-menu panels are absolutely
    // positioned and still count towards scrollWidth even while invisible, so
    // that reading is inflated and reports an overflow that is not real.
    function doesNotFit() {
      if (inner.getBoundingClientRect().height > baseline) return true;
      var innerRect = inner.getBoundingClientRect();
      var contentRight = innerRect.right - parseFloat(getComputedStyle(inner).paddingRight);
      return utility.getBoundingClientRect().right > contentRight + 1;
    }

    root.classList.remove('is-compact');
    if (!doesNotFit()) return;
    root.classList.add('is-compact');
    if (!doesNotFit()) return;
    root.hidden = true;
  }

  var fitTimer = null;
  window.addEventListener('resize', function () {
    if (fitTimer) window.clearTimeout(fitTimer);
    // fitToHeader un-hides before measuring, so a widened header wins its
    // space back rather than staying hidden from an earlier narrow pass.
    fitTimer = window.setTimeout(fitToHeader, 150);
  });

  function renderMenu(currentSlug) {
    menuEl.innerHTML = worlds.map(function (world) {
      var current = world.current || {};
      return '<button type="button" role="menuitem" class="pw-weather-option'
        + (world.slug === currentSlug ? ' is-current' : '')
        + '" data-slug="' + weatherEscape(world.slug) + '"'
        + (world.accent ? ' style="--pw-weather-accent: ' + weatherEscape(world.accent) + '"' : '')
        + (world.slug === currentSlug ? ' aria-current="true"' : '')
        + '>'
        + '<span class="pw-weather-option-dot" aria-hidden="true"></span>'
        + '<span class="pw-weather-option-name">' + weatherEscape(world.name) + '</span>'
        + '<span class="pw-weather-option-temp">'
        + weatherEscape(current.temperature_c != null ? current.temperature_c + '°' : '--')
        + '</span>'
        + '</button>';
    }).join('');
  }

  // --- picker ---------------------------------------------------------------
  // Mirrors the notification dropdown's contract: aria-expanded, outside-click
  // and Escape closing, with Escape returning focus to the trigger.

  function setOpen(next) {
    open = next;
    menuEl.hidden = !next;
    root.classList.toggle('is-open', next);
    toggleEl.setAttribute('aria-expanded', next ? 'true' : 'false');
  }

  toggleEl.addEventListener('click', function (event) {
    event.preventDefault();
    setOpen(!open);
    if (open) {
      var first = menuEl.querySelector('.pw-weather-option.is-current') || menuEl.querySelector('.pw-weather-option');
      if (first) first.focus();
    }
  });

  menuEl.addEventListener('click', function (event) {
    var option = event.target.closest ? event.target.closest('.pw-weather-option') : null;
    if (!option) return;
    activeSlug = option.dataset.slug;
    rememberChoice(activeSlug);
    render();
    setOpen(false);
    toggleEl.focus();
  });

  document.addEventListener('click', function (event) {
    if (open && !root.contains(event.target)) setOpen(false);
  });

  root.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape' || !open) return;
    setOpen(false);
    toggleEl.focus();
  });

  // --- data -----------------------------------------------------------------

  function applyWorlds(list) {
    if (!list || !list.length) return;
    worlds = list;
    render();
  }

  function readCache() {
    try {
      var raw = window.localStorage.getItem(PW_WEATHER_CACHE_KEY);
      if (!raw) return null;
      var parsed = JSON.parse(raw);
      if (!parsed || !Array.isArray(parsed.worlds) || !parsed.fetchedAt) return null;
      return parsed;
    } catch (e) { return null; }
  }

  function fetchWorlds() {
    fetch('/api/worlds-weather-glance.php', { credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data || !data.ok || !Array.isArray(data.worlds)) return;
        try {
          window.localStorage.setItem(PW_WEATHER_CACHE_KEY,
            JSON.stringify({ fetchedAt: Date.now(), worlds: data.worlds }));
        } catch (e) {}
        applyWorlds(data.worlds);
      })
      .catch(function () {
        // The header is fully usable without this; the widget just stays hidden.
      });
  }

  // Paint instantly from cache so there is no flash and no layout shift, then
  // refresh in the background only when the cache has actually aged out.
  var cached = readCache();
  if (cached) applyWorlds(cached.worlds);
  if (!cached || (Date.now() - cached.fetchedAt) > PW_WEATHER_TTL_MS) {
    if ('requestIdleCallback' in window) window.requestIdleCallback(fetchWorlds, { timeout: 3000 });
    else setTimeout(fetchWorlds, 1500);
  }

  // members.js resolves the session after this runs, so a member's stored world
  // only becomes known here. Re-render then, but only while they have not
  // already picked something in this page view.
  document.addEventListener('pw-auth-ready', function () {
    if (!activeSlug) render();
  });
}

// Index loads this non-critical enhancement bundle after the first mobile
// paint. Keep the normal DOM-ready path for every other page, while allowing
// that delayed homepage load to initialise immediately rather than missing an
// event that has already fired.
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initMain);
else initMain();
