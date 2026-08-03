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
    btn.addEventListener('click', function () {
      lightbox.hidden = false;
      // Remembered so focus can be handed back on close; a map opened from a
      // thumbnail must not drop a keyboard reader at the top of the document.
      lightbox.pwReturnFocus = btn;
      if (lightbox.pwZoomReset) lightbox.pwZoomReset();
    });
  });
  document.querySelectorAll('.map-lightbox').forEach(wireMapLightbox);
}
window.wireWorldInteractions = wireWorldInteractions;

/* ---------------------------------------------------------------------------
   Map lightbox: zoom and pan

   The world maps and district art are drawn at a detail level the 90vh frame
   cannot show -- at fit size the place names on a city cross-section are
   simply too small to read on a laptop. Opening the map already existed; this
   adds the part that makes opening it worth doing.

   Deliberately built here rather than in js/world-detail.js: the same
   .map-lightbox markup is emitted by the World Record renderer and can be
   used by any static page, and wireWorldInteractions() is already the
   re-callable hook that late-injected markup goes through.

   The controls are created in JS rather than added to the markup for the same
   reason the header weather widget is: there is more than one producer of this
   block, and none of them should have to carry a copy of the button row.
--------------------------------------------------------------------------- */

var MAP_ZOOM_MIN = 1;
var MAP_ZOOM_MAX = 6;
var MAP_ZOOM_STEP = 0.35;

