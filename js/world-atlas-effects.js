/*
 * Cinematic Worlds atlas experience.
 *
 * GSAP owns the scene-level depth transforms. A single native-resolution
 * canvas owns every ambient world effect so the artwork, lock treatment and
 * effects always share the same 1672 x 941 coordinate system.
 */
(function (global) {
  'use strict';

  var WIDTH = 1672;
  var HEIGHT = 941;
  var TAU = Math.PI * 2;
  var FRAME_INTERVAL = 1000 / 24;
  var NEXUS = { x: 836, y: 472 };

  function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
  }

  function wrap(value) {
    return value - Math.floor(value);
  }

  function hashString(value) {
    var hash = 2166136261;
    for (var i = 0; i < value.length; i += 1) {
      hash ^= value.charCodeAt(i);
      hash = Math.imul(hash, 16777619);
    }
    return hash >>> 0;
  }

  function seededRandom(seed) {
    var state = seed >>> 0;
    return function () {
      state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
      return state / 4294967296;
    };
  }

  function parseRgb(value) {
    return String(value || '180, 150, 230').split(',').map(function (part) {
      return clamp(parseInt(part, 10) || 0, 0, 255);
    });
  }

  function rgba(rgb, alpha) {
    return 'rgba(' + rgb[0] + ',' + rgb[1] + ',' + rgb[2] + ',' + alpha.toFixed(3) + ')';
  }

  function buildParticles(slug, count) {
    var random = seededRandom(hashString(slug));
    var particles = [];
    for (var i = 0; i < count; i += 1) {
      particles.push({
        x: random(),
        y: random(),
        size: 0.62 + random() * 2.05,
        speed: 0.18 + random() * 0.72,
        drift: random() * 2 - 1,
        phase: random() * TAU,
        alpha: 0.35 + random() * 0.65
      });
    }
    return particles;
  }

  function buildLightningPath(random, startX, startY, direction, length, segments, jitter) {
    var points = [];
    var normal = direction + Math.PI * 0.5;
    for (var step = 0; step <= segments; step += 1) {
      var progress = step / segments;
      var taper = 0.4 + Math.sin(progress * Math.PI) * 0.85;
      var deviation = step === 0 ? 0 : (random() - 0.5) * jitter * taper;
      points.push({
        x: startX + Math.cos(direction) * length * progress + Math.cos(normal) * deviation,
        y: startY + Math.sin(direction) * length * progress + Math.sin(normal) * deviation
      });
    }
    return points;
  }

  function buildNexusLightningBolts(count) {
    var random = seededRandom(hashString('nexus-cloud-lightning'));
    var bolts = [];
    for (var i = 0; i < count; i += 1) {
      var cloudAngle = Math.PI * (1.08 + random() * 0.84);
      var cloudRadius = 172 + random() * 92;
      var originX = NEXUS.x + Math.cos(cloudAngle) * cloudRadius;
      var originY = NEXUS.y + Math.sin(cloudAngle) * cloudRadius * 0.68;
      var direction = cloudAngle + Math.PI * 0.5 + (random() - 0.5) * 1.2;
      var length = 88 + random() * 74;
      var startX = originX - Math.cos(direction) * length * 0.5;
      var startY = originY - Math.sin(direction) * length * 0.5;
      var points = buildLightningPath(random, startX, startY, direction, length, 14, 27);
      var branches = [];
      var branchCount = 4 + Math.floor(random() * 3);
      for (var branchIndex = 0; branchIndex < branchCount; branchIndex += 1) {
        var forkIndex = 2 + Math.floor(random() * (points.length - 5));
        var fork = points[forkIndex];
        var side = random() > 0.5 ? 1 : -1;
        var branchDirection = direction + side * (0.52 + random() * 0.72);
        branches.push(buildLightningPath(
          random,
          fork.x,
          fork.y,
          branchDirection,
          30 + random() * 42,
          6 + Math.floor(random() * 3),
          18
        ));
      }
      bolts.push({
        points: points,
        branches: branches,
        originX: originX,
        originY: originY,
        period: 5.2 + random() * 7.8,
        duration: 0.48 + random() * 0.14,
        offset: random() * 12,
        cyan: random() > 0.48
      });
    }
    return bolts;
  }

  function withWorldClip(ctx, entry, draw) {
    var point = entry.point;
    ctx.save();
    ctx.beginPath();
    ctx.arc(point.x, point.y, point.r - 4, 0, TAU);
    ctx.clip();
    draw(point);
    ctx.restore();
  }

  function drawSoftGlow(ctx, point, rgb, alpha, radiusScale, yOffset) {
    var gradient = ctx.createRadialGradient(
      point.x,
      point.y + (yOffset || 0),
      2,
      point.x,
      point.y + (yOffset || 0),
      point.r * (radiusScale || 1)
    );
    gradient.addColorStop(0, rgba(rgb, alpha));
    gradient.addColorStop(0.45, rgba(rgb, alpha * 0.42));
    gradient.addColorStop(1, rgba(rgb, 0));
    ctx.fillStyle = gradient;
    ctx.fillRect(point.x - point.r, point.y - point.r, point.r * 2, point.r * 2);
  }

  function eventPulse(time, period, duration, offset) {
    var cycle = wrap((time + (offset || 0)) / period) * period;
    if (cycle >= duration) return 0;
    return Math.sin((cycle / duration) * Math.PI);
  }

  function eventProgress(time, period, duration, offset) {
    var cycle = wrap((time + (offset || 0)) / period) * period;
    return cycle < duration ? cycle / duration : -1;
  }

  function drawHoverZoom(ctx, image, entry, activity) {
    if (!image || !image.naturalWidth || activity < 0.01) return;
    var point = entry.point;
    var scale = 1 + activity * 0.028;
    var sourceRadius = point.r / scale;
    ctx.save();
    ctx.beginPath();
    ctx.arc(point.x, point.y, point.r - 4, 0, TAU);
    ctx.clip();
    ctx.globalAlpha = clamp(activity * 1.2, 0, 1);
    ctx.drawImage(
      image,
      point.x - sourceRadius,
      point.y - sourceRadius,
      sourceRadius * 2,
      sourceRadius * 2,
      point.x - point.r,
      point.y - point.r,
      point.r * 2,
      point.r * 2
    );
    ctx.restore();
  }

  function drawWorldRim(ctx, entry, time, activity) {
    var point = entry.point;
    var pulse = 0.5 + Math.sin(time * 1.45 + hashString(entry.slug) * 0.00001) * 0.5;
    var alpha = 0.07 + activity * (0.25 + pulse * 0.13);
    ctx.save();
    ctx.strokeStyle = rgba(entry.rgb, alpha);
    ctx.lineWidth = 1.5 + activity * 2.4;
    ctx.shadowColor = rgba(entry.rgb, 0.72);
    ctx.shadowBlur = 4 + activity * 15;
    ctx.beginPath();
    ctx.arc(point.x, point.y, point.r - 5, 0, TAU);
    ctx.stroke();
    ctx.restore();
  }

  function drawNexusWisps(ctx, time, pulse) {
    ctx.save();
    ctx.globalCompositeOperation = 'lighter';
    for (var i = 0; i < 10; i += 1) {
      var ring = 78 + (i % 4) * 43;
      var direction = i % 2 ? -1 : 1;
      var angle = i / 10 * TAU + time * (0.012 + (i % 3) * 0.004) * direction;
      var x = NEXUS.x + Math.cos(angle) * ring;
      var y = NEXUS.y + Math.sin(angle) * ring * 0.68;
      var radius = 28 + (i % 3) * 11;
      var color = i % 3 === 1 ? '73,183,255' : '179,79,255';
      var alpha = 0.032 + pulse * 0.018 + (i % 2) * 0.008;
      var wisp = ctx.createRadialGradient(x, y, 1, x, y, radius);
      wisp.addColorStop(0, 'rgba(' + color + ',' + alpha.toFixed(3) + ')');
      wisp.addColorStop(0.45, 'rgba(' + color + ',' + (alpha * 0.42).toFixed(3) + ')');
      wisp.addColorStop(1, 'rgba(' + color + ',0)');
      ctx.fillStyle = wisp;
      ctx.fillRect(x - radius, y - radius, radius * 2, radius * 2);
    }
    ctx.restore();
  }

  function drawNexusLightning(ctx, time, bolts) {
    ctx.save();
    ctx.globalCompositeOperation = 'lighter';
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    bolts.forEach(function (bolt) {
      var progress = eventProgress(time, bolt.period, bolt.duration, bolt.offset);
      if (progress < 0) return;
      var firstFlash = Math.exp(-Math.pow((progress - 0.11) / 0.08, 2));
      var secondFlash = 0.88 * Math.exp(-Math.pow((progress - 0.49) / 0.105, 2));
      var afterGlow = progress > 0.55 ? 0.2 * (1 - progress) / 0.45 : 0;
      var intensity = clamp(firstFlash + secondFlash + afterGlow, 0, 1);
      if (intensity < 0.025) return;
      var glowColor = bolt.cyan ? '91,196,255' : '185,101,255';
      var cloudGlow = ctx.createRadialGradient(
        bolt.originX,
        bolt.originY,
        2,
        bolt.originX,
        bolt.originY,
        96
      );
      cloudGlow.addColorStop(0, 'rgba(' + glowColor + ',' + (0.28 * intensity).toFixed(3) + ')');
      cloudGlow.addColorStop(0.42, 'rgba(' + glowColor + ',' + (0.11 * intensity).toFixed(3) + ')');
      cloudGlow.addColorStop(1, 'rgba(' + glowColor + ',0)');
      ctx.fillStyle = cloudGlow;
      ctx.fillRect(bolt.originX - 96, bolt.originY - 96, 192, 192);

      function trace(points) {
        ctx.beginPath();
        points.forEach(function (point, index) {
          if (index === 0) ctx.moveTo(point.x, point.y);
          else ctx.lineTo(point.x, point.y);
        });
      }

      trace(bolt.points);
      ctx.strokeStyle = 'rgba(' + glowColor + ',' + (0.44 * intensity).toFixed(3) + ')';
      ctx.lineWidth = 8;
      ctx.shadowColor = 'rgba(' + glowColor + ',0.7)';
      ctx.shadowBlur = 22;
      ctx.stroke();
      trace(bolt.points);
      ctx.strokeStyle = 'rgba(238,247,255,' + (0.98 * intensity).toFixed(3) + ')';
      ctx.lineWidth = 1.45;
      ctx.shadowBlur = 5;
      ctx.stroke();
      bolt.branches.forEach(function (branch) {
        trace(branch);
        ctx.strokeStyle = 'rgba(' + glowColor + ',' + (0.34 * intensity).toFixed(3) + ')';
        ctx.lineWidth = 4;
        ctx.shadowBlur = 12;
        ctx.stroke();
        trace(branch);
        ctx.strokeStyle = 'rgba(232,243,255,' + (0.84 * intensity).toFixed(3) + ')';
        ctx.lineWidth = 0.82;
        ctx.shadowBlur = 5;
        ctx.stroke();
      });
    });
    ctx.restore();
  }

  function drawNexusStorm(ctx, time, particles, lightningBolts) {
    var pulse = 0.5 + Math.sin(time * 0.42) * 0.5;
    var core = ctx.createRadialGradient(NEXUS.x, NEXUS.y, 4, NEXUS.x, NEXUS.y, 118);
    core.addColorStop(0, 'rgba(255,205,104,' + (0.10 + pulse * 0.055).toFixed(3) + ')');
    core.addColorStop(0.2, 'rgba(116,173,255,' + (0.075 + pulse * 0.035).toFixed(3) + ')');
    core.addColorStop(0.58, 'rgba(151,70,234,' + (0.035 + pulse * 0.025).toFixed(3) + ')');
    core.addColorStop(1, 'rgba(91,42,155,0)');
    ctx.fillStyle = core;
    ctx.fillRect(NEXUS.x - 120, NEXUS.y - 120, 240, 240);

    drawNexusWisps(ctx, time, pulse);

    particles.forEach(function (particle, index) {
      var travel = wrap(particle.x - time * (0.008 + particle.speed * 0.007));
      var radius = 34 + travel * 238;
      var angle = particle.phase + time * (index % 2 ? -0.055 : 0.07) + travel * 3.4;
      var x = NEXUS.x + Math.cos(angle) * radius;
      var y = NEXUS.y + Math.sin(angle) * radius * 0.68;
      var alpha = (1 - travel) * particle.alpha * 0.24;
      ctx.fillStyle = index % 3 === 0
        ? 'rgba(255,198,102,' + alpha.toFixed(3) + ')'
        : 'rgba(155,115,255,' + alpha.toFixed(3) + ')';
      ctx.beginPath();
      ctx.arc(x, y, 0.8 + particle.size * 0.62, 0, TAU);
      ctx.fill();
    });
    drawNexusLightning(ctx, time, lightningBolts);
  }

  function drawNeoh(ctx, image, entry, time, strength, particles) {
    var point = entry.point;
    var cycle = (time + 1.3) % 6.8;
    var burst = cycle < 0.78 ? Math.sin((cycle / 0.78) * Math.PI) : 0;
    drawSoftGlow(ctx, point, entry.rgb, (0.095 + burst * 0.025) * strength, 0.96, 0);

    ctx.lineWidth = 0.7;
    for (var y = point.y - point.r; y < point.y + point.r; y += 10) {
      ctx.strokeStyle = 'rgba(187,115,255,' + (0.028 * strength).toFixed(3) + ')';
      ctx.beginPath();
      ctx.moveTo(point.x - point.r, y);
      ctx.lineTo(point.x + point.r, y);
      ctx.stroke();
    }

    particles.slice(0, 7).forEach(function (particle, index) {
      var pulse = 0.5 + Math.sin(time * (0.55 + particle.speed * 0.2) + particle.phase) * 0.5;
      ctx.fillStyle = index % 2 ? rgba(entry.rgb, 0.062 * pulse * strength) : 'rgba(81,218,255,' + (0.048 * pulse * strength).toFixed(3) + ')';
      ctx.fillRect(
        point.x - point.r + particle.x * point.r * 2,
        point.y - point.r + particle.y * point.r * 2,
        1 + particle.size,
        7 + particle.size * 5
      );
    });

    if (!burst || !image || !image.naturalWidth) return;
    for (var i = 0; i < 4; i += 1) {
      var sliceY = Math.round(point.y - 52 + i * 27 + Math.sin(i * 2.1) * 5);
      var sliceHeight = 3 + (i % 3) * 2;
      var shift = (i % 2 ? -1 : 1) * (2.5 + burst * 4.5);
      ctx.save();
      ctx.globalAlpha = 0.11 * burst * strength;
      ctx.drawImage(
        image,
        point.x - point.r,
        sliceY,
        point.r * 2,
        sliceHeight,
        point.x - point.r + shift,
        sliceY,
        point.r * 2,
        sliceHeight
      );
      ctx.fillStyle = i % 2 ? 'rgba(63,220,255,0.2)' : 'rgba(208,64,255,0.22)';
      ctx.fillRect(point.x - point.r + shift, sliceY, point.r * 2, sliceHeight);
      ctx.restore();
    }
  }

  function drawHighHammer(ctx, image, entry, time, strength, particles) {
    var point = entry.point;
    var flare = eventPulse(time, 6.4, 1.35, 0.8);
    drawSoftGlow(ctx, point, [218, 126, 55], (0.105 + flare * 0.045) * strength, 0.98, point.r * 0.48);
    particles.slice(0, 13).forEach(function (particle) {
      var progress = wrap(particle.y + time * particle.speed * 0.055);
      var x = point.x + (particle.x * 2 - 1) * point.r * 0.72 + Math.sin(time + particle.phase) * 3;
      var y = point.y + point.r * 0.78 - progress * point.r * 1.55;
      ctx.fillStyle = 'rgba(255,160,66,' + ((0.21 + flare * 0.12) * particle.alpha * strength * (1 - progress)).toFixed(3) + ')';
      ctx.beginPath();
      ctx.ellipse(x, y, 0.7 + particle.size * 0.42, 1.1 + particle.size * 0.75, particle.drift * 0.2, 0, TAU);
      ctx.fill();
    });
    ctx.strokeStyle = 'rgba(218,205,186,' + ((0.052 + flare * 0.035) * strength).toFixed(3) + ')';
    ctx.lineWidth = 3;
    for (var i = 0; i < 2; i += 1) {
      var steamX = point.x - 20 + i * 34;
      var steamY = point.y - 10 - wrap(time * 0.025 + i * 0.43) * 45;
      ctx.beginPath();
      ctx.bezierCurveTo(steamX - 8, steamY + 18, steamX + 10, steamY + 3, steamX, steamY - 18);
      ctx.stroke();
    }
    if (flare > 0) {
      for (var spark = 0; spark < 4; spark += 1) {
        var sparkX = point.x - 24 + spark * 16;
        var sparkY = point.y + 27 - spark * 4;
        ctx.fillStyle = 'rgba(255,209,116,' + (0.42 * flare * strength).toFixed(3) + ')';
        ctx.beginPath();
        ctx.arc(sparkX, sparkY, 1 + (spark % 2) * 0.7, 0, TAU);
        ctx.fill();
      }
    }
  }

  function drawCerius(ctx, image, entry, time, strength, particles) {
    var point = entry.point;
    var flare = eventPulse(time, 5.9, 1.45, 2.1);
    drawSoftGlow(ctx, point, [233, 71, 42], (0.125 + flare * 0.07) * strength, 1, point.r * 0.58);
    particles.slice(0, 12).forEach(function (particle, index) {
      var fall = wrap(particle.y + time * (0.018 + particle.speed * 0.017));
      var ashX = point.x + (particle.x * 2 - 1) * point.r * 0.82 + Math.sin(time * 0.35 + particle.phase) * 6;
      var ashY = point.y - point.r + fall * point.r * 2;
      ctx.fillStyle = 'rgba(198,187,177,' + (0.15 * particle.alpha * strength).toFixed(3) + ')';
      ctx.beginPath();
      ctx.arc(ashX, ashY, particle.size * 0.8, 0, TAU);
      ctx.fill();

      if (index > 5) return;
      var rise = wrap(particle.y + time * (0.045 + particle.speed * 0.045));
      var emberX = point.x + (particle.x * 2 - 1) * point.r * 0.68 + Math.sin(time + particle.phase) * 3;
      var emberY = point.y + point.r * 0.75 - rise * point.r * 1.35;
      ctx.fillStyle = 'rgba(255,103,38,' + ((0.38 + flare * 0.22) * particle.alpha * strength * (1 - rise)).toFixed(3) + ')';
      ctx.fillRect(emberX, emberY, 1.2 + particle.size * 0.55, 2.2 + particle.size);
    });
    if (flare > 0) drawSoftGlow(ctx, point, [255, 114, 36], 0.12 * flare * strength, 0.64, point.r * 0.48);
  }

  function drawReanium(ctx, image, entry, time, strength, particles) {
    var point = entry.point;
    var pulse = 0.5 + Math.sin(time * 0.72) * 0.5;
    var surge = eventPulse(time, 6.7, 1.5, 3.2);
    drawSoftGlow(ctx, point, [130, 255, 49], (0.082 + pulse * 0.055 + surge * 0.055) * strength, 1, 0);
    for (var i = 0; i < 2; i += 1) {
      var progress = wrap(time * 0.045 + i * 0.5);
      ctx.strokeStyle = 'rgba(151,255,75,' + ((0.16 + surge * 0.12) * (1 - progress) * strength).toFixed(3) + ')';
      ctx.lineWidth = 1.4 + surge;
      ctx.beginPath();
      ctx.arc(point.x, point.y, 24 + progress * 48, 0, TAU);
      ctx.stroke();
    }
    particles.slice(0, 11).forEach(function (particle) {
      var x = point.x + (particle.x * 2 - 1) * point.r * 0.78 + Math.sin(time * 0.34 + particle.phase) * 5;
      var y = point.y + (particle.y * 2 - 1) * point.r * 0.78 - Math.cos(time * 0.28 + particle.phase) * 4;
      ctx.fillStyle = 'rgba(174,255,91,' + ((0.17 + surge * 0.1) * particle.alpha * strength).toFixed(3) + ')';
      ctx.beginPath();
      ctx.arc(x, y, particle.size, 0, TAU);
      ctx.fill();
    });
  }

  function drawAsmecu(ctx, image, entry, time, strength, particles) {
    var point = entry.point;
    var surge = eventPulse(time, 7.1, 1.55, 1.1);
    drawSoftGlow(ctx, point, [52, 172, 255], (0.092 + surge * 0.045) * strength, 1, point.r * 0.24);
    ctx.lineWidth = 1.2;
    for (var row = -2; row <= 2; row += 1) {
      var baseY = point.y + row * 16 + Math.sin(time * 0.52 + row) * 3;
      ctx.strokeStyle = 'rgba(91,207,255,' + ((0.052 + (row === 0 ? 0.038 : 0) + surge * (row === 0 ? 0.09 : 0.025)) * strength).toFixed(3) + ')';
      ctx.beginPath();
      for (var x = point.x - point.r; x <= point.x + point.r; x += 5) {
        var waveY = baseY + Math.sin(x * 0.075 + time * 0.8 + row) * 3.5;
        if (x === point.x - point.r) ctx.moveTo(x, waveY); else ctx.lineTo(x, waveY);
      }
      ctx.stroke();
    }
    particles.slice(0, 9).forEach(function (particle) {
      var rise = wrap(particle.y + time * (0.022 + particle.speed * 0.022));
      var x = point.x + (particle.x * 2 - 1) * point.r * 0.7 + Math.sin(time * 0.4 + particle.phase) * 2;
      var y = point.y + point.r * 0.8 - rise * point.r * 1.55;
      ctx.strokeStyle = 'rgba(165,229,255,' + ((0.17 + surge * 0.09) * particle.alpha * strength).toFixed(3) + ')';
      ctx.lineWidth = 0.8;
      ctx.beginPath();
      ctx.arc(x, y, 1.2 + particle.size, 0, TAU);
      ctx.stroke();
    });
  }

  function drawBabki(ctx, image, entry, time, strength, particles) {
    var point = entry.point;
    var gust = eventPulse(time, 6.1, 2.25, 1.4);
    var breeze = 0.5 + Math.sin(time * 0.48) * 0.5;
    drawSoftGlow(ctx, point, [91, 196, 91], (0.075 + gust * 0.055) * strength, 0.98, -8);
    particles.slice(0, 16).forEach(function (particle, index) {
      var fall = wrap(particle.y + time * (0.026 + particle.speed * 0.032));
      var wind = 9 + breeze * 10 + gust * 15;
      var x = point.x + (particle.x * 2 - 1) * point.r * 0.76 +
        Math.sin(time * (0.62 + particle.speed * 0.22) + particle.phase) * wind +
        (fall - 0.5) * (10 + gust * 13);
      var y = point.y - point.r * 0.92 + fall * point.r * 1.84;
      var leafLength = 2.8 + particle.size * 1.45;
      var leafWidth = 1.15 + particle.size * 0.5;
      ctx.save();
      ctx.translate(x, y);
      ctx.rotate(particle.phase + time * (0.72 + particle.speed * 0.52) + Math.sin(time + particle.phase) * 0.3);
      ctx.fillStyle = index % 3 === 0
        ? 'rgba(226,198,91,' + ((0.27 + gust * 0.13) * particle.alpha * strength).toFixed(3) + ')'
        : 'rgba(139,222,105,' + ((0.25 + gust * 0.12) * particle.alpha * strength).toFixed(3) + ')';
      ctx.beginPath();
      ctx.moveTo(0, -leafLength);
      ctx.bezierCurveTo(leafWidth, -leafLength * 0.42, leafWidth, leafLength * 0.42, 0, leafLength);
      ctx.bezierCurveTo(-leafWidth, leafLength * 0.42, -leafWidth, -leafLength * 0.42, 0, -leafLength);
      ctx.fill();
      ctx.restore();
    });
  }

  function drawSed(ctx, image, entry, time, strength, particles) {
    var point = entry.point;
    var sunX = point.x - point.r * 0.12;
    var sunY = point.y - point.r * 0.1;
    var glare = 0.82 + Math.sin(time * 0.62) * 0.08;
    var sun = ctx.createRadialGradient(sunX, sunY, 0, sunX, sunY, point.r * 0.92);
    sun.addColorStop(0, 'rgba(255,255,235,' + Math.min(1, 0.86 * strength).toFixed(3) + ')');
    sun.addColorStop(0.08, 'rgba(255,235,146,' + Math.min(1, 0.76 * strength).toFixed(3) + ')');
    sun.addColorStop(0.26, 'rgba(255,151,39,' + (0.42 * glare * strength).toFixed(3) + ')');
    sun.addColorStop(0.58, 'rgba(224,55,19,' + (0.18 * glare * strength).toFixed(3) + ')');
    sun.addColorStop(1, 'rgba(126,15,14,0)');
    ctx.fillStyle = sun;
    ctx.fillRect(point.x - point.r, point.y - point.r, point.r * 2, point.r * 2);

    ctx.save();
    ctx.globalCompositeOperation = 'lighter';
    ctx.strokeStyle = 'rgba(255,225,145,' + (0.13 * glare * strength).toFixed(3) + ')';
    ctx.shadowColor = 'rgba(255,151,53,0.88)';
    ctx.shadowBlur = 8;
    ctx.lineWidth = 0.8;
    for (var rayIndex = 0; rayIndex < 18; rayIndex += 1) {
      var rayAngle = rayIndex / 18 * TAU + Math.sin(time * 0.15) * 0.025;
      var rayStart = point.r * (0.1 + (rayIndex % 3) * 0.025);
      var rayEnd = point.r * (0.47 + (rayIndex % 4) * 0.08);
      ctx.beginPath();
      ctx.moveTo(sunX + Math.cos(rayAngle) * rayStart, sunY + Math.sin(rayAngle) * rayStart);
      ctx.lineTo(sunX + Math.cos(rayAngle) * rayEnd, sunY + Math.sin(rayAngle) * rayEnd);
      ctx.stroke();
    }
    ctx.restore();

    var crackCycle = wrap((time + 1.8) / 9.2);
    var crackReveal = clamp(crackCycle / 0.46, 0, 1);
    var crackFade = crackCycle > 0.74 ? 1 - (crackCycle - 0.74) / 0.26 : 1;
    var crackAlpha = crackReveal * clamp(crackFade, 0, 1) * strength;
    var crackPaths = [
      [[-0.08, -0.03], [-0.22, -0.19], [-0.34, -0.31], [-0.49, -0.35], [-0.64, -0.52]],
      [[-0.18, -0.16], [-0.1, -0.34], [-0.17, -0.48]],
      [[-0.06, 0.01], [0.1, -0.12], [0.27, -0.09], [0.42, -0.22], [0.62, -0.17]],
      [[0.25, -0.09], [0.3, 0.08], [0.47, 0.18], [0.55, 0.36]],
      [[-0.04, 0.02], [-0.16, 0.18], [-0.1, 0.34], [-0.25, 0.49], [-0.2, 0.68]],
      [[-0.12, 0.27], [-0.34, 0.3], [-0.47, 0.43]],
      [[0.02, 0.03], [0.14, 0.2], [0.08, 0.39], [0.25, 0.55], [0.22, 0.7]],
      [[0.13, 0.21], [0.34, 0.25], [0.48, 0.42], [0.66, 0.47]]
    ];
    ctx.save();
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    crackPaths.forEach(function (path, pathIndex) {
      var localReveal = clamp(crackReveal * 1.28 - pathIndex * 0.045, 0, 1);
      var visibleSegments = Math.max(1, Math.ceil((path.length - 1) * localReveal));
      if (localReveal <= 0) return;
      ctx.beginPath();
      path.slice(0, visibleSegments + 1).forEach(function (node, nodeIndex) {
        var x = sunX + node[0] * point.r;
        var y = sunY + node[1] * point.r;
        if (nodeIndex === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);
      });
      ctx.strokeStyle = 'rgba(35,5,8,' + Math.min(0.82, crackAlpha * 0.54).toFixed(3) + ')';
      ctx.lineWidth = 2.2;
      ctx.stroke();
      ctx.strokeStyle = 'rgba(255,194,92,' + Math.min(0.78, crackAlpha * 0.48).toFixed(3) + ')';
      ctx.shadowColor = 'rgba(255,102,42,0.9)';
      ctx.shadowBlur = 5;
      ctx.lineWidth = 0.75;
      ctx.stroke();
    });
    ctx.restore();
  }

  function drawGeof(ctx, image, entry, time, strength, particles) {
    var point = entry.point;
    var gust = eventPulse(time, 7.8, 1.8, 5.1);
    var fog = ctx.createLinearGradient(point.x, point.y - point.r, point.x, point.y + point.r);
    fog.addColorStop(0, 'rgba(190,212,229,0)');
    fog.addColorStop(0.72, 'rgba(190,212,229,' + ((0.085 + gust * 0.08) * strength).toFixed(3) + ')');
    fog.addColorStop(1, 'rgba(190,212,229,0)');
    ctx.fillStyle = fog;
    ctx.fillRect(point.x - point.r, point.y - point.r, point.r * 2, point.r * 2);
    particles.slice(0, 15).forEach(function (particle) {
      var fall = wrap(particle.y + time * (0.035 + particle.speed * 0.035));
      var x = point.x + (particle.x * 2 - 1) * point.r * 0.9;
      var y = point.y - point.r + fall * point.r * 2;
      ctx.strokeStyle = 'rgba(194,220,237,' + ((0.11 + gust * 0.08) * particle.alpha * strength).toFixed(3) + ')';
      ctx.lineWidth = 0.7;
      ctx.beginPath();
      ctx.moveTo(x, y);
      ctx.lineTo(x - 2, y + 6 + particle.size * 2);
      ctx.stroke();
    });
  }

  function drawBeoctica(ctx, image, entry, time, strength, particles) {
    var point = entry.point;
    var frost = eventPulse(time, 7.2, 1.5, 3.7);
    if (image && image.naturalWidth) {
      var search = 0.5 - Math.cos(time * 0.18) * 0.5;
      var scale = 1.025 + search * 0.09 + entry.activity * 0.015;
      var sourceRadius = point.r / scale;
      var lookX = Math.sin(time * 0.13) * point.r * 0.055;
      var lookY = Math.cos(time * 0.105) * point.r * 0.038;
      ctx.save();
      ctx.globalAlpha = 0.98;
      ctx.drawImage(
        image,
        point.x - sourceRadius + lookX,
        point.y - sourceRadius + lookY,
        sourceRadius * 2,
        sourceRadius * 2,
        point.x - point.r,
        point.y - point.r,
        point.r * 2,
        point.r * 2
      );
      ctx.restore();
    }
    drawSoftGlow(ctx, point, [222, 237, 255], (0.082 + frost * 0.055) * strength, 0.96, 0);
    particles.slice(0, 13).forEach(function (particle, index) {
      var fall = wrap(particle.y + time * (0.008 + particle.speed * 0.014));
      var x = point.x + (particle.x * 2 - 1) * point.r * 0.82 + Math.sin(time * 0.2 + particle.phase) * 4;
      var y = point.y - point.r + fall * point.r * 2;
      var twinkle = 0.45 + Math.sin(time * (0.5 + particle.speed) + particle.phase) * 0.45;
      ctx.fillStyle = 'rgba(235,246,255,' + ((0.18 + frost * 0.12) * particle.alpha * twinkle * strength).toFixed(3) + ')';
      ctx.beginPath();
      ctx.arc(x, y, 0.6 + particle.size * 0.55, 0, TAU);
      ctx.fill();
      if (index > 2 || twinkle < 0.72) return;
      ctx.strokeStyle = 'rgba(255,255,255,' + ((0.19 + frost * 0.18) * twinkle * strength).toFixed(3) + ')';
      ctx.beginPath();
      ctx.moveTo(x - 3, y);
      ctx.lineTo(x + 3, y);
      ctx.moveTo(x, y - 3);
      ctx.lineTo(x, y + 3);
      ctx.stroke();
    });
  }

  function drawTerek(ctx, image, entry, time, strength, particles) {
    var point = entry.point;
    var blastPulse = 0;
    particles.slice(0, 6).forEach(function (particle, index) {
      var blastProgress = eventProgress(time, 3.6 + index * 0.43, 1.12, particle.phase + index * 0.71);
      if (blastProgress < 0) return;
      blastPulse = Math.max(blastPulse, Math.sin(blastProgress * Math.PI));
    });
    if (image && image.naturalWidth && entry.activity > 0.01) {
      var shake = entry.activity * (1.05 + blastPulse * 1.85);
      var offsetX = (Math.sin(time * 31) + Math.sin(time * 17.7)) * shake;
      var offsetY = (Math.cos(time * 27.2) + Math.sin(time * 14.5)) * shake * 0.58;
      ctx.save();
      ctx.globalAlpha = 0.72 * entry.activity;
      ctx.drawImage(
        image,
        point.x - point.r,
        point.y - point.r,
        point.r * 2,
        point.r * 2,
        point.x - point.r + offsetX,
        point.y - point.r + offsetY,
        point.r * 2,
        point.r * 2
      );
      ctx.restore();
    }
    particles.slice(0, 6).forEach(function (particle, index) {
      var progress = eventProgress(time, 3.6 + index * 0.43, 1.12, particle.phase + index * 0.71);
      if (progress < 0) return;
      var blast = Math.sin(progress * Math.PI);
      var blastX = point.x + (particle.x * 2 - 1) * point.r * 0.62;
      var blastY = point.y - point.r * 0.58 + particle.y * point.r * 0.9;
      var radius = 4 + progress * (13 + particle.size * 3.5);
      ctx.save();
      ctx.globalCompositeOperation = 'lighter';
      var fireball = ctx.createRadialGradient(blastX, blastY, 0, blastX, blastY, radius);
      fireball.addColorStop(0, 'rgba(255,255,224,' + Math.min(0.96, blast * strength).toFixed(3) + ')');
      fireball.addColorStop(0.16, 'rgba(255,203,71,' + Math.min(0.9, blast * particle.alpha * strength).toFixed(3) + ')');
      fireball.addColorStop(0.43, 'rgba(255,72,22,' + Math.min(0.72, blast * particle.alpha * strength * 0.76).toFixed(3) + ')');
      fireball.addColorStop(0.72, 'rgba(145,18,17,' + Math.min(0.38, blast * strength * 0.34).toFixed(3) + ')');
      fireball.addColorStop(1, 'rgba(68,7,10,0)');
      ctx.fillStyle = fireball;
      ctx.fillRect(blastX - radius, blastY - radius, radius * 2, radius * 2);
      ctx.restore();

      if (progress < 0.24) return;
      var smokeProgress = (progress - 0.24) / 0.76;
      var smokeRadius = radius * (0.65 + smokeProgress * 0.85);
      var smoke = ctx.createRadialGradient(
        blastX,
        blastY - smokeProgress * 8,
        smokeRadius * 0.12,
        blastX,
        blastY - smokeProgress * 8,
        smokeRadius
      );
      smoke.addColorStop(0, 'rgba(49,38,43,' + ((1 - smokeProgress) * 0.3 * strength).toFixed(3) + ')');
      smoke.addColorStop(0.56, 'rgba(24,20,28,' + ((1 - smokeProgress) * 0.2 * strength).toFixed(3) + ')');
      smoke.addColorStop(1, 'rgba(13,12,17,0)');
      ctx.fillStyle = smoke;
      ctx.fillRect(blastX - smokeRadius, blastY - smokeRadius - 8, smokeRadius * 2, smokeRadius * 2);
    });
  }

  function drawValerium(ctx, image, entry, time, strength, particles) {
    var point = entry.point;
    var revelation = eventPulse(time, 7.9, 2.65, 4.9);
    var breath = 0.5 + Math.sin(time * 0.46) * 0.5;
    drawSoftGlow(ctx, point, [255, 216, 118], (0.12 + breath * 0.035 + revelation * 0.105) * strength, 1, -8);
    ctx.save();
    ctx.globalCompositeOperation = 'lighter';
    ctx.translate(point.x, point.y - 6);
    ctx.lineCap = 'round';
    for (var rayIndex = 0; rayIndex < 12; rayIndex += 1) {
      var angle = rayIndex / 12 * TAU + time * 0.018;
      var rayLength = point.r * (0.48 + (rayIndex % 3) * 0.12 + revelation * 0.1);
      var startRadius = 9 + (rayIndex % 2) * 4;
      var rayAlpha = (0.055 + breath * 0.028 + revelation * 0.13) * strength * (rayIndex % 2 ? 0.72 : 1);
      ctx.strokeStyle = 'rgba(255,226,151,' + rayAlpha.toFixed(3) + ')';
      ctx.shadowColor = 'rgba(255,208,92,0.82)';
      ctx.shadowBlur = 6 + revelation * 8;
      ctx.lineWidth = 0.7 + (rayIndex % 3) * 0.28;
      ctx.beginPath();
      ctx.moveTo(Math.cos(angle) * startRadius, Math.sin(angle) * startRadius);
      ctx.lineTo(Math.cos(angle) * rayLength, Math.sin(angle) * rayLength);
      ctx.stroke();
    }
    var halo = ctx.createRadialGradient(0, 0, 1, 0, 0, 29 + revelation * 9);
    halo.addColorStop(0, 'rgba(255,248,211,' + ((0.31 + revelation * 0.25) * strength).toFixed(3) + ')');
    halo.addColorStop(0.18, 'rgba(255,215,117,' + ((0.18 + revelation * 0.18) * strength).toFixed(3) + ')');
    halo.addColorStop(1, 'rgba(255,196,79,0)');
    ctx.fillStyle = halo;
    ctx.fillRect(-42, -42, 84, 84);
    ctx.restore();
    particles.slice(0, 12).forEach(function (particle) {
      var rise = wrap(particle.y + time * (0.012 + particle.speed * 0.018));
      var x = point.x + (particle.x * 2 - 1) * point.r * 0.76 + Math.sin(time * 0.24 + particle.phase) * 4;
      var y = point.y + point.r * 0.72 - rise * point.r * 1.42;
      ctx.fillStyle = 'rgba(255,218,118,' + ((0.22 + revelation * 0.16) * particle.alpha * strength * (1 - rise * 0.4)).toFixed(3) + ')';
      ctx.beginPath();
      ctx.arc(x, y, 0.8 + particle.size * 0.65, 0, TAU);
      ctx.fill();
    });
  }

  function drawVermillia(ctx, image, entry, time, strength, particles) {
    var point = entry.point;
    var downpour = eventPulse(time, 6.9, 2.25, 2.8);
    drawSoftGlow(ctx, point, [154, 190, 218], (0.085 + downpour * 0.055) * strength, 1, point.r * 0.18);
    particles.slice(0, 18).forEach(function (particle, index) {
      var fall = wrap(particle.y + time * (0.075 + particle.speed * 0.068));
      var x = point.x + (particle.x * 2 - 1) * point.r * 0.88 + Math.sin(time * 0.42 + particle.phase) * 3;
      var y = point.y - point.r * 0.94 + fall * point.r * 1.88;
      var length = 5 + particle.size * 3.5 + downpour * 3;
      ctx.strokeStyle = index % 4 === 0
        ? 'rgba(239,187,117,' + ((0.22 + downpour * 0.16) * particle.alpha * strength).toFixed(3) + ')'
        : 'rgba(191,220,239,' + ((0.24 + downpour * 0.17) * particle.alpha * strength).toFixed(3) + ')';
      ctx.lineWidth = 0.55 + particle.size * 0.22;
      ctx.beginPath();
      ctx.moveTo(x - 1.8, y - length);
      ctx.lineTo(x + 1.2, y);
      ctx.stroke();
      if (fall < 0.84) return;
      var ripple = (fall - 0.84) / 0.16;
      ctx.strokeStyle = 'rgba(198,222,237,' + ((1 - ripple) * 0.16 * strength).toFixed(3) + ')';
      ctx.lineWidth = 0.55;
      ctx.beginPath();
      ctx.ellipse(x, point.y + point.r * 0.72, 2 + ripple * 7, 0.8 + ripple * 2.1, 0, 0, TAU);
      ctx.stroke();
    });
  }

  var DRAWERS = {
    'neoh': drawNeoh,
    'high-hammer': drawHighHammer,
    'cerius': drawCerius,
    'reanium': drawReanium,
    'asmecu': drawAsmecu,
    'babki-prime': drawBabki,
    'sed': drawSed,
    'geof-v': drawGeof,
    'beoctica': drawBeoctica,
    'terek-ii': drawTerek,
    'valerium-prime': drawValerium,
    'vermillia-xi': drawVermillia
  };

  function setupDepth(options) {
    var gsap = global.gsap;
    var ScrollTrigger = global.ScrollTrigger;
    var stage = options.stage;
    var scene = options.scene;
    var back = options.back;
    var glow = options.glow;
    var front = options.front;
    if (!gsap || !stage || !scene) return function () {};

    if (ScrollTrigger) gsap.registerPlugin(ScrollTrigger);
    stage.classList.add('has-gsap-depth');
    var media = gsap.matchMedia();
    media.add({
      desktop: '(min-width: 781px) and (pointer: fine)',
      reduce: '(prefers-reduced-motion: reduce)'
    }, function (context) {
      if (context.conditions.reduce) return;
      var cleanups = [];

      if (glow) gsap.set(glow, { xPercent: -50, yPercent: -50, transformOrigin: '50% 50%' });

      if (context.conditions.desktop) {
        var rect = null;
        var sceneX = gsap.quickTo(scene, 'x', { duration: 0.72, ease: 'power3.out' });
        var sceneY = gsap.quickTo(scene, 'y', { duration: 0.72, ease: 'power3.out' });
        var sceneRX = gsap.quickTo(scene, 'rotationX', { duration: 0.82, ease: 'power3.out' });
        var sceneRY = gsap.quickTo(scene, 'rotationY', { duration: 0.82, ease: 'power3.out' });
        var backX = back ? gsap.quickTo(back, 'x', { duration: 1, ease: 'power3.out' }) : function () {};
        var backY = back ? gsap.quickTo(back, 'y', { duration: 1, ease: 'power3.out' }) : function () {};
        var glowX = glow ? gsap.quickTo(glow, 'x', { duration: 0.9, ease: 'power3.out' }) : function () {};
        var glowY = glow ? gsap.quickTo(glow, 'y', { duration: 0.9, ease: 'power3.out' }) : function () {};
        var frontX = front ? gsap.quickTo(front, 'x', { duration: 0.62, ease: 'power3.out' }) : function () {};
        var frontY = front ? gsap.quickTo(front, 'y', { duration: 0.62, ease: 'power3.out' }) : function () {};

        function resetPointer() {
          sceneX(0); sceneY(0); sceneRX(0); sceneRY(0);
          backX(0); backY(0); glowX(0); glowY(0); frontX(0); frontY(0);
        }

        function pointerEnter() {
          rect = stage.getBoundingClientRect();
        }

        function pointerMove(event) {
          if (!rect || event.pointerType === 'touch') return;
          var nx = clamp(((event.clientX - rect.left) / rect.width - 0.5) * 2, -1, 1);
          var ny = clamp(((event.clientY - rect.top) / rect.height - 0.5) * 2, -1, 1);
          sceneX(nx * 5.5); sceneY(ny * 4.2);
          sceneRY(nx * 0.62); sceneRX(ny * -0.46);
          backX(nx * -3.2); backY(ny * -2.4);
          glowX(nx * -8.5); glowY(ny * -6.5);
          frontX(nx * 10.5); frontY(ny * 8);
        }

        stage.addEventListener('pointerenter', pointerEnter);
        stage.addEventListener('pointermove', pointerMove);
        stage.addEventListener('pointerleave', resetPointer);
        global.addEventListener('resize', pointerEnter);
        cleanups.push(function () {
          stage.removeEventListener('pointerenter', pointerEnter);
          stage.removeEventListener('pointermove', pointerMove);
          stage.removeEventListener('pointerleave', resetPointer);
          global.removeEventListener('resize', pointerEnter);
        });
      }

      if (ScrollTrigger) {
        gsap.fromTo(scene, { yPercent: -0.45, scale: 1.012 }, {
          yPercent: 0.45,
          scale: 1,
          ease: 'none',
          scrollTrigger: { trigger: stage, start: 'top bottom', end: 'bottom top', scrub: 0.75 }
        });
        if (back) gsap.fromTo(back, { yPercent: -1.4 }, {
          yPercent: 1.4,
          ease: 'none',
          scrollTrigger: { trigger: stage, start: 'top bottom', end: 'bottom top', scrub: 1 }
        });
        if (front) gsap.fromTo(front, { yPercent: 1.8 }, {
          yPercent: -1.8,
          ease: 'none',
          scrollTrigger: { trigger: stage, start: 'top bottom', end: 'bottom top', scrub: 0.65 }
        });
        if (glow) gsap.fromTo(glow, { scale: 0.94, opacity: 0.3 }, {
          scale: 1.06,
          opacity: 0.5,
          ease: 'none',
          scrollTrigger: { trigger: stage, start: 'top bottom', end: 'bottom top', scrub: 0.9 }
        });
      }

      return function () {
        cleanups.forEach(function (cleanup) { cleanup(); });
      };
    });

    return function () {
      media.revert();
      stage.classList.remove('has-gsap-depth');
    };
  }

  function create(options) {
    options = options || {};
    var stage = options.stage;
    var canvas = options.canvas;
    var image = options.image;
    var cloudVideo = options.cloudVideo;
    var gsap = global.gsap;
    if (!stage || !canvas || !canvas.getContext || !gsap) return null;

    canvas.width = WIDTH;
    canvas.height = HEIGHT;
    var ctx = canvas.getContext('2d', { alpha: true, desynchronized: true });
    var reducedQuery = global.matchMedia('(prefers-reduced-motion: reduce)');
    var hoveredSlug = null;
    var inViewport = false;
    var tabVisible = !document.hidden;
    var ticking = false;
    var lastPaint = 0;
    var destroyed = false;
    var particleCache = {};

    var available = (options.worlds || []).filter(function (world) {
      return world.status === 'available' && options.getPoint(world) && DRAWERS[world.slug];
    }).map(function (world) {
      var tone = options.getTone(world) || { rgb: '180, 150, 230' };
      return {
        slug: world.slug,
        point: options.getPoint(world),
        rgb: parseRgb(tone.rgb),
        activity: 0
      };
    });

    available.forEach(function (entry) {
      particleCache[entry.slug] = buildParticles(entry.slug, 18);
    });
    var nexusParticles = buildParticles('nexus-storm', 24);
    var nexusLightningBolts = buildNexusLightningBolts(8);

    function clearAllCanvas() {
      ctx.clearRect(0, 0, WIDTH, HEIGHT);
    }

    function render() {
      var now = performance.now();
      if (now - lastPaint < FRAME_INTERVAL) return;
      lastPaint = now;
      var time = now / 1000;
      clearAllCanvas();
      drawNexusStorm(ctx, time, nexusParticles, nexusLightningBolts);
      available.forEach(function (entry) {
        var drawer = DRAWERS[entry.slug];
        var target = entry.slug === hoveredSlug ? 1 : 0;
        var response = target > entry.activity ? 0.18 : 0.11;
        entry.activity += (target - entry.activity) * response;
        if (entry.activity < 0.002) entry.activity = 0;
        var strength = 1.05 + entry.activity * 1.05;
        drawHoverZoom(ctx, image, entry, entry.activity);
        withWorldClip(ctx, entry, function () {
          drawer(ctx, image, entry, time, strength, particleCache[entry.slug]);
        });
        drawWorldRim(ctx, entry, time, entry.activity);
      });
    }

    function shouldRun() {
      return !destroyed && inViewport && tabVisible && !reducedQuery.matches;
    }

    function syncCloudVideo(active) {
      if (!cloudVideo) return;
      if (!active) {
        if (!cloudVideo.paused) cloudVideo.pause();
        return;
      }
      if (cloudVideo.paused) {
        var playRequest = cloudVideo.play();
        if (playRequest && playRequest.catch) playRequest.catch(function () {});
      }
    }

    function syncTicker() {
      var active = shouldRun();
      syncCloudVideo(active);
      if (active && !ticking) {
        gsap.ticker.add(render);
        ticking = true;
        return;
      }
      if (!active && ticking) {
        gsap.ticker.remove(render);
        ticking = false;
        clearAllCanvas();
      }
    }

    var observer = null;
    if ('IntersectionObserver' in global) {
      observer = new IntersectionObserver(function (entries) {
        inViewport = entries[0].isIntersecting;
        syncTicker();
      }, { rootMargin: '220px 0px', threshold: 0.01 });
      observer.observe(stage);
    } else {
      // The atlas remains functional in older browsers; only the off-screen
      // rendering optimization is unavailable there.
      inViewport = true;
      syncTicker();
    }

    function handleVisibility() {
      tabVisible = !document.hidden;
      syncTicker();
    }

    function handleMotionPreference() {
      syncTicker();
    }

    document.addEventListener('visibilitychange', handleVisibility);
    if (reducedQuery.addEventListener) reducedQuery.addEventListener('change', handleMotionPreference);
    else reducedQuery.addListener(handleMotionPreference);

    var destroyDepth = setupDepth({
      stage: stage,
      scene: options.scene,
      back: options.back,
      glow: options.glow,
      front: options.front
    });

    return {
      setHovered: function (slug) {
        hoveredSlug = slug || null;
      },
      destroy: function () {
        destroyed = true;
        if (observer) observer.disconnect();
        document.removeEventListener('visibilitychange', handleVisibility);
        if (reducedQuery.removeEventListener) reducedQuery.removeEventListener('change', handleMotionPreference);
        else reducedQuery.removeListener(handleMotionPreference);
        if (ticking) gsap.ticker.remove(render);
        ticking = false;
        if (cloudVideo && !cloudVideo.paused) cloudVideo.pause();
        clearAllCanvas();
        destroyDepth();
      }
    };
  }

  global.WorldAtlasEffects = { create: create };
}(window));
