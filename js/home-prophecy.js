// Homepage GSAP effects: the "Prophecy" scene (Nexus Veil teaser) and a
// scroll-reveal for the God-Cores/Overlords/Thirteenth Key intro cards.
// Everything here is skipped under prefers-reduced-motion, matching every
// other animated page on this site.
document.addEventListener('DOMContentLoaded', function () {
  var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var hasGsap = typeof window.gsap !== 'undefined';
  if (hasGsap && window.ScrollTrigger) gsap.registerPlugin(window.ScrollTrigger);

  // "Prophecy" scene: the background is a looping WebM (soft crystal
  // glow); layered on top are a scroll-scrubbed push-in zoom, a
  // fragmented "shard-crack" reveal of the heading synced with a crystal
  // glow pulse (both once-only, ScrollTrigger), and a small deterministic
  // ember particle drift rising from the floor sigil in the artwork. The
  // CSS-only rotating sigil ring needs no JS. Under reduced motion the
  // scene is just the poster frame with no motion at all.
  var scene = document.getElementById('prophecy-scene');
  if (scene) {
  if (!reducedMotion && hasGsap && window.ScrollTrigger) {
    var bg = scene.querySelector('.prophecy-bg');
    if (bg) {
      gsap.fromTo(bg, { scale: 1.02, yPercent: -2 }, {
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
    // The background is a looping WebM (soft crystal glow), same
    // muted/loop/playsinline/preload=none/poster convention as the Worlds
    // atlas's nexus-clouds-loop.webm. Only plays while the scene is both
    // in the viewport and the tab is visible, same reasoning as every
    // other continuous-motion effect on this site; reduced-motion visitors
    // never call play() at all, so they just see the poster frame (the
    // original static photo).
    var bgVideo = scene.querySelector('.prophecy-bg');
    if (bgVideo && bgVideo.tagName === 'VIDEO') {
      var videoInView = false;
      var syncVideo = function () {
        if (videoInView && !document.hidden) {
          if (bgVideo.paused) {
            var playRequest = bgVideo.play();
            if (playRequest && playRequest.catch) playRequest.catch(function () {});
          }
        } else if (!bgVideo.paused) {
          bgVideo.pause();
        }
      };
      if ('IntersectionObserver' in window) {
        var videoObserver = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) { videoInView = entry.isIntersecting; });
          syncVideo();
        }, { threshold: 0.1 });
        videoObserver.observe(scene);
      } else {
        videoInView = true;
      }
      document.addEventListener('visibilitychange', syncVideo);
      syncVideo();
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
  } // end scene

  // God-Cores/Overlords/Thirteenth Key intro cards: staggered fade-rise
  // reveal as the row scrolls into view, same autoAlpha/y/stagger pattern
  // and onRefresh already-in-view fix as Known Figures' section reveals.
  if (!reducedMotion && hasGsap && window.ScrollTrigger) {
    var watermarkCards = document.querySelectorAll('.card--watermark');
    if (watermarkCards.length) {
      var cardsRow = watermarkCards[0].parentElement;
      gsap.set(watermarkCards, { autoAlpha: 0, y: 26 });
      gsap.to(watermarkCards, {
        autoAlpha: 1, y: 0, duration: 0.7, ease: 'power2.out', stagger: 0.12,
        scrollTrigger: {
          trigger: cardsRow, start: 'top 82%', once: true,
          onRefresh: function (self) { if (self.progress > 0) self.animation.progress(1); }
        }
      });
    }
  }

  // Featured Book (The Mindweaver's Lie): the cover swings in on a Y-axis
  // rotation like a book cover opening toward the viewer (same perspective/
  // rotationY entrance technique as Known Figures' portrait frames, using
  // .featured-book's own CSS `perspective` instead of a JS transformPerspective
  // set), while the text column fades/rises in with a stagger alongside it.
  if (!reducedMotion && hasGsap && window.ScrollTrigger) {
    var featuredBook = document.querySelector('.featured-book');
    if (featuredBook) {
      var featuredCover = featuredBook.querySelector('.cover-feature-frame');
      var featuredTextEls = featuredBook.querySelectorAll('.featured-book > div:last-child > *');
      if (featuredCover || featuredTextEls.length) {
        if (featuredCover) gsap.set(featuredCover, { autoAlpha: 0, y: 24, rotationY: -22 });
        if (featuredTextEls.length) gsap.set(featuredTextEls, { autoAlpha: 0, y: 18 });
        var featuredTl = gsap.timeline({
          scrollTrigger: {
            trigger: featuredBook, start: 'top 78%', once: true,
            onRefresh: function (self) { if (self.progress > 0) self.animation.progress(1); }
          }
        });
        if (featuredCover) {
          featuredTl.to(featuredCover, { autoAlpha: 1, y: 0, rotationY: 0, duration: 0.9, ease: 'power3.out' }, 0);
        }
        if (featuredTextEls.length) {
          featuredTl.to(featuredTextEls, { autoAlpha: 1, y: 0, duration: 0.6, ease: 'power2.out', stagger: 0.08 }, 0.15);
        }
      }
    }
  }

  // Quiz teaser ("Which Overlord Are You?"): had no scroll reveal at all
  // before. Fades/rises in like the rest of the page, plus a one-time
  // holographic foil sweep across the whole card (.quiz-teaser-foil's CSS
  // has the gradient; skewX/xPercent are set here rather than in CSS since
  // GSAP would overwrite a stylesheet transform the first time it touches
  // this element anyway).
  if (!reducedMotion && hasGsap && window.ScrollTrigger) {
    var quizTeaser = document.querySelector('.quiz-teaser');
    if (quizTeaser) {
      var quizFoil = quizTeaser.querySelector('.quiz-teaser-foil');
      gsap.set(quizTeaser, { autoAlpha: 0, y: 26 });
      var quizTl = gsap.timeline({
        scrollTrigger: {
          trigger: quizTeaser, start: 'top 80%', once: true,
          onRefresh: function (self) { if (self.progress > 0) self.animation.progress(1); }
        }
      });
      quizTl.to(quizTeaser, { autoAlpha: 1, y: 0, duration: 0.7, ease: 'power2.out' }, 0);
      if (quizFoil) {
        gsap.set(quizFoil, { xPercent: -150, skewX: -20 });
        quizTl.to(quizFoil, { xPercent: 350, duration: 1.1, ease: 'power1.inOut' }, 0.3)
          .to(quizFoil, { opacity: 1, duration: 0.25, ease: 'sine.out' }, 0.3)
          .to(quizFoil, { opacity: 0, duration: 0.35, ease: 'sine.in' }, 1.15);
      }
    }
  }
});
