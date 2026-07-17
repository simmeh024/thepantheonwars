// Homepage "Prophecy" scene (Nexus Veil teaser). Three GSAP/canvas-driven
// effects layered on the static hero image: a scroll-scrubbed push-in zoom,
// a fragmented "shard-crack" reveal of the heading synced with a crystal
// glow pulse (both once-only, ScrollTrigger), and a small deterministic
// ember particle drift rising from the floor sigil in the artwork. The
// CSS-only rotating sigil ring needs no JS. Everything here is skipped
// under prefers-reduced-motion, matching every other animated page on this
// site -- the scene is then just the static image with no motion at all.
document.addEventListener('DOMContentLoaded', function () {
  var scene = document.getElementById('prophecy-scene');
  if (!scene) return;

  var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var hasGsap = typeof window.gsap !== 'undefined';
  if (hasGsap && window.ScrollTrigger) gsap.registerPlugin(window.ScrollTrigger);

  if (!reducedMotion && hasGsap && window.ScrollTrigger) {
    // .prophecy-cracks is traced against the source photo's exact pixels,
    // so it must scale/pan in lockstep with .prophecy-bg or the glowing
    // lines drift off the real cracks as the page scrolls -- driving both
    // elements from one tween (rather than two separate identical ones)
    // guarantees they can never fall out of sync.
    var bgLayers = scene.querySelectorAll('.prophecy-bg, .prophecy-cracks');
    if (bgLayers.length) {
      gsap.fromTo(bgLayers, { scale: 1.02, yPercent: -2 }, {
        scale: 1.16, yPercent: 2, ease: 'none',
        scrollTrigger: { trigger: scene, start: 'top bottom', end: 'bottom top', scrub: 0.6 }
      });
    }

    var heading = scene.querySelector('.prophecy-heading');
    if (heading) {
      var fullText = heading.textContent;
      heading.setAttribute('aria-label', fullText);
      heading.innerHTML = fullText.split(' ').map(function (word) {
        return '<span class="prophecy-word" aria-hidden="true">' + word + '&nbsp;</span>';
      }).join('');
      var wordEls = heading.querySelectorAll('.prophecy-word');

      // Small deterministic PRNG (not Math.random) so the scattered starting
      // offsets are stable across renders/refreshes -- same reasoning as the
      // atlas's deterministic particle pools.
      var seed = 0;
      function rnd() {
        seed += 1;
        var x = Math.sin(seed * 999.77) * 10000;
        return x - Math.floor(x);
      }
      gsap.set(wordEls, {
        opacity: 0,
        x: function () { return (rnd() - 0.5) * 140; },
        y: function () { return (rnd() - 0.5) * 90; },
        rotation: function () { return (rnd() - 0.5) * 30; },
        filter: 'blur(6px)'
      });

      var glow = scene.querySelector('.prophecy-crystal-glow');
      var tl = gsap.timeline({
        scrollTrigger: {
          trigger: heading, start: 'top 78%', once: true,
          onRefresh: function (self) { if (self.progress > 0) self.animation.progress(1); }
        }
      });
      tl.to(wordEls, {
        opacity: 1, x: 0, y: 0, rotation: 0, filter: 'blur(0px)',
        duration: 0.9, ease: 'power3.out', stagger: 0.09
      });
      if (glow) {
        tl.to(glow, { opacity: 0.65, scale: 1.15, duration: 0.7, ease: 'sine.out' }, 0.1)
          .to(glow, { opacity: 0.3, scale: 1, duration: 1.1, ease: 'sine.inOut' }, '>-0.2');
      }
    }
  }

  if (!reducedMotion) {
    var canvas = scene.querySelector('.prophecy-embers');
    if (canvas && canvas.getContext) {
      var ctx = canvas.getContext('2d');
      var W = 0, H = 0;
      var DPR = Math.min(window.devicePixelRatio || 1, 2);

      function resize() {
        W = scene.clientWidth;
        H = scene.clientHeight;
        canvas.width = W * DPR;
        canvas.height = H * DPR;
        canvas.style.width = W + 'px';
        canvas.style.height = H + 'px';
        ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
      }

      // Deterministic PRNG, same reasoning as above -- a stable ember drift
      // rather than a different random pattern on every reload.
      var pseed = 7;
      function prand() {
        pseed = (pseed * 9301 + 49297) % 233280;
        return pseed / 233280;
      }

      var ORIGIN_X_PCT = 0.58; // approximate floor-sigil position in the artwork
      var ORIGIN_Y_PCT = 0.85;
      var POOL_SIZE = 22;
      var particles = [];
      function resetParticle(p) {
        p.x = W * ORIGIN_X_PCT + (prand() - 0.5) * W * 0.05;
        p.y = H * ORIGIN_Y_PCT;
        p.vx = (prand() - 0.5) * 0.15;
        p.vy = -(0.25 + prand() * 0.35);
        p.life = Math.floor(prand() * 220);
        p.maxLife = 220 + prand() * 160;
        p.size = 1 + prand() * 1.8;
        p.gold = prand() < 0.55;
      }
      for (var i = 0; i < POOL_SIZE; i++) {
        var p = {};
        resetParticle(p);
        particles.push(p);
      }

      function draw() {
        ctx.clearRect(0, 0, W, H);
        particles.forEach(function (particle) {
          particle.life++;
          particle.x += particle.vx;
          particle.y += particle.vy;
          var t = particle.life / particle.maxLife;
          if (t >= 1) { resetParticle(particle); return; }
          var alpha = t < 0.15 ? t / 0.15 : (1 - (t - 0.15) / 0.85);
          ctx.beginPath();
          ctx.fillStyle = (particle.gold ? 'rgba(236,201,135,' : 'rgba(162,121,236,') + (alpha * 0.75) + ')';
          ctx.arc(particle.x, particle.y, particle.size, 0, Math.PI * 2);
          ctx.fill();
        });
      }

      var inView = false;
      var lastFrame = 0;
      var FRAME_MS = 1000 / 24;
      function loop(ts) {
        requestAnimationFrame(loop);
        if (!inView || document.hidden) return;
        if (ts - lastFrame < FRAME_MS) return;
        lastFrame = ts;
        draw();
      }

      resize();
      // A plain window 'resize' listener isn't enough: if this section
      // isn't laid out yet at DOMContentLoaded time (e.g. still behind a
      // slow webfont swap or reflow), clientWidth reads 0 here and the
      // canvas locks into a zero-width buffer until the window itself is
      // resized. ResizeObserver catches that first real layout pass too,
      // not just later viewport changes.
      if ('ResizeObserver' in window) {
        new ResizeObserver(resize).observe(scene);
      } else {
        window.addEventListener('resize', resize);
        window.addEventListener('load', resize);
      }
      requestAnimationFrame(loop);

      if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) { inView = entry.isIntersecting; });
        }, { threshold: 0.1 });
        observer.observe(scene);
      } else {
        inView = true;
      }
    }
  }
});
