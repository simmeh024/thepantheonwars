// Known Figures — cinematic vertical chronicle, now rendered from
// api/known-figures.php (Admin Console > Lore Management > Known Figures
// Control) instead of four hand-authored <section> blocks. Each record picks
// one of a small hand-authored "motif" preset (pulse/glitch/twirl/glint/none)
// that supplies its veil texture, idle "signature detail" loop, and glyph
// icon; accent_color drives the eyebrow/glyph/portrait-border color via CSS
// custom properties. Once rendered, sections reveal via GSAP/ScrollTrigger as
// they enter, then run their idle loop for as long as they stay in the
// viewport. Reduced-motion visitors get every section fully visible
// immediately, with no reveal animation and no idle loops.
document.addEventListener('DOMContentLoaded', function () {
  var chronicleEl = document.getElementById('figures-chronicle');
  if (!chronicleEl) return;

  var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var hasGsap = typeof window.gsap !== 'undefined';
  var canHoverTilt = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  if (hasGsap && window.ScrollTrigger) gsap.registerPlugin(window.ScrollTrigger);

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char];
    });
  }

  function hexToRgba(hex, alpha) {
    var m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})/i.exec(hex || '');
    if (!m) return 'rgba(199,204,214,' + alpha + ')';
    var r = parseInt(m[1], 16), g = parseInt(m[2], 16), b = parseInt(m[3], 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
  }

  var MOTIF_VEIL = { pulse: 'mist', glitch: 'glitch', twirl: 'static', glint: 'glint' };
  var MOTIF_GLYPH = {
    pulse: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 3v3M12 18v3M3 12h3M18 12h3"/><circle cx="12" cy="12" r="4.2"/></svg>',
    glitch: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/></svg>',
    twirl: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 18 18 6M9 6h9v9"/></svg>',
    glint: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 20 20 4M13 4h7v7"/></svg>'
  };

  function renderScene(figure, index) {
    var motif = MOTIF_VEIL[figure.motif] ? figure.motif : 'none';
    var reverse = index % 2 === 1;
    var imgUrl = figure.portrait_image_url || '';
    var accent = figure.accent_color || '#c7ccd6';
    var style = '--figure-accent:' + accent + '; --figure-accent-border:' + hexToRgba(accent, 0.4) + ';';

    var veilHtml = motif !== 'none'
      ? '<div class="figure-scene-veil figure-scene-veil--' + MOTIF_VEIL[motif] + '" aria-hidden="true"></div>'
      : '';
    var glitchOverlayHtml = motif === 'glitch' ? '<span class="figure-portrait-glitch" aria-hidden="true"></span>' : '';
    var statusHtml = figure.status_line ? '<span class="figure-status">' + escapeHtml(figure.status_line) + '</span>' : '';
    var body1Html = figure.body_paragraph_1 ? '<p class="figure-body">' + escapeHtml(figure.body_paragraph_1) + '</p>' : '';
    var body2Html = figure.body_paragraph_2 ? '<p class="figure-body">' + escapeHtml(figure.body_paragraph_2) + '</p>' : '';
    var quoteHtml = figure.quote_text
      ? '<blockquote class="myth figure-quote">' + escapeHtml(figure.quote_text) +
        (figure.quote_cite ? '<cite>' + escapeHtml(figure.quote_cite) + '</cite>' : '') + '</blockquote>'
      : '';
    var signatureHtml = (motif !== 'none' && figure.signature_label)
      ? '<div class="figure-signature" aria-hidden="true">' +
          '<span class="figure-signature-glyph">' + MOTIF_GLYPH[motif] + '</span>' +
          '<span class="figure-signature-label">' + escapeHtml(figure.signature_label) + '</span>' +
        '</div>'
      : '';

    return (
      '<section class="figure-scene" data-figure="' + escapeHtml(figure.slug) + '" data-motif="' + motif + '" aria-labelledby="figure-' + escapeHtml(figure.slug) + '-name" style="' + style + '">' +
        '<div class="figure-scene-bg" style="background-image: url(\'' + imgUrl + '\');"></div>' +
        veilHtml +
        '<div class="container figure-scene-inner' + (reverse ? ' figure-scene-inner--reverse' : '') + '">' +
          '<div class="figure-portrait-frame">' +
            '<img src="' + imgUrl + '" alt="' + escapeHtml(figure.name) + '" loading="lazy" decoding="async">' +
            glitchOverlayHtml +
            '<span class="figure-portrait-sheen" aria-hidden="true"></span>' +
          '</div>' +
          '<div class="figure-content">' +
            '<span class="figure-eyebrow">' + escapeHtml(figure.eyebrow) + '</span>' +
            '<h2 class="figure-name" id="figure-' + escapeHtml(figure.slug) + '-name">' + escapeHtml(figure.name) + '</h2>' +
            statusHtml +
            body1Html +
            body2Html +
            quoteHtml +
            signatureHtml +
          '</div>' +
        '</div>' +
      '</section>'
    );
  }

  function buildIdleLoop(sceneEl) {
    var glyph = sceneEl.querySelector('.figure-signature-glyph');
    if (!glyph || reducedMotion || !hasGsap) return null;
    var motif = sceneEl.getAttribute('data-motif');
    var tl = gsap.timeline({ paused: true, repeat: -1 });

    if (motif === 'pulse') {
      // A steady, alive heartbeat pulse.
      tl.to(glyph, { scale: 1.18, opacity: 1, duration: 0.55, ease: 'sine.inOut' })
        .to(glyph, { scale: 1, opacity: 0.72, duration: 0.75, ease: 'sine.inOut' })
        .to(glyph, { scale: 1, opacity: 0.72, duration: 0.9 });
    } else if (motif === 'glitch') {
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
    } else if (motif === 'twirl') {
      // Restless, uneven -- never settles into a clean rhythm.
      tl.to(glyph, { rotation: 140, duration: 0.5, ease: 'power2.out' })
        .to(glyph, { rotation: 95, duration: 0.25, ease: 'power1.inOut' })
        .to(glyph, { rotation: 210, duration: 0.6, ease: 'power2.inOut' })
        .to(glyph, { rotation: 180, duration: 0.9, ease: 'sine.inOut' })
        .to(glyph, { rotation: 360, duration: 0.7, ease: 'power2.in' })
        .to(glyph, { rotation: 340, duration: 1.1 });
    } else if (motif === 'glint') {
      // Quiet stillness with an occasional glint -- danger without showmanship.
      tl.to(glyph, { opacity: 0.55, duration: 2.6 })
        .to(glyph, { opacity: 1, filter: 'brightness(1.6)', duration: 0.18, ease: 'sine.out' })
        .to(glyph, { opacity: 0.55, filter: 'brightness(1)', duration: 0.5 })
        .to(glyph, { opacity: 0.55, duration: 3.4 });
    } else {
      return null;
    }
    return tl;
  }

  function wireScenes() {
    var scenes = chronicleEl.querySelectorAll('.figure-scene');
    if (!scenes.length) return;

    var idleTimelines = [];

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
  }

  fetch('/api/known-figures.php', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      var figures = (data.ok && data.known_figures) || [];
      if (!figures.length) {
        chronicleEl.innerHTML = '<div class="container" style="padding: 4rem 0; text-align: center;"><p class="lede">No known figures on record yet.</p></div>';
        return;
      }
      chronicleEl.innerHTML = figures.map(renderScene).join('');
      wireScenes();
    })
    .catch(function () {
      chronicleEl.innerHTML = '<div class="container" style="padding: 4rem 0; text-align: center;"><p class="lede">Could not load Known Figures right now. Please try again later.</p></div>';
    });
});
