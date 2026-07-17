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
        scrollTrigger: { trigger: sceneEl, start: 'top 78%', once: true }
      });
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