function wireMapLightbox(lightbox) {
  if (lightbox.dataset.wired) return;
  lightbox.dataset.wired = '1';

  var inner = lightbox.querySelector('.map-lightbox-inner');
  var img = inner && inner.querySelector('img');
  var closeBtn = lightbox.querySelector('.map-lightbox-close');
  var backdrop = lightbox.querySelector('.map-lightbox-backdrop');

  function close() {
    lightbox.hidden = true;
    reset();
    var returnTo = lightbox.pwReturnFocus;
    lightbox.pwReturnFocus = null;
    if (returnTo && document.contains(returnTo)) returnTo.focus();
  }
  if (closeBtn) closeBtn.addEventListener('click', close);
  if (backdrop) backdrop.addEventListener('click', close);

  // No image means there is nothing to zoom; the plain open/close lightbox
  // above is still perfectly usable, so bail out rather than building a
  // control bar for controls that would do nothing.
  if (!inner || !img) {
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !lightbox.hidden) close();
    });
    return;
  }

  inner.classList.add('is-zoomable');

  var scale = 1;
  var tx = 0;
  var ty = 0;

  var controls = document.createElement('div');
  controls.className = 'map-lightbox-controls';
  controls.innerHTML =
    '<button type="button" class="map-zoom-btn" data-map-zoom="out" aria-label="Zoom out">&minus;</button>' +
    '<span class="map-zoom-level" role="status" aria-live="polite">100%</span>' +
    '<button type="button" class="map-zoom-btn" data-map-zoom="in" aria-label="Zoom in">+</button>' +
    '<button type="button" class="map-zoom-btn map-zoom-reset" data-map-zoom="reset">Reset</button>';
  lightbox.appendChild(controls);
  var levelEl = controls.querySelector('.map-zoom-level');

  /* Pan is clamped so the image can never be dragged clear of its own frame.
     At scale 1 the allowance is zero, which is what makes a fully zoomed-out
     map sit still instead of sliding around under the pointer. */
  function clamp() {
    var box = inner.getBoundingClientRect();
    var maxX = Math.max(0, (box.width * (scale - 1)) / 2);
    var maxY = Math.max(0, (box.height * (scale - 1)) / 2);
    tx = Math.max(-maxX, Math.min(maxX, tx));
    ty = Math.max(-maxY, Math.min(maxY, ty));
  }

  function apply() {
    clamp();
    img.style.transform = 'translate(' + tx.toFixed(1) + 'px, ' + ty.toFixed(1) + 'px) scale(' + scale.toFixed(3) + ')';
    inner.classList.toggle('is-zoomed', scale > 1.001);
    levelEl.textContent = Math.round(scale * 100) + '%';
    var atMin = scale <= MAP_ZOOM_MIN + 0.001;
    var atMax = scale >= MAP_ZOOM_MAX - 0.001;
    controls.querySelector('[data-map-zoom="out"]').disabled = atMin;
    controls.querySelector('[data-map-zoom="in"]').disabled = atMax;
    controls.querySelector('[data-map-zoom="reset"]').disabled = atMin && !tx && !ty;
  }

  function reset() {
    scale = 1; tx = 0; ty = 0;
    apply();
  }
  lightbox.pwZoomReset = reset;

  /* Zooming toward a point rather than the centre. Without the origin term,
     zooming in on a corner of a district map walks the thing you were looking
     at straight out of frame, which is the whole reason to zoom at all. */
  function zoomAt(nextScale, clientX, clientY) {
    var previous = scale;
    scale = Math.max(MAP_ZOOM_MIN, Math.min(MAP_ZOOM_MAX, nextScale));
    if (scale === previous) { apply(); return; }
    var box = inner.getBoundingClientRect();
    var originX = (clientX == null ? box.left + box.width / 2 : clientX) - (box.left + box.width / 2);
    var originY = (clientY == null ? box.top + box.height / 2 : clientY) - (box.top + box.height / 2);
    var ratio = scale / previous;
    tx = originX - (originX - tx) * ratio;
    ty = originY - (originY - ty) * ratio;
    if (scale <= MAP_ZOOM_MIN + 0.001) { tx = 0; ty = 0; }
    apply();
  }

  controls.addEventListener('click', function (event) {
    var btn = event.target.closest('[data-map-zoom]');
    if (!btn) return;
    var action = btn.getAttribute('data-map-zoom');
    if (action === 'reset') { reset(); return; }
    zoomAt(scale + (action === 'in' ? MAP_ZOOM_STEP : -MAP_ZOOM_STEP) * scale, null, null);
  });

  inner.addEventListener('wheel', function (event) {
    event.preventDefault();
    zoomAt(scale * (event.deltaY < 0 ? 1.12 : 1 / 1.12), event.clientX, event.clientY);
  }, { passive: false });

  inner.addEventListener('dblclick', function (event) {
    if (scale > 1.001) reset();
    else zoomAt(2.5, event.clientX, event.clientY);
  });

  var dragging = false;
  var startX = 0, startY = 0, startTx = 0, startTy = 0;
  inner.addEventListener('pointerdown', function (event) {
    if (scale <= 1.001 || event.button !== 0) return;
    dragging = true;
    startX = event.clientX; startY = event.clientY;
    startTx = tx; startTy = ty;
    inner.classList.add('is-panning');
    inner.setPointerCapture(event.pointerId);
  });
  inner.addEventListener('pointermove', function (event) {
    if (!dragging) return;
    tx = startTx + (event.clientX - startX);
    ty = startTy + (event.clientY - startY);
    apply();
  });
  function endPan(event) {
    if (!dragging) return;
    dragging = false;
    inner.classList.remove('is-panning');
    if (event && inner.hasPointerCapture && inner.hasPointerCapture(event.pointerId)) {
      inner.releasePointerCapture(event.pointerId);
    }
  }
  inner.addEventListener('pointerup', endPan);
  inner.addEventListener('pointercancel', endPan);

  document.addEventListener('keydown', function (e) {
    if (lightbox.hidden) return;
    if (e.key === 'Escape') { close(); return; }
    // The zoom keys are only claimed while a map is actually open, so they
    // never compete with typing anywhere else on the page.
    if (e.key === '+' || e.key === '=') { e.preventDefault(); zoomAt(scale + MAP_ZOOM_STEP * scale, null, null); }
    else if (e.key === '-' || e.key === '_') { e.preventDefault(); zoomAt(scale - MAP_ZOOM_STEP * scale, null, null); }
    else if (e.key === '0') { e.preventDefault(); reset(); }
  });

  window.addEventListener('resize', function () { if (!lightbox.hidden) apply(); });
  apply();
}

