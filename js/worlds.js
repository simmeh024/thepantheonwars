// The Worlds page fetches live world data from the admin-managed World Control
// backend and renders the interactive atlas. Available medallions lead to the
// dedicated world record; locked records remain sealed in the atlas itself.
document.addEventListener('DOMContentLoaded', function () {
  var atlasEl = document.getElementById('world-atlas');
  var atlasStageEl = document.getElementById('world-atlas-stage');
  var atlasPanEl = document.getElementById('world-atlas-pan');
  var atlasHotspotsEl = document.getElementById('world-atlas-hotspots');
  var atlasImageEl = atlasPanEl ? atlasPanEl.querySelector('img') : null;
  var atlasCloudVideoEl = document.getElementById('world-atlas-cloud-loop');
  var atlasLockCanvasEl = document.getElementById('world-atlas-lock-canvas');
  var atlasEffectsCanvasEl = document.getElementById('world-atlas-effects-canvas');
  var atlasDepthBackEl = document.getElementById('world-atlas-depth-back');
  var atlasNexusGlowEl = document.getElementById('world-atlas-nexus-glow');
  var atlasDepthFrontEl = document.getElementById('world-atlas-depth-front');
  var atlasInfoEl = document.getElementById('world-atlas-info');
  var atlasInfoKickerEl = document.querySelector('.world-atlas-info-kicker');
  var atlasInfoTitleEl = document.getElementById('world-atlas-info-title');
  var atlasInfoCopyEl = document.getElementById('world-atlas-info-copy');
  var atlasProgressEl = document.getElementById('world-atlas-progress');
  var atlasProgressTrackEl = document.getElementById('world-atlas-progress-track');
  var atlasProgressFillEl = document.getElementById('world-atlas-progress-fill');
  var atlasProgressSweepEl = document.getElementById('world-atlas-progress-sweep');
  var atlasProgressMarkersEl = document.getElementById('world-atlas-progress-markers');
  var atlasProgressSparksEl = document.getElementById('world-atlas-progress-sparks');
  var atlasProgressCurrentEl = document.getElementById('world-atlas-progress-current');
  var atlasExperienceController = null;
  if (!atlasEl || !atlasHotspotsEl) return;

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
      var tone = atlasTone(world);
      var toneStyle = tone ? ' style="--atlas-tone-rgb:' + tone.rgb + '"' : '';
      var label = world.status === 'available' ? 'Open world record for ' + world.name : 'Lore locked: ' + world.name;
      var circles =
        '<circle class="world-atlas-hit" cx="' + point.x + '" cy="' + point.y + '" r="' + point.r + '"></circle>' +
        '<circle class="world-atlas-ring" cx="' + point.x + '" cy="' + point.y + '" r="' + (point.r + 4) + '"></circle>' +
        (world.status === 'available' ? '<circle class="world-atlas-signal" cx="' + point.x + '" cy="' + point.y + '" r="' + (point.r + 10) + '"></circle>' : '<circle class="world-atlas-lock-shade" cx="' + point.x + '" cy="' + point.y + '" r="' + point.r + '"></circle>');
      if (world.status === 'available') {
        return '<a class="world-atlas-hotspot is-available" href="' + worldRecordUrl(world) + '" data-world-slug="' + escapeHtml(world.slug) + '" aria-label="' + escapeHtml(label) + '"' + toneStyle + '>' + circles + '</a>';
      }
      return '<g class="world-atlas-hotspot is-locked" data-world-slug="' + escapeHtml(world.slug) + '" tabindex="0" role="img" aria-label="' + escapeHtml(label) + '"' + toneStyle + '>' + circles + '</g>';
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
        cloudVideo: atlasCloudVideoEl,
        worlds: worlds,
        getPoint: atlasPoint,
        getTone: atlasTone
      });
      if (atlasExperienceController) return;
    }
    if (atlasCloudVideoEl && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      var cloudPlayback = atlasCloudVideoEl.play();
      if (cloudPlayback && cloudPlayback.catch) cloudPlayback.catch(function () {});
    }
    wireAtlasParallax();
  }

  function renderAtlasProgress(worlds) {
    var totalWorlds = 13;
    if (!atlasProgressEl || !atlasProgressTrackEl || !atlasProgressFillEl || !atlasProgressCurrentEl) return;
    var unlockedWorlds = Math.min(totalWorlds, worlds.filter(function (world) {
      return world.status === 'available';
    }).length);
    var progress = unlockedWorlds / totalWorlds;
    var progressPercent = progress * 100;

    function updateCount(value) {
      var rounded = Math.min(unlockedWorlds, Math.max(0, Math.round(value)));
      atlasProgressCurrentEl.textContent = String(rounded);
      atlasProgressTrackEl.setAttribute('aria-valuenow', String(rounded));
      atlasProgressTrackEl.setAttribute('aria-valuetext', rounded + ' of ' + totalWorlds + ' worlds unlocked');
    }

    if (atlasProgressMarkersEl) {
      atlasProgressMarkersEl.innerHTML = Array.from({ length: totalWorlds }, function (_, index) {
        var position = (index + 1) / totalWorlds * 100;
        return '<span class="world-atlas-progress-marker" style="--world-marker-position:' + position.toFixed(4) + '%"></span>';
      }).join('');
    }

    var markers = atlasProgressMarkersEl
      ? Array.prototype.slice.call(atlasProgressMarkersEl.querySelectorAll('.world-atlas-progress-marker'))
      : [];
    var sparks = atlasProgressSparksEl
      ? Array.prototype.slice.call(atlasProgressSparksEl.querySelectorAll('i'))
      : [];
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var gsap = window.gsap;

    function applyFinalState() {
      atlasProgressFillEl.style.transform = 'scaleX(' + progress.toFixed(5) + ')';
      markers.forEach(function (marker, index) {
        marker.classList.toggle('is-unlocked', index < unlockedWorlds);
      });
      updateCount(unlockedWorlds);
      if (atlasProgressSparksEl) atlasProgressSparksEl.style.left = progressPercent.toFixed(4) + '%';
    }

    if (!gsap || reducedMotion || unlockedWorlds === 0) {
      applyFinalState();
      return;
    }

    gsap.set(atlasProgressFillEl, { scaleX: 0, transformOrigin: 'left center' });
    if (atlasProgressSweepEl) gsap.set(atlasProgressSweepEl, { xPercent: -80, opacity: 0 });
    if (atlasProgressSparksEl) gsap.set(atlasProgressSparksEl, { left: '0%' });
    gsap.set(sparks, { opacity: 0, x: 0, y: 0, scale: 0.6 });
    updateCount(0);

    var hasAnimated = false;
    function animateProgress() {
      if (hasAnimated) return;
      hasAnimated = true;
      var counter = { value: 0 };
      var timeline = gsap.timeline({
        defaults: { overwrite: 'auto' },
        onComplete: function () {
          applyFinalState();
        }
      });
      timeline.to(atlasProgressFillEl, {
        scaleX: progress,
        duration: 1.65,
        ease: 'power3.out'
      }, 0);
      timeline.to(counter, {
        value: unlockedWorlds,
        duration: 1.65,
        ease: 'power3.out',
        onUpdate: function () { updateCount(counter.value); }
      }, 0);
      if (atlasProgressSparksEl) {
        timeline.to(atlasProgressSparksEl, {
          left: progressPercent + '%',
          duration: 1.65,
          ease: 'power3.out'
        }, 0);
      }
      if (atlasProgressSweepEl) {
        timeline.fromTo(atlasProgressSweepEl, {
          xPercent: -80,
          opacity: 0
        }, {
          xPercent: 520,
          opacity: 0.9,
          duration: 1.05,
          ease: 'power2.inOut'
        }, 0.28);
        timeline.to(atlasProgressSweepEl, { opacity: 0, duration: 0.2 }, 1.28);
      }
      markers.slice(0, unlockedWorlds).forEach(function (marker, index) {
        var markerTime = 0.2 + 1.2 * ((index + 1) / totalWorlds);
        timeline.call(function () { marker.classList.add('is-unlocked'); }, null, markerTime);
        timeline.fromTo(marker, {
          scale: 0.65
        }, {
          scale: 1.75,
          duration: 0.16,
          ease: 'back.out(2.4)'
        }, markerTime);
        timeline.to(marker, { scale: 1, duration: 0.24, ease: 'power2.out' }, markerTime + 0.16);
      });
      sparks.forEach(function (spark, index) {
        var sparkTime = 0.38 + index * 0.11;
        var direction = index % 2 === 0 ? -1 : 1;
        timeline.fromTo(spark, {
          opacity: 0,
          x: -3,
          y: 0,
          scale: 0.55
        }, {
          opacity: 0.95,
          x: direction * (4 + index),
          y: -4 - (index % 3) * 4,
          scale: 1,
          duration: 0.16,
          ease: 'power2.out'
        }, sparkTime);
        timeline.to(spark, {
          opacity: 0,
          x: direction * (10 + index * 2),
          y: 8 + (index % 2) * 5,
          scale: 0.2,
          duration: 0.52,
          ease: 'power2.in'
        }, sparkTime + 0.16);
      });
      timeline.to(atlasProgressEl, {
        scale: 1.006,
        boxShadow: '0 16px 38px rgba(0,0,0,0.3), 0 0 28px rgba(153,84,224,0.2)',
        duration: 0.2,
        ease: 'power2.out',
        yoyo: true,
        repeat: 1
      }, 1.63);
    }

    if (window.ScrollTrigger) {
      gsap.registerPlugin(window.ScrollTrigger);
      window.ScrollTrigger.create({
        trigger: atlasProgressEl,
        start: 'top 92%',
        once: true,
        onEnter: animateProgress
      });
    } else if ('IntersectionObserver' in window) {
      var observer = new IntersectionObserver(function (entries) {
        if (!entries[0].isIntersecting) return;
        observer.disconnect();
        animateProgress();
      }, { threshold: 0.15 });
      observer.observe(atlasProgressEl);
    } else {
      animateProgress();
    }
  }

  // Compact subset of the same icon paths world-detail.js uses for the full
  // World Record weather card -- duplicated rather than shared per this
  // site's established no-shared-JS-module convention (see formatBody()/
  // stripBbcodePreview() elsewhere).
  function glanceIconHtml(icon) {
    var paths = {
      'acid-rain': '<path d="M13 35h34a10 10 0 0 0 1-20 16 16 0 0 0-30-3 12 12 0 0 0-5 23z"/><path d="m20 43-4 9m15-9-4 9m15-9-4 9"/>',
      storm: '<path d="M13 34h34a10 10 0 0 0 1-20 16 16 0 0 0-30-3 12 12 0 0 0-5 23z"/><path d="m34 38-8 11h7l-4 10 13-15h-8z"/>',
      smog: '<path d="M13 31h34a10 10 0 0 0 1-20 16 16 0 0 0-30-3 12 12 0 0 0-5 23z"/><path d="M10 40h36M18 47h34M8 54h31"/>',
      clear: '<circle cx="32" cy="32" r="11"/><path d="M32 8v8m0 32v8M8 32h8m32 0h8M15 15l6 6m22 22 6 6m0-34-6 6M21 43l-6 6"/>',
      overcast: '<path d="M13 37h34a10 10 0 0 0 1-20 16 16 0 0 0-30-3 12 12 0 0 0-5 23z"/>'
    };
    return '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (paths[icon] || paths.overcast) + '</svg>';
  }

  function glanceCardHtml(entry) {
    var tone = atlasTone({ slug: entry.slug }) || { rgb: '154, 96, 238' };
    var current = entry.current || {};
    return '<a class="worlds-glance-card" href="' + worldRecordUrl(entry) + '" style="--glance-accent: ' + tone.rgb + '">' +
      '<span class="worlds-glance-card-icon">' + glanceIconHtml(current.icon) + '</span>' +
      '<span class="worlds-glance-card-body">' +
        '<strong>' + escapeHtml(entry.name) + '</strong>' +
        '<span class="worlds-glance-card-condition">' + escapeHtml(current.condition) + '</span>' +
      '</span>' +
      '<span class="worlds-glance-card-temp">' + escapeHtml(current.temperature_c) + '&deg;</span>' +
    '</a>';
  }

  function renderWeatherGlance() {
    var section = document.getElementById('worlds-glance');
    var strip = document.getElementById('worlds-glance-strip');
    if (!section || !strip) return;
    fetch('/api/worlds-weather-glance.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok || !data.worlds || !data.worlds.length) return;
        strip.innerHTML = data.worlds.map(glanceCardHtml).join('');
        section.hidden = false;
      })
      .catch(function () {
        // The atlas and progress bar above remain fully usable without this.
      });
  }

  fetch('/api/worlds.php', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.ok || !data.worlds) return;
      var worlds = data.worlds.slice().sort(function (a, b) { return a.sort_order - b.sort_order; });

      renderAtlas(worlds);
      wireAtlasExperience(worlds);
      renderAtlasProgress(worlds);
    })
    .catch(function () {
      // Keep the static atlas artwork visible if live status data is unavailable.
    });

  renderWeatherGlance();
});
