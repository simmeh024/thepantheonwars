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
  var atlasImageEl = atlasPanEl ? atlasPanEl.querySelector('img') : null;
  var atlasLockCanvasEl = document.getElementById('world-atlas-lock-canvas');
  var atlasEffectsCanvasEl = document.getElementById('world-atlas-effects-canvas');
  var atlasDepthBackEl = document.getElementById('world-atlas-depth-back');
  var atlasNexusGlowEl = document.getElementById('world-atlas-nexus-glow');
  var atlasDepthFrontEl = document.getElementById('world-atlas-depth-front');
  var atlasInfoEl = document.getElementById('world-atlas-info');
  var atlasInfoKickerEl = document.querySelector('.world-atlas-info-kicker');
  var atlasInfoTitleEl = document.getElementById('world-atlas-info-title');
  var atlasInfoCopyEl = document.getElementById('world-atlas-info-copy');
  var atlasExperienceController = null;

  // Coordinates use the atlas artwork's native 1672 × 941 viewBox. Artwork
  // numbers are deliberately separate from World Control sort order: Asmecu
  // and Reanium are ordered differently in the database than on this atlas.
  var ATLAS_POINTS = {
    1: { x: 773, y: 130, r: 77 },
    2: { x: 1044, y: 156, r: 77 },
    3: { x: 1262, y: 271, r: 77 },
    4: { x: 1339, y: 455, r: 77 },
    5: { x: 1275, y: 630, r: 77 },
    6: { x: 1098, y: 736, r: 77 },
    7: { x: 808, y: 794, r: 77 },
    8: { x: 553, y: 740, r: 77 },
    9: { x: 392, y: 637, r: 77 },
    10: { x: 291, y: 496, r: 77 },
    11: { x: 375, y: 344, r: 77 },
    12: { x: 530, y: 232, r: 77 }
  };

  // Stable slugs own atlas placement. Admins may reorder the public world list
  // without moving lock states, hover copy, or hit targets to another planet.
  var ATLAS_SLOT_BY_SLUG = {
    'neoh': 1,
    'high-hammer': 2,
    'cerius': 3,
    'reanium': 4,
    'asmecu': 5,
    'babki-prime': 6,
    'sed': 7,
    'geof-v': 8,
    'beoctica': 9,
    'terek-ii': 10,
    'valerium-prime': 11,
    'vermillia-xi': 12
  };

  // The color is visual metadata for the atlas only. World Control still owns
  // the actual availability state and the public record content.
  var ATLAS_TONES = {
    1: { key: 'neoh', label: 'Neoh signal', rgb: '154, 96, 238' },
    2: { key: 'high-hammer', label: 'High Hammer signal', rgb: '184, 111, 66' },
    3: { key: 'cerius', label: 'Cerius signal', rgb: '204, 72, 80' },
    4: { key: 'reanium', label: 'Reanium signal', rgb: '159, 224, 65' },
    5: { key: 'asmecu', label: 'Asmecu signal', rgb: '68, 150, 237' },
    6: { key: 'babki-prime', label: 'Babki Prime signal', rgb: '59, 148, 83' },
    7: { key: 'sed', label: 'Sed signal', rgb: '166, 36, 57' },
    8: { key: 'geof-v', label: 'Geof V signal', rgb: '158, 175, 193' },
    9: { key: 'beoctica', label: 'Beoctica signal', rgb: '225, 232, 241' },
    10: { key: 'terek-ii', label: 'Terek II signal', rgb: '121, 29, 40' },
    11: { key: 'valerium-prime', label: 'Valerium Prime signal', rgb: '218, 176, 76' },
    12: { key: 'vermillia-xi', label: 'Vermillia XI signal', rgb: '210, 142, 72' }
  };

  function atlasSlot(world) {
    return world ? ATLAS_SLOT_BY_SLUG[world.slug] : null;
  }

  function atlasPoint(world) {
    return ATLAS_POINTS[atlasSlot(world)] || null;
  }

  function atlasTone(world) {
    return ATLAS_TONES[atlasSlot(world)] || null;
  }

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
    if (!atlasInfoTitleEl || !atlasInfoCopyEl) return;
    if (!world) {
      if (atlasInfoEl) {
        atlasInfoEl.classList.remove('is-active');
        atlasInfoEl.setAttribute('aria-hidden', 'true');
        atlasInfoEl.removeAttribute('data-atlas-tone');
        atlasInfoEl.removeAttribute('data-side');
        atlasInfoEl.style.removeProperty('--atlas-tone-rgb');
        atlasInfoEl.style.removeProperty('--atlas-info-x');
        atlasInfoEl.style.removeProperty('--atlas-info-y');
      }
      if (atlasInfoKickerEl) atlasInfoKickerEl.textContent = 'Hover a world';
      atlasInfoTitleEl.textContent = 'Select a world';
      atlasInfoCopyEl.textContent = 'Hover or focus a medallion to inspect its record. Available worlds can be opened in their own field file.';
      return;
    }
    var tone = atlasTone(world);
    var point = atlasPoint(world);
    if (atlasInfoEl && tone) {
      atlasInfoEl.classList.add('is-active');
      atlasInfoEl.setAttribute('aria-hidden', 'false');
      atlasInfoEl.setAttribute('data-atlas-tone', tone.key);
      atlasInfoEl.style.setProperty('--atlas-tone-rgb', tone.rgb);
    }
    if (atlasInfoEl && point) {
      var placeLeft = point.x > 1080;
      var anchorX = placeLeft ? point.x - point.r - 20 : point.x + point.r + 20;
      atlasInfoEl.setAttribute('data-side', placeLeft ? 'left' : 'right');
      atlasInfoEl.style.setProperty('--atlas-info-x', (anchorX / 1672 * 100).toFixed(3) + '%');
      atlasInfoEl.style.setProperty('--atlas-info-y', (point.y / 941 * 100).toFixed(3) + '%');
    }
    if (atlasInfoKickerEl) atlasInfoKickerEl.textContent = tone ? tone.label : 'World signal';
    if (world.status === 'available') {
      atlasInfoTitleEl.textContent = world.name;
      atlasInfoCopyEl.textContent = world.card_blurb || world.tagline || 'This world record is available to explore.';
      return;
    }
    atlasInfoTitleEl.textContent = 'ERROR: LORE LOCK';
    atlasInfoCopyEl.textContent = 'MISSING INFORMATION — ' + world.name + ' remains sealed in the lore archive. Its world record will unlock when the archive is cleared.';
  }

  // SVG blend/filter compositing differs between browser engines. Paint the
  // locked regions from the exact same 1672 × 941 raster instead: the canvas
  // and visible image have identical CSS boxes, so there is no separate image
  // transform that can cause a lock state to drift away from its medallion.
  function renderLockedWorldCanvas(lockedWorlds) {
    if (!atlasLockCanvasEl || !atlasImageEl) return;
    var paint = function () {
      if (!atlasImageEl.naturalWidth) return;
      var ctx = atlasLockCanvasEl.getContext('2d');
      atlasLockCanvasEl.width = 1672;
      atlasLockCanvasEl.height = 941;
      ctx.clearRect(0, 0, 1672, 941);
      if (!lockedWorlds.length) return;
      ctx.drawImage(atlasImageEl, 0, 0, 1672, 941);
      var pixels;
      try {
        pixels = ctx.getImageData(0, 0, 1672, 941);
      } catch (error) {
        // Same-origin artwork is required for the canvas path. If a future
        // asset host makes this unavailable, leave the original map intact.
        ctx.clearRect(0, 0, 1672, 941);
        return;
      }
      var data = pixels.data;
      var i;
      for (i = 3; i < data.length; i += 4) data[i] = 0;
      lockedWorlds.forEach(function (world) {
        var point = atlasPoint(world);
        var radius = point.r + 2;
        var radiusSquared = radius * radius;
        var minX = Math.max(0, Math.floor(point.x - radius));
        var maxX = Math.min(1671, Math.ceil(point.x + radius));
        var minY = Math.max(0, Math.floor(point.y - radius));
        var maxY = Math.min(940, Math.ceil(point.y + radius));
        for (var y = minY; y <= maxY; y += 1) {
          for (var x = minX; x <= maxX; x += 1) {
            var dx = x - point.x;
            var dy = y - point.y;
            if (dx * dx + dy * dy > radiusSquared) continue;
            var offset = (y * 1672 + x) * 4;
            var luminance = (data[offset] * 0.299 + data[offset + 1] * 0.587 + data[offset + 2] * 0.114) * 0.74;
            data[offset] = luminance;
            data[offset + 1] = luminance;
            data[offset + 2] = luminance;
            data[offset + 3] = 255;
          }
        }
      });
      ctx.putImageData(pixels, 0, 0);
    };
    if (atlasImageEl.complete && atlasImageEl.naturalWidth) {
      paint();
    } else {
      atlasImageEl.addEventListener('load', paint, { once: true });
    }
  }

  function renderAtlas(worlds) {
    if (!atlasEl || !atlasHotspotsEl) return;
    var mappedWorlds = worlds.filter(function (world) { return !!atlasPoint(world); });
    var locked = mappedWorlds.filter(function (world) { return world.status !== 'available'; });
    renderLockedWorldCanvas(locked);
    var hotspots = mappedWorlds.map(function (world) {
      var point = atlasPoint(world);
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

    atlasHotspotsEl.innerHTML = hotspots;
    var bySlug = {};
    mappedWorlds.forEach(function (world) { bySlug[world.slug] = world; });
    Array.prototype.forEach.call(atlasHotspotsEl.querySelectorAll('[data-world-slug]'), function (hotspot) {
      var world = bySlug[hotspot.getAttribute('data-world-slug')];
      hotspot.addEventListener('mouseenter', function () {
        setAtlasInfo(world);
        if (atlasExperienceController) atlasExperienceController.setHovered(world.slug);
      });
      hotspot.addEventListener('mouseleave', function () {
        setAtlasInfo(null);
        if (atlasExperienceController) atlasExperienceController.setHovered(null);
      });
      hotspot.addEventListener('focus', function () {
        setAtlasInfo(world);
        if (atlasExperienceController) atlasExperienceController.setHovered(world.slug);
      });
      hotspot.addEventListener('blur', function () {
        setAtlasInfo(null);
        if (atlasExperienceController) atlasExperienceController.setHovered(null);
      });
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

  function wireAtlasExperience(worlds) {
    if (window.WorldAtlasEffects && window.gsap && atlasEffectsCanvasEl) {
      atlasExperienceController = window.WorldAtlasEffects.create({
        stage: atlasStageEl,
        scene: atlasPanEl,
        back: atlasDepthBackEl,
        glow: atlasNexusGlowEl,
        front: atlasDepthFrontEl,
        canvas: atlasEffectsCanvasEl,
        image: atlasImageEl,
        worlds: worlds,
        getPoint: atlasPoint,
        getTone: atlasTone
      });
      if (atlasExperienceController) return;
    }
    wireAtlasParallax();
  }

  fetch('/api/worlds.php', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.ok || !data.worlds) return;
      var worlds = data.worlds.slice().sort(function (a, b) { return a.sort_order - b.sort_order; });

      renderAtlas(worlds);
      wireAtlasExperience(worlds);
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
