// Dynamic World Record page. The URL is intentionally stable per world
// (`world.html?slug=neoh`) while its content remains entirely controlled by
// World Control and the existing public worlds endpoint.
document.addEventListener('DOMContentLoaded', function () {
  var hero = document.getElementById('world-record-hero');
  var eyebrow = document.getElementById('world-record-eyebrow');
  var title = document.getElementById('world-record-title');
  var lede = document.getElementById('world-record-lede');
  var status = document.getElementById('world-record-status');
  var content = document.getElementById('world-record-content');
  var slug = new URLSearchParams(window.location.search).get('slug');

  function escapeHtml(value) {
    var normalized = value === null || value === undefined ? '' : value;
    return String(normalized).replace(/[&<>"']/g, function (char) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char];
    });
  }

  function setDocumentTitle(world) {
    document.title = world.name + ' — World Record — The Pantheon Wars';
  }

  // A discovery is awarded exactly once on the server for each member/world
  // pair. Waiting for the auth bootstrap keeps this page fully public while
  // still rewarding signed-in explorers who arrive before session-check ends.
  function recordLoreDiscovery(worldId) {
    function send() {
      if (!window.PW_AUTH || !window.PW_AUTH.loggedIn || !window.PW_AUTH.csrf) return;
      fetch('/api/reputation/lore-discovery.php', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ entity_type: 'world', entity_id: worldId, csrf: window.PW_AUTH.csrf })
      }).catch(function () {});
    }
    if (window.PW_AUTH && window.PW_AUTH.loggedIn) send();
    else document.addEventListener('pw-auth-ready', send, { once: true });
  }

  function setHeroImage(url) {
    if (!hero || !url) return;
    hero.style.setProperty('--world-record-image', 'url("' + String(url).replace(/"/g, '%22') + '")');
  }

  function quoteHtml(text, cite) {
    if (!text) return '';
    return '<blockquote>“' + escapeHtml(text) + '”<cite>— ' + escapeHtml(cite) + '</cite></blockquote>';
  }

  function weatherIconHtml(icon) {
    var paths = {
      'acid-rain': '<path d="M13 35h34a10 10 0 0 0 1-20 16 16 0 0 0-30-3 12 12 0 0 0-5 23z"/><path d="m20 43-4 9m15-9-4 9m15-9-4 9"/><path d="M18 55h22"/>',
      storm: '<path d="M13 34h34a10 10 0 0 0 1-20 16 16 0 0 0-30-3 12 12 0 0 0-5 23z"/><path d="m34 38-8 11h7l-4 10 13-15h-8z"/>',
      smog: '<path d="M13 31h34a10 10 0 0 0 1-20 16 16 0 0 0-30-3 12 12 0 0 0-5 23z"/><path d="M10 40h36M18 47h34M8 54h31"/>',
      clear: '<circle cx="32" cy="32" r="11"/><path d="M32 8v8m0 32v8M8 32h8m32 0h8M15 15l6 6m22 22 6 6m0-34-6 6M21 43l-6 6"/>',
      overcast: '<path d="M13 37h34a10 10 0 0 0 1-20 16 16 0 0 0-30-3 12 12 0 0 0-5 23z"/>'
    };
    return '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (paths[icon] || paths.overcast) + '</svg>';
  }

  // Distinct evocative bureau names per calibrated world; falls back to a
  // generic "<World> Atmospheric Service" label for any future world whose
  // weather profile doesn't have a bespoke entry here yet.
  var WEATHER_SERVICE_LABELS = {
    neoh: 'Neoh Atmospheric Service',
    asmecu: 'Asmecu Tideglass Bureau',
    'high-hammer': 'High Hammer Forge Watch',
    cerius: 'Cerius Cinderwatch',
    reanium: 'Reanium Fallout Watch',
    'babki-prime': 'Babki Prime Canopy Watch',
    sed: 'Sed Scorch Bureau',
    'geof-v': 'Geof V Column Watch',
    beoctica: 'Beoctica Frostwatch',
    'terek-ii': 'Terek II Frontline Watch',
    'valerium-prime': 'Valerium Prime Halo Bureau',
    'vermillia-xi': 'Vermillia XI Dome Watch'
  };

  /**
   * The shared hourly rail that sits under the five-day strip.
   *
   * One rail for all five days rather than a popup per day: it spans the whole
   * card so around ten hours are readable at once, and its scroll arrows are
   * real buttons -- which could not live inside a day, since each day is itself
   * a button and nesting interactive elements is invalid.
   *
   * Times are UTC, and the rail says so. Resolving them in the visitor's own
   * zone would put hours under a day heading whose boundaries are UTC, and the
   * two would disagree about which hours belong to today.
   */
  function hourlyRailHtml() {
    return '<div class="world-weather-hourly" id="world-weather-hourly" hidden>' +
      '<span class="world-weather-hourly-head">' +
        '<b>Hourly weather</b><em class="world-weather-hourly-day"></em>' +
        '<i>times in UTC</i>' +
      '</span>' +
      '<div class="world-weather-hourly-rail">' +
        '<button type="button" class="world-weather-hourly-nav is-prev" aria-label="Earlier hours">&lsaquo;</button>' +
        // The arc lives inside the scrolling list so it travels with the
        // columns it belongs to. It is an <li> because only <li> is valid
        // inside <ul>, and absolutely positioned so it takes no flex slot.
        '<ul class="world-weather-hour-list">' +
          '<li class="world-weather-arc-layer" aria-hidden="true"></li>' +
        '</ul>' +
        '<button type="button" class="world-weather-hourly-nav is-next" aria-label="Later hours">&rsaquo;</button>' +
      '</div>' +
    '</div>';
  }

  /**
   * The temperature arc behind the hourly columns.
   *
   * Hand-built inline SVG that computes its own scale from the day's real
   * range, the same approach as the System Status CPU chart -- there is no
   * chart library anywhere in this codebase and this does not add one.
   * preserveAspectRatio="none" lets one path stretch across however many
   * columns the rail holds, so it stays aligned while the rail scrolls.
   */
  function hourlyArcSvg(hours) {
    if (!hours || hours.length < 2) return '';
    var temps = hours.map(function (h) { return h.temperature_c; });
    var min = Math.min.apply(null, temps);
    var max = Math.max.apply(null, temps);
    var span = (max - min) || 1;
    var width = hours.length * 10;
    var points = temps.map(function (t, i) {
      // Inset vertically so the line never touches the box edges.
      return (i * 10 + 5) + ',' + (22 - ((t - min) / span) * 16).toFixed(2);
    });
    return '<svg class="world-weather-arc" viewBox="0 0 ' + width + ' 26" preserveAspectRatio="none" aria-hidden="true">' +
      '<polyline points="' + points.join(' ') + '" fill="none" vector-effect="non-scaling-stroke"/>' +
      '<polygon points="' + points.join(' ') + ' ' + (width - 5) + ',26 5,26" class="world-weather-arc-fill"/>' +
    '</svg>';
  }

  // Hours the world spends in darkness. The generator's own curve troughs at
  // 03:00 and peaks at 15:00, so this shades the half of the cycle that curve
  // already treats as night rather than inventing a second notion of one.
  function isNightHour(hour) { return hour >= 19 || hour < 6; }

  function hourlyItemsHtml(day, isToday) {
    return (day.hours || []).map(function (entry, position) {
      var isNow = isToday && position === 0;
      return '<li class="world-weather-hour' + (isNow ? ' is-now' : '') +
        (isNightHour(entry.hour) ? ' is-night' : '') + '">' +
        '<span class="world-weather-hour-time">' + escapeHtml(entry.short_label != null ? entry.short_label : entry.label) + '</span>' +
        '<span class="world-weather-hour-icon">' + weatherIconHtml(entry.icon) + '</span>' +
        '<span class="world-weather-hour-temp">' + escapeHtml(entry.temperature_c) + '&deg;</span>' +
        '<span class="world-weather-hour-precip">' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3s6 6.5 6 10a6 6 0 0 1-12 0c0-3.5 6-10 6-10z"/></svg>' +
          escapeHtml(entry.precipitation != null ? entry.precipitation : 0) + '%' +
        '</span>' +
        (isNow ? '<span class="world-weather-hour-now">now</span>' : '') +
      '</li>';
    }).join('');
  }

  /**
   * Fills the shared rail from whichever day is hovered, focused or tapped, and
   * keeps the arrows in step with how far it is scrolled.
   */
  function wireHourlyPanels(scope, forecast) {
    var days = Array.prototype.slice.call(scope.querySelectorAll('.world-weather-day'));
    var panel = scope.querySelector('#world-weather-hourly');
    if (!days.length || !panel) return;

    var list = panel.querySelector('.world-weather-hour-list');
    var arc = panel.querySelector('.world-weather-arc-layer');
    var dayLabel = panel.querySelector('.world-weather-hourly-day');
    var prev = panel.querySelector('.is-prev');
    var next = panel.querySelector('.is-next');
    var canHover = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    var shown = -1;

    function syncArrows() {
      var max = list.scrollWidth - list.clientWidth;
      prev.disabled = list.scrollLeft <= 1;
      next.disabled = list.scrollLeft >= max - 1;
    }

    function show(index) {
      var day = forecast[index];
      if (!day || !day.hours || !day.hours.length) return;
      if (shown !== index) {
        // Rebuilt rather than replaced wholesale, so the arc layer survives.
        list.innerHTML = '<li class="world-weather-arc-layer" aria-hidden="true"></li>' +
          hourlyItemsHtml(day, day.day === 'Today');
        arc = list.querySelector('.world-weather-arc-layer');
        arc.innerHTML = hourlyArcSvg(day.hours);
        // Spans the whole scroll width rather than the visible window, so the
        // curve stays registered with its columns as the rail moves.
        // Must mirror .world-weather-hour's own width exactly: six columns
        // share the list width minus its five 2px gaps, then the arc spans all
        // N of them plus the N-1 gaps between. Carrying a stale gap total here
        // leaves the curve short of its own columns.
        arc.style.width = 'calc((100% - 10px) / 6 * ' + day.hours.length + ' + ' + ((day.hours.length - 1) * 2) + 'px)';
        list.scrollLeft = 0;
        dayLabel.textContent = day.day === 'Today' ? 'Remaining today' : day.day;
        shown = index;
      }
      panel.hidden = false;
      days.forEach(function (other, i) {
        other.classList.toggle('is-open', i === index);
        other.setAttribute('aria-expanded', i === index ? 'true' : 'false');
      });
      syncArrows();
    }

    function hide() {
      panel.hidden = true;
      shown = -1;
      days.forEach(function (day) {
        day.classList.remove('is-open');
        day.setAttribute('aria-expanded', 'false');
      });
    }

    days.forEach(function (day, index) {
      if (canHover) day.addEventListener('mouseenter', function () { show(index); });
      day.addEventListener('focus', function () { show(index); });
      day.addEventListener('click', function () {
        if (day.getAttribute('aria-expanded') === 'true') hide();
        else show(index);
      });
      day.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        hide();
        day.blur();
      });
    });

    // On a pointer device the rail closes when the pointer leaves the whole
    // forecast area, not each day -- otherwise moving from a day down onto the
    // rail to scroll it would dismiss the thing being reached for.
    if (canHover) {
      var region = panel.parentElement;
      region.addEventListener('mouseleave', hide);
    }

    // Roughly five columns per press, so a 24-hour day is two presses across.
    // Deliberately no `behavior` here: passing one in JS overrides the CSS
    // scroll-behavior, which would defeat the reduced-motion rule that turns
    // the smooth scroll off. Letting CSS own it keeps that preference honoured.
    function step(direction) {
      var item = list.querySelector('.world-weather-hour');
      var gap = parseFloat(getComputedStyle(list).columnGap) || 2;
      var width = item ? item.getBoundingClientRect().width + gap : 36;
      list.scrollBy({ left: direction * width * 5 });
    }
    prev.addEventListener('click', function () { step(-1); });
    next.addEventListener('click', function () { step(1); });
    list.addEventListener('scroll', syncArrows);
    window.addEventListener('resize', syncArrows);
  }

  /**
   * Where this world sits in its own yearly cycle.
   *
   * The seasonal drift has always been shaping these temperatures with a
   * per-world phase offset and has never been visible to a reader. This states
   * the phase and the actual degrees it is worth, rather than a vague mood.
   */
  function seasonLineHtml(season) {
    if (!season || !season.label) return '';
    var shift = season.shift_c;
    var effect = shift === 0
      ? 'holding at this world&rsquo;s mean'
      : (shift > 0 ? '+' : '') + escapeHtml(shift) + '&deg; against this world&rsquo;s mean';
    return '<p class="world-weather-season is-' + escapeHtml(season.key) + '">' +
      '<span class="world-weather-season-mark" aria-hidden="true"></span>' +
      '<b>' + escapeHtml(season.label) + '</b>' +
      '<i>' + effect + '</i>' +
    '</p>';
  }

  /**
   * Severe-weather banner. Severity is judged against each world's own
   * configured bounds server-side, so 43 degrees is routine on Sed and an
   * event on Beoctica.
   */
  function severeAlertHtml(severity) {
    if (!severity || !severity.severe) return '';
    var reasons = (severity.reasons || []).map(function (r) { return escapeHtml(r.label); }).join(' &middot; ');
    return '<p class="world-weather-severe" role="status">' +
      '<span aria-hidden="true">!</span>' +
      '<b>Severe conditions</b>' +
      '<i>' + reasons + '</i>' +
    '</p>';
  }

  /**
   * A band showing each day's high/low against the whole week's spread, so a
   * day that is far out of step reads immediately instead of being five
   * unrelated numbers. Same flat-div technique as dev-metrics' language chart;
   * still no chart library anywhere in this codebase.
   */
  function rangeBandHtml(forecast) {
    if (!forecast || forecast.length < 2) return '';
    var lows = forecast.map(function (d) { return d.low_c; });
    var highs = forecast.map(function (d) { return d.high_c; });
    var floor = Math.min.apply(null, lows);
    var ceiling = Math.max.apply(null, highs);
    var span = (ceiling - floor) || 1;
    var bars = forecast.map(function (day) {
      var bottom = ((day.low_c - floor) / span) * 100;
      var height = ((day.high_c - day.low_c) / span) * 100;
      return '<span class="world-weather-range-col"' +
        ' title="' + escapeHtml(day.day) + ': ' + escapeHtml(day.high_c) + '&deg; / ' + escapeHtml(day.low_c) + '&deg;">' +
        '<i style="bottom:' + bottom.toFixed(1) + '%;height:' + Math.max(4, height).toFixed(1) + '%"></i>' +
      '</span>';
    }).join('');
    return '<div class="world-weather-range" aria-hidden="true">' +
      '<span class="world-weather-range-scale"><b>' + escapeHtml(ceiling) + '&deg;</b><b>' + escapeHtml(floor) + '&deg;</b></span>' +
      '<div class="world-weather-range-cols">' + bars + '</div>' +
    '</div>';
  }

  function weatherCardHtml(weather, worldSlug, worldName) {
    var current = weather.current || {};
    var forecast = weather.forecast || [];
    var serviceLabel = WEATHER_SERVICE_LABELS[worldSlug] || (worldName + ' Atmospheric Service');
    var forecastHtml = forecast.map(function (day, index) {
      var isToday = day.day === 'Today';
      // A button, not a div: the hourly panel has to be reachable by keyboard
      // and on touch, where there is no hover at all.
      return '<button type="button" class="world-weather-day' + (isToday ? ' is-today' : '') + '"' +
        ' aria-expanded="false" aria-label="' + escapeHtml(day.day) + ', ' + escapeHtml(day.condition) +
        ', high ' + escapeHtml(day.high_c) + ' degrees. Show hourly projection.">' +
        '<span class="world-weather-day-name">' + escapeHtml(day.day_short) + '</span>' +
        '<span class="world-weather-day-icon">' + weatherIconHtml(day.icon) + '</span>' +
        '<strong>' + escapeHtml(day.high_c) + '&deg;</strong>' +
        '<small>' + escapeHtml(day.low_c) + '&deg;</small>' +
        '<span class="world-weather-day-condition">' + escapeHtml(day.condition) + '</span>' +
      '</button>';
    }).join('');
    return '<div class="world-weather-card-scan" aria-hidden="true"></div>' +
      // The head's right column, so it can carry more than the archive pill.
      // With nothing else in it the column behaves exactly as the bare pill
      // did, which is why every world gets the wrapper rather than only the
      // ones with an instrument under it.
      '<header class="world-weather-head"><div><span>' + escapeHtml(serviceLabel) + '</span><h2>' + escapeHtml(weather.location) + '</h2></div>' +
        '<span class="world-weather-head-side">' +
          '<span class="world-weather-live"><i></i>Live archive</span>' +
          doseLineHtml(worldSlug) +
        '</span></header>' +
      '<p class="world-weather-climate">' + escapeHtml(weather.climate) + '</p>' +
      '<div class="world-weather-current">' +
        '<span class="world-weather-current-icon">' + weatherIconHtml(current.icon) + '</span>' +
        '<strong>' + escapeHtml(current.temperature_c) + '&deg;</strong>' +
        '<span><b>' + escapeHtml(current.condition) + '</b><small>' + escapeHtml(current.secondary) + '</small></span>' +
      '</div>' +
      '<div class="world-weather-metrics">' +
        '<div><span>Feels like</span><strong>' + escapeHtml(current.feels_like_c) + '&deg;C</strong></div>' +
        '<div><span>Humidity</span><strong>' + escapeHtml(current.humidity) + '%</strong></div>' +
        '<div><span>Precipitation</span><strong>' + escapeHtml(current.precipitation) + '%</strong></div>' +
        '<div><span>Wind</span><strong>' + escapeHtml(current.wind_kph) + ' km/h</strong></div>' +
      '</div>' +
      seasonLineHtml(weather.season) +
      severeAlertHtml(current.severity) +
      '<div class="world-weather-divider"><span>5-day atmospheric projection</span></div>' +
      rangeBandHtml(forecast) +
      // Wrapped together so the rail can close on leaving the whole region
      // rather than on leaving an individual day, which would dismiss it the
      // moment the pointer moved down to scroll it.
      '<div class="world-weather-forecast-region">' +
        '<div class="world-weather-forecast">' + forecastHtml + '</div>' +
        hourlyRailHtml() +
      '</div>' +
      (weather.hazard_note ? '<p class="world-weather-hazard"><span>!</span>' + escapeHtml(weather.hazard_note) + '</p>' : '') +
      '<footer>Forecast cycle ' + escapeHtml(weather.generated_for) + ' &middot; UTC archive time</footer>';
  }

  /* --- Dosimeter -----------------------------------------------------------
     Only worlds that would actually carry one get a dosimeter, so this is a
     list rather than a flag on every card: Reanium is the irradiated world,
     and a second would be one more entry here rather than a change to the
     renderer or the markup. */
  var DOSE_WORLDS = {
    reanium: { label: 'Cumulative dosage', unit: 'Sv' }
  };
  var doseTimer = null;

  function doseLineHtml(worldSlug) {
    var cfg = DOSE_WORLDS[worldSlug];
    if (!cfg) return '';
    // A span, not a paragraph: this sits inside the header's right column,
    // which is phrasing content.
    return '<span class="world-weather-dose">' +
      '<span class="world-weather-dose-head">' +
        '<span class="world-weather-dose-mark" aria-hidden="true"></span>' +
        '<b>' + escapeHtml(cfg.label) + '</b>' +
      '</span>' +
      '<i class="world-weather-dose-value">&mdash;</i></span>';
  }

  function doseHash(text) {
    var h = 2166136261;
    for (var i = 0; i < text.length; i += 1) {
      h ^= text.charCodeAt(i);
      h = (h * 16777619) >>> 0;
    }
    return h;
  }

  /**
   * The dose this world would accumulate across a whole UTC day.
   *
   * Seeded from the date the forecast was generated for, so every visitor sees
   * the same figure and it rolls over at UTC midnight with the forecast --
   * the same "stable for a whole UTC day" rule the weather itself follows,
   * rather than a number that differs per browser.
   *
   * Colder days dose harder: the reading is taken against this world's own
   * configured bounds, so it means the same thing whatever those bounds are.
   */
  function doseDailyTotal(weather, worldSlug) {
    var range = weather.range || {};
    var min = Number(range.min_c);
    var max = Number(range.max_c);
    var now = Number((weather.current || {}).temperature_c);
    var cold = 0.5;
    if (isFinite(min) && isFinite(max) && isFinite(now) && max > min) {
      cold = Math.max(0, Math.min(1, (max - now) / (max - min)));
    }
    // A seeded share on top of the temperature term, so two days at the same
    // temperature still differ rather than reading as a broken instrument.
    var variance = (doseHash(worldSlug + '|' + (weather.generated_for || '')) % 1000) / 1000;
    return 340 + cold * 520 + variance * 180;
  }

  /**
   * What the instrument shows right now: the day's accumulation so far, plus a
   * slow wander so it behaves like a live reading rather than a printed value.
   *
   * It never reads zero at midnight -- background radiation does not stop --
   * so the day opens on a residual and climbs from there. The wander is two
   * sine terms whose periods do not divide into each other, which drifts up
   * and down indefinitely instead of repeating a visible loop.
   */
  function doseReading(total) {
    var d = new Date();
    var progress = (d.getUTCHours() * 3600 + d.getUTCMinutes() * 60 + d.getUTCSeconds()) / 86400;
    var t = Date.now() / 1000;
    var wander = Math.sin(t / 7.3) * 0.22 + Math.sin(t / 3.1) * 0.11;
    return Math.max(0, total * (0.06 + progress * 0.94) + wander);
  }

  function startDoseReadout(card, weather, worldSlug) {
    if (doseTimer) {
      window.clearInterval(doseTimer);
      doseTimer = null;
    }
    var cfg = DOSE_WORLDS[worldSlug];
    var valueEl = card.querySelector('.world-weather-dose-value');
    if (!cfg || !valueEl) return;

    var total = doseDailyTotal(weather, worldSlug);
    function paint() {
      valueEl.textContent = doseReading(total).toFixed(1) + ' ' + cfg.unit;
    }
    paint();

    // A reading that changes under someone who asked for less motion is worse
    // than a still one, so it is painted once and left.
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    // No request is made here, so there is nothing to poll -- the hidden check
    // only avoids repainting a card nobody is looking at.
    doseTimer = window.setInterval(function () {
      if (!document.hidden) paint();
    }, 2000);
  }

  /**
   * Places today's reading within this world's own stated range as --heat, 0 to 1.
   *
   * Set on every card rather than only the one variant that currently reads it,
   * exactly as --world-weather-icon works: the data is world-generic, and a
   * variant opting in should not need a change here. Absolute temperature would
   * be meaningless across twelve worlds -- the same figure is an ordinary
   * afternoon on one and an event on another -- so this is always relative to
   * the world's configured bounds.
   */
  function applyHeatScale(card, weather) {
    var range = weather.range || {};
    var min = Number(range.min_c);
    var max = Number(range.max_c);
    var now = Number((weather.current || {}).temperature_c);
    // Removed rather than blanked: setting a custom property to '' still counts
    // as set and silently kills the var() fallback every consumer relies on.
    if (!isFinite(min) || !isFinite(max) || !isFinite(now) || max <= min) {
      card.style.removeProperty('--heat');
      return;
    }
    // An authored day 0/1 temperature may sit outside the world's stated
    // bounds, which pw_weather_severity() treats as exceptional. Clamped, so
    // such a day reads as the extreme rather than overshooting past it.
    var heat = (now - min) / (max - min);
    card.style.setProperty('--heat', Math.max(0, Math.min(1, heat)).toFixed(3));
  }

  function renderWorldWeather(data, worldSlug, worldName) {
    var slot = document.getElementById('world-weather-card');
    if (!slot || !data || !data.ok || !data.available || !data.weather) return;
    // Today's condition as a class rather than a number, so the stylesheet
    // decides what a condition looks like on a given world and this only has
    // to state the fact. Keyed by the same five icons weather-effects.js uses.
    var conditionKey = (data.weather.current || {}).icon;
    slot.className = 'world-weather-card world-weather-card--' + worldSlug +
      (conditionKey ? ' is-weather-' + conditionKey : '');
    slot.innerHTML = weatherCardHtml(data.weather, worldSlug, worldName);
    applyHeatScale(slot, data.weather);
    startDoseReadout(slot, data.weather, worldSlug);
    slot.hidden = false;
    wireHourlyPanels(slot, data.weather.forecast || []);

    // Ambient weather matched to the condition, not the world.
    if (window.WorldWeatherEffects) {
      window.WorldWeatherEffects.create(slot, (data.weather.current || {}).icon);
    }

    recordSevereWitness(data.weather, worldSlug);
  }

  /**
   * Records that this member was present for severe weather here.
   *
   * The client's say-so is not trusted: witness.php recomputes the severity
   * from the world's own profile before awarding anything, the same way the
   * Timeline discovery endpoint re-checks its gate. This call is only a nudge,
   * so a signed-out visitor or a failed request costs nothing.
   */
  function recordSevereWitness(weather, worldSlug) {
    var severity = (weather.current || {}).severity;
    if (!severity || !severity.severe) return;
    function send() {
      var auth = window.PW_AUTH;
      if (!auth || !auth.loggedIn || !auth.csrf) return;
      fetch('/api/weather/witness.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ slug: worldSlug, csrf: auth.csrf })
      }).catch(function () {});
    }
    // The weather can render before session-check resolves, so checking
    // PW_AUTH once and giving up would silently skip a signed-in member who
    // simply arrived early. Same wait the lore-discovery call above uses.
    if (window.PW_AUTH && window.PW_AUTH.loggedIn) send();
    else document.addEventListener('pw-auth-ready', send, { once: true });
  }

  function landmarkHtml(landmark) {
    return '<div class="sub-landmark restricted">' +
      '<span class="sub-landmark-name">' + escapeHtml(landmark.name) + '</span>' +
      '<span class="sub-landmark-tag">' + escapeHtml(landmark.tag_label) + '</span>' +
      '<p>' + escapeHtml(landmark.description) + '</p>' +
      quoteHtml(landmark.quote_text, landmark.quote_cite) +
    '</div>';
  }

  function layerHtml(layer, index, isVertical) {
    var sublocations = (layer.sublocations || []).map(function (label) {
      return '<span class="sublocation-tag">' + escapeHtml(label) + '</span>';
    }).join('');
    var landmarks = (layer.landmarks || []).map(landmarkHtml).join('');
    var indexLabel = String(index + 1).padStart(2, '0');
    return '<div class="' + (isVertical ? 'city-layer' : 'city-layer harbor-district') + ' world-layer-tint--' + escapeHtml(layer.tint_key || 'gold') + '">' +
      '<button type="button" class="layer-toggle" aria-expanded="false">' +
        '<span class="layer-idx">' + indexLabel + '</span>' +
        '<span class="layer-name">' + escapeHtml(layer.name) + '</span>' +
        '<span class="layer-tag">' + escapeHtml(layer.theme_tags) + '</span>' +
        '<span class="layer-plus" aria-hidden="true"></span>' +
      '</button>' +
      '<div class="layer-panel"><div class="layer-panel-inner"><div class="inner-pad">' +
        '<span class="layer-tagline">' + escapeHtml(layer.tagline) + '</span>' +
        '<p>' + escapeHtml(layer.description) + '</p>' +
        quoteHtml(layer.quote_text, layer.quote_cite) +
        (sublocations ? '<div class="layer-sublocations">' + sublocations + '</div>' : '') +
        landmarks +
      '</div></div></div>' +
    '</div>';
  }

  function mapHtml(world, isVertical) {
    return '<div class="city-map-figure' + (isVertical ? '' : ' harbor-map-figure') + '">' +
      '<button type="button" class="map-thumb-btn" data-lightbox="world-record-map-lightbox" aria-label="View full map of ' + escapeHtml(world.name) + '">' +
        '<img src="' + escapeHtml(world.map_thumb_image_url) + '" alt="Map of ' + escapeHtml(world.name) + '" decoding="async">' +
        '<span class="map-expand-hint">🔎 View Full Map</span>' +
      '</button>' +
      '<p class="map-caption">' + escapeHtml(world.map_caption) + '</p>' +
    '</div>';
  }

  function detailHtml(world) {
    var isVertical = world.layout_orientation === 'vertical';
    var layers = (world.layers || []).map(function (layer, index) {
      return layerHtml(layer, index, isVertical);
    }).join('');
    var distant = (world.distant_landmarks || []).map(landmarkHtml).join('');
    var overlord = world.overlord ?
      '<span class="overlord-tag"><a href="overlord.html?slug=' + encodeURIComponent(world.overlord.slug || '') + '">Overlord: ' + escapeHtml(world.overlord.name) + (world.overlord.epithet ? ' — ' + escapeHtml(world.overlord.epithet) : '') + '</a></span>' : '';
    var head = '<div class="world-detail-head">' +
      (world.portrait_image_url ? '<span class="world-detail-portrait"><img src="' + escapeHtml(world.portrait_image_url) + '" alt="' + escapeHtml(world.overlord ? world.overlord.name : world.name) + '" decoding="async"></span>' : '') +
      '<h2>' + escapeHtml(world.name) + '</h2>' + overlord +
    '</div>';
    var crossSection;
    if (isVertical) {
      crossSection = '<div class="city-cross-wrap" style="margin-top: 3rem;">' + mapHtml(world, true) +
        '<div class="city-cross-section"><div class="city-ruler" aria-hidden="true"></div><div class="city-stack">' +
          (world.altitude_top_label ? '<div class="city-altitude">▲ ' + escapeHtml(world.altitude_top_label) + '</div>' : '') +
          layers +
          (world.altitude_bottom_label ? '<div class="city-altitude">▼ ' + escapeHtml(world.altitude_bottom_label) + '</div>' : '') +
        '</div></div></div>' + distant + '</div>';
    } else {
      crossSection = '<div class="harbor-wrap" style="margin-top: 3rem;">' + mapHtml(world, false) +
        '<div class="harbor-cross-section"><div class="harbor-row">' + layers + '</div></div>' + distant + '</div>';
    }
    return '<a class="world-record-backlink" href="worlds.html">← Return to the Twelve Worlds atlas</a>' +
      '<div class="world-record-overview"><div class="world-record-overview-copy">' + head +
        (world.intro_paragraph_1 ? '<p class="world-record-intro">' + escapeHtml(world.intro_paragraph_1) + '</p>' : '') +
        (world.intro_paragraph_2 ? '<p class="world-record-intro">' + escapeHtml(world.intro_paragraph_2) + '</p>' : '') +
      '</div><aside class="world-weather-card" id="world-weather-card" aria-label="Five-day weather forecast" hidden></aside></div>' +
      crossSection +
      '<div class="map-lightbox" id="world-record-map-lightbox" hidden><div class="map-lightbox-backdrop"></div><button type="button" class="map-lightbox-close" aria-label="Close map">&times;</button><div class="map-lightbox-inner"><img src="' + escapeHtml(world.map_full_image_url) + '" alt="Full map of ' + escapeHtml(world.name) + '" decoding="async"></div></div>';
  }

  function showError(message) {
    if (title) title.textContent = 'World Record Unavailable';
    if (lede) lede.textContent = message;
    if (content) content.innerHTML = '<a class="world-record-backlink" href="worlds.html">← Return to the Twelve Worlds atlas</a><div class="world-record-placeholder">The requested record could not be retrieved. Return to the atlas and choose another available world.</div>';
  }

  if (!slug) {
    showError('No world record was specified.');
    return;
  }

  // Start the small weather request alongside the larger lore record. Its
  // failure is intentionally non-blocking: the World Record remains complete
  // before the migration is run or whenever a profile is switched off.
  var weatherRequest = fetch('/api/world-weather.php?slug=' + encodeURIComponent(slug), { credentials: 'same-origin' })
    .then(function (response) { return response.ok ? response.json() : null; })
    .catch(function () { return null; });

  fetch('/api/worlds.php?slug=' + encodeURIComponent(slug), { credentials: 'same-origin' })
    .then(function (response) { return response.ok ? response.json() : Promise.reject(); })
    .then(function (data) {
      if (!data.ok || !data.world) throw new Error('World unavailable');
      var world = data.world;
      setDocumentTitle(world);
      setHeroImage(world.thumb_image_url || world.map_full_image_url);
      if (eyebrow) eyebrow.textContent = world.overlord ? 'World of ' + world.overlord.name : 'Pantheon World Record';
      if (title) title.textContent = world.name;
      if (lede) lede.textContent = world.tagline || world.card_blurb || 'A record from the Pantheon archive.';
      if (status) {
        status.textContent = world.status === 'available' ? 'Archive clearance: available' : 'ERROR: LORE LOCK';
        status.classList.toggle('is-locked', world.status !== 'available');
      }
      if (world.status !== 'available') {
        if (content) content.innerHTML = '<a class="world-record-backlink" href="worlds.html">← Return to the Twelve Worlds atlas</a><div class="world-record-placeholder"><strong>MISSING INFORMATION.</strong><br>' + escapeHtml(world.name) + ' remains sealed in the lore archive. This field record will open when World Control clears the lock.</div>';
        return;
      }
      if (content) content.innerHTML = detailHtml(world);
      recordLoreDiscovery(world.id);
      if (window.wireWorldInteractions) window.wireWorldInteractions();
      weatherRequest.then(function (weatherData) { renderWorldWeather(weatherData, slug, world.name); });
    })
    .catch(function () { showError('The archive could not load this world record right now.'); });
});
