// Known Figures — cinematic vertical chronicle. Each .figure-scene reveals
// once via GSAP/ScrollTrigger as it enters, then runs one small looping
// "signature detail" animation (unique per figure) for as long as its
// section stays in the viewport. Reduced-motion visitors get every section
// fully visible immediately, with no reveal animation and no idle loops.
document.addEventListener('DOMContentLoaded', function () {
  var scenes = document.querySelectorAll('.figure-scene');
  if (!scenes.length) return;

  var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var hasGsap = typeof window.gsap !== 'undefined';
  if (hasGsap && window.ScrollTrigger) gsap.registerPlugin(window.ScrollTrigger);

  var idleTimelines = [];

  function buildIdleLoop(sceneEl) {
    var glyph = sceneEl.querySelector('.figure-signature-glyph');
    if (!glyph || reducedMotion || !hasGsap) return null;
    var kind = sceneEl.getAttribute('data-figure');
    var tl = gsap.timeline({ paused: true, repeat: -1 });

    if (kind === 'kael') {
      // A steady, alive heartbeat pulse -- the shard borrowing its glow from him.
      tl.to(glyph, { scale: 1.18, opacity: 1, duration: 0.55, ease: 'sine.inOut' })
        .to(glyph, { scale: 1, opacity: 0.72, duration: 0.75, ease: 'sine.inOut' })
        .to(glyph, { scale: 1, opacity: 0.72, duration: 0.9 });
    } else if (kind === 'brann') {
      // A broken, intrusive glitch-flicker rather than a clean pulse.
      tl.to(glyph, { opacity: 0.25, x: -1, duration: 0.05 })
        .to(glyph, { opacity: 1, x: 1, duration: 0.05 })
        .to(glyph, { opacity: 1, x: 0, duration: 0.05 })
        .to(glyph, { opacity: 1, duration: 2.4 })
        .to(glyph, { opacity: 0.3, duration: 0.06 })
        .to(glyph, { opacity: 1, duration: 0.06 })
        .to(glyph, { opacity: 1, duration: 1.6 });
      var sceneRoot = sceneEl.querySelector('.figure-portrait-glitch');
      if (sceneRoot) {
        gsap.timeline({ repeat: -1, delay: 1 })
          .to(sceneRoot, { opacity: 0.5, duration: 0.05 })
          .to(sceneRoot, { opacity: 0, duration: 0.08 })
          .to(sceneRoot, { opacity: 0, duration: 3.2 + Math.random() * 2 });
      }
    } else if (kind === 'vb') {
      // Restless, uneven -- never settles into a clean rhythm.
      tl.to(glyph, { rotation: 140, duration: 0.5, ease: 'power2.out' })
        .to(glyph, { rotation: 95, duration: 0.25, ease: 'power1.inOut' })
        .to(glyph, { rotation: 210, duration: 0.6, ease: 'power2.inOut' })
        .to(glyph, { rotation: 180, duration: 0.9, ease: 'sine.inOut' })
        .to(glyph, { rotation: 360, duration: 0.7, ease: 'power2.in' })
        .to(glyph, { rotation: 340, duration: 1.1 });
    } else if (kind === 'teo') {
      // Quiet stillness with an occasional glint -- danger without showmanship.
      tl.to(glyph, { opacity: 0.55, duration: 2.6 })
        .to(glyph, { opacity: 1, filter: 'brightness(1.6)', duration: 0.18, ease: 'sine.out' })
        .to(glyph, { opacity: 0.55, filter: 'brightness(1)', duration: 0.5 })
        .to(glyph, { opacity: 0.55, duration: 3.4 });
    }
    return tl;
  }

  scenes.forEach(function (sceneEl) {
    var reveal = sceneEl.querySelectorAll('.figure-portrait-frame, .figure-eyebrow, .figure-name, .figure-status, .figure-body, .figure-quote, .figure-signature');

    if (!reducedMotion && hasGsap && window.ScrollTrigger) {
      gsap.set(reveal, { autoAlpha: 0, y: 22 });
      gsap.to(reveal, {
        autoAlpha: 1, y: 0, duration: 0.7, ease: 'power2.out', stagger: 0.09,
        scrollTrigger: {
          trigger: sceneEl, start: 'top 78%', once: true,
          // A scene already sitting inside its trigger zone at setup time (a
          // deep link, a mid-page refresh, browser scroll restoration) never
          // experiences an inactive->active *transition*, so the default
          // "onEnter" toggle action has nothing to fire on. Without this, a
          // visitor who never crosses the boundary from below sees a
          // permanently invisible section instead of a missed animation.
          onRefresh: function (self) { if (self.progress > 0) self.animation.progress(1); }
        }
      });
    }

    if (!reducedMotion && hasGsap && window.ScrollTrigger) {
      // Scroll-scrubbed parallax: the sharp foreground portrait travels the
      // most (the strong effect that was asked for), the blurred background
      // travels less and in a slightly different rhythm, so the two read as
      // separate depth planes rather than the whole photo just sliding as
      // one flat image. Both CSS layers are oversized (content.css) to give
      // this room to move without ever exposing an edge.
      var portraitImg = sceneEl.querySelector('.figure-portrait-frame img');
      var bgLayer = sceneEl.querySelector('.figure-scene-bg');
      var parallaxScroll = { trigger: sceneEl, start: 'top bottom', end: 'bottom top', scrub: 0.6 };
      if (portraitImg) {
        gsap.fromTo(portraitImg, { yPercent: -14 }, { yPercent: 14, ease: 'none', scrollTrigger: parallaxScroll });
      }
      if (bgLayer) {
        gsap.fromTo(bgLayer, { yPercent: -6 }, { yPercent: 6, ease: 'none', scrollTrigger: parallaxScroll });
      }
    }

    var idle = buildIdleLoop(sceneEl);
    if (idle) idleTimelines.push({ el: sceneEl, tl: idle });
  });

  if (idleTimelines.length && 'IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        var match = idleTimelines.filter(function (item) { return item.el === entry.target; })[0];
        if (!match) return;
        if (entry.isIntersecting) match.tl.play();
        else match.tl.pause();
      });
    }, { threshold: 0.2 });
    idleTimelines.forEach(function (item) { observer.observe(item.el); });
  } else {
    idleTimelines.forEach(function (item) { item.tl.play(); });
  }
});
