// The Overlords roster is an API-driven Throne Ring carousel. Content remains
// admin-managed; presentation themes are keyed by established slugs and fall
// back safely for future records.
document.addEventListener('DOMContentLoaded', function () {
  var ringEl = document.getElementById('throne-ring');
  var sealedEl = document.getElementById('throne-ring-sealed');
  var sealedGridEl = document.getElementById('throne-ring-sealed-grid');
  var noteEl = document.getElementById('overlords-note');
  var available = [];
  var activeIndex = 0;
  var swipeStartX = null;
  var isTransitioning = false;
  var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var gsap = window.gsap;
  if (!ringEl) return;

  var THRONE_THEMES = {
    'syn-dravus': { accent: '#a279ec', glow: 'rgba(162,121,236,0.62)', motif: 'Neural archive', scene: 'neural', particle: 'neural', throneImage: 'images/overlord-syn-throne.png' },
    'malric-thorne': { accent: '#e05a4a', glow: 'rgba(224,90,74,0.54)', motif: 'Iron roots', scene: 'iron', particle: 'shard', throneImage: 'images/overlord-malric-throne.png' },
    'korrus-vale': { accent: '#9fe041', glow: 'rgba(159,224,65,0.58)', motif: 'Radiant decay', scene: 'radiation', particle: 'ember', throneImage: 'images/overlord-korrus-throne.png' },
    'cealis-dorne': { accent: '#b7b25d', glow: 'rgba(183,178,93,0.54)', motif: 'Alchemical restraint', scene: 'alchemy', particle: 'ember', throneImage: 'images/overlord-cealis-throne.png' },
    'lysara-venthe': { accent: '#4fb3e8', glow: 'rgba(79,179,232,0.54)', motif: 'Veiled echoes', scene: 'veil', particle: 'veil', throneImage: 'images/overlord-lysara-throne.png' },
    'zura-kaleth': { accent: '#a8c75b', glow: 'rgba(168,199,91,0.56)', motif: 'Ritual fire', scene: 'ember', particle: 'ember', throneImage: 'images/overlord-zura-throne.png' },
    'prime-eidra': { accent: '#dfe8ff', glow: 'rgba(223,232,255,0.54)', motif: 'Perfect symmetry', scene: 'precision', particle: 'precision' },
    'drak-varros': { accent: '#c66145', glow: 'rgba(198,97,69,0.58)', motif: 'Gravity bound', scene: 'gravity', particle: 'gravity', throneImage: 'images/overlord-drak-throne.png' },
    'maerion-thal': { accent: '#75b9dc', glow: 'rgba(117,185,220,0.56)', motif: 'Reflected faces', scene: 'water', particle: 'water', throneImage: 'images/overlord-maerion-throne.png' },
    'krev-ashmane': { accent: '#b45b35', glow: 'rgba(180,91,53,0.58)', motif: 'Screamstone eternal', scene: 'chains', particle: 'ember', throneImage: 'images/overlord-krev-throne.png' },
    'marion-thal': { accent: '#75b9dc', glow: 'rgba(117,185,220,0.56)', motif: 'Reflected faces', scene: 'water', particle: 'water', throneImage: 'images/overlord-maerion-throne.png' }
  };
  var FALLBACK_THEME = { accent: '#b894f5', glow: 'rgba(184,148,245,0.5)', motif: 'Unknown signal', scene: 'unknown', particle: 'neural' };

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>'"]/g, function (character) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[character];
    });
  }

  function themeFor(overlord) { return THRONE_THEMES[overlord.slug] || FALLBACK_THEME; }
  function themeStyle(theme) { return '--throne-accent:' + theme.accent + ';--throne-glow:' + theme.glow + ';'; }
  function profileHref(overlord) { return 'overlord.html?slug=' + encodeURIComponent(overlord.slug); }
  function wrapIndex(index) { return (index + available.length) % available.length; }
  function sceneImageFor(overlord) { return themeFor(overlord).throneImage || overlord.portrait_image_url || ''; }

  function renderSeat(overlord, index) {
    var theme = themeFor(overlord);
    var isActive = index === activeIndex;
    return '<button class="throne-ring-seat throne-ring-seat--' + escapeHtml(theme.scene) + (isActive ? ' is-active' : '') + '" type="button" data-throne-index="' + index + '" style="' + themeStyle(theme) + '" aria-label="Select ' + escapeHtml(overlord.name) + '" aria-pressed="' + (isActive ? 'true' : 'false') + '"><span class="throne-ring-seat-portrait"><img src="' + escapeHtml(overlord.portrait_image_url) + '" alt="" loading="lazy" decoding="async"></span><span class="throne-ring-seat-name">' + escapeHtml(overlord.name) + '</span></button>';
  }

  function renderShadowThrone(overlord, side) {
    if (!overlord) return '';
    return '<div class="throne-ring-shadow-throne throne-ring-shadow-throne--' + side + '" aria-hidden="true"><img src="' + escapeHtml(sceneImageFor(overlord)) + '" alt="" loading="lazy" decoding="async"></div>';
  }

  function renderFocal(overlord) {
    var theme = themeFor(overlord);
    var world = overlord.world ? 'Overlord of ' + overlord.world.name : 'The signal remains uncharted';
    var teaser = overlord.card_teaser || 'A throne waits beyond the Nexus Veil.';
    return '<article class="throne-ring-focal throne-ring-focal--' + escapeHtml(theme.scene) + '" style="' + themeStyle(theme) + '" id="throne-ring-focal"><div class="throne-ring-focal-art"><img src="' + escapeHtml(sceneImageFor(overlord)) + '" alt="' + escapeHtml(overlord.name) + ' upon the throne" decoding="async"></div><div class="throne-ring-focal-copy"><span class="throne-ring-focal-sigil" aria-hidden="true">&#10022;</span><span class="throne-ring-focal-motif">' + escapeHtml(theme.motif) + '</span><h3>' + escapeHtml(overlord.name) + '</h3>' + (overlord.epithet ? '<p class="throne-ring-focal-epithet">' + escapeHtml(overlord.epithet) + '</p>' : '') + '<p class="throne-ring-focal-world">' + escapeHtml(world) + '</p><p class="throne-ring-focal-teaser">' + escapeHtml(teaser) + '</p><a class="throne-ring-focal-link" href="' + profileHref(overlord) + '">Enter profile <span aria-hidden="true">&rarr;</span></a></div></article>';
  }

  function renderNavButton(direction, neighbor, activeTheme) {
    var isPrevious = direction === 'previous';
    var label = isPrevious ? 'Previous' : 'Next';
    return '<button class="throne-ring-nav throne-ring-nav--' + direction + ' throne-ring-nav--' + escapeHtml(activeTheme.scene) + '" type="button" data-throne-direction="' + direction + '" aria-label="' + label + ' Overlord: ' + escapeHtml(neighbor.name) + '"><span class="throne-ring-nav-ornament" aria-hidden="true"><i></i><i></i><i></i></span><span class="throne-ring-nav-diamond" aria-hidden="true"><span class="throne-ring-nav-chevron">' + (isPrevious ? '&#8249;' : '&#8250;') + '</span></span><span class="throne-ring-nav-label" aria-hidden="true"><i></i><span>' + label + '</span><i></i></span></button>';
  }

  function renderParticles(theme) {
    var particles = '';
    for (var index = 0; index < 18; index += 1) {
      particles += '<i class="throne-ring-particle throne-ring-particle--' + escapeHtml(theme.particle) + '" style="' + themeStyle(theme) + '" aria-hidden="true"></i>';
    }
    return '<div class="throne-ring-particles" aria-hidden="true">' + particles + '</div>';
  }

  function renderSealedCard(overlord) {
    var theme = themeFor(overlord);
    return '<article class="throne-ring-sealed-card" style="' + themeStyle(theme) + '"><span class="throne-ring-sealed-portrait"><img src="' + escapeHtml(sceneImageFor(overlord)) + '" alt="' + escapeHtml(overlord.name) + '" loading="lazy" decoding="async"></span><div><h4>' + escapeHtml(overlord.name) + '</h4><span>Lore coming soon</span></div></article>';
  }

  function renderCarousel() {
    var selected = available[activeIndex];
    var previous = available[wrapIndex(activeIndex - 1)];
    var next = available[wrapIndex(activeIndex + 1)];
    var theme = themeFor(selected);
    ringEl.innerHTML = '<div class="throne-ring-stage throne-ring-stage--' + escapeHtml(theme.scene) + '" data-throne-theme="' + escapeHtml(theme.scene) + '" style="' + themeStyle(theme) + '" tabindex="0" aria-label="Throne Ring carousel. Use left and right arrow keys to change Overlord."><div class="throne-ring-color-wash" aria-hidden="true"></div>' + renderParticles(theme) + renderShadowThrone(previous, 'previous') + renderShadowThrone(next, 'next') + '<div class="throne-ring-orbit" aria-label="Select an Overlord">' + available.map(renderSeat).join('') + '</div>' + renderNavButton('previous', previous, theme) + renderNavButton('next', next, theme) + renderFocal(selected) + '<p class="throne-ring-live" role="status" aria-live="polite">' + escapeHtml(selected.name) + ' selected. ' + escapeHtml(selected.epithet || '') + '</p></div><p class="throne-ring-guidance">Use the arrows, swipe, or select a throne to move through the Pantheon.</p>';
  }

  function getStage() { return ringEl.querySelector('.throne-ring-stage'); }

  function setControlsLocked(stage, locked) {
    if (!stage) return;
    stage.classList.toggle('is-transitioning', locked);
    Array.prototype.forEach.call(stage.querySelectorAll('button'), function (button) {
      button.disabled = locked;
      button.setAttribute('aria-disabled', locked ? 'true' : 'false');
    });
  }

  function emitTrail(stage, theme, direction) {
    if (!gsap || reducedMotion || !stage) return;
    var particles = stage.querySelectorAll('.throne-ring-particle');
    var sign = direction === 'next' ? -1 : 1;
    Array.prototype.forEach.call(particles, function (particle, index) {
      var angle = (index / particles.length) * Math.PI * 2;
      var startX = Math.cos(angle) * (30 + (index % 3) * 14);
      var startY = Math.sin(angle) * (26 + (index % 4) * 11);
      gsap.fromTo(particle,
        { x: startX, y: startY, opacity: 0, scale: 0.25, rotation: index * 17 },
        { x: startX + sign * (95 + (index % 5) * 28), y: startY - 22 + (index % 6) * 12, opacity: 0.84, scale: 1 + (index % 3) * 0.18, rotation: index * 49, duration: 0.28 + (index % 4) * 0.045, delay: (index % 6) * 0.018, ease: 'power2.out', yoyo: true, repeat: 1 }
      );
    });
  }

  function setIncomingState(stage, direction) {
    var sign = direction === 'next' ? 1 : -1;
    var art = stage.querySelector('.throne-ring-focal-art');
    var copyItems = stage.querySelectorAll('.throne-ring-focal-sigil, .throne-ring-focal-motif, .throne-ring-focal h3, .throne-ring-focal-epithet, .throne-ring-focal-world, .throne-ring-focal-teaser, .throne-ring-focal-link');
    gsap.set(art, { x: sign * 250, opacity: 0, filter: 'blur(9px) brightness(0.65)' });
    gsap.set(copyItems, { y: 22, opacity: 0 });
    gsap.set(stage.querySelectorAll('.throne-ring-shadow-throne'), { x: sign * 70, opacity: 0 });
    gsap.set(stage.querySelector('.throne-ring-orbit'), { x: sign * 46, opacity: 0.35 });
    gsap.set(stage.querySelectorAll('.throne-ring-nav'), { opacity: 0, scale: 0.9 });
  }

  function animateIncoming(stage, theme, direction, focusTarget) {
    var sign = direction === 'next' ? 1 : -1;
    var art = stage.querySelector('.throne-ring-focal-art');
    var copyItems = stage.querySelectorAll('.throne-ring-focal-sigil, .throne-ring-focal-motif, .throne-ring-focal h3, .throne-ring-focal-epithet, .throne-ring-focal-world, .throne-ring-focal-teaser, .throne-ring-focal-link');
    var timeline = gsap.timeline({ onComplete: function () {
      isTransitioning = false;
      setControlsLocked(stage, false);
      if (focusTarget) {
        var selector = focusTarget === 'seat' ? '[data-throne-index="' + activeIndex + '"]' : '[data-throne-direction="' + focusTarget + '"]';
        var focusElement = ringEl.querySelector(selector);
        if (focusElement) focusElement.focus();
      }
    } });
    timeline.to(stage.querySelector('.throne-ring-color-wash'), { opacity: 0.72, duration: 0.15, ease: 'power2.out' }, 0)
      .to(art, { x: 0, opacity: 1, filter: 'blur(0px) brightness(1)', duration: 0.58, ease: 'power3.out' }, 0.06)
      .to(stage.querySelectorAll('.throne-ring-shadow-throne'), { x: 0, opacity: 0.3, duration: 0.48, ease: 'power2.out' }, 0.12)
      .to(stage.querySelector('.throne-ring-orbit'), { x: 0, opacity: 1, duration: 0.42, ease: 'power2.out' }, 0.16)
      .to(stage.querySelectorAll('.throne-ring-nav'), { opacity: 1, scale: 1, duration: 0.28, stagger: 0.04, ease: 'back.out(1.5)' }, 0.2)
      .to(copyItems, { y: 0, opacity: 1, duration: 0.34, stagger: 0.055, ease: 'power2.out' }, 0.28)
      .to(stage.querySelector('.throne-ring-focal-link'), { boxShadow: '0 0 0 4px rgba(5,3,12,0.4), 0 0 34px ' + theme.glow, duration: 0.28, yoyo: true, repeat: 1, ease: 'sine.inOut' }, 0.57)
      .to(stage.querySelector('.throne-ring-color-wash'), { opacity: 0, duration: 0.48, ease: 'power2.inOut' }, 0.38);
    emitTrail(stage, theme, direction);
  }

  function transitionToIndex(index, direction, focusTarget) {
    var nextIndex = wrapIndex(index);
    if (nextIndex === activeIndex || isTransitioning) return;
    if (!gsap || reducedMotion) {
      activeIndex = nextIndex;
      renderCarousel();
      if (focusTarget) {
        var immediateSelector = focusTarget === 'seat' ? '[data-throne-index="' + activeIndex + '"]' : '[data-throne-direction="' + focusTarget + '"]';
        var immediateFocus = ringEl.querySelector(immediateSelector);
        if (immediateFocus) immediateFocus.focus();
      }
      return;
    }
    isTransitioning = true;
    var outgoingStage = getStage();
    var outgoingTheme = themeFor(available[activeIndex]);
    var exitSign = direction === 'next' ? -1 : 1;
    setControlsLocked(outgoingStage, true);
    emitTrail(outgoingStage, outgoingTheme, direction);
    var exitTimeline = gsap.timeline();
    exitTimeline.to(outgoingStage.querySelector('.throne-ring-color-wash'), { opacity: 0.68, duration: 0.14, ease: 'power2.out' }, 0)
      .to(outgoingStage.querySelector('.throne-ring-focal-art'), { x: exitSign * 250, opacity: 0, filter: 'blur(10px) brightness(0.58)', duration: 0.42, ease: 'power2.in' }, 0.03)
      .to(outgoingStage.querySelector('.throne-ring-focal-copy'), { x: exitSign * 95, opacity: 0, duration: 0.29, ease: 'power2.in' }, 0.06)
      .to(outgoingStage.querySelectorAll('.throne-ring-shadow-throne'), { x: exitSign * 70, opacity: 0, duration: 0.3, ease: 'power2.in' }, 0.04)
      .to(outgoingStage.querySelector('.throne-ring-orbit'), { x: exitSign * 45, opacity: 0.22, duration: 0.28, ease: 'power2.in' }, 0.04)
      .to(outgoingStage.querySelectorAll('.throne-ring-nav'), { opacity: 0, scale: 0.9, duration: 0.2, ease: 'power2.in' }, 0.06)
      .call(function () {
        activeIndex = nextIndex;
        renderCarousel();
        var incomingStage = getStage();
        var incomingTheme = themeFor(available[activeIndex]);
        setControlsLocked(incomingStage, true);
        setIncomingState(incomingStage, direction);
        animateIncoming(incomingStage, incomingTheme, direction, focusTarget);
      });
  }

  function changeBy(offset, focusTarget) {
    transitionToIndex(activeIndex + offset, offset < 0 ? 'previous' : 'next', focusTarget);
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
      if (!isNaN(targetIndex)) transitionToIndex(targetIndex, targetIndex > activeIndex ? 'next' : 'previous', 'seat');
    }
  });

  ringEl.addEventListener('keydown', function (event) {
    if (event.key === 'ArrowLeft') { event.preventDefault(); changeBy(-1, 'previous'); }
    if (event.key === 'ArrowRight') { event.preventDefault(); changeBy(1, 'next'); }
  });

  ringEl.addEventListener('pointerdown', function (event) { swipeStartX = event.clientX; });
  ringEl.addEventListener('pointerup', function (event) {
    if (swipeStartX === null || isTransitioning) return;
    var delta = event.clientX - swipeStartX;
    swipeStartX = null;
    if (Math.abs(delta) >= 42) changeBy(delta > 0 ? -1 : 1, delta > 0 ? 'previous' : 'next');
  });
  ringEl.addEventListener('pointercancel', function () { swipeStartX = null; });
  ringEl.addEventListener('pointermove', function (event) {
    if (!gsap || reducedMotion || isTransitioning || event.pointerType === 'touch') return;
    var stage = event.target.closest('.throne-ring-stage');
    if (!stage) return;
    var bounds = stage.getBoundingClientRect();
    var x = (event.clientX - bounds.left) / bounds.width - 0.5;
    var y = (event.clientY - bounds.top) / bounds.height - 0.5;
    gsap.to(stage.querySelector('.throne-ring-focal-art'), { x: x * 14, y: y * 9, duration: 0.45, ease: 'power2.out', overwrite: 'auto' });
    gsap.to(stage.querySelectorAll('.throne-ring-shadow-throne'), { x: x * -9, y: y * -5, duration: 0.5, ease: 'power2.out', overwrite: 'auto' });
  });
  ringEl.addEventListener('pointerleave', function () {
    if (!gsap || reducedMotion || isTransitioning) return;
    var stage = getStage();
    if (!stage) return;
    gsap.to(stage.querySelector('.throne-ring-focal-art'), { x: 0, y: 0, duration: 0.55, ease: 'power2.out', overwrite: 'auto' });
    gsap.to(stage.querySelectorAll('.throne-ring-shadow-throne'), { x: 0, y: 0, duration: 0.55, ease: 'power2.out', overwrite: 'auto' });
  });

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
