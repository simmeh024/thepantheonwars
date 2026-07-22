/*
 * Ambient weather for the World Record's atmospheric card.
 *
 * Deliberately NOT the atlas's effect engine (js/world-atlas-effects.js). Those
 * drawers are private to that module's closure, keyed by world slug, and bound
 * to the atlas's fixed 1672x941 medallion geometry -- they paint into a circle
 * at a known point, which is meaningless here. This is the same technique at a
 * much smaller scale, keyed by the five condition icons the forecast already
 * produces, so a world's card changes with its weather rather than being fixed
 * to its identity.
 *
 * One canvas, sized to the card, tinted by the card's own --world-weather-icon
 * so each world keeps its palette. Pauses off-screen and when the tab is
 * hidden, and never starts at all under prefers-reduced-motion.
 */
(function (global) {
  'use strict';

  var FRAME_INTERVAL = 1000 / 24;   // the cinematic 24fps the atlas also uses

  function rand(seed) {
    // Deterministic per particle index, so a resize does not reshuffle the
    // field into a visibly different arrangement.
    var x = Math.sin(seed * 12.9898) * 43758.5453;
    return x - Math.floor(x);
  }

  function makeParticles(count) {
    var particles = [];
    for (var i = 0; i < count; i++) {
      particles.push({
        x: rand(i + 1),
        y: rand(i + 101),
        speed: 0.35 + rand(i + 201) * 0.9,
        drift: rand(i + 301) - 0.5,
        size: 0.5 + rand(i + 401) * 1.6,
        phase: rand(i + 501) * Math.PI * 2
      });
    }
    return particles;
  }

  // Each drawer gets normalised particles (0..1) and paints into the card box.
  var DRAWERS = {
    'acid-rain': function (ctx, w, h, t, particles, rgb) {
      ctx.strokeStyle = 'rgba(' + rgb + ',0.30)';
      ctx.lineWidth = 1;
      particles.forEach(function (p) {
        var y = ((p.y + t * p.speed * 0.55) % 1) * h;
        var x = p.x * w + Math.sin(t * 0.4 + p.phase) * 4;
        ctx.beginPath();
        ctx.moveTo(x, y);
        ctx.lineTo(x + p.drift * 3, y + 9 + p.size * 5);
        ctx.stroke();
      });
    },
    storm: function (ctx, w, h, t, particles, rgb) {
      DRAWERS['acid-rain'](ctx, w, h, t * 1.9, particles, rgb);
      // A double flash on a long cycle, rather than a steady strobe.
      var cycle = t % 7.5;
      var flash = cycle < 0.12 ? 1 : (cycle > 0.28 && cycle < 0.38 ? 0.65 : 0);
      if (flash > 0) {
        ctx.fillStyle = 'rgba(' + rgb + ',' + (0.09 * flash).toFixed(3) + ')';
        ctx.fillRect(0, 0, w, h);
      }
    },
    smog: function (ctx, w, h, t, particles, rgb) {
      particles.slice(0, 14).forEach(function (p, i) {
        var y = p.y * h + Math.sin(t * 0.22 + p.phase) * 10;
        var x = ((p.x + t * 0.014 * p.speed) % 1.2 - 0.1) * w;
        var r = 26 + p.size * 30;
        var g = ctx.createRadialGradient(x, y, 0, x, y, r);
        g.addColorStop(0, 'rgba(' + rgb + ',' + (0.05 + (i % 3) * 0.012).toFixed(3) + ')');
        g.addColorStop(1, 'rgba(' + rgb + ',0)');
        ctx.fillStyle = g;
        ctx.beginPath();
        ctx.arc(x, y, r, 0, Math.PI * 2);
        ctx.fill();
      });
    },
    clear: function (ctx, w, h, t, particles, rgb) {
      // Heat shimmer: slow horizontal bands rather than falling matter.
      for (var i = 0; i < 5; i++) {
        var y = (i / 5) * h + Math.sin(t * 0.5 + i) * 6;
        ctx.fillStyle = 'rgba(' + rgb + ',0.028)';
        ctx.fillRect(0, y, w, 1.5 + Math.sin(t + i) * 0.8);
      }
      particles.slice(0, 10).forEach(function (p) {
        var y = ((p.y - t * p.speed * 0.06) % 1 + 1) % 1 * h;
        ctx.fillStyle = 'rgba(' + rgb + ',0.16)';
        ctx.fillRect(p.x * w, y, p.size, p.size);
      });
    },
    overcast: function (ctx, w, h, t, particles, rgb) {
      particles.slice(0, 18).forEach(function (p) {
        var y = ((p.y + t * p.speed * 0.05) % 1) * h;
        var x = ((p.x + Math.sin(t * 0.2 + p.phase) * 0.02) % 1) * w;
        ctx.fillStyle = 'rgba(' + rgb + ',' + (0.10 + p.size * 0.04).toFixed(3) + ')';
        ctx.fillRect(x, y, p.size, p.size);
      });
    }
  };

  function create(card, icon) {
    if (!card) return null;
    var reduced = global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduced) return null;

    var draw = DRAWERS[icon] || DRAWERS.overcast;
    var canvas = card.querySelector('.world-weather-fx');
    if (!canvas) {
      canvas = document.createElement('canvas');
      canvas.className = 'world-weather-fx';
      canvas.setAttribute('aria-hidden', 'true');
      card.insertBefore(canvas, card.firstChild);
    }
    var ctx = canvas.getContext('2d');
    if (!ctx) return null;

    // Tinted by whatever the card's icon colour resolves to, so each world's
    // variant carries through without this module knowing about worlds at all.
    var colour = getComputedStyle(card).getPropertyValue('--world-weather-icon').trim() || '#70ccdf';
    var rgb = hexToRgbParts(colour);
    var particles = makeParticles(26);
    var width = 0, height = 0, ratio = 1;
    var running = false, visible = false, rafId = null, last = 0, start = 0;

    function resize() {
      var rect = card.getBoundingClientRect();
      ratio = Math.min(global.devicePixelRatio || 1, 2);
      width = Math.max(1, Math.round(rect.width));
      height = Math.max(1, Math.round(rect.height));
      canvas.width = Math.round(width * ratio);
      canvas.height = Math.round(height * ratio);
      ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    }

    function frame(now) {
      if (!running) return;
      rafId = global.requestAnimationFrame(frame);
      if (now - last < FRAME_INTERVAL) return;
      last = now;
      if (!start) start = now;
      // Cleared completely each frame: leaving the previous frame behind is what
      // made the atlas's rims smear before it did the same.
      ctx.clearRect(0, 0, width, height);
      draw(ctx, width, height, (now - start) / 1000, particles, rgb);
    }

    function play() {
      if (running || !visible || global.document.hidden) return;
      running = true;
      last = 0;
      rafId = global.requestAnimationFrame(frame);
    }
    function pause() {
      running = false;
      if (rafId) global.cancelAnimationFrame(rafId);
      rafId = null;
    }

    resize();
    global.addEventListener('resize', resize);
    // The card is not a fixed height: opening the hourly rail grows it. Window
    // resize alone would leave the backing store sized to the old card and the
    // effect stretched over the difference, so watch the element itself.
    if (global.ResizeObserver) {
      new global.ResizeObserver(resize).observe(card);
    }
    global.document.addEventListener('visibilitychange', function () {
      if (global.document.hidden) pause(); else play();
    });
    if (global.IntersectionObserver) {
      new global.IntersectionObserver(function (entries) {
        visible = entries[0].isIntersecting;
        if (visible) play(); else pause();
      }, { threshold: 0.05 }).observe(card);
    } else {
      visible = true;
      play();
    }
    return { play: play, pause: pause };
  }

  function hexToRgbParts(value) {
    var hex = String(value).trim();
    if (hex.charAt(0) === '#') {
      hex = hex.slice(1);
      if (hex.length === 3) hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
      var n = parseInt(hex, 16);
      if (!isNaN(n) && hex.length === 6) {
        return ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255);
      }
    }
    var match = hex.match(/(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/);
    return match ? match[1] + ',' + match[2] + ',' + match[3] : '112,204,223';
  }

  global.WorldWeatherEffects = { create: create };
}(window));
