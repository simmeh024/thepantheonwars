// Lore Timeline — timeline.html.
//
// Renders api/timeline.php into a horizontal drag/scroll rail (vertical on
// mobile, via CSS alone — the DOM order is identical). Two things are worth
// knowing before changing this file:
//
// 1. A locked event arrives with NO title, summary, body, image or slug. The
//    server withholds them; this file never had them to hide. Do not "improve"
//    the sealed state by rendering placeholder text from a field that is not
//    in the payload — there is nothing there to read.
//
// 2. Discovering an unlocked event awards reputation once, through the same
//    first-visit endpoint used by Worlds and Overlords. It fires on first
//    open of a given event, not on page load, so simply scrolling past an
//    event does not silently bank the reward.
//
// All motion is skipped under prefers-reduced-motion, and the idle marker
// loops pause via IntersectionObserver when the rail leaves the viewport,
// matching every other animated surface on this site.
document.addEventListener('DOMContentLoaded', function () {
  var section = document.getElementById('timeline-section');
  var rail = document.getElementById('timeline-rail');
  if (!section || !rail) return;

  var loadingEl = document.getElementById('timeline-loading');
  var hintEl = document.getElementById('timeline-hint');
  var railWrap = document.getElementById('timeline-rail-wrap');
  var progressEl = document.getElementById('timeline-progress');
  var progressFill = document.getElementById('timeline-progress-fill');
  var progressLabel = document.getElementById('timeline-progress-label');

  var panel = document.getElementById('timeline-panel');
  var panelMedia = document.getElementById('timeline-panel-media');
  var panelImage = document.getElementById('timeline-panel-image');
  var panelEra = document.getElementById('timeline-panel-era');
  var panelTitle = document.getElementById('timeline-panel-title');
  var panelDate = document.getElementById('timeline-panel-date');
  var panelText = document.getElementById('timeline-panel-text');

  var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var isCoarse = window.matchMedia('(hover: none), (pointer: coarse)').matches;
  var discovered = {};
  var activeNode = null;

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char];
    });
  }

  // Accepts "#a279ec" / "#a7e" and returns "r, g, b" for the --node-accent
  // custom property. Falls back to the site purple on anything unparseable so
  // a bad admin value can never leave a marker unstyled.
  function hexToRgbParts(hex) {
    var value = String(hex || '').trim().replace(/^#/, '');
    if (value.length === 3) {
      value = value[0] + value[0] + value[1] + value[1] + value[2] + value[2];
    }
    if (!/^[0-9a-f]{6}$/i.test(value)) return '162, 121, 236';
    return parseInt(value.slice(0, 2), 16) + ', ' + parseInt(value.slice(2, 4), 16) + ', ' + parseInt(value.slice(4, 6), 16);
  }

  function resolveImageUrl(url) {
    var raw = String(url || '').trim();
    if (!raw) return '';
    if (/^https?:\/\//i.test(raw) || raw.charAt(0) === '/') return raw;
    return '/' + raw.replace(/^\.?\//, '');
  }

  // ---------------------------------------------------------------- panel

  function positionPanel(node) {
    // Static in the mobile/vertical layout — CSS places it in flow there, and
    // measuring would fight that.
    if (window.matchMedia('(max-width: 780px)').matches) return;
    var rect = node.getBoundingClientRect();
    var panelRect = panel.getBoundingClientRect();
    var left = rect.left + (rect.width / 2) - (panelRect.width / 2);
    left = Math.max(12, Math.min(left, window.innerWidth - panelRect.width - 12));
    var top = rect.bottom + 16;
    // Flip above the marker when there is not enough room below.
    if (top + panelRect.height > window.innerHeight - 12) {
      top = Math.max(12, rect.top - panelRect.height - 16);
    }
    panel.style.left = left + 'px';
    panel.style.top = top + 'px';
  }

  function closePanel() {
    panel.classList.remove('is-open');
    panel.hidden = true;
    if (activeNode) activeNode.setAttribute('aria-expanded', 'false');
    activeNode = null;
  }

  function openPanel(node, event) {
    activeNode = node;
    node.setAttribute('aria-expanded', 'true');
    panel.style.borderColor = 'rgba(' + (event.locked ? '150, 150, 165' : hexToRgbParts(event.accent_color)) + ', 0.5)';

    if (event.locked) {
      panelMedia.hidden = true;
      panelEra.textContent = 'Sealed record';
      panelTitle.textContent = 'ERROR: LORE LOCK';
      panelDate.textContent = event.required_level_name
        ? 'Requires ' + event.required_level_name
        : 'Requires a higher standing';
      panelText.textContent = event.required_level_threshold
        ? 'The Overcode withholds this entry. Reach ' + event.required_level_threshold
          + ' reputation to restore it to the record.'
        : 'The Overcode withholds this entry until your standing in the Nexus is sufficient.';
    } else {
      var img = resolveImageUrl(event.image_url);
      if (img) {
        panelImage.src = img;
        panelImage.alt = event.title || '';
        panelMedia.hidden = false;
      } else {
        panelMedia.hidden = true;
      }
      panelEra.textContent = event.era_label || '';
      panelEra.hidden = !event.era_label;
      panelTitle.textContent = event.title || '';
      panelDate.textContent = event.date_label || '';
      panelDate.hidden = !event.date_label;
      panelText.textContent = event.summary || event.body || '';
      recordDiscovery(event);
    }

    panel.hidden = false;
    // Measure after unhiding, before the opening transition.
    positionPanel(node);
    requestAnimationFrame(function () { panel.classList.add('is-open'); });
  }

  // ------------------------------------------------------------ discovery

  function recordDiscovery(event) {
    if (event.locked || !event.id || discovered[event.id]) return;
    // Only signed-in members can earn reputation, and only once per event.
    if (!window.PW_AUTH || !window.PW_AUTH.loggedIn || !window.PW_AUTH.csrf) return;
    discovered[event.id] = true;
    fetch('/api/reputation/lore-discovery.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ entity_type: 'timeline_event', entity_id: event.id, csrf: window.PW_AUTH.csrf })
    }).catch(function () {
      // A failed award must never interrupt reading. Allow a later retry.
      discovered[event.id] = false;
    });
  }

  // --------------------------------------------------------------- render

  function buildNode(event, index) {
    var node = document.createElement('button');
    node.type = 'button';
    node.className = 'timeline-node' + (event.locked ? ' is-locked' : '');
    node.setAttribute('role', 'listitem');
    node.setAttribute('aria-expanded', 'false');
    node.style.setProperty('--node-accent', event.locked ? '150, 150, 165' : hexToRgbParts(event.accent_color));

    if (event.locked) {
      node.setAttribute('aria-label', 'Sealed record. '
        + (event.required_level_name ? 'Requires ' + event.required_level_name + '.' : 'Requires a higher standing.'));
      node.innerHTML =
        '<span class="timeline-node-dot" aria-hidden="true"></span>' +
        '<span class="timeline-node-inner">' +
          '<span class="timeline-node-title">Sealed</span>' +
          (event.required_level_name
            ? '<span class="timeline-node-req">' + escapeHtml(event.required_level_name) + '</span>'
            : '') +
        '</span>';
    } else {
      node.innerHTML =
        '<span class="timeline-node-dot" aria-hidden="true"></span>' +
        '<span class="timeline-node-inner">' +
          (event.date_label ? '<span class="timeline-node-date">' + escapeHtml(event.date_label) + '</span>' : '') +
          '<span class="timeline-node-title">' + escapeHtml(event.title) + '</span>' +
          (event.era_label ? '<span class="timeline-node-era">' + escapeHtml(event.era_label) + '</span>' : '') +
        '</span>';
    }

    function open() { openPanel(node, event); }

    // Pointer devices reveal on hover; touch devices need an explicit tap,
    // and a second tap closes it again.
    if (!isCoarse) {
      node.addEventListener('mouseenter', open);
      node.addEventListener('mouseleave', function () {
        if (activeNode === node) closePanel();
      });
    }
    node.addEventListener('focus', open);
    node.addEventListener('blur', function () {
      if (activeNode === node) closePanel();
    });
    node.addEventListener('click', function (e) {
      e.preventDefault();
      if (activeNode === node && isCoarse) { closePanel(); return; }
      open();
    });

    return node;
  }

  function render(data) {
    var events = data.events || [];
    rail.innerHTML = '';
    if (!events.length) {
      rail.innerHTML = '<p class="timeline-loading">The record is still being compiled.</p>';
      return;
    }

    events.forEach(function (event, i) {
      rail.appendChild(buildNode(event, i));
    });

    if (hintEl) hintEl.hidden = false;

    // Progress reflects how much of the record this visitor can actually read.
    if (progressEl && data.total_count) {
      progressEl.hidden = false;
      var pct = Math.round((data.unlocked_count / data.total_count) * 100);
      progressLabel.textContent = data.unlocked_count + ' of ' + data.total_count + ' records recovered';
      if (reducedMotion) {
        progressFill.style.width = pct + '%';
      } else {
        requestAnimationFrame(function () { progressFill.style.width = pct + '%'; });
      }
    }

    startMotion();
  }

  // --------------------------------------------------------------- motion

  function startMotion() {
    if (reducedMotion) return;

    var nodes = Array.prototype.slice.call(rail.querySelectorAll('.timeline-node'));

    if ('IntersectionObserver' in window) {
      // Idle loops only run while the rail is actually on screen, matching
      // every other animated surface on the site.
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            nodes.forEach(function (n) { n.classList.add('is-idle'); });
            if (railWrap && !railWrap.dataset.swept) {
              railWrap.dataset.swept = '1';
              railWrap.classList.add('is-sweeping');
            }
          } else {
            nodes.forEach(function (n) { n.classList.remove('is-idle'); });
          }
        });
      }, { threshold: 0.15 });
      observer.observe(section);
    } else {
      nodes.forEach(function (n) { n.classList.add('is-idle'); });
    }

    // Staggered entrance. GSAP is already vendored and loaded by this page;
    // without it the markers simply appear, which is a fine fallback.
    if (typeof window.gsap !== 'undefined') {
      gsap.from(nodes, { opacity: 0, y: 16, duration: 0.5, stagger: 0.045, ease: 'power2.out' });
    }
  }

  // ----------------------------------------------------------- drag to pan

  // Horizontal rail only. Pointer events cover mouse and pen; touch already
  // scrolls natively, and the mobile layout is a vertical column anyway.
  var isDown = false, startX = 0, startScroll = 0, moved = false;
  rail.addEventListener('pointerdown', function (e) {
    if (e.pointerType === 'touch' || window.matchMedia('(max-width: 780px)').matches) return;
    isDown = true; moved = false;
    startX = e.clientX;
    startScroll = rail.scrollLeft;
    rail.classList.add('is-dragging');
  });
  rail.addEventListener('pointermove', function (e) {
    if (!isDown) return;
    var dx = e.clientX - startX;
    if (Math.abs(dx) > 3) moved = true;
    rail.scrollLeft = startScroll - dx;
  });
  function endDrag() {
    if (!isDown) return;
    isDown = false;
    rail.classList.remove('is-dragging');
  }
  rail.addEventListener('pointerup', endDrag);
  rail.addEventListener('pointercancel', endDrag);
  rail.addEventListener('pointerleave', endDrag);
  // Suppress the click that follows a real drag, so panning does not also
  // fire a marker open.
  rail.addEventListener('click', function (e) {
    if (moved) { e.stopPropagation(); e.preventDefault(); moved = false; }
  }, true);

  // Keep an open panel glued to its marker while the rail or page moves.
  function reposition() { if (activeNode) positionPanel(activeNode); }
  rail.addEventListener('scroll', reposition, { passive: true });
  window.addEventListener('resize', reposition);
  window.addEventListener('scroll', reposition, { passive: true });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && activeNode) { activeNode.blur(); closePanel(); }
  });

  // ----------------------------------------------------------------- load

  // Deliberately not tied to the session check: an unauthenticated visitor
  // sees the same page, just with more sealed records. The endpoint resolves
  // reputation itself from whatever session cookie was sent.
  fetch('/api/timeline.php', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.ok) throw new Error('failed');
      render(data);
    })
    .catch(function () {
      if (loadingEl) loadingEl.textContent = 'The record could not be recovered right now.';
    });
});
