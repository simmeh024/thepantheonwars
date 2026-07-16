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
  var WORLD_CLEAR_PADDING = 30;
  var NEXUS = { x: 836, y: 472, rx: 292, ry: 218 };

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

  function buildNexusEnergyChannels(count) {
    var random = seededRandom(hashString('nexus-cloud-energy'));
    var channels = [];
    for (var i = 0; i < count; i += 1) {
      channels.push({
        angle: random() * TAU,
        radius: 142 + random() * 118,
        span: 0.58 + random() * 0.62,
        trail: 0.2 + random() * 0.16,
        jitter: 4 + random() * 8,
        period: 6.8 + random() * 6.4,
        duration: 2.6 + random() * 1.7,
        offset: random() * 11,
        drift: (random() > 0.5 ? 1 : -1) * (0.006 + random() * 0.01),
        cyan: random() > 0.48
      });
    }
    return channels;
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

  function drawNexusEnergy(ctx, time, channels) {
    ctx.save();
    ctx.translate(NEXUS.x, NEXUS.y);
    ctx.scale(1, 0.68);
    ctx.globalCompositeOperation = 'lighter';
    channels.forEach(function (channel, channelIndex) {
      var progress = eventProgress(time, channel.period, channel.duration, channel.offset);
      if (progress < 0) return;
      var envelope = Math.sin(progress * Math.PI);
      var travelStart = -channel.span * 0.5 - channel.trail + progress * (channel.span + channel.trail * 2);
      var travelEnd = Math.min(travelStart + channel.trail, channel.span * 0.5);
      travelStart = Math.max(travelStart, -channel.span * 0.5);
      if (travelEnd <= travelStart) return;
      ctx.save();
      ctx.rotate(channel.angle + time * channel.drift);
      ctx.beginPath();
      for (var step = 0; step <= 15; step += 1) {
        var ratio = step / 15;
        var angle = travelStart + (travelEnd - travelStart) * ratio;
        var radius = channel.radius + Math.sin(step * 2.35 + channelIndex * 1.9) * channel.jitter;
        var x = Math.cos(angle) * radius;
        var y = Math.sin(angle) * radius;
        if (step === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
      }
      var glowColor = channel.cyan ? '91,196,255' : '185,101,255';
      ctx.strokeStyle = 'rgba(' + glowColor + ',' + (0.15 * envelope).toFixed(3) + ')';
      ctx.lineWidth = 6;
      ctx.shadowColor = 'rgba(' + glowColor + ',0.7)';
      ctx.shadowBlur = 14;
      ctx.stroke();
      ctx.strokeStyle = 'rgba(218,229,255,' + (0.5 * envelope).toFixed(3) + ')';
      ctx.lineWidth = 1.15;
      ctx.shadowBlur = 5;
      ctx.stroke();
      ctx.restore();
    });
    ctx.restore();
  }

  function drawNexusStorm(ctx, time, particles, energyChannels) {
    var pulse = 0.5 + Math.sin(time * 0.42) * 0.5;
    var core = ctx.createRadialGradient(NEXUS.x, NEXUS.y, 4, NEXUS.x, NEXUS.y, 118);
    core.addColorStop(0, 'rgba(255,205,104,' + (0.10 + pulse * 0.055).toFixed(3) + ')');
    core.addColorStop(0.2, 'rgba(116,173,255,' + (0.075 + pulse * 0.035).toFixed(3) + ')');
    core.addColorStop(0.58, 'rgba(151,70,234,' + (0.035 + pulse * 0.025).toFixed(3) + ')');
    core.addColorStop(1, 'rgba(91,42,155,0)');
    ctx.fillStyle = core;
    ctx.fillRect(NEXUS.x - 120, NEXUS.y - 120, 240, 240);

    ctx.save();
    ctx.translate(NEXUS.x, NEXUS.y);
    ctx.scale(1, 0.68);
    ctx.globalCompositeOperation = 'lighter';
    for (var layer = 0; layer < 3; layer += 1) {
      var direction = layer % 2 ? -1 : 1;
      ctx.save();
      ctx.rotate(time * (0.018 + layer * 0.007) * direction + layer * 1.35);
      ctx.strokeStyle = layer === 1
        ? 'rgba(73,183,255,' + (0.055 + pulse * 0.018).toFixed(3) + ')'
        : 'rgba(179,79,255,' + (0.045 + pulse * 0.018).toFixed(3) + ')';
      ctx.lineWidth = 7 - layer * 1.4;
      ctx.shadowColor = layer === 1 ? 'rgba(61,152,255,0.3)' : 'rgba(174,62,255,0.28)';
      ctx.shadowBlur = 12;
      for (var arm = 0; arm < 3; arm += 1) {
        ctx.beginPath();
        for (var step = 0; step <= 30; step += 1) {
          var radius = 28 + step * (7.5 + layer * 0.55);
          var angle = arm * TAU / 3 + step * (0.105 + layer * 0.008);
          var x = Math.cos(angle) * radius;
          var y = Math.sin(angle) * radius;
          if (step === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        }
        ctx.stroke();
      }
      ctx.restore();
    }
    ctx.restore();

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
    drawNexusEnergy(ctx, time, energyChannels);
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
      ctx.strokeStyle = 'rgba(255,160,66,' + ((0.24 + flare * 0.14) * particle.alpha * strength * (1 - progress)).toFixed(3) + ')';
      ctx.lineWidth = particle.size * 0.75;
      ctx.beginPath();
      ctx.moveTo(x, y + 5 + particle.size * 2);
      ctx.lineTo(x + particle.drift * 2, y);
      ctx.stroke();
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
      ctx.strokeStyle = 'rgba(255,209,116,' + (0.52 * flare * strength).toFixed(3) + ')';
      ctx.lineWidth = 1.4;
      for (var spark = 0; spark < 4; spark += 1) {
        ctx.beginPath();
        ctx.moveTo(point.x - 24 + spark * 16, point.y + 45);
        ctx.lineTo(point.x - 31 + spark * 18, point.y + 20 - spark * 4);
        ctx.stroke();
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
    var bloom = eventPulse(time, 7.6, 1.75, 4.4);
    var shaft = ctx.createLinearGradient(point.x - 36, point.y - point.r, point.x + 34, point.y + point.r);
    shaft.addColorStop(0, 'rgba(205,255,158,' + ((0.11 + bloom * 0.11) * strength).toFixed(3) + ')');
    shaft.addColorStop(1, 'rgba(87,190,98,0)');
    ctx.fillStyle = shaft;
    ctx.beginPath();
    ctx.moveTo(point.x - 42, point.y - point.r);
    ctx.lineTo(point.x - 7, point.y - point.r);
    ctx.lineTo(point.x + 38, point.y + point.r);
    ctx.lineTo(point.x + 8, point.y + point.r);
    ctx.closePath();
    ctx.fill();
    particles.slice(0, 13).forEach(function (particle) {
      var x = point.x + (particle.x * 2 - 1) * point.r * 0.82 + Math.sin(time * 0.27 + particle.phase) * 7;
      var y = point.y + (particle.y * 2 - 1) * point.r * 0.8 + Math.cos(time * 0.22 + particle.phase) * 5;
      ctx.fillStyle = 'rgba(210,241,143,' + ((0.15 + bloom * 0.1) * particle.alpha * strength).toFixed(3) + ')';
      ctx.beginPath();
      ctx.ellipse(x, y, 0.7 + particle.size, 0.4 + particle.size * 0.45, particle.phase + time * 0.08, 0, TAU);
      ctx.fill();
    });
    if (bloom > 0) drawSoftGlow(ctx, point, [132, 236, 111], 0.08 * bloom * strength, 0.8, -16);
  }

  function drawSed(ctx, image, entry, time, strength, particles) {
    var point = entry.point;
    var surge = eventPulse(time, 6.3, 1.4, 2.6);
    drawSoftGlow(ctx, point, [188, 38, 55], (0.105 + surge * 0.06) * strength, 1, point.r * 0.5);
    ctx.lineWidth = 1;
    for (var row = 0; row < 4; row += 1) {
      var y = point.y - 24 + row * 16;
      ctx.strokeStyle = 'rgba(255,91,76,' + ((0.048 + surge * 0.075) * strength).toFixed(3) + ')';
      ctx.beginPath();
      for (var x = point.x - 60; x <= point.x + 60; x += 4) {
        var hazeY = y + Math.sin(x * 0.06 + time * 0.7 + row) * 2.5;
        if (x === point.x - 60) ctx.moveTo(x, hazeY); else ctx.lineTo(x, hazeY);
      }
      ctx.stroke();
    }
    particles.slice(0, 10).forEach(function (particle) {
      var travel = wrap(particle.x + time * (0.012 + particle.speed * 0.018));
      var x = point.x - point.r + travel * point.r * 2;
      var y = point.y + (particle.y * 2 - 1) * point.r * 0.72 + Math.sin(time * 0.25 + particle.phase) * 4;
      ctx.fillStyle = 'rgba(184,91,72,' + ((0.125 + surge * 0.08) * particle.alpha * strength).toFixed(3) + ')';
      ctx.fillRect(x, y, 1 + particle.size, 0.8 + particle.size * 0.5);
    });
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
    var pulse = 0.5 + Math.sin(time * 0.52) * 0.5;
    var alert = eventPulse(time, 6.6, 1.1, 0.5);
    drawSoftGlow(ctx, point, [156, 29, 44], (0.055 + pulse * 0.052 + alert * 0.085) * strength, 1, 0);
    ctx.strokeStyle = 'rgba(226,52,62,' + ((0.12 * pulse + alert * 0.22) * strength).toFixed(3) + ')';
    ctx.lineWidth = 1.3 + alert * 1.6;
    ctx.beginPath();
    ctx.arc(point.x, point.y, 52 + pulse * 10, 0, TAU);
    ctx.stroke();
    particles.slice(0, 8).forEach(function (particle) {
      var rise = wrap(particle.y + time * (0.012 + particle.speed * 0.012));
      var x = point.x + (particle.x * 2 - 1) * point.r * 0.68 + Math.sin(time * 0.16 + particle.phase) * 8;
      var y = point.y + point.r * 0.75 - rise * point.r * 1.4;
      ctx.fillStyle = 'rgba(25,20,28,' + ((0.12 + alert * 0.06) * particle.alpha * strength * (1 - rise)).toFixed(3) + ')';
      ctx.beginPath();
      ctx.arc(x, y, 5 + particle.size * 3, 0, TAU);
      ctx.fill();
    });
  }

  function drawValerium(ctx, image, entry, time, strength, particles) {
    var point = entry.point;
    var flare = eventPulse(time, 7.9, 1.7, 4.9);
    drawSoftGlow(ctx, point, [255, 204, 88], (0.095 + flare * 0.07) * strength, 1, -8);
    ctx.save();
    ctx.translate(point.x, point.y);
    ctx.rotate(-0.22 + Math.sin(time * 0.12) * 0.02);
    var ray = ctx.createLinearGradient(0, -point.r, 0, point.r);
    ray.addColorStop(0, 'rgba(255,224,142,' + ((0.14 + flare * 0.18) * strength).toFixed(3) + ')');
    ray.addColorStop(1, 'rgba(255,224,142,0)');
    ctx.fillStyle = ray;
    ctx.fillRect(-17, -point.r, 34, point.r * 2);
    ctx.restore();
    particles.slice(0, 12).forEach(function (particle) {
      var x = point.x + (particle.x * 2 - 1) * point.r * 0.78 + Math.sin(time * 0.24 + particle.phase) * 3;
      var y = point.y + (particle.y * 2 - 1) * point.r * 0.75 - Math.cos(time * 0.2 + particle.phase) * 3;
      ctx.fillStyle = 'rgba(255,218,118,' + ((0.18 + flare * 0.12) * particle.alpha * strength).toFixed(3) + ')';
      ctx.beginPath();
      ctx.arc(x, y, 0.7 + particle.size * 0.6, 0, TAU);
      ctx.fill();
    });
  }

  function drawVermillia(ctx, image, entry, time, strength, particles) {
    var point = entry.point;
    var gust = eventPulse(time, 6.9, 1.65, 2.8);
    drawSoftGlow(ctx, point, [221, 139, 61], (0.078 + gust * 0.052) * strength, 1, point.r * 0.22);
    particles.slice(0, 15).forEach(function (particle) {
      var travel = wrap(particle.x + time * (0.018 + particle.speed * 0.025));
      var x = point.x - point.r + travel * point.r * 2;
      var y = point.y + (particle.y * 2 - 1) * point.r * 0.78 + Math.sin(time * 0.28 + particle.phase) * 5;
      ctx.strokeStyle = 'rgba(235,164,91,' + ((0.135 + gust * 0.13) * particle.alpha * strength).toFixed(3) + ')';
      ctx.lineWidth = 0.6 + particle.size * 0.4;
      ctx.beginPath();
      ctx.moveTo(x - 5 - particle.size * 2, y + 2);
      ctx.lineTo(x, y);
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
    var nexusEnergyChannels = buildNexusEnergyChannels(7);

    function clearCanvas() {
      ctx.clearRect(
        NEXUS.x - NEXUS.rx - 16,
        NEXUS.y - NEXUS.ry - 16,
        NEXUS.rx * 2 + 32,
        NEXUS.ry * 2 + 32
      );
      available.forEach(function (entry) {
        var point = entry.point;
        ctx.clearRect(
          point.x - point.r - WORLD_CLEAR_PADDING,
          point.y - point.r - WORLD_CLEAR_PADDING,
          point.r * 2 + WORLD_CLEAR_PADDING * 2,
          point.r * 2 + WORLD_CLEAR_PADDING * 2
        );
      });
    }

    function clearAllCanvas() {
      ctx.clearRect(0, 0, WIDTH, HEIGHT);
    }

    function render() {
      var now = performance.now();
      if (now - lastPaint < FRAME_INTERVAL) return;
      lastPaint = now;
      var time = now / 1000;
      clearCanvas();
      drawNexusStorm(ctx, time, nexusParticles, nexusEnergyChannels);
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

    function syncTicker() {
      if (shouldRun() && !ticking) {
        gsap.ticker.add(render);
        ticking = true;
        return;
      }
      if (!shouldRun() && ticking) {
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
        clearAllCanvas();
        destroyDepth();
      }
    };
  }

  global.WorldAtlasEffects = { create: create };
}(window));
