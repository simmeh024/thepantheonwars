// The Overlords roster is an API-driven, link-first Throne Ring carousel.
// Each existing or future record receives a neutral scene by default; known
// slugs can opt into a bespoke visual treatment without changing admin data.
document.addEventListener('DOMContentLoaded', function () {
  var ringEl = document.getElementById('throne-ring');
  var sealedEl = document.getElementById('throne-ring-sealed');
  var sealedGridEl = document.getElementById('throne-ring-sealed-grid');
  var noteEl = document.getElementById('overlords-note');
  var available = [];
  var activeIndex = 0;
  var swipeStartX = null;
  if (!ringEl) return;

  var THRONE_THEMES = {
    'syn-dravus': { accent: '#a279ec', glow: 'rgba(162,121,236,0.62)', motif: 'Neural archive', scene: 'neural', throneImage: 'images/overlord-syn-throne.png' },
    'malric-thorne': { accent: '#e05a4a', glow: 'rgba(224,90,74,0.54)', motif: 'Iron roots', scene: 'iron' },
    'lysara-venthe': { accent: '#4fb3e8', glow: 'rgba(79,179,232,0.54)', motif: 'Veiled echoes', scene: 'veil' },
    'zura-kaleth': { accent: '#e59048', glow: 'rgba(229,144,72,0.56)', motif: 'Ritual fire', scene: 'ember' },
    'prime-eidra': { accent: '#dfe8ff', glow: 'rgba(223,232,255,0.54)', motif: 'Perfect symmetry', scene: 'precision' },
    'drak-varros': { accent: '#8891ad', glow: 'rgba(136,145,173,0.54)', motif: 'Gravity bound', scene: 'gravity' },
    'maerion-thal': { accent: '#f0c479', glow: 'rgba(240,196,121,0.54)', motif: 'Reflected faces', scene: 'water' },
    'marion-thal': { accent: '#f0c479', glow: 'rgba(240,196,121,0.54)', motif: 'Reflected faces', scene: 'water' }
  };
  var FALLBACK_THEME = { accent: '#b894f5', glow: 'rgba(184,148,245,0.5)', motif: 'Unknown signal', scene: 'unknown' };

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>'"]/g, function (character) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[character];
    });
  }

  function themeFor(overlord) {
    return THRONE_THEMES[overlord.slug] || FALLBACK_THEME;
  }

  function themeStyle(theme) {
    return '--throne-accent:' + theme.accent + ';--throne-glow:' + theme.glow + ';';
  }

  function profileHref(overlord) {
    return 'overlord.html?slug=' + encodeURIComponent(overlord.slug);
  }

  function wrapIndex(index) {
    return (index + available.length) % available.length;
  }

  function sceneImageFor(overlord) {
    return themeFor(overlord).throneImage || overlord.portrait_image_url || '';
  }

  function renderSeat(overlord, index) {
    var theme = themeFor(overlord);
    var isActive = index === activeIndex;
    return (
      '<button class="throne-ring-seat throne-ring-seat--' + escapeHtml(theme.scene) + (isActive ? ' is-active' : '') + '"' +
        ' type="button" data-throne-index="' + index + '" style="' + themeStyle(theme) + '"' +
        ' aria-label="Select ' + escapeHtml(overlord.name) + '" aria-pressed="' + (isActive ? 'true' : 'false') + '">' +
        '<span class="throne-ring-seat-portrait"><img src="' + escapeHtml(overlord.portrait_image_url) + '" alt="" loading="lazy" decoding="async"></span>' +
        '<span class="throne-ring-seat-name">' + escapeHtml(overlord.name) + '</span>' +
      '</button>'
    );
  }

  function renderShadowThrone(overlord, side) {
    if (!overlord) return '';
    return (
      '<div class="throne-ring-shadow-throne throne-ring-shadow-throne--' + side + '" aria-hidden="true">' +
        '<img src="' + escapeHtml(sceneImageFor(overlord)) + '" alt="" loading="lazy" decoding="async">' +
      '</div>'
    );
  }

  function renderFocal(overlord) {
    var theme = themeFor(overlord);
    var world = overlord.world ? 'Overlord of ' + overlord.world.name : 'The signal remains uncharted';
    var teaser = overlord.card_teaser || 'A throne waits beyond the Nexus Veil.';
    return (
      '<article class="throne-ring-focal throne-ring-focal--' + escapeHtml(theme.scene) + '" style="' + themeStyle(theme) + '" id="throne-ring-focal">' +
        '<div class="throne-ring-focal-art"><img src="' + escapeHtml(sceneImageFor(overlord)) + '" alt="' + escapeHtml(overlord.name) + ' upon the throne" decoding="async"></div>' +
        '<div class="throne-ring-focal-copy">' +
          '<span class="throne-ring-focal-sigil" aria-hidden="true">&#10022;</span>' +
          '<span class="throne-ring-focal-motif">' + escapeHtml(theme.motif) + '</span>' +
          '<h3>' + escapeHtml(overlord.name) + '</h3>' +
          (overlord.epithet ? '<p class="throne-ring-focal-epithet">' + escapeHtml(overlord.epithet) + '</p>' : '') +
          '<p class="throne-ring-focal-world">' + escapeHtml(world) + '</p>' +
          '<p class="throne-ring-focal-teaser">' + escapeHtml(teaser) + '</p>' +
          '<a class="throne-ring-focal-link" href="' + profileHref(overlord) + '">Enter profile <span aria-hidden="true">&rarr;</span></a>' +
        '</div>' +
      '</article>'
    );
  }

  function renderNavButton(direction, neighbor) {
    var isPrevious = direction === 'previous';
    return (
      '<button class="throne-ring-nav throne-ring-nav--' + direction + '" type="button" data-throne-direction="' + direction + '"' +
        ' aria-label="' + (isPrevious ? 'Previous' : 'Next') + ' Overlord: ' + escapeHtml(neighbor.name) + '">' +
        '<span class="throne-ring-nav-diamond" aria-hidden="true">' + (isPrevious ? '&#8249;' : '&#8250;') + '</span>' +
        '<span>' + (isPrevious ? 'Previous' : 'Next') + '</span>' +
      '</button>'
    );
  }

  function renderSealedCard(overlord) {
    var theme = themeFor(overlord);
    return (
      '<article class="throne-ring-sealed-card" style="' + themeStyle(theme) + '">' +
        '<span class="throne-ring-sealed-portrait"><img src="' + escapeHtml(overlord.portrait_image_url) + '" alt="' + escapeHtml(overlord.name) + '" loading="lazy" decoding="async"></span>' +
        '<div><h4>' + escapeHtml(overlord.name) + '</h4><span>Lore coming soon</span></div>' +
      '</article>'
    );
  }

  function renderCarousel(direction) {
    var selected = available[activeIndex];
    var previous = available[wrapIndex(activeIndex - 1)];
    var next = available[wrapIndex(activeIndex + 1)];
    var theme = themeFor(selected);
    ringEl.innerHTML =
      '<div class="throne-ring-stage throne-ring-stage--' + escapeHtml(theme.scene) + (direction ? ' is-entering is-entering--' + direction : '') + '"' +
        ' data-throne-theme="' + escapeHtml(theme.scene) + '" style="' + themeStyle(theme) + '" tabindex="0" aria-label="Throne Ring carousel. Use left and right arrow keys to change Overlord.">' +
        '<div class="throne-ring-constellation" aria-hidden="true"></div>' +
        renderShadowThrone(previous, 'previous') + renderShadowThrone(next, 'next') +
        '<div class="throne-ring-orbit" aria-label="Select an Overlord">' + available.map(renderSeat).join('') + '</div>' +
        renderNavButton('previous', previous) + renderNavButton('next', next) +
        renderFocal(selected) +
        '<p class="throne-ring-live" role="status" aria-live="polite">' + escapeHtml(selected.name) + ' selected. ' + escapeHtml(selected.epithet || '') + '</p>' +
      '</div>' +
      '<p class="throne-ring-guidance">Use the arrows, swipe, or select a throne to move through the Pantheon.</p>';
  }

  function selectIndex(index, direction, focusTarget) {
    activeIndex = wrapIndex(index);
    renderCarousel(direction);
    if (focusTarget) {
      var selector = focusTarget === 'seat' ? '[data-throne-index="' + activeIndex + '"]' : '[data-throne-direction="' + focusTarget + '"]';
      var target = ringEl.querySelector(selector);
      if (target) target.focus();
    }
  }

  function changeBy(offset, focusTarget) {
    selectIndex(activeIndex + offset, offset < 0 ? 'previous' : 'next', focusTarget);
  }

  ringEl.addEventListener('click', function (event) {
    var directionButton = event.target.closest('[data-throne-direction]');
    if (directionButton) {
      changeBy(directionButton.getAttribute('data-throne-direction') === 'previous' ? -1 : 1, directionButton.getAttribute('data-throne-direction'));
      return;
    }
    var seat = event.target.closest('[data-throne-index]');
    if (seat) {
      var targetIndex = parseInt(seat.getAttribute('data-throne-index'), 10);
      if (!isNaN(targetIndex) && targetIndex !== activeIndex) {
        selectIndex(targetIndex, targetIndex > activeIndex ? 'next' : 'previous', 'seat');
      }
    }
  });

  ringEl.addEventListener('keydown', function (event) {
    if (event.key === 'ArrowLeft') {
      event.preventDefault();
      changeBy(-1, 'previous');
    } else if (event.key === 'ArrowRight') {
      event.preventDefault();
      changeBy(1, 'next');
    }
  });

  ringEl.addEventListener('pointerdown', function (event) {
    swipeStartX = event.clientX;
  });

  ringEl.addEventListener('pointerup', function (event) {
    if (swipeStartX === null) return;
    var delta = event.clientX - swipeStartX;
    swipeStartX = null;
    if (Math.abs(delta) >= 42) changeBy(delta > 0 ? -1 : 1, delta > 0 ? 'previous' : 'next');
  });

  ringEl.addEventListener('pointercancel', function () { swipeStartX = null; });

  fetch('/api/overlords.php', { credentials: 'same-origin' })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      if (!data.ok || !data.overlords) throw new Error('Overlord roster unavailable');
      var overlords = data.overlords.slice().sort(function (a, b) { return a.sort_order - b.sort_order; });
      available = overlords.filter(function (overlord) { return overlord.status === 'available'; });
      var sealed = overlords.filter(function (overlord) { return overlord.status !== 'available'; });
      if (!available.length) {
        ringEl.innerHTML = '<p class="overlords-note">No throne is open yet. Return when a signal reaches the Nexus Veil.</p>';
        ringEl.setAttribute('aria-busy', 'false');
        return;
      }
      renderCarousel();
      ringEl.setAttribute('aria-busy', 'false');
      if (sealed.length) {
        sealedGridEl.innerHTML = sealed.map(renderSealedCard).join('');
        sealedEl.hidden = false;
        noteEl.textContent = sealed.length + (sealed.length === 1 ? ' more Overlord awaits' : ' more Overlords await') + ' beyond the Nexus Veil — their profiles unlock as new books release.';
        noteEl.hidden = false;
      }
    })
    .catch(function () {
      ringEl.setAttribute('aria-busy', 'false');
      ringEl.innerHTML = '<p class="overlords-note">The Throne Ring could not be reached. Please try again shortly.</p>';
    });
});
