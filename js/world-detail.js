// Dynamic World Record page. The URL is intentionally stable per world
// (`world.html?slug=neoh`) while its content remains entirely controlled by
// World Control and the existing public worlds endpoint.
document.addEventListener('DOMContentLoaded', function () {
  var hero = document.getElementById('world-record-hero');
  var eyebrow = document.getElementById('world-record-eyebrow');
  var title = document.getElementById('world-record-title');
  var lede = document.getElementById('world-record-lede');
  var status = document.getElementById('world-record-status');
  var content = document.getElementById('world-record-content');
  var slug = new URLSearchParams(window.location.search).get('slug');

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char];
    });
  }

  function setDocumentTitle(world) {
    document.title = world.name + ' — World Record — The Pantheon Wars';
  }

  function setHeroImage(url) {
    if (!hero || !url) return;
    hero.style.setProperty('--world-record-image', 'url("' + String(url).replace(/"/g, '%22') + '")');
  }

  function quoteHtml(text, cite) {
    if (!text) return '';
    return '<blockquote>“' + escapeHtml(text) + '”<cite>— ' + escapeHtml(cite) + '</cite></blockquote>';
  }

  function landmarkHtml(landmark) {
    return '<div class="sub-landmark restricted">' +
      '<span class="sub-landmark-name">' + escapeHtml(landmark.name) + '</span>' +
      '<span class="sub-landmark-tag">' + escapeHtml(landmark.tag_label) + '</span>' +
      '<p>' + escapeHtml(landmark.description) + '</p>' +
      quoteHtml(landmark.quote_text, landmark.quote_cite) +
    '</div>';
  }

  function layerHtml(layer, index, isVertical) {
    var sublocations = (layer.sublocations || []).map(function (label) {
      return '<span class="sublocation-tag">' + escapeHtml(label) + '</span>';
    }).join('');
    var landmarks = (layer.landmarks || []).map(landmarkHtml).join('');
    var indexLabel = String(index + 1).padStart(2, '0');
    return '<div class="' + (isVertical ? 'city-layer' : 'city-layer harbor-district') + ' world-layer-tint--' + escapeHtml(layer.tint_key || 'gold') + '">' +
      '<button type="button" class="layer-toggle" aria-expanded="false">' +
        '<span class="layer-idx">' + indexLabel + '</span>' +
        '<span class="layer-name">' + escapeHtml(layer.name) + '</span>' +
        '<span class="layer-tag">' + escapeHtml(layer.theme_tags) + '</span>' +
        '<span class="layer-plus" aria-hidden="true"></span>' +
      '</button>' +
      '<div class="layer-panel"><div class="layer-panel-inner"><div class="inner-pad">' +
        '<span class="layer-tagline">' + escapeHtml(layer.tagline) + '</span>' +
        '<p>' + escapeHtml(layer.description) + '</p>' +
        quoteHtml(layer.quote_text, layer.quote_cite) +
        (sublocations ? '<div class="layer-sublocations">' + sublocations + '</div>' : '') +
        landmarks +
      '</div></div></div>' +
    '</div>';
  }

  function mapHtml(world, isVertical) {
    return '<div class="city-map-figure' + (isVertical ? '' : ' harbor-map-figure') + '">' +
      '<button type="button" class="map-thumb-btn" data-lightbox="world-record-map-lightbox" aria-label="View full map of ' + escapeHtml(world.name) + '">' +
        '<img src="' + escapeHtml(world.map_thumb_image_url) + '" alt="Map of ' + escapeHtml(world.name) + '" decoding="async">' +
        '<span class="map-expand-hint">🔎 View Full Map</span>' +
      '</button>' +
      '<p class="map-caption">' + escapeHtml(world.map_caption) + '</p>' +
    '</div>';
  }

  function detailHtml(world) {
    var isVertical = world.layout_orientation === 'vertical';
    var layers = (world.layers || []).map(function (layer, index) {
      return layerHtml(layer, index, isVertical);
    }).join('');
    var distant = (world.distant_landmarks || []).map(landmarkHtml).join('');
    var overlord = world.overlord ?
      '<span class="overlord-tag"><a href="overlord.html?slug=' + encodeURIComponent(world.overlord.slug || '') + '">Overlord: ' + escapeHtml(world.overlord.name) + (world.overlord.epithet ? ' — ' + escapeHtml(world.overlord.epithet) : '') + '</a></span>' : '';
    var head = '<div class="world-detail-head">' +
      (world.portrait_image_url ? '<span class="world-detail-portrait"><img src="' + escapeHtml(world.portrait_image_url) + '" alt="' + escapeHtml(world.overlord ? world.overlord.name : world.name) + '" decoding="async"></span>' : '') +
      '<h2>' + escapeHtml(world.name) + '</h2>' + overlord +
    '</div>';
    var crossSection;
    if (isVertical) {
      crossSection = '<div class="city-cross-wrap" style="margin-top: 3rem;">' + mapHtml(world, true) +
        '<div class="city-cross-section"><div class="city-ruler" aria-hidden="true"></div><div class="city-stack">' +
          (world.altitude_top_label ? '<div class="city-altitude">▲ ' + escapeHtml(world.altitude_top_label) + '</div>' : '') +
          layers +
          (world.altitude_bottom_label ? '<div class="city-altitude">▼ ' + escapeHtml(world.altitude_bottom_label) + '</div>' : '') +
        '</div></div></div>' + distant + '</div>';
    } else {
      crossSection = '<div class="harbor-wrap" style="margin-top: 3rem;">' + mapHtml(world, false) +
        '<div class="harbor-cross-section"><div class="harbor-row">' + layers + '</div></div>' + distant + '</div>';
    }
    return '<a class="world-record-backlink" href="worlds.html">← Return to the Twelve Worlds atlas</a>' +
      head +
      (world.intro_paragraph_1 ? '<p class="world-record-intro">' + escapeHtml(world.intro_paragraph_1) + '</p>' : '') +
      (world.intro_paragraph_2 ? '<p class="world-record-intro">' + escapeHtml(world.intro_paragraph_2) + '</p>' : '') +
      crossSection +
      '<div class="map-lightbox" id="world-record-map-lightbox" hidden><div class="map-lightbox-backdrop"></div><button type="button" class="map-lightbox-close" aria-label="Close map">&times;</button><div class="map-lightbox-inner"><img src="' + escapeHtml(world.map_full_image_url) + '" alt="Full map of ' + escapeHtml(world.name) + '" decoding="async"></div></div>';
  }

  function showError(message) {
    if (title) title.textContent = 'World Record Unavailable';
    if (lede) lede.textContent = message;
    if (content) content.innerHTML = '<a class="world-record-backlink" href="worlds.html">← Return to the Twelve Worlds atlas</a><div class="world-record-placeholder">The requested record could not be retrieved. Return to the atlas and choose another available world.</div>';
  }

  if (!slug) {
    showError('No world record was specified.');
    return;
  }

  fetch('/api/worlds.php?slug=' + encodeURIComponent(slug), { credentials: 'same-origin' })
    .then(function (response) { return response.ok ? response.json() : Promise.reject(); })
    .then(function (data) {
      if (!data.ok || !data.world) throw new Error('World unavailable');
      var world = data.world;
      setDocumentTitle(world);
      setHeroImage(world.thumb_image_url || world.map_full_image_url);
      if (eyebrow) eyebrow.textContent = world.overlord ? 'World of ' + world.overlord.name : 'Pantheon World Record';
      if (title) title.textContent = world.name;
      if (lede) lede.textContent = world.tagline || world.card_blurb || 'A record from the Pantheon archive.';
      if (status) {
        status.textContent = world.status === 'available' ? 'Archive clearance: available' : 'ERROR: LORE LOCK';
        status.classList.toggle('is-locked', world.status !== 'available');
      }
      if (world.status !== 'available') {
        if (content) content.innerHTML = '<a class="world-record-backlink" href="worlds.html">← Return to the Twelve Worlds atlas</a><div class="world-record-placeholder"><strong>MISSING INFORMATION.</strong><br>' + escapeHtml(world.name) + ' remains sealed in the lore archive. This field record will open when World Control clears the lock.</div>';
        return;
      }
      if (content) content.innerHTML = detailHtml(world);
      if (window.wireWorldInteractions) window.wireWorldInteractions();
    })
    .catch(function () { showError('The archive could not load this world record right now.'); });
});
