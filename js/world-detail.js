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
    var normalized = value === null || value === undefined ? '' : value;
    return String(normalized).replace(/[&<>"']/g, function (char) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char];
    });
  }

  function setDocumentTitle(world) {
    document.title = world.name + ' — World Record — The Pantheon Wars';
  }

  // A discovery is awarded exactly once on the server for each member/world
  // pair. Waiting for the auth bootstrap keeps this page fully public while
  // still rewarding signed-in explorers who arrive before session-check ends.
  function recordLoreDiscovery(worldId) {
    function send() {
      if (!window.PW_AUTH || !window.PW_AUTH.loggedIn || !window.PW_AUTH.csrf) return;
      fetch('/api/reputation/lore-discovery.php', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ entity_type: 'world', entity_id: worldId, csrf: window.PW_AUTH.csrf })
      }).catch(function () {});
    }
    if (window.PW_AUTH && window.PW_AUTH.loggedIn) send();
    else document.addEventListener('pw-auth-ready', send, { once: true });
  }

  function setHeroImage(url) {
    if (!hero || !url) return;
    hero.style.setProperty('--world-record-image', 'url("' + String(url).replace(/"/g, '%22') + '")');
  }

  function quoteHtml(text, cite) {
    if (!text) return '';
    return '<blockquote>“' + escapeHtml(text) + '”<cite>— ' + escapeHtml(cite) + '</cite></blockquote>';
  }

  function weatherIconHtml(icon) {
    var paths = {
      'acid-rain': '<path d="M13 35h34a10 10 0 0 0 1-20 16 16 0 0 0-30-3 12 12 0 0 0-5 23z"/><path d="m20 43-4 9m15-9-4 9m15-9-4 9"/><path d="M18 55h22"/>',
      storm: '<path d="M13 34h34a10 10 0 0 0 1-20 16 16 0 0 0-30-3 12 12 0 0 0-5 23z"/><path d="m34 38-8 11h7l-4 10 13-15h-8z"/>',
      smog: '<path d="M13 31h34a10 10 0 0 0 1-20 16 16 0 0 0-30-3 12 12 0 0 0-5 23z"/><path d="M10 40h36M18 47h34M8 54h31"/>',
      clear: '<circle cx="32" cy="32" r="11"/><path d="M32 8v8m0 32v8M8 32h8m32 0h8M15 15l6 6m22 22 6 6m0-34-6 6M21 43l-6 6"/>',
      overcast: '<path d="M13 37h34a10 10 0 0 0 1-20 16 16 0 0 0-30-3 12 12 0 0 0-5 23z"/>'
    };
    return '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (paths[icon] || paths.overcast) + '</svg>';
  }

  // Distinct evocative bureau names per calibrated world; falls back to a
  // generic "<World> Atmospheric Service" label for any future world whose
  // weather profile doesn't have a bespoke entry here yet.
  var WEATHER_SERVICE_LABELS = {
    neoh: 'Neoh Atmospheric Service',
    asmecu: 'Asmecu Tideglass Bureau',
    'high-hammer': 'High Hammer Forge Watch',
    cerius: 'Cerius Cinderwatch',
    reanium: 'Reanium Fallout Watch',
    'babki-prime': 'Babki Prime Canopy Watch',
    sed: 'Sed Scorch Bureau',
    'geof-v': 'Geof V Column Watch',
    beoctica: 'Beoctica Frostwatch',
    'terek-ii': 'Terek II Frontline Watch',
    'valerium-prime': 'Valerium Prime Halo Bureau',
    'vermillia-xi': 'Vermillia XI Dome Watch'
  };

  function weatherCardHtml(weather, worldSlug, worldName) {
    var current = weather.current || {};
    var forecast = weather.forecast || [];
    var serviceLabel = WEATHER_SERVICE_LABELS[worldSlug] || (worldName + ' Atmospheric Service');
    var forecastHtml = forecast.map(function (day) {
      return '<div class="world-weather-day' + (day.day === 'Today' ? ' is-today' : '') + '">' +
        '<span class="world-weather-day-name">' + escapeHtml(day.day_short) + '</span>' +
        '<span class="world-weather-day-icon">' + weatherIconHtml(day.icon) + '</span>' +
        '<strong>' + escapeHtml(day.high_c) + '&deg;</strong>' +
        '<small>' + escapeHtml(day.low_c) + '&deg;</small>' +
        '<span class="world-weather-day-condition">' + escapeHtml(day.condition) + '</span>' +
      '</div>';
    }).join('');
    return '<div class="world-weather-card-scan" aria-hidden="true"></div>' +
      '<header class="world-weather-head"><div><span>' + escapeHtml(serviceLabel) + '</span><h2>' + escapeHtml(weather.location) + '</h2></div><span class="world-weather-live"><i></i>Live archive</span></header>' +
      '<p class="world-weather-climate">' + escapeHtml(weather.climate) + '</p>' +
      '<div class="world-weather-current">' +
        '<span class="world-weather-current-icon">' + weatherIconHtml(current.icon) + '</span>' +
        '<strong>' + escapeHtml(current.temperature_c) + '&deg;</strong>' +
        '<span><b>' + escapeHtml(current.condition) + '</b><small>' + escapeHtml(current.secondary) + '</small></span>' +
      '</div>' +
      '<div class="world-weather-metrics">' +
        '<div><span>Feels like</span><strong>' + escapeHtml(current.feels_like_c) + '&deg;C</strong></div>' +
        '<div><span>Humidity</span><strong>' + escapeHtml(current.humidity) + '%</strong></div>' +
        '<div><span>Precipitation</span><strong>' + escapeHtml(current.precipitation) + '%</strong></div>' +
        '<div><span>Wind</span><strong>' + escapeHtml(current.wind_kph) + ' km/h</strong></div>' +
      '</div>' +
      '<div class="world-weather-divider"><span>5-day atmospheric projection</span></div>' +
      '<div class="world-weather-forecast">' + forecastHtml + '</div>' +
      (weather.hazard_note ? '<p class="world-weather-hazard"><span>!</span>' + escapeHtml(weather.hazard_note) + '</p>' : '') +
      '<footer>Forecast cycle ' + escapeHtml(weather.generated_for) + ' &middot; UTC archive time</footer>';
  }

  function renderWorldWeather(data, worldSlug, worldName) {
    var slot = document.getElementById('world-weather-card');
    if (!slot || !data || !data.ok || !data.available || !data.weather) return;
    slot.className = 'world-weather-card world-weather-card--' + worldSlug;
    slot.innerHTML = weatherCardHtml(data.weather, worldSlug, worldName);
    slot.hidden = false;
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
      '<div class="world-record-overview"><div class="world-record-overview-copy">' + head +
        (world.intro_paragraph_1 ? '<p class="world-record-intro">' + escapeHtml(world.intro_paragraph_1) + '</p>' : '') +
        (world.intro_paragraph_2 ? '<p class="world-record-intro">' + escapeHtml(world.intro_paragraph_2) + '</p>' : '') +
      '</div><aside class="world-weather-card" id="world-weather-card" aria-label="Five-day weather forecast" hidden></aside></div>' +
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

  // Start the small weather request alongside the larger lore record. Its
  // failure is intentionally non-blocking: the World Record remains complete
  // before the migration is run or whenever a profile is switched off.
  var weatherRequest = fetch('/api/world-weather.php?slug=' + encodeURIComponent(slug), { credentials: 'same-origin' })
    .then(function (response) { return response.ok ? response.json() : null; })
    .catch(function () { return null; });

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
      recordLoreDiscovery(world.id);
      if (window.wireWorldInteractions) window.wireWorldInteractions();
      weatherRequest.then(function (weatherData) { renderWorldWeather(weatherData, slug, world.name); });
    })
    .catch(function () { showError('The archive could not load this world record right now.'); });
});