function initMain() {
  // Public navigation polish: mark the actual route (including dropdown
  // destinations), and enrich the two discovery-oriented menus without
  // duplicating navigation markup on every page.
  (function enhancePublicNavigation() {
    var nav = document.querySelector('.main-nav');
    if (!nav) return;
    // Missions is a direct, member-facing destination. A handful of older
    // specialised pages have compact legacy navigation, so make the route
    // present there as well while their static templates catch up.
    if (!nav.querySelector('a[href="missions.html"]')) {
      var missionsLink = document.createElement('a');
      missionsLink.href = 'missions.html';
      missionsLink.textContent = 'Missions';
      nav.appendChild(missionsLink);
    }
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

  initCopyLinks();
  initKeyboardShortcuts();
  initWeatherWidget();
}

/* ---------------------------------------------------------------------------
   Copy-link affordance for lore anchors

   A World Record district, a Timeline event and a Known Figures record are all
   individually addressable, and none of them had a way to get at that address:
   the URL bar showed the page, never the part of it being read. This is the
   missing half of a deep link that already worked.

   Delegated from the document rather than wired per element, because every
   surface that emits one of these builds its markup after DOMContentLoaded --
   the World Record renders from a fetch, the Timeline from another, and Known
   Figures from a third. A delegated handler covers all of them with no
   re-wiring hook and no ordering rule to remember.
--------------------------------------------------------------------------- */

var COPY_LINK_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg>';
var COPY_DONE_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>';

/* Emits the button every producer uses, so the glyph, the class and the
   accessible name cannot drift between the three pages that render one.
   `target` is either a bare '#fragment' or a full path with one. */
function pwCopyLinkButton(target, label) {
  var safe = String(target == null ? '' : target)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  var safeLabel = String(label == null ? '' : label)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  return '<button type="button" class="pw-copy-link" data-copy-link="' + safe + '"' +
    ' title="Copy link to ' + safeLabel + '" aria-label="Copy link to ' + safeLabel + '">' +
    COPY_LINK_ICON + '<span class="pw-copy-link-flash" aria-hidden="true">' + COPY_DONE_ICON + '</span>' +
    '</button>';
}
window.pwCopyLinkButton = pwCopyLinkButton;

function pwWriteClipboard(text) {
  if (navigator.clipboard && window.isSecureContext) {
    return navigator.clipboard.writeText(text);
  }
  // execCommand is deprecated but is still the only path on a page served
  // over plain HTTP, which is what a local preview of this site is.
  return new Promise(function (resolve, reject) {
    var field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.style.cssText = 'position:fixed;top:-1000px;opacity:0';
    document.body.appendChild(field);
    field.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    document.body.removeChild(field);
    if (ok) resolve(); else reject(new Error('copy failed'));
  });
}

function initCopyLinks() {
  var announcer = null;
  function announce(message) {
    if (!announcer) {
      announcer = document.createElement('span');
      announcer.className = 'sr-only';
      announcer.setAttribute('role', 'status');
      announcer.setAttribute('aria-live', 'polite');
      document.body.appendChild(announcer);
    }
    // Cleared first so an identical second message is still announced.
    announcer.textContent = '';
    window.setTimeout(function () { announcer.textContent = message; }, 30);
  }

  document.addEventListener('click', function (event) {
    var btn = event.target.closest && event.target.closest('[data-copy-link]');
    if (!btn) return;
    event.preventDefault();
    // A copy button often sits inside a larger control (a district's own
    // expand toggle). Copying a link must not also open the thing.
    event.stopPropagation();
    var target = btn.getAttribute('data-copy-link') || '';
    var url = target.charAt(0) === '#'
      ? location.origin + location.pathname + location.search + target
      : new URL(target, location.href).href;
    pwWriteClipboard(url).then(function () {
      btn.classList.add('is-copied');
      announce('Link copied to clipboard.');
      window.setTimeout(function () { btn.classList.remove('is-copied'); }, 1600);
    }).catch(function () {
      // Nothing was copied, so say so rather than flashing a tick that lies.
      btn.classList.add('is-copy-failed');
      announce('Could not copy the link. Copy it from the address bar instead.');
      window.setTimeout(function () { btn.classList.remove('is-copy-failed'); }, 2200);
    });
  });
}

/* ---------------------------------------------------------------------------
   Breadcrumb trail

   world.html?slug=, overlord.html?slug= and news-post.html?slug= are all
   arrived at directly, from a shared link, a notification or a search result.
   Landing on one gave a reader no route into the rest of the site except the
   browser's own Back button -- which, on a first visit from an external link,
   goes nowhere useful at all. The World Record had a single "return to the
   atlas" line; this generalises that into a real trail and gives the other two
   the same treatment.

   Built here rather than per page so the three cannot drift, and rendered from
   JS because the name of the final crumb is only known once the record it
   describes has been fetched.
--------------------------------------------------------------------------- */

function pwBreadcrumbHtml(trail) {
  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  var crumbs = (trail || []).map(function (crumb, index) {
    var last = index === (trail.length - 1);
    // The final crumb is the page you are on, so it is text rather than a link
    // to itself, and carries aria-current for a screen reader.
    var body = (crumb.href && !last)
      ? '<a href="' + esc(crumb.href) + '">' + esc(crumb.label) + '</a>'
      : '<span aria-current="page">' + esc(crumb.label) + '</span>';
    return '<li class="pw-crumb">' + body + '</li>';
  }).join('<li class="pw-crumb-sep" aria-hidden="true">&rsaquo;</li>');
  return '<nav class="pw-breadcrumb" aria-label="Breadcrumb"><ol>' + crumbs + '</ol></nav>';
}
window.pwBreadcrumbHtml = pwBreadcrumbHtml;

/* ---------------------------------------------------------------------------
   Keyboard shortcuts

   Site-wide and deliberately small: `/` to search, j/k to walk a list, Enter
   to open, left/right for pagination, `?` for the list of them.

   Items are resolved from the DOM at keypress time rather than registered by
   each page. Every list on this site is built from a fetch after load -- the
   forum's topic rows, the news feed, the book grid -- so anything that had to
   be registered up front would silently cover none of them, and re-registering
   after each render is a hook every future list would have to remember.
--------------------------------------------------------------------------- */

var KBD_ITEM_SELECTOR = '[data-kbd-item], .topic-row, .board-row, .book-row, .post-card, .figure-scene, .member-card';
var KBD_SEARCH_SELECTOR = '[data-kbd-search], input[type="search"]';

function initKeyboardShortcuts() {
  var activeItem = null;
  var helpPanel = null;

  function isTypingTarget(el) {
    if (!el) return false;
    if (el.isContentEditable) return true;
    var tag = el.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
  }

  function isVisible(el) {
    // offsetParent is null for a display:none element and for anything inside
    // one, which is exactly what has to be skipped: the forum keeps all five
    // of its views in the DOM and only shows one.
    return !!(el.offsetParent || el.getClientRects().length);
  }

  function items() {
    return Array.prototype.filter.call(document.querySelectorAll(KBD_ITEM_SELECTOR), isVisible);
  }

  function highlight(list, index) {
    list.forEach(function (el) { el.classList.remove('is-kbd-active'); });
    var next = list[index];
    if (!next) return;
    next.classList.add('is-kbd-active');
    activeItem = next;
    next.scrollIntoView({ block: 'center' });
  }

  function move(delta) {
    var list = items();
    if (!list.length) return false;
    var current = activeItem ? list.indexOf(activeItem) : -1;
    var next = current === -1
      ? (delta > 0 ? 0 : list.length - 1)
      : Math.max(0, Math.min(list.length - 1, current + delta));
    highlight(list, next);
    return true;
  }

  /* A row on this site is sometimes a link and sometimes a plain div with a
     click handler (the forum's own topic rows are the latter). Prefer a real
     link when there is one, since that keeps modifier-click and the status bar
     working, and fall back to clicking the row itself. */
  function activate() {
    if (!activeItem || !document.contains(activeItem)) return false;
    var link = activeItem.matches('a[href]') ? activeItem : activeItem.querySelector('a[href]');
    if (link) { link.click(); return true; }
    activeItem.click();
    return true;
  }

  function focusSearch() {
    var field = Array.prototype.filter.call(document.querySelectorAll(KBD_SEARCH_SELECTOR), isVisible)[0];
    if (!field) return false;
    field.focus();
    field.select();
    return true;
  }

  function paginate(direction) {
    var selector = direction < 0
      ? '[data-kbd-prev], a[rel="prev"], .history-page-prev, .map-lightbox-nav.is-prev'
      : '[data-kbd-next], a[rel="next"], .history-page-next, .map-lightbox-nav.is-next';
    var control = Array.prototype.filter.call(document.querySelectorAll(selector), isVisible)[0];
    if (!control || control.disabled) return false;
    control.click();
    return true;
  }

  function buildHelp() {
    var panel = document.createElement('div');
    panel.className = 'pw-kbd-help';
    panel.id = 'pw-kbd-help';
    panel.hidden = true;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'false');
    panel.setAttribute('aria-label', 'Keyboard shortcuts');
    panel.innerHTML =
      '<div class="pw-kbd-help-card">' +
        '<button type="button" class="pw-kbd-help-close" aria-label="Close keyboard shortcuts">&times;</button>' +
        '<span class="pw-kbd-help-kicker">Keyboard</span>' +
        '<h2>Shortcuts</h2>' +
        '<dl>' +
          '<dt><kbd>j</kbd> <kbd>k</kbd></dt><dd>Move down / up the list on this page</dd>' +
          '<dt><kbd>Enter</kbd></dt><dd>Open the highlighted entry</dd>' +
          '<dt><kbd>/</kbd></dt><dd>Jump to the search field</dd>' +
          '<dt><kbd>&larr;</kbd> <kbd>&rarr;</kbd></dt><dd>Previous / next page</dd>' +
          '<dt><kbd>?</kbd></dt><dd>Show or hide this list</dd>' +
          '<dt><kbd>Esc</kbd></dt><dd>Close a panel, map or menu</dd>' +
        '</dl>' +
        '<p class="pw-kbd-help-note">Shortcuts stand down while you are typing.</p>' +
      '</div>';
    document.body.appendChild(panel);
    panel.querySelector('.pw-kbd-help-close').addEventListener('click', function () { toggleHelp(false); });
    panel.addEventListener('click', function (event) {
      if (event.target === panel) toggleHelp(false);
    });
    return panel;
  }

  var helpReturnFocus = null;
  function toggleHelp(next) {
    helpPanel = helpPanel || buildHelp();
    var show = next === undefined ? helpPanel.hidden : next;
    if (show) helpReturnFocus = document.activeElement;
    helpPanel.hidden = !show;
    if (show) helpPanel.querySelector('.pw-kbd-help-close').focus();
    else if (helpReturnFocus && document.contains(helpReturnFocus)) helpReturnFocus.focus();
  }

  document.addEventListener('keydown', function (event) {
    if (event.ctrlKey || event.metaKey || event.altKey) return;
    if (event.key === 'Escape' && helpPanel && !helpPanel.hidden) {
      event.preventDefault();
      toggleHelp(false);
      return;
    }
    // Typing wins outright. `/` inside a search box has to stay a slash, and
    // `j` has to stay a letter.
    if (isTypingTarget(event.target)) return;
    // A map, a modal or the auth dialog owns the keyboard while it is open;
    // each already has its own Escape handling and its own controls.
    if (document.querySelector('.map-lightbox:not([hidden]), .auth-modal:not([hidden]), .pw-rankup-modal:not([hidden])')) return;

    var handled = false;
    switch (event.key) {
      case 'j': handled = move(1); break;
      case 'k': handled = move(-1); break;
      case 'Enter': handled = activate(); break;
      case '/': handled = focusSearch(); break;
      case 'ArrowLeft': handled = paginate(-1); break;
      case 'ArrowRight': handled = paginate(1); break;
      case '?': toggleHelp(); handled = true; break;
      default: return;
    }
    if (handled) event.preventDefault();
  });

  // A rebuilt list drops the element that was highlighted, so forget it
  // rather than leaving j/k anchored to a node no longer in the document.
  document.addEventListener('click', function () {
    if (activeItem && !document.contains(activeItem)) activeItem = null;
  }, true);
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
  // Keep a compact, honest control in the header while the world feed resolves.
  // A missing or delayed feed must not make the requested weather route vanish.
  root.hidden = false;
  root.classList.add('is-loading');
  root.innerHTML =
    '<a class="pw-weather-bar" href="#" aria-label="World weather">'
    + '<span class="pw-weather-icon"></span>'
    + '<span class="pw-weather-temp"></span>'
    + '<span class="pw-weather-condition"></span>'
    + '</a>'
    + '<button type="button" class="pw-weather-toggle" aria-expanded="false" aria-haspopup="true" aria-label="Choose a world">'
    + '<span class="pw-weather-caret" aria-hidden="true">&#8964;</span>'
    + '</button>'
    + '<div class="pw-weather-menu" role="menu" hidden></div>'
    + '<div class="pw-weather-hours" hidden></div>';
  // Appended, so the pill sits at the far right of the nav bar, after the
  // profile chip and the notification bell.
  utility.appendChild(root);

  var barEl = root.querySelector('.pw-weather-bar');
  var iconEl = root.querySelector('.pw-weather-icon');
  var tempEl = root.querySelector('.pw-weather-temp');
  var condEl = root.querySelector('.pw-weather-condition');
  var toggleEl = root.querySelector('.pw-weather-toggle');
  var menuEl = root.querySelector('.pw-weather-menu');
  var hoursEl = root.querySelector('.pw-weather-hours');
  // Keyed by slug + the UTC hour it was fetched in, so the roll refreshes when
  // the clock ticks over instead of going stale behind the glance cache.
  var hourCache = {};
  var hoursPending = null;
  var hoursWanted = false;

  function renderUnavailable() {
    iconEl.innerHTML = weatherIconSvg('overcast');
    tempEl.textContent = '--';
    condEl.textContent = 'World weather';
    barEl.href = 'worlds.html';
    barEl.setAttribute('aria-label', 'World weather is loading. Open the Worlds.');
    barEl.title = 'World weather';
    toggleEl.disabled = true;
    root.hidden = false;
    root.classList.add('is-loading');
  }

  renderUnavailable();

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
    if (!world) { renderUnavailable(); return; }

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
    // accent and its translucent glow.
    //
    // Set on .nav-utility rather than on the widget, so the profile chip and
    // the notification bell inherit it too and the whole right-hand group
    // re-tints to the chosen world. Removed rather than set empty when absent:
    // an empty custom property still counts as set and would defeat the CSS
    // fallback that keeps the group its original purple.
    // The class suppresses the pills' colour transitions across the swap. A
    // transitioned colour resolved through a custom property does not reliably
    // restart when that property changes, so without this the chip and bell
    // keep the previous world's tone even though the variable has updated.
    // The forced reflow is what makes the new value take effect while the
    // transition is off; see the .is-accent-swap rule in css/components.css.
    utility.classList.add('is-accent-swap');
    if (world.accent) utility.style.setProperty('--pw-weather-accent', world.accent);
    else utility.style.removeProperty('--pw-weather-accent');
    void utility.offsetWidth;
    utility.classList.remove('is-accent-swap');

    renderMenu(world.slug);
    toggleEl.disabled = false;
    root.hidden = false;
    root.classList.remove('is-loading');
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
    // widget removed: drop the condition text first, then the temperature, but
    // keep the weather route itself available.
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

    root.classList.remove('is-compact', 'is-icon-only');
    if (!doesNotFit()) return;
    root.classList.add('is-compact');
    if (!doesNotFit()) return;
    root.classList.add('is-icon-only');
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

  // --- rolling twelve-hour projection ---------------------------------------
  // Opens on hover over the pill. Fetched on first hover rather than bundled
  // into the glance response, which serves every available world and would
  // otherwise carry twelve unused rows apiece.

  function currentUtcHourKey(slug) {
    return slug + '@' + new Date().getUTCHours();
  }

  function renderHours(slug, hours) {
    if (!hours || !hours.length) { hoursEl.hidden = true; return; }
    var world = resolveWorld();
    var total = hours.length;
    hoursEl.innerHTML =
      '<span class="pw-weather-hours-head">' + weatherEscape(world ? world.name : '') +
        '<i>next ' + total + 'h &middot; UTC</i></span>' +
      '<ul class="pw-weather-hour-list">' +
        hours.map(function (entry, index) {
          // Rows fade with distance: the projection is meant to read as
          // degrading the further out it reaches, not as twelve equally
          // trustworthy readings.
          var strength = Math.max(0.28, 1 - (index / total) * 0.8).toFixed(2);
          return '<li class="pw-weather-hour' + (entry.is_now ? ' is-now' : '') + '" style="opacity:' + strength + '">' +
            '<span class="pw-weather-hour-time">' + weatherEscape(entry.label) + '</span>' +
            '<span class="pw-weather-hour-icon">' + weatherIconSvg(entry.icon) + '</span>' +
            '<span class="pw-weather-hour-temp">' + weatherEscape(entry.temperature_c) + '&deg;</span>' +
          '</li>';
        }).join('') +
      '</ul>' +
      '<span class="pw-weather-hours-note">Confidence degrades beyond 6h</span>';
    hoursEl.hidden = false;
  }

  function showHours() {
    var world = resolveWorld();
    if (!world || open) return;   // the picker owns the space when it is open
    // Tracked explicitly rather than read back off :hover when the response
    // lands: :hover is false for a keyboard user, so testing it there would
    // mean the panel never appeared on focus before the cache was warm.
    hoursWanted = true;
    var key = currentUtcHourKey(world.slug);
    if (hourCache[key]) { renderHours(world.slug, hourCache[key]); return; }
    if (hoursPending === key) return;
    hoursPending = key;
    fetch('/api/world-weather-hours.php?slug=' + encodeURIComponent(world.slug), { credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        hoursPending = null;
        if (!data || !data.ok || !data.available || !Array.isArray(data.hours)) return;
        hourCache[key] = data.hours;
        // Only render if the visitor is still on the pill and has not switched
        // world while the request was in flight.
        var latest = resolveWorld();
        if (hoursWanted && latest && latest.slug === world.slug) renderHours(world.slug, data.hours);
      })
      .catch(function () {
        hoursPending = null;
        // The pill itself stays perfectly usable without the projection.
      });
  }

  function hideHours() {
    hoursWanted = false;
    hoursEl.hidden = true;
  }

  root.addEventListener('mouseenter', showHours);
  root.addEventListener('mouseleave', hideHours);
  barEl.addEventListener('focus', showHours);
  barEl.addEventListener('blur', hideHours);

  // --- picker ---------------------------------------------------------------
  // Mirrors the notification dropdown's contract: aria-expanded, outside-click
  // and Escape closing, with Escape returning focus to the trigger.

  function setOpen(next) {
    open = next;
    menuEl.hidden = !next;
    root.classList.toggle('is-open', next);
    toggleEl.setAttribute('aria-expanded', next ? 'true' : 'false');
    // The picker and the projection occupy the same spot, so opening one
    // dismisses the other rather than stacking them.
    if (next) hideHours();
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
        // Keep the visible Worlds fallback in place when the live feed cannot
        // resolve. It is still a useful, honest way into the weather system.
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
