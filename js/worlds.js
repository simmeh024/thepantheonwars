// The Worlds page — fetches live world data from the admin-managed World
// Control backend (api/worlds.php) and renders both the card grid and, for
// each "available" world, its cross-section detail section (layers,
// sublocations, and landmarks). Markup shapes mirror what used to be
// hand-authored directly in worlds.html so no CSS changes were needed.
document.addEventListener('DOMContentLoaded', function () {
  var gridEl = document.getElementById('world-grid');
  var detailEl = document.getElementById('world-detail-sections');
  if (!gridEl || !detailEl) return;

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
          '<a class="thumb" href="#' + world.slug + '">' +
            '<img src="' + (world.thumb_image_url || '') + '" alt="' + world.name + '"></a>' +
          '<span class="world-tag">' + overlordTagText(world) + '</span>' +
          '<h3>' + world.tagline + '</h3>' +
          '<p>' + world.card_blurb + '</p>' +
          '<a href="#' + world.slug + '" class="learn-more">Explore Below &darr;</a>' +
        '</div>'
      );
    }
    return (
      '<div class="world-card card locked" id="' + cardId + '">' +
        '<div class="thumb"><img src="' + (world.thumb_image_url || '') + '" alt="' + world.name + '"></div>' +
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
          '<img src="' + (world.map_thumb_image_url || '') + '" alt="Map of ' + world.name + '">' +
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
            (world.portrait_image_url ? '<span class="world-detail-portrait"><img src="' + world.portrait_image_url + '" alt="' + (world.overlord ? world.overlord.name : world.name) + '"></span>' : '') +
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
            '<img src="' + (world.map_full_image_url || '') + '" alt="Full map of ' + world.name + '">' +
          '</div>' +
        '</div>' +
      '</section>'
    );
  }

  fetch('/api/worlds.php', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.ok || !data.worlds) return;
      var worlds = data.worlds.slice().sort(function (a, b) { return a.sort_order - b.sort_order; });

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
