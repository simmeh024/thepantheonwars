// The Worlds page — fetches live world data from the admin-managed World
// Control backend (api/worlds.php) and renders both the card grid and, for
// each "available" world, its cross-section detail section (layers,
// sublocations, and landmarks). Markup shapes mirror what used to be
// hand-authored directly in worlds.html so no CSS changes were needed.
document.addEventListener('DOMContentLoaded', function () {
  var gridEl = document.getElementById('world-grid');
  var detailEl = document.getElementById('world-detail-sections');
  if (!gridEl || !detailEl) return;
  var atlasEl = document.getElementById('world-atlas');
  var atlasStageEl = document.getElementById('world-atlas-stage');
  var atlasPanEl = document.getElementById('world-atlas-pan');
  var atlasHotspotsEl = document.getElementById('world-atlas-hotspots');
  var atlasInfoTitleEl = document.getElementById('world-atlas-info-title');
  var atlasInfoCopyEl = document.getElementById('world-atlas-info-copy');
  var atlasInfoLinkEl = document.getElementById('world-atlas-info-link');

  // Coordinates use the atlas artwork's native 1672 × 941 viewBox and the
  // World Control sort order, so content staff only need to manage a world's
  // normal status. Unlocking a world automatically activates its medallion.
  var ATLAS_POINTS = {
    1: { x: 773, y: 130, r: 77 },
    2: { x: 1044, y: 156, r: 77 },
    3: { x: 1272, y: 270, r: 77 },
    4: { x: 1351, y: 451, r: 77 },
    5: { x: 1283, y: 632, r: 77 },
    6: { x: 1094, y: 735, r: 77 },
    7: { x: 796, y: 783, r: 77 },
    8: { x: 531, y: 712, r: 77 },
    9: { x: 380, y: 635, r: 77 },
    10: { x: 280, y: 493, r: 77 },
    11: { x: 368, y: 345, r: 77 },
    12: { x: 530, y: 232, r: 77 }
  };

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char];
    });
  }

  function worldRecordUrl(world) {
    return 'world.html?slug=' + encodeURIComponent(world.slug);
  }

  function pad2(n) {
    return n < 10 ? '0' + n : String(n);
  }

  function overlordTagText(world) {
    if (!world.overlord) return world.name;
    return world.name + ' — Overlord: ' + world.overlord.name;
  }

  function overlordDetailTagHtml(world) {
    if (!world.overlord) return '';
    var label = 'Overlord: ' + world.overlord.name + (world.overlord.epithet ? ' — ' + world.overlord.epithet : '');
    if (world.overlord.slug) {
      return '<span class="overlord-tag"><a href="overlord.html?slug=' + world.overlord.slug + '">' + label + '</a></span>';
    }
    return '<span class="overlord-tag">' + label + '</span>';
  }

  function renderCard(world) {
    var cardId = world.slug + '-card';
    if (world.status === 'available') {
      return (
        '<div class="world-card card available" id="' + cardId + '">' +
          '<a class="thumb" href="' + worldRecordUrl(world) + '">' +
            '<img src="' + (world.thumb_image_url || '') + '" alt="' + world.name + '" loading="lazy" decoding="async"></a>' +
          '<span class="world-tag">' + overlordTagText(world) + '</span>' +
          '<h3>' + world.tagline + '</h3>' +
          '<p>' + world.card_blurb + '</p>' +
          '<a href="' + worldRecordUrl(world) + '" class="learn-more">Open World Record &rarr;</a>' +
        '</div>'
      );
    }
    return (
      '<div class="world-card card locked" id="' + cardId + '">' +
        '<div class="thumb"><img src="' + (world.thumb_image_url || '') + '" alt="' + world.name + '" loading="lazy" decoding="async"></div>' +
        '<span class="world-tag">' + overlordTagText(world) + '</span>' +
        '<h3>' + world.tagline + '</h3>' +
        '<p>' + world.card_blurb + '</p>' +
        '<span class="lore-status">' + (world.lore_status_label || 'Lore Coming Soon') + '</span>' +
      '</div>'
    );
  }

  function renderQuote(quoteText, quoteCite) {
    if (!quoteText) return '';
    return '<blockquote>“' + quoteText + '”<cite>— ' + quoteCite + '</cite></blockquote>';
  }

  function renderLandmark(lm) {
    return (
      '<div class="sub-landmark ' + (lm.kind_class || 'restricted') + '">' +
        '<span class="sub-landmark-name">' + lm.name + '</span>' +
        '<span class="sub-landmark-tag">' + (lm.tag_label || '') + '</span>' +
        '<p>' + lm.description + '</p>' +
        renderQuote(lm.quote_text, lm.quote_cite) +
      '</div>'
    );
  }

  function renderLayer(layer, index, isVertical) {
    var idx = pad2(index + 1);
    var subsHtml = (layer.sublocations || []).map(function (s) {
      return '<span class="sublocation-tag">' + s + '</span>';
    }).join('');
    var landmarksHtml = (layer.landmarks || []).map(function (lm) {
      lm.kind_class = 'restricted';
      return renderLandmark(lm);
    }).join('');
    var layerClass = (isVertical ? 'city-layer' : 'city-layer harbor-district') +
      ' world-layer-tint--' + (layer.tint_key || 'gold');
    return (
      '<div class="' + layerClass + '">' +
        '<button class="layer-toggle">' +
          '<span class="layer-idx">' + idx + '</span>' +
          '<span class="layer-name">' + layer.name + '</span>' +
          '<span class="layer-tag">' + (layer.theme_tags || '') + '</span>' +
          '<span class="layer-plus"></span>' +
        '</button>' +
        '<div class="layer-panel"><div class="layer-panel-inner"><div class="inner-pad">' +
          '<span class="layer-tagline">' + (layer.tagline || '') + '</span>' +
          '<p>' + layer.description + '</p>' +
          renderQuote(layer.quote_text, layer.quote_cite) +
          (subsHtml ? '<div class="layer-sublocations">' + subsHtml + '</div>' : '') +
          landmarksHtml +
        '</div></div></div>' +
      '</div>'
    );
  }

  function renderDetailSection(world) {
    var isVertical = world.layout_orientation === 'vertical';
    var layersHtml = (world.layers || []).map(function (layer, i) {
      return renderLayer(layer, i, isVertical);
    }).join('');
    var distantHtml = (world.distant_landmarks || []).map(function (lm) {
      lm.kind_class = 'distant';
      return renderLandmark(lm);
    }).join('');

    var mapFigureHtml =
      '<div class="city-map-figure' + (isVertical ? '' : ' harbor-map-figure') + '">' +
        '<button type="button" class="map-thumb-btn" data-lightbox="' + world.slug + '-map-lightbox" aria-label="View full map of ' + world.name + '">' +
          '<img src="' + (world.map_thumb_image_url || '') + '" alt="Map of ' + world.name + '" loading="lazy" decoding="async">' +
          '<span class="map-expand-hint">&#128269; View Full Map</span>' +
        '</button>' +
        '<p class="map-caption">' + (world.map_caption || '') + '</p>' +
      '</div>';

    var crossSectionHtml;
    if (isVertical) {
      crossSectionHtml =
        '<div class="city-cross-wrap" style="margin-top: 3rem;">' +
          mapFigureHtml +
          '<div class="city-cross-section">' +
          '<div class="city-ruler" aria-hidden="true"></div>' +
          '<div class="city-stack">' +
            (world.altitude_top_label ? '<div class="city-altitude">&#9650; ' + world.altitude_top_label + '</div>' : '') +
            layersHtml +
            (world.altitude_bottom_label ? '<div class="city-altitude">&#9660; ' + world.altitude_bottom_label + '</div>' : '') +
          '</div>' +
          '</div>' +
          distantHtml +
        '</div>';
    } else {
      crossSectionHtml =
        '<div class="harbor-wrap" style="margin-top: 3rem;">' +
          mapFigureHtml +
          '<div class="harbor-cross-section">' +
          '<div class="harbor-row">' +
            layersHtml +
          '</div>' +
          '</div>' +
          distantHtml +
        '</div>';
    }

    return (
      '<section id="' + world.slug + '">' +
        '<div class="container">' +
          '<div class="world-detail-head">' +
            (world.portrait_image_url ? '<span class="world-detail-portrait"><img src="' + world.portrait_image_url + '" alt="' + (world.overlord ? world.overlord.name : world.name) + '" loading="lazy" decoding="async"></span>' : '') +
            '<h2>' + world.name + '</h2>' +
            overlordDetailTagHtml(world) +
          '</div>' +
          (world.intro_paragraph_1 ? '<p style="max-width: 720px;">' + world.intro_paragraph_1 + '</p>' : '') +
          (world.intro_paragraph_2 ? '<p style="max-width: 720px;">' + world.intro_paragraph_2 + '</p>' : '') +
          crossSectionHtml +
        '</div>' +
        '<div class="map-lightbox" id="' + world.slug + '-map-lightbox" hidden>' +
          '<div class="map-lightbox-backdrop"></div>' +
          '<button type="button" class="map-lightbox-close" aria-label="Close map">&times;</button>' +
          '<div class="map-lightbox-inner">' +
            '<img src="' + (world.map_full_image_url || '') + '" alt="Full map of ' + world.name + '" loading="lazy" decoding="async">' +
          '</div>' +
        '</div>' +
      '</section>'
    );
  }

  function setAtlasInfo(world) {
    if (!atlasInfoTitleEl || !atlasInfoCopyEl || !atlasInfoLinkEl) return;
    if (!world) {
      atlasInfoTitleEl.textContent = 'Select a world';
      atlasInfoCopyEl.textContent = 'Hover or focus a medallion to inspect its record. Available worlds can be opened in their own field file.';
      atlasInfoLinkEl.hidden = true;
      return;
    }
    if (world.status === 'available') {
      atlasInfoTitleEl.textContent = world.name;
      atlasInfoCopyEl.textContent = world.card_blurb || world.tagline || 'This world record is available to explore.';
      atlasInfoLinkEl.href = worldRecordUrl(world);
      atlasInfoLinkEl.hidden = false;
      return;
    }
    atlasInfoTitleEl.textContent = 'ERROR: LORE LOCK';
    atlasInfoCopyEl.textContent = 'MISSING INFORMATION — ' + world.name + ' remains sealed in the lore archive. Its world record will unlock when the archive is cleared.';
    atlasInfoLinkEl.hidden = true;
  }

  function renderAtlas(worlds) {
    if (!atlasEl || !atlasHotspotsEl) return;
    var mappedWorlds = worlds.filter(function (world) { return !!ATLAS_POINTS[world.sort_order]; });
    var locked = mappedWorlds.filter(function (world) { return world.status !== 'available'; });
    var defs =
      '<defs>' +
        '<filter id="world-atlas-lock-filter"><feColorMatrix type="saturate" values="0"></feColorMatrix><feComponentTransfer><feFuncR type="linear" slope="0.33"></feFuncR><feFuncG type="linear" slope="0.33"></feFuncG><feFuncB type="linear" slope="0.38"></feFuncB></feComponentTransfer></filter>' +
        locked.map(function (world) {
          var point = ATLAS_POINTS[world.sort_order];
          return '<clipPath id="world-atlas-clip-' + escapeHtml(world.slug) + '"><circle cx="' + point.x + '" cy="' + point.y + '" r="' + (point.r + 3) + '"></circle></clipPath>';
        }).join('') +
      '</defs>';
    var lockedArtwork = locked.map(function (world) {
      return '<image href="images/twelve-worlds-atlas.png?v=2" x="0" y="0" width="1672" height="941" preserveAspectRatio="none" clip-path="url(#world-atlas-clip-' + escapeHtml(world.slug) + ')" filter="url(#world-atlas-lock-filter)"></image>';
    }).join('');
    var hotspots = mappedWorlds.map(function (world) {
      var point = ATLAS_POINTS[world.sort_order];
      var label = world.status === 'available' ? 'Open world record for ' + world.name : 'Lore locked: ' + world.name;
      var circles =
        '<circle class="world-atlas-hit" cx="' + point.x + '" cy="' + point.y + '" r="' + point.r + '"></circle>' +
        '<circle class="world-atlas-ring" cx="' + point.x + '" cy="' + point.y + '" r="' + (point.r + 4) + '"></circle>' +
        (world.status === 'available' ? '<circle class="world-atlas-signal" cx="' + point.x + '" cy="' + point.y + '" r="' + (point.r + 10) + '"></circle>' : '<circle class="world-atlas-lock-shade" cx="' + point.x + '" cy="' + point.y + '" r="' + point.r + '"></circle>');
      if (world.status === 'available') {
        return '<a class="world-atlas-hotspot is-available" href="' + worldRecordUrl(world) + '" data-world-slug="' + escapeHtml(world.slug) + '" aria-label="' + escapeHtml(label) + '">' + circles + '</a>';
      }
      return '<g class="world-atlas-hotspot is-locked" data-world-slug="' + escapeHtml(world.slug) + '" tabindex="0" role="img" aria-label="' + escapeHtml(label) + '">' + circles + '</g>';
    }).join('');

    atlasHotspotsEl.innerHTML = defs + lockedArtwork + hotspots;
    var bySlug = {};
    mappedWorlds.forEach(function (world) { bySlug[world.slug] = world; });
    Array.prototype.forEach.call(atlasHotspotsEl.querySelectorAll('[data-world-slug]'), function (hotspot) {
      var world = bySlug[hotspot.getAttribute('data-world-slug')];
      hotspot.addEventListener('mouseenter', function () { setAtlasInfo(world); });
      hotspot.addEventListener('focus', function () { setAtlasInfo(world); });
    });
    atlasEl.addEventListener('mouseleave', function () { setAtlasInfo(null); });
    setAtlasInfo(null);
  }

  function wireAtlasParallax() {
    if (!atlasStageEl || !atlasPanEl || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var frame = 0;
    var targetX = 0;
    var targetY = 0;
    function paint() {
      frame = 0;
      atlasPanEl.style.setProperty('--atlas-x', targetX.toFixed(2) + 'px');
      atlasPanEl.style.setProperty('--atlas-y', targetY.toFixed(2) + 'px');
    }
    atlasStageEl.addEventListener('pointermove', function (event) {
      if (event.pointerType === 'touch') return;
      var rect = atlasStageEl.getBoundingClientRect();
      targetX = ((event.clientX - rect.left) / rect.width - 0.5) * 7;
      targetY = ((event.clientY - rect.top) / rect.height - 0.5) * 7;
      if (!frame) frame = window.requestAnimationFrame(paint);
    });
    atlasStageEl.addEventListener('pointerleave', function () {
      targetX = 0;
      targetY = 0;
      if (!frame) frame = window.requestAnimationFrame(paint);
    });
  }

  fetch('/api/worlds.php', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.ok || !data.worlds) return;
      var worlds = data.worlds.slice().sort(function (a, b) { return a.sort_order - b.sort_order; });

      renderAtlas(worlds);
      wireAtlasParallax();
      gridEl.innerHTML = worlds.map(renderCard).join('');

      var available = worlds.filter(function (w) { return w.status === 'available'; });
      var detailHtml = available.map(function (world, i) {
        var divider = i > 0 ?
          '<div class="world-divider-wrap"><div class="container"><div class="profile-divider"><span class="profile-divider-shard"></span></div></div></div>' : '';
        return divider + renderDetailSection(world);
      }).join('');
      detailEl.innerHTML = detailHtml;

      if (window.wireWorldInteractions) window.wireWorldInteractions();
    })
    .catch(function () {
      // No fallback markup exists (worlds.html has no static content anymore);
      // the page simply shows an empty grid if the fetch fails.
    });
});
