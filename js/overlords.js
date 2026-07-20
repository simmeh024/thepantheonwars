// The Overlords roster page fetches the admin-managed roster and creates the
// structural layer of the Throne Ring. Interaction and ambient effects are
// deliberately separate follow-up layers: this one remains link-first and
// usable without hover, motion, canvas, or third-party animation libraries.
document.addEventListener('DOMContentLoaded', function () {
  var ringEl = document.getElementById('throne-ring');
  var sealedEl = document.getElementById('throne-ring-sealed');
  var sealedGridEl = document.getElementById('throne-ring-sealed-grid');
  var noteEl = document.getElementById('overlords-note');
  if (!ringEl) return;

  // This visual vocabulary is intentionally keyed by slug rather than stored
  // in the database. New records receive the neutral fallback automatically;
  // established rulers can receive a bespoke scene without changing API data.
  var THRONE_THEMES = {
    'syn-dravus': { accent: '#a279ec', glow: 'rgba(162,121,236,0.54)', motif: 'Neural archive', scene: 'neural' },
    'malric-thorne': { accent: '#e05a4a', glow: 'rgba(224,90,74,0.5)', motif: 'Iron roots', scene: 'iron' },
    'lysara-venthe': { accent: '#4fb3e8', glow: 'rgba(79,179,232,0.5)', motif: 'Veiled echoes', scene: 'veil' },
    'zura-kaleth': { accent: '#e59048', glow: 'rgba(229,144,72,0.52)', motif: 'Ritual fire', scene: 'ember' },
    'prime-eidra': { accent: '#dfe8ff', glow: 'rgba(223,232,255,0.52)', motif: 'Perfect symmetry', scene: 'precision' },
    'drak-varros': { accent: '#8891ad', glow: 'rgba(136,145,173,0.5)', motif: 'Gravity bound', scene: 'gravity' },
    'maerion-thal': { accent: '#f0c479', glow: 'rgba(240,196,121,0.5)', motif: 'Reflected faces', scene: 'water' },
    'marion-thal': { accent: '#f0c479', glow: 'rgba(240,196,121,0.5)', motif: 'Reflected faces', scene: 'water' }
  };
  var FALLBACK_THEME = { accent: '#b894f5', glow: 'rgba(184,148,245,0.48)', motif: 'Unknown signal', scene: 'unknown' };

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

  function renderSeat(overlord, index, total) {
    var theme = themeFor(overlord);
    var world = overlord.world ? 'Overlord of ' + overlord.world.name : 'A throne beyond the Veil';
    return (
      '<a class="throne-ring-seat throne-ring-seat--' + escapeHtml(theme.scene) + '"' +
        ' href="' + profileHref(overlord) + '"' +
        ' style="' + themeStyle(theme) + '--ring-angle:' + ((360 / total) * index) + 'deg;"' +
        ' aria-label="Read the profile of ' + escapeHtml(overlord.name) + ', ' + escapeHtml(world) + '">' +
        '<span class="throne-ring-seat-portrait"><img src="' + escapeHtml(overlord.portrait_image_url) + '" alt="" loading="lazy" decoding="async"></span>' +
        '<span class="throne-ring-seat-name">' + escapeHtml(overlord.name) + '</span>' +
      '</a>'
    );
  }

  function renderFocal(overlord) {
    var theme = themeFor(overlord);
    var world = overlord.world ? 'Overlord of ' + overlord.world.name : 'The signal remains uncharted';
    return (
      '<article class="throne-ring-focal throne-ring-focal--' + escapeHtml(theme.scene) + '" style="' + themeStyle(theme) + '">' +
        '<div class="throne-ring-focal-halo" aria-hidden="true"></div>' +
        '<div class="throne-ring-focal-portrait"><img src="' + escapeHtml(overlord.portrait_image_url) + '" alt="' + escapeHtml(overlord.name) + '"></div>' +
        '<div class="throne-ring-focal-copy">' +
          '<span class="throne-ring-focal-motif">' + escapeHtml(theme.motif) + '</span>' +
          '<h3>' + escapeHtml(overlord.name) + '</h3>' +
          (overlord.epithet ? '<p class="throne-ring-focal-epithet">' + escapeHtml(overlord.epithet) + '</p>' : '') +
          '<p class="throne-ring-focal-world">' + escapeHtml(world) + '</p>' +
          '<a class="throne-ring-focal-link" href="' + profileHref(overlord) + '">Enter this profile <span aria-hidden="true">&rarr;</span></a>' +
        '</div>' +
      '</article>'
    );
  }

  function renderSealedCard(overlord) {
    var theme = themeFor(overlord);
    return (
      '<article class="throne-ring-sealed-card" style="' + themeStyle(theme) + '">' +
        '<span class="throne-ring-sealed-portrait"><img src="' + escapeHtml(overlord.portrait_image_url) + '" alt="' + escapeHtml(overlord.name) + '" loading="lazy" decoding="async"></span>' +
        '<div><h4>' + escapeHtml(overlord.name) + '</h4>' +
        '<span>Lore coming soon</span></div>' +
      '</article>'
    );
  }

  fetch('/api/overlords.php', { credentials: 'same-origin' })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      if (!data.ok || !data.overlords) throw new Error('Overlord roster unavailable');
      var overlords = data.overlords.slice().sort(function (a, b) { return a.sort_order - b.sort_order; });
      var available = overlords.filter(function (overlord) { return overlord.status === 'available'; });
      var sealed = overlords.filter(function (overlord) { return overlord.status !== 'available'; });

      if (!available.length) {
        ringEl.innerHTML = '<p class="overlords-note">No throne is open yet. Return when a signal reaches the Nexus Veil.</p>';
        ringEl.setAttribute('aria-busy', 'false');
        return;
      }

      var selected = available[0];
      ringEl.innerHTML =
        '<div class="throne-ring-stage" data-throne-theme="' + escapeHtml(themeFor(selected).scene) + '">' +
          '<div class="throne-ring-orbit" aria-label="Open Overlord profiles">' +
            available.map(function (overlord, index) { return renderSeat(overlord, index, available.length); }).join('') +
          '</div>' +
          renderFocal(selected) +
        '</div>' +
        '<p class="throne-ring-guidance">The first open throne is in focus. Select any portrait to enter its record.</p>';
      ringEl.setAttribute('aria-busy', 'false');

      if (sealed.length) {
        sealedGridEl.innerHTML = sealed.map(renderSealedCard).join('');
        sealedEl.hidden = false;
        noteEl.textContent = sealed.length + (sealed.length === 1 ? ' more Overlord awaits' : ' more Overlords await') +
          ' beyond the Nexus Veil — their profiles unlock as new books release.';
        noteEl.hidden = false;
      }
    })
    .catch(function () {
      ringEl.setAttribute('aria-busy', 'false');
      ringEl.innerHTML = '<p class="overlords-note">The Throne Ring could not be reached. Please try again shortly.</p>';
    });
});
