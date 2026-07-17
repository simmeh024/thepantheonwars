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
  var canHoverTilt = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
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

  scenes.forEach(function (sceneEl, sceneIndex) {
    var reveal = sceneEl.querySelectorAll('.figure-portrait-frame, .figure-eyebrow, .figure-name, .figure-status, .figure-body, .figure-quote, .figure-signature');
    var portraitFrame = sceneEl.querySelector('.figure-portrait-frame');

    if (hasGsap && portraitFrame) {
      gsap.set(portraitFrame, { transformPerspective: 1200, transformOrigin: '50% 50%' });
    }

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
      // Each tween needs its own distinct scrollTrigger config object -- GSAP
      // otherwise treats a shared/reused object as "already claimed" by
      // whichever tween wired it up second, silently leaving the first
      // tween's animation unlinked (a real bug caught by checking the
      // deployed page's computed transform, not just reading the code back).
      if (portraitImg) {
        gsap.fromTo(portraitImg, { yPercent: -14 }, {
          yPercent: 14, ease: 'none',
          scrollTrigger: { trigger: sceneEl, start: 'top bottom', end: 'bottom top', scrub: 0.6 }
        });
      }
      if (bgLayer) {
        gsap.fromTo(bgLayer, { yPercent: -6 }, {
          yPercent: 6, ease: 'none',
          scrollTrigger: { trigger: sceneEl, start: 'top bottom', end: 'bottom top', scrub: 0.6 }
        });
      }

      // 3D emergence: the frame turns in from an alternating Y-axis tilt as
      // it enters, rather than only fading/rising like the rest of the
      // section -- its own scrollTrigger object, same reasoning as above.
      if (portraitFrame) {
        var emergeDir = sceneIndex % 2 === 0 ? -1 : 1;
        gsap.fromTo(portraitFrame, { rotationY: emergeDir * 26, scale: 0.92 }, {
          rotationY: 0, scale: 1, duration: 0.9, ease: 'power3.out',
          scrollTrigger: {
            trigger: sceneEl, start: 'top 78%', once: true,
            onRefresh: function (self) { if (self.progress > 0) self.animation.progress(1); }
          }
        });
      }
    }

    if (!reducedMotion && hasGsap && canHoverTilt && portraitFrame) {
      // Pointer-tilt "holo card": the frame tracks the cursor in 3D, the
      // sharp foreground image shifts opposite it for extra depth, and a
      // radial sheen sweeps across to sell the tilt as a lit, physical
      // surface rather than a flat rotated photo. Fine-pointer/hover only --
      // touch devices never attach these listeners at all.
      var tiltImg = portraitFrame.querySelector('img');
      var sheen = portraitFrame.querySelector('.figure-portrait-sheen');
      var frameRotY = gsap.quickTo(portraitFrame, 'rotationY', { duration: 0.5, ease: 'power3.out' });
      var frameRotX = gsap.quickTo(portraitFrame, 'rotationX', { duration: 0.5, ease: 'power3.out' });
      var imgX = tiltImg ? gsap.quickTo(tiltImg, 'x', { duration: 0.5, ease: 'power3.out' }) : function () {};
      var sheenX = null, sheenY = null;
      if (sheen) {
        gsap.set(sheen, { xPercent: -50, yPercent: -50 });
        sheenX = gsap.quickTo(sheen, 'x', { duration: 0.4, ease: 'power3.out' });
        sheenY = gsap.quickTo(sheen, 'y', { duration: 0.4, ease: 'power3.out' });
      }
      var tiltRect = null;

      function tiltEnter() {
        tiltRect = portraitFrame.getBoundingClientRect();
        if (sheen) gsap.to(sheen, { opacity: 1, duration: 0.25 });
      }
      function tiltMove(event) {
        if (!tiltRect || event.pointerType === 'touch') return;
        var nx = Math.max(-1, Math.min(1, ((event.clientX - tiltRect.left) / tiltRect.width - 0.5) * 2));
        var ny = Math.max(-1, Math.min(1, ((event.clientY - tiltRect.top) / tiltRect.height - 0.5) * 2));
        frameRotY(nx * 11);
        frameRotX(ny * -9);
        imgX(nx * -7);
        if (sheenX) { sheenX(nx * tiltRect.width * 0.35); sheenY(ny * tiltRect.height * 0.35); }
      }
      function tiltLeave() {
        frameRotY(0); frameRotX(0); imgX(0);
        if (sheen) gsap.to(sheen, { opacity: 0, duration: 0.4 });
      }

      portraitFrame.addEventListener('pointerenter', tiltEnter);
      portraitFrame.addEventListener('pointermove', tiltMove);
      portraitFrame.addEventListener('pointerleave', tiltLeave);
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
