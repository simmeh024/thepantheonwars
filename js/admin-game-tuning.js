/* Game Tuning: a read-only balance simulator.
 *
 * This file draws and collects input. It computes no game figure of its own --
 * every number on screen came from api/admin/game-tuning/simulate.php, which
 * calls the same helpers the live launch and claim paths call. A tuning tool
 * that re-derives the game's arithmetic is wrong exactly when it is being
 * trusted to find something wrong.
 *
 * The chart is hand-built inline SVG, matching the System Status CPU chart:
 * this codebase carries no chart library and deliberately keeps it that way. */
(function () {
  'use strict';

  var catalog = null;
  var state = {
    crewId: null, itemIds: [], researchIds: [], missionIds: [],
    mode: 'level', metric: 'success_percent',
    levelFrom: 1, levelTo: 50, level: 1, crewCount: 1,
    result: null, dragItemId: null, loaded: false
  };
  /* One colour per series. Distinguishable without relying on hue alone --
   * every line also carries its own dash pattern and marker, so a reader who
   * cannot separate the colours can still separate the lines. */
  var SERIES = [
    { color: '#7ee3e8', dash: '' },
    { color: '#edc179', dash: '6 4' },
    { color: '#9edba8', dash: '2 3' },
    { color: '#c79aef', dash: '9 3 2 3' },
    { color: '#e8a894', dash: '1 4' },
    { color: '#8fb6ff', dash: '12 4' }
  ];

  function el(id) { return document.getElementById(id); }
  function esc(value) { var n = document.createElement('div'); n.textContent = value == null ? '' : String(value); return n.innerHTML; }
  function can(key) { return typeof window.pwHasPermission === 'function' && window.pwHasPermission(key); }
  function setError(message) { var box = el('tuning-error'); if (box) box.textContent = message || ''; }

  function request(url, payload) {
    var options = { credentials: 'same-origin', cache: 'no-store' };
    if (payload) {
      payload.csrf = window.PW_AUTH && window.PW_AUTH.csrf ? window.PW_AUTH.csrf : '';
      options.method = 'POST';
      options.headers = { 'Content-Type': 'application/json' };
      options.body = JSON.stringify(payload);
    }
    return fetch(url, options).then(function (r) { return r.json().catch(function () { return {}; }); }).then(function (data) {
      if (!data.ok) throw new Error(data.error || 'The tuning request could not be completed.');
      return data;
    });
  }

  function itemById(id) {
    var list = (catalog && catalog.items) || [];
    for (var i = 0; i < list.length; i++) if (Number(list[i].id) === Number(id)) return list[i];
    return null;
  }
  function crewById(id) {
    var list = (catalog && catalog.crew) || [];
    for (var i = 0; i < list.length; i++) if (Number(list[i].id) === Number(id)) return list[i];
    return null;
  }
  function bonusText(item) {
    return ['strength', 'cunning', 'science', 'charisma'].map(function (stat) {
      var value = Number(item['bonus_' + stat]) || 0;
      return value ? (value > 0 ? '+' : '') + value + ' ' + stat.slice(0, 3).toUpperCase() : '';
    }).filter(Boolean).join(' ') || 'No bonus';
  }
  function slotLabel(key) { return (catalog && catalog.gear_slots && catalog.gear_slots[key]) || key; }

  /* ---- Loadout ---------------------------------------------------------
   * One item per slot, which is the live loadout rule. Choosing a second item
   * for an occupied slot replaces it rather than being refused, because the
   * point of this page is rapid comparison. */
  function itemInSlot(slot) {
    for (var i = 0; i < state.itemIds.length; i++) {
      var item = itemById(state.itemIds[i]);
      if (item && item.slot === slot) return item;
    }
    return null;
  }
  function assignItem(id) {
    var item = itemById(id);
    if (!item) return;
    state.itemIds = state.itemIds.filter(function (existing) {
      var other = itemById(existing);
      return other && other.slot !== item.slot;
    });
    state.itemIds.push(Number(id));
    renderSlots(); renderItems(); run();
  }
  function clearSlot(slot) {
    state.itemIds = state.itemIds.filter(function (id) {
      var item = itemById(id);
      return item && item.slot !== slot;
    });
    renderSlots(); renderItems(); run();
  }

  /* Re-rendering a whole list of checkboxes throws away the focused element,
   * so a keyboard user is returned to the top of the page on every toggle and
   * cannot select a second operation without tabbing back down. The series
   * swatches genuinely have to be redrawn -- adding one operation renumbers the
   * colours of all of them -- so the list is rebuilt and the focus put back on
   * the control that caused it. */
  function keepFocus(host, render) {
    var active = document.activeElement;
    var key = active && host.contains(active) ? active.getAttribute('data-mission-id') || active.getAttribute('data-research-id') : null;
    var attr = active && active.hasAttribute && active.hasAttribute('data-mission-id') ? 'data-mission-id' : 'data-research-id';
    render();
    if (key === null) return;
    var restored = host.querySelector('[' + attr + '="' + key + '"]');
    if (restored) restored.focus();
  }

  function renderSlots() {
    var host = el('tuning-slots');
    if (!host) return;
    var slots = (catalog && catalog.gear_slots) || {};
    var crew = crewById(state.crewId);
    host.innerHTML = Object.keys(slots).map(function (key) {
      var item = itemInSlot(key);
      if (!item) {
        return '<div class="tuning-slot is-empty" data-slot="' + esc(key) + '">'
          + '<span class="tuning-slot-label">' + esc(slots[key]) + '</span>'
          + '<span class="tuning-slot-hint">Drop an item</span></div>';
      }
      /* Stated on the slot, because a requirement the subject can never meet
       * is the difference between a loadout and a wish. */
      var blocked = crew && item.required_role && item.required_role !== crew.role
        ? item.required_role + ' only'
        : (Number(item.required_level) > 1 ? 'From level ' + item.required_level : '');
      return '<div class="tuning-slot is-filled is-' + esc(item.tier) + '" data-slot="' + esc(key) + '">'
        + '<span class="tuning-slot-label">' + esc(slots[key]) + '</span>'
        + '<strong>' + esc(item.name) + '</strong>'
        + '<span class="tuning-slot-bonus">' + esc(bonusText(item)) + '</span>'
        + (blocked ? '<span class="tuning-slot-req">' + esc(blocked) + '</span>' : '')
        + '<button type="button" class="tuning-slot-clear" data-clear-slot="' + esc(key) + '" aria-label="' + esc('Remove ' + item.name) + '">&times;</button></div>';
    }).join('');
  }

  /* ---- Stat reference ---------------------------------------------------
   * What each stat and each role is worth, generated server-side from the
   * engine's own constants by pw_missions_stat_reference(). Not written out
   * here: a page that explains the rules by restating them will eventually
   * explain a rule the game no longer follows.
   *
   * The selected crew member's own role is called out, because which of the
   * three per-level bonuses applies is the single most useful thing to know
   * while reading their curve.
   * -------------------------------------------------------------------- */
  function renderStatReference() {
    var host = el('tuning-stat-reference');
    if (!host) return;
    var ref = catalog && catalog.stat_reference;
    if (!ref) { host.innerHTML = ''; return; }
    var crew = crewById(state.crewId);
    var caps = ref.caps || {};

    var stats = (ref.stats || []).map(function (stat) {
      return '<div class="tuning-stat-row is-' + esc(stat.key) + '">'
        + '<span class="tuning-stat-key"><i>' + esc(stat.short) + '</i>' + esc(stat.label) + '</span>'
        + '<span class="tuning-stat-rate">+' + stat.per_point + esc(stat.unit) + ' <small>per point</small></span>'
        + '<span class="tuning-stat-affects">' + esc(stat.affects) + '</span>'
        + '<p>' + esc(stat.detail) + '</p></div>';
    }).join('');

    var roles = (ref.roles || []).map(function (role) {
      var mine = crew && crew.role === role.role;
      return '<div class="tuning-stat-row is-role' + (mine ? ' is-subject' : '') + '">'
        + '<span class="tuning-stat-key">' + esc(role.role) + (mine ? '<em>this subject</em>' : '') + '</span>'
        + '<span class="tuning-stat-rate">+' + role.per_level + esc(role.unit) + ' <small>per level</small></span>'
        + '<span class="tuning-stat-affects">' + esc(role.affects) + '</span>'
        + '<p>' + esc(role.detail) + '</p></div>';
    }).join('');

    var affinity = ref.affinity || {};
    var preferred = Object.keys(affinity.preferred_by_type || {}).map(function (type) {
      return '<span class="tuning-affinity-type"><b>' + esc(String(type).toUpperCase()) + '</b> '
        + esc((affinity.preferred_by_type[type] || []).join(', ')) + '</span>';
    }).join('');

    host.innerHTML = '<div class="tuning-stat-group"><h3>Stats</h3>'
      + '<p class="tuning-stat-note">Summed across the whole assigned crew, then applied once. '
      + 'Levels allocate 2 points a level into the role&rsquo;s primary stat and 1 into Cunning, capped at '
      + Number(caps.max_stat_from_levels) + '; equipment carries a stat to ' + Number(caps.max_stat_with_gear) + '.</p>'
      + stats + '</div>'
      + '<div class="tuning-stat-group"><h3>Roles</h3>'
      + '<p class="tuning-stat-note">A per-level bonus on top of the stats above, contributed by every crew member of that role.</p>'
      + roles + '</div>'
      + '<div class="tuning-stat-group"><h3>Operation affinity</h3>'
      + '<p class="tuning-stat-note">' + esc(affinity.detail || '') + '</p>'
      + '<div class="tuning-affinity-list">' + preferred + '</div>'
      + '<p class="tuning-stat-note is-warn">Neither preferred role assigned: +'
      + Number(affinity.penalty_duration_percent) + '% duration and &minus;'
      + Number(affinity.penalty_success_percent) + '% success. Each matching crew member adds +'
      + Number(affinity.percent) + '% of that operation type&rsquo;s own bonus.</p></div>'
      + rarityGroupMarkup(crew);
  }

  /* Role ceilings are sent by the same shared helper that decides the crew
   * card's MAXED state. Requirement levels stay visible on individual gear,
   * but never lower this target: a level-three Engineer is progressing toward
   * the published Engineer ceiling, not finished merely because higher gear is
   * still locked. */
  function tuningSlotShortLabel(key) {
    var labels = { head: 'H', chest: 'C', main_hand: 'MH', off_hand: 'OH', legs: 'L', feet: 'F', utility: 'U' };
    return labels[key] || String(slotLabel(key)).slice(0, 2).toUpperCase();
  }

  function renderItemLevelCeilings() {
    var host = el('tuning-ilvl-ceilings');
    if (!host) return;
    var ceilings = catalog && catalog.item_level_ceilings;
    if (!ceilings || !ceilings.ready) {
      host.innerHTML = '<p class="admin-empty">Item levels will appear here after the Mission Item Levels migration has been run.</p>';
      return;
    }
    var roles = ceilings.roles || {};
    var roleNames = Object.keys(roles);
    if (!roleNames.length) {
      host.innerHTML = '<p class="admin-empty">No supported crew roles are available to compare.</p>';
      return;
    }
    var slotKeys = Object.keys((catalog && catalog.gear_slots) || {});
    var slotCount = Math.max(1, Number(ceilings.slot_count) || slotKeys.length);
    var leaderTotal = Math.max(0, Number(ceilings.leader_total) || 0);
    var selected = crewById(state.crewId);
    host.innerHTML = '<p class="tuning-ilvl-note">AVG is total maximum iLvl divided by all ' + slotCount + ' worn slots. A gap exposes roles whose enabled catalogue trails the current leader.</p>'
      + '<div class="tuning-ilvl-role-list">' + roleNames.map(function (roleName) {
        var role = roles[roleName] || {};
        var total = Math.max(0, Number(role.total) || 0);
        var average = Math.round(Math.max(0, Number(role.average) || 0));
        var covered = Math.max(0, Number(role.slots_covered) || 0);
        var gap = Math.max(0, leaderTotal - total);
        var subject = selected && selected.role === roleName;
        var slotValues = slotKeys.map(function (slot) {
          var level = Math.max(0, Number((role.slots || {})[slot]) || 0);
          var label = slotLabel(slot);
          return '<span class="tuning-ilvl-slot' + (level ? '' : ' is-missing') + '" title="' + esc(label + ': iLvl ' + (level || 0)) + '"><small>'
            + esc(tuningSlotShortLabel(slot)) + '</small><b>' + (level || '&mdash;') + '</b></span>';
        }).join('');
        return '<article class="tuning-ilvl-role' + (subject ? ' is-subject' : '') + (gap ? ' is-behind' : ' is-leader') + '">'
          + '<header><strong>' + esc(roleName) + '</strong><span><small>AVG iLvl</small><b>' + average + '</b></span></header>'
          + '<p><b>' + total + '</b> total &middot; ' + covered + ' / ' + slotCount + ' slots authored</p>'
          + '<div class="tuning-ilvl-slot-grid">' + slotValues + '</div>'
          + '<em>' + (gap ? gap + ' total iLvl below leader' : 'Role leader') + '</em></article>';
      }).join('') + '</div>';
  }

  /* ---- Rarity ------------------------------------------------------------
   * A crew member's rarity is worth three separate things and none of them
   * were on this page: it scales every level-derived stat, it raises the
   * role's own per-level rate, and it prices a duplicate award. A balance tool
   * that showed only level would be comparing recruits on a third of what
   * decides them.
   *
   * Read from pw_missions_crew_tier_profile() rather than restated here, for
   * the same reason the stat and role tables above are generated: a page that
   * explains the rules by repeating them eventually explains a rule the game
   * no longer follows.
   * -------------------------------------------------------------------- */
  function rarityGroupMarkup(crew) {
    var tiers = catalog && catalog.crew_tiers;
    if (!tiers) return '';
    var rates = (catalog && catalog.role_rates) || {};
    /* The subject's own role, so the role-bonus column is a number the reader
     * can act on rather than a bare "+0.15 on top of whatever the rate is". */
    var roleRate = null, roleLabel = '';
    if (crew && rates[crew.role]) {
      var key = Object.keys(rates[crew.role])[0];
      roleRate = Number(rates[crew.role][key]);
      roleLabel = crew.role;
    }
    var rows = Object.keys(tiers).map(function (name) {
      var tier = tiers[name];
      var mine = crew && String(crew.tier || 'common').toLowerCase() === name;
      var add = Number(tier.role_bonus_add) || 0;
      var bonus = roleRate === null
        ? (add > 0 ? '+' + add + ' per level' : 'base rate')
        : num(roleRate + add) + ' per level' + (add > 0 ? ' (+' + add + ')' : '');
      return '<div class="tuning-stat-row is-rarity is-tier-' + esc(name) + (mine ? ' is-subject' : '') + '">'
        + '<span class="tuning-stat-key">' + esc(name.charAt(0).toUpperCase() + name.slice(1))
        + (mine ? '<em>this subject</em>' : '') + '</span>'
        + '<span class="tuning-stat-rate">&times;' + num(tier.stat_multiplier) + ' <small>stats</small></span>'
        + '<span class="tuning-stat-affects">' + esc(bonus) + (roleLabel ? ' <small>' + esc(roleLabel) + '</small>' : '') + '</span>'
        + '<p>Stats scale by &times;' + num(tier.stat_multiplier) + ', rounded up. A duplicate award pays '
        + Number(tier.duplicate_credits) + ' credits.</p></div>';
    }).join('');
    return '<div class="tuning-stat-group"><h3>Rarity</h3>'
      + '<p class="tuning-stat-note">Rarity multiplies the stats a level grants (rounded up), adds to the role&rsquo;s '
      + 'per-level rate, and sets what a duplicate crew award pays instead. The curve above already reflects the '
      + 'selected recruit&rsquo;s own rarity.</p>' + rows + '</div>';
  }

  function num(value) {
    var rounded = Math.round(Number(value) * 100) / 100;
    return String(rounded);
  }

  /* ---- Salvage Sweep ----------------------------------------------------
   * One row per sector. Survival is the column that matters: a sector nobody
   * loses and a sector nobody banks are both broken, in opposite directions,
   * and neither is visible from the authored numbers alone.
   * -------------------------------------------------------------------- */
  function renderSweep(data) {
    var host = el('tuning-sweep-body');
    if (!host) return;
    var sectors = (data && data.sectors) || [];
    if (!sectors.length) {
      host.innerHTML = '<p class="admin-list-empty">No sweep sectors are enabled yet.</p>';
      return;
    }
    var who = data.crew
      ? esc(data.crew.name) + ' at level ' + Number(data.level)
      : 'no crew member selected, so these are the sector\u2019s own numbers';
    var rows = sectors.map(function (sector) {
      /* Survival is toned rather than only printed: a ladder is read down this
         column, and 8% against 97% has to separate without being read. */
      var band = sector.survive_percent >= 97 ? ' is-safe'
        : (sector.survive_percent <= 15 ? ' is-risky' : '');
      return '<div class="tuning-sweep-row' + band + (sector.has_manifest ? '' : ' is-broken') + '">'
        + '<span class="tuning-sweep-rank"><b>' + Number(sector.rank_number) + '</b><small>' + esc(sector.name) + '</small></span>'
        + '<span class="tuning-sweep-board">' + esc(sector.grid) + ' &middot; ' + Number(sector.hazards) + ' collapse'
        + (Number(sector.hazards) === 1 ? '' : 's') + ' &middot; ' + Number(sector.picks) + ' scans</span>'
        + '<span class="tuning-sweep-figure"><small>Survives</small><b>' + sector.survive_percent + '%</b></span>'
        + '<span class="tuning-sweep-figure"><small>Reveals</small><b>' + sector.expected_reveals + '</b></span>'
        + '<span class="tuning-sweep-figure"><small>Credits</small><b>' + Number(sector.expected_credits).toLocaleString() + '</b></span>'
        + '<span class="tuning-sweep-figure"><small>Cr / fatigue</small><b>' + sector.credits_per_fatigue + '</b></span>'
        + '<span class="tuning-sweep-figure"><small>Items</small><b>' + sector.expected_finds + '</b></span>'
        + '<span class="tuning-sweep-figure"><small>1st scan risk</small><b>' + sector.first_scan_risk_percent + '%</b></span>'
        + (sector.has_manifest ? '' : '<span class="admin-pill is-warn">No manifest</span>')
        + '</div>';
    }).join('');
    var findings = (data.findings || []).map(function (finding) {
      return '<li class="tuning-sweep-finding is-' + esc(finding.severity) + '">'
        + '<strong>' + esc(finding.label) + '</strong><span>' + esc(finding.detail) + '</span></li>';
    }).join('');
    host.innerHTML = '<p class="tuning-stat-note">Played to the last scan by ' + who
      + '. That is the most a player can risk, not what they must do &mdash; the first-scan risk is what they actually decide on each time.</p>'
      + '<div class="tuning-sweep-table">' + rows + '</div>'
      + (findings
        ? '<ul class="tuning-sweep-findings">' + findings + '</ul>'
        : '<p class="tuning-stat-note">Nothing on the ladder looks out of step.</p>');
  }

  function renderItems() {
    var host = el('tuning-item-list');
    if (!host) return;
    if (!catalog || !catalog.gear_ready) {
      host.innerHTML = '<p class="admin-empty">Equipment needs the mission gear migration before it can be simulated.</p>';
      return;
    }
    var term = (el('tuning-item-search').value || '').trim().toLowerCase();
    var crew = crewById(state.crewId);
    var rows = (catalog.items || []).filter(function (item) {
      /* Role only, never level. A role requirement is permanent for this crew
       * member, so an item they can never wear is noise in a list they are
       * choosing from. A level requirement is temporary -- the page is a sweep
       * across levels, and watching an item become legal partway up is one of
       * the things it exists to show -- so those stay listed, with the level
       * called out on the row. */
      if (crew && item.required_role && item.required_role !== crew.role) return false;
      if (!term) return true;
      return (item.name + ' ' + item.slot + ' ' + item.tier).toLowerCase().indexOf(term) !== -1;
    });
    host.innerHTML = rows.length ? rows.map(function (item) {
      var chosen = state.itemIds.indexOf(Number(item.id)) !== -1;
      return '<button type="button" class="tuning-item is-' + esc(item.tier) + (chosen ? ' is-chosen' : '') + '"'
        + ' draggable="true" data-item-id="' + Number(item.id) + '">'
        + '<span class="tuning-item-name">' + esc(item.name) + '</span>'
        + '<span class="tuning-item-meta">' + esc(slotLabel(item.slot)) + ' &middot; ' + esc(item.tier)
        + (Number(item.required_level) > 1 ? ' &middot; L' + item.required_level : '')
        + (item.required_role ? ' &middot; ' + esc(item.required_role) : '') + '</span>'
        + '<span class="tuning-item-bonus">' + esc(bonusText(item)) + '</span></button>';
    }).join('') : '<p class="admin-empty">' + (term
      ? 'No items match that filter.'
      : esc(crew ? 'Nothing in the catalogue can be worn by a ' + crew.role + '.' : 'No equipment has been authored yet.')) + '</p>';
  }

  function renderResearch() {
    var host = el('tuning-research-list');
    if (!host) return;
    if (!catalog || !catalog.research_ready || !(catalog.research || []).length) {
      host.innerHTML = '<p class="admin-empty">No research protocols are published, so every simulation runs with none online.</p>';
      el('tuning-research-count').textContent = '';
      return;
    }
    host.innerHTML = (catalog.research || []).map(function (node) {
      var on = state.researchIds.indexOf(Number(node.id)) !== -1;
      var type = (catalog.effect_types || {})[node.effect_type] || {};
      var flat = node.effect_type === 'crew_capacity' || node.effect_type === 'crew_fatigue';
      var special = node.effect_type === 'secret_mission' || node.effect_type === 'rare_loot_table';
      var value = special ? 'unlock' : flat ? '+' + Math.floor(node.effect_value) : '+' + node.effect_value + '%';
      return '<label class="tuning-research' + (on ? ' is-on' : '') + '">'
        + '<input type="checkbox" data-research-id="' + Number(node.id) + '"' + (on ? ' checked' : '') + '>'
        + '<span class="tuning-research-copy"><strong>' + esc(node.name) + '</strong>'
        + '<small>' + esc((node.category_name ? node.category_name + ' · ' : '') + (type.label || node.effect_type)) + '</small></span>'
        + '<b>' + esc(value) + '</b></label>';
    }).join('');
    el('tuning-research-count').textContent = state.researchIds.length + ' of ' + (catalog.research || []).length + ' online';
  }

  function renderMissions() {
    var host = el('tuning-mission-list');
    if (!host) return;
    host.innerHTML = ((catalog && catalog.missions) || []).map(function (mission) {
      var on = state.missionIds.indexOf(Number(mission.id)) !== -1;
      var index = state.missionIds.indexOf(Number(mission.id));
      var swatch = on ? '<i style="background:' + SERIES[index % SERIES.length].color + '"></i>' : '<i class="is-off"></i>';
      return '<label class="tuning-mission' + (on ? ' is-on' : '') + (mission.is_enabled ? '' : ' is-disabled') + '">'
        + '<input type="checkbox" data-mission-id="' + Number(mission.id) + '"' + (on ? ' checked' : '') + '>'
        + swatch
        + '<span class="tuning-mission-copy"><strong>' + esc(mission.name) + (mission.overlord_name ? ' <em>' + esc(mission.overlord_name) + '</em>' : '') + '</strong>'
        + '<small>' + esc(String(mission.mission_type).toUpperCase()) + ' &middot; ' + Math.round(mission.duration_seconds / 60) + 'm &middot; '
        + mission.min_crew + (mission.max_crew !== mission.min_crew ? '–' + mission.max_crew : '') + ' crew'
        + (mission.is_enabled ? '' : ' &middot; disabled') + '</small></span></label>';
    }).join('') || '<p class="admin-empty">No operations have been authored yet.</p>';
  }

  /* ---- The chart -------------------------------------------------------
   * Hand-built inline SVG on a fixed viewBox, scaled by the container. Axis
   * ticks come from the real data range rather than a fixed scale, so a metric
   * whose whole span is 70-72% still fills the plot and its shape is visible.
   * -------------------------------------------------------------------- */
  /* The viewBox is measured from the container at render time rather than
   * fixed, so one SVG unit is always one CSS pixel and the axis labels are the
   * size the stylesheet asks for at every column width. A fixed box scaled by
   * the container instead: at the narrowest column the layout allows, a
   * 900-wide box rendered its 12px labels at under seven pixels. */
  var CHART = { w: 760, h: 360, left: 58, right: 16, top: 18, bottom: 38 };
  function chartBox(host) {
    var width = host ? Math.round(host.getBoundingClientRect().width) : 0;
    return {
      /* Floored only enough to survive a hidden or not-yet-laid-out container,
       * which measures zero. Set higher (420 was tried) the floor itself
       * reintroduces the scaling this measurement exists to avoid, on exactly
       * the narrow screens where the labels can least afford it. */
      w: Math.max(300, width || CHART.w),
      h: CHART.h, left: CHART.left, right: CHART.right, top: CHART.top, bottom: CHART.bottom
    };
  }

  function niceTicks(min, max, count) {
    if (max <= min) { max = min + 1; }
    var raw = (max - min) / Math.max(1, count);
    var mag = Math.pow(10, Math.floor(Math.log(raw) / Math.LN10));
    var norm = raw / mag;
    var step = (norm >= 5 ? 10 : norm >= 2 ? 5 : norm >= 1 ? 2 : 1) * mag;
    var start = Math.floor(min / step) * step;
    var ticks = [];
    for (var v = start; v <= max + step * 0.5; v += step) ticks.push(Math.round(v * 1000) / 1000);
    return ticks;
  }

  function formatValue(value, metric) {
    var meta = ((catalog && catalog.metrics) || {})[metric] || {};
    if (metric === 'duration_seconds') {
      var s = Math.max(0, Math.round(value));
      var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
      return h ? h + 'h ' + m + 'm' : m ? m + 'm' : s + 's';
    }
    var rounded = Math.abs(value) >= 100 ? Math.round(value) : Math.round(value * 100) / 100;
    return rounded + (meta.unit === '%' ? '%' : '');
  }

  function renderChart() {
    var host = el('tuning-chart');
    if (!host) return;
    var result = state.result;
    if (!result || !result.series.length) {
      host.innerHTML = '<p class="admin-empty">Choose a crew member and at least one operation to plot.</p>';
      el('tuning-legend').innerHTML = '';
      return;
    }
    var CHART = chartBox(host);
    var metric = state.metric;
    var xKey = result.mode === 'crew_count' ? 'crew_count' : 'level';
    var xs = [], ys = [];
    result.series.forEach(function (s) {
      s.points.forEach(function (p) { xs.push(Number(p[xKey])); ys.push(Number(p[metric]) || 0); });
    });
    if (!xs.length) { host.innerHTML = '<p class="admin-empty">Nothing to plot for this combination.</p>'; return; }
    var xMin = Math.min.apply(null, xs), xMax = Math.max.apply(null, xs);
    var yMin = Math.min.apply(null, ys), yMax = Math.max.apply(null, ys);
    /* A flat line is real information -- an item that changes nothing, say --
     * so it is drawn through the middle of a padded band rather than collapsed
     * onto an axis where it would look like missing data. */
    if (yMax === yMin) { yMax = yMin + Math.max(1, Math.abs(yMin) * 0.1); yMin = Math.max(0, yMin - Math.max(1, Math.abs(yMin) * 0.1)); }
    else { var pad = (yMax - yMin) * 0.08; yMax += pad; yMin = Math.max(0, yMin - pad); }
    if (xMax === xMin) xMax = xMin + 1;

    var plotW = CHART.w - CHART.left - CHART.right;
    var plotH = CHART.h - CHART.top - CHART.bottom;
    var px = function (x) { return CHART.left + ((x - xMin) / (xMax - xMin)) * plotW; };
    var py = function (y) { return CHART.top + plotH - ((y - yMin) / (yMax - yMin)) * plotH; };

    var yTicks = niceTicks(yMin, yMax, 5).filter(function (t) { return t >= yMin - 0.001 && t <= yMax + 0.001; });
    var xTicks = niceTicks(xMin, xMax, Math.min(10, xMax - xMin)).filter(function (t) {
      return t >= xMin && t <= xMax && Math.abs(t - Math.round(t)) < 0.001;
    });

    var grid = yTicks.map(function (t) {
      return '<line class="tuning-grid" x1="' + CHART.left + '" y1="' + py(t).toFixed(1) + '" x2="' + (CHART.w - CHART.right) + '" y2="' + py(t).toFixed(1) + '"></line>'
        + '<text class="tuning-axis" x="' + (CHART.left - 8) + '" y="' + (py(t) + 3.5).toFixed(1) + '" text-anchor="end">' + esc(formatValue(t, metric)) + '</text>';
    }).join('');
    var xAxis = xTicks.map(function (t) {
      return '<text class="tuning-axis" x="' + px(t).toFixed(1) + '" y="' + (CHART.h - CHART.bottom + 18) + '" text-anchor="middle">' + t + '</text>';
    }).join('');

    var lines = result.series.map(function (s, index) {
      var style = SERIES[index % SERIES.length];
      var d = s.points.map(function (p, i) {
        return (i ? 'L' : 'M') + px(Number(p[xKey])).toFixed(1) + ' ' + py(Number(p[metric]) || 0).toFixed(1);
      }).join(' ');
      var dots = s.points.length <= 26 ? s.points.map(function (p) {
        return '<circle class="tuning-dot" cx="' + px(Number(p[xKey])).toFixed(1) + '" cy="' + py(Number(p[metric]) || 0).toFixed(1) + '" r="2.6" fill="' + style.color + '"></circle>';
      }).join('') : '';
      return '<path class="tuning-line" d="' + d + '" stroke="' + style.color + '"'
        + (style.dash ? ' stroke-dasharray="' + style.dash + '"' : '') + '></path>' + dots;
    }).join('');

    var meta = ((catalog && catalog.metrics) || {})[metric] || {};
    host.innerHTML = '<svg viewBox="0 0 ' + CHART.w + ' ' + CHART.h + '" preserveAspectRatio="xMidYMid meet" role="img">'
      + '<line class="tuning-axis-line" x1="' + CHART.left + '" y1="' + CHART.top + '" x2="' + CHART.left + '" y2="' + (CHART.h - CHART.bottom) + '"></line>'
      + '<line class="tuning-axis-line" x1="' + CHART.left + '" y1="' + (CHART.h - CHART.bottom) + '" x2="' + (CHART.w - CHART.right) + '" y2="' + (CHART.h - CHART.bottom) + '"></line>'
      + grid + xAxis + lines
      + '<text class="tuning-axis-title" x="' + (CHART.left + plotW / 2) + '" y="' + (CHART.h - 4) + '" text-anchor="middle">'
      + (result.mode === 'crew_count' ? 'Crew assigned' : 'Crew level') + '</text>'
      + '</svg>';

    el('tuning-legend').innerHTML = result.series.map(function (s, index) {
      var style = SERIES[index % SERIES.length];
      var last = s.points[s.points.length - 1];
      return '<span class="tuning-legend-item"><i style="background:' + style.color + '"></i>'
        + esc(s.mission_name) + ' <b>' + esc(formatValue(Number(last[metric]) || 0, metric)) + '</b></span>';
    }).join('');
    el('tuning-chart-title').textContent = (meta.label || metric) + ' · ' + result.crew.name;
    el('tuning-chart-sub').textContent = result.mode === 'crew_count'
      ? 'At level ' + state.level + ', swept across each operation’s own crew limits.'
      : 'Levels ' + state.levelFrom + '–' + state.levelTo + ' with ' + state.crewCount + ' crew assigned.';
  }

  /* Every metric at the far end of the sweep, so the chart's single line can be
   * read against everything it did not plot. */
  function renderTable() {
    var body = el('tuning-table').querySelector('tbody');
    var result = state.result;
    if (!result || !result.series.length) { body.innerHTML = ''; return; }
    var metrics = catalog.metrics || {};
    var head = '<tr><th>Metric</th>' + result.series.map(function (s, i) {
      return '<th><i style="background:' + SERIES[i % SERIES.length].color + '"></i>' + esc(s.mission_name) + '</th>';
    }).join('') + '</tr>';
    var rows = Object.keys(metrics).map(function (key) {
      var cells = result.series.map(function (s) {
        var last = s.points[s.points.length - 1];
        return '<td>' + esc(last ? formatValue(Number(last[key]) || 0, key) : '—') + '</td>';
      }).join('');
      return '<tr' + (key === state.metric ? ' class="is-current"' : '') + '><th scope="row">' + esc(metrics[key].label) + '</th>' + cells + '</tr>';
    }).join('');
    body.innerHTML = head + rows;
  }

  function renderReadout() {
    var host = el('tuning-readout');
    var result = state.result;
    if (!result || !result.series.length) { host.innerHTML = ''; return; }
    var first = result.series[0];
    var last = first.points[first.points.length - 1];
    if (!last) { host.innerHTML = ''; return; }
    var research = result.research_effects || {};
    var active = ['mission_speed_percent', 'xp_percent', 'reputation_percent', 'credit_percent', 'luck_percent']
      .filter(function (key) { return Number(research[key]) > 0; })
      .map(function (key) { return '+' + research[key] + '% ' + key.replace('_percent', '').replace('_', ' '); });
    host.innerHTML = '<p>At level ' + last.level + ' with ' + last.crew_count + ' crew, <strong>' + esc(first.mission_name)
      + '</strong> runs in ' + esc(formatValue(last.duration_seconds, 'duration_seconds')) + ' at ' + last.success_percent
      + '% success, costs ' + last.fatigue_cost + ' fatigue, and moves each crew member '
      + last.level_progress_percent + '% toward their next level.</p>'
      + '<p class="tuning-readout-sub">Stat totals STR ' + last.stat_totals.strength + ' &middot; CUN ' + last.stat_totals.cunning
      + ' &middot; SCI ' + last.stat_totals.science + ' &middot; CHA ' + last.stat_totals.charisma
      + ' &middot; ' + last.gear_slots_used + ' of ' + last.gear_slots_chosen + ' chosen items worn at this level'
      + (active.length ? ' &middot; research ' + esc(active.join(', ')) : ' &middot; no research online') + '.</p>';
  }

  var runTimer = null;
  function run() {
    if (!state.crewId || !state.missionIds.length) {
      state.result = null; renderChart(); renderTable(); renderReadout();
      return;
    }
    window.clearTimeout(runTimer);
    // Debounced: dragging a slider or ticking through protocols would otherwise
    // fire a request per keystroke.
    runTimer = window.setTimeout(function () {
      request('/api/admin/game-tuning/simulate.php', {
        crew_definition_id: state.crewId,
        mission_ids: state.missionIds,
        item_ids: state.itemIds,
        research_node_ids: state.researchIds,
        mode: state.mode,
        level: state.level,
        level_from: state.levelFrom,
        level_to: state.levelTo,
        crew_count: state.crewCount
      }).then(function (data) {
        setError('');
        state.result = data;
        renderChart(); renderTable(); renderReadout();
      }).catch(function (error) { setError(error.message); });
    }, 160);
  }

  function renderFindings(data) {
    var host = el('tuning-findings');
    var findings = data.findings || [];
    el('tuning-scan-summary').textContent = findings.length
      ? findings.length + ' finding' + (findings.length === 1 ? '' : 's') + ' across ' + data.baseline.missions_scanned + ' operations'
      : 'Nothing looks out of step across ' + data.baseline.missions_scanned + ' operations.';
    host.innerHTML = findings.map(function (f) {
      return '<div class="tuning-finding is-' + esc(f.severity) + '">'
        + '<span class="tuning-finding-area">' + esc(f.area) + '</span>'
        + '<strong>' + esc(f.subject) + '</strong>'
        + '<p>' + esc(f.detail) + '</p></div>';
    }).join('');
  }

  function renderScenarios(list) {
    var host = el('tuning-scenario-list');
    if (!host) return;
    host.innerHTML = (list || []).length ? list.map(function (row) {
      return '<div class="tuning-scenario"><button type="button" class="tuning-scenario-load" data-scenario="'
        + esc(JSON.stringify(row.config)) + '">' + esc(row.name) + '</button>'
        + '<button type="button" class="tuning-scenario-delete" data-scenario-delete="' + Number(row.id) + '" aria-label="'
        + esc('Delete ' + row.name) + '">&times;</button></div>';
    }).join('') : '<p class="admin-empty">No saved scenarios yet.</p>';
  }

  function loadScenarios() {
    if (!catalog || !catalog.scenarios_ready) {
      var card = el('tuning-scenario-card');
      if (card) card.hidden = true;
      return;
    }
    request('/api/admin/game-tuning/scenarios.php').then(function (data) { renderScenarios(data.scenarios); }).catch(function () {});
  }

  function applyScenario(config) {
    state.crewId = Number(config.crew_definition_id) || state.crewId;
    state.missionIds = (config.mission_ids || []).map(Number);
    state.itemIds = (config.item_ids || []).map(Number);
    state.researchIds = (config.research_node_ids || []).map(Number);
    state.mode = config.mode || 'level';
    state.metric = config.metric || 'success_percent';
    state.level = Number(config.level) || 1;
    state.levelFrom = Number(config.level_from) || 1;
    state.levelTo = Number(config.level_to) || catalog.max_level;
    state.crewCount = Number(config.crew_count) || 1;
    syncInputs();
    renderSlots(); renderItems(); renderStatReference(); renderItemLevelCeilings(); renderResearch(); renderMissions(); run();
  }

  function syncInputs() {
    el('tuning-crew').value = state.crewId ? String(state.crewId) : '';
    el('tuning-mode').value = state.mode;
    el('tuning-metric').value = state.metric;
    el('tuning-level-from').value = state.levelFrom;
    el('tuning-level-to').value = state.levelTo;
    el('tuning-fixed-level').value = state.level;
    el('tuning-crew-count').value = state.crewCount;
    el('tuning-level-range').hidden = state.mode !== 'level';
    el('tuning-fixed-level-field').hidden = state.mode !== 'crew_count';
    el('tuning-crew-count-field').hidden = state.mode !== 'level';
  }

  function wire() {
    /* The plot is measured, so a resized window needs a redraw. Debounced, and
     * only while there is something plotted. */
    var resizeTimer = null;
    window.addEventListener('resize', function () {
      window.clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(function () { if (state.result) renderChart(); }, 140);
    });
    el('tuning-crew').addEventListener('change', function () {
      state.crewId = Number(this.value) || null;
      /* A loadout outlives the crew member it was built for. Anything the new
       * subject may never wear is dropped rather than left sitting in a slot
       * contributing nothing -- which is what the server does with it, so
       * leaving it visible would misreport the simulation. */
      var crew = crewById(state.crewId);
      if (crew) {
        state.itemIds = state.itemIds.filter(function (id) {
          var item = itemById(id);
          return item && (!item.required_role || item.required_role === crew.role);
        });
      }
      renderSlots(); renderItems(); renderStatReference(); renderItemLevelCeilings(); run();
    });
    el('tuning-mode').addEventListener('change', function () { state.mode = this.value; syncInputs(); run(); });
    el('tuning-metric').addEventListener('change', function () { state.metric = this.value; renderChart(); renderTable(); });
    el('tuning-level-from').addEventListener('input', function () { state.levelFrom = Math.max(1, Number(this.value) || 1); run(); });
    el('tuning-level-to').addEventListener('input', function () { state.levelTo = Math.max(1, Number(this.value) || 1); run(); });
    el('tuning-fixed-level').addEventListener('input', function () { state.level = Math.max(1, Number(this.value) || 1); run(); });
    el('tuning-crew-count').addEventListener('input', function () { state.crewCount = Math.max(1, Number(this.value) || 1); run(); });
    el('tuning-item-search').addEventListener('input', renderItems);

    el('tuning-item-list').addEventListener('click', function (event) {
      var button = event.target.closest('[data-item-id]');
      if (button) assignItem(Number(button.getAttribute('data-item-id')));
    });
    /* Drag is the enhancement; the click above is the route that works on
     * touch and for a keyboard user, since every item is a real button. */
    el('tuning-item-list').addEventListener('dragstart', function (event) {
      var button = event.target.closest('[data-item-id]');
      if (!button) return;
      state.dragItemId = Number(button.getAttribute('data-item-id'));
      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'copy';
        try { event.dataTransfer.setData('text/plain', String(state.dragItemId)); } catch (ignore) {}
      }
    });
    el('tuning-slots').addEventListener('dragover', function (event) {
      var slot = event.target.closest('[data-slot]');
      if (!slot || !state.dragItemId) return;
      var item = itemById(state.dragItemId);
      // Only a slot the item actually fits accepts the drop.
      if (!item || item.slot !== slot.getAttribute('data-slot')) return;
      event.preventDefault();
      slot.classList.add('is-drop-target');
    });
    el('tuning-slots').addEventListener('dragleave', function (event) {
      var slot = event.target.closest('[data-slot]');
      if (slot) slot.classList.remove('is-drop-target');
    });
    el('tuning-slots').addEventListener('drop', function (event) {
      var slot = event.target.closest('[data-slot]');
      if (!slot || !state.dragItemId) return;
      event.preventDefault();
      slot.classList.remove('is-drop-target');
      var id = state.dragItemId;
      state.dragItemId = null;
      assignItem(id);
    });
    el('tuning-slots').addEventListener('click', function (event) {
      var clear = event.target.closest('[data-clear-slot]');
      if (clear) clearSlot(clear.getAttribute('data-clear-slot'));
    });

    el('tuning-research-list').addEventListener('change', function (event) {
      var box = event.target.closest('[data-research-id]');
      if (!box) return;
      var id = Number(box.getAttribute('data-research-id'));
      state.researchIds = box.checked
        ? state.researchIds.concat([id])
        : state.researchIds.filter(function (existing) { return existing !== id; });
      keepFocus(el('tuning-research-list'), renderResearch); run();
    });
    el('tuning-research-none').addEventListener('click', function () { state.researchIds = []; renderResearch(); run(); });
    el('tuning-research-all').addEventListener('click', function () {
      state.researchIds = ((catalog && catalog.research) || []).map(function (n) { return Number(n.id); });
      renderResearch(); run();
    });

    el('tuning-mission-list').addEventListener('change', function (event) {
      var box = event.target.closest('[data-mission-id]');
      if (!box) return;
      var id = Number(box.getAttribute('data-mission-id'));
      if (box.checked) {
        if (state.missionIds.length >= 6) { box.checked = false; setError('Compare at most six operations at once.'); return; }
        state.missionIds = state.missionIds.concat([id]);
      } else {
        state.missionIds = state.missionIds.filter(function (existing) { return existing !== id; });
      }
      setError('');
      keepFocus(el('tuning-mission-list'), renderMissions); run();
    });

    var sweepScan = el('tuning-sweep-scan');
    if (sweepScan) sweepScan.addEventListener('click', function () {
      var button = this;
      button.disabled = true; button.classList.add('is-busy');
      /* The crew member and level already chosen on this page drive it: a
         sector's numbers mean nothing without saying who is walking into it,
         and asking twice for the same choice is how two answers appear. */
      request('/api/admin/game-tuning/sweep.php', { crew_definition_id: state.crewId, level: state.level })
        .then(renderSweep)
        .catch(function (error) { setError(error.message); })
        .finally(function () { button.disabled = false; button.classList.remove('is-busy'); });
    });

    el('tuning-scan').addEventListener('click', function () {
      var button = this;
      button.disabled = true; button.classList.add('is-busy');
      request('/api/admin/game-tuning/outliers.php')
        .then(renderFindings)
        .catch(function (error) { setError(error.message); })
        .finally(function () { button.disabled = false; button.classList.remove('is-busy'); });
    });

    var saveBtn = el('tuning-scenario-save');
    if (saveBtn) saveBtn.addEventListener('click', function () {
      var name = (el('tuning-scenario-name').value || '').trim();
      if (!name) { setError('Give the scenario a name before saving it.'); return; }
      saveBtn.disabled = true; saveBtn.classList.add('is-busy');
      request('/api/admin/game-tuning/scenarios.php', {
        action: 'save', name: name,
        config: {
          crew_definition_id: state.crewId, mission_ids: state.missionIds, item_ids: state.itemIds,
          research_node_ids: state.researchIds, mode: state.mode, metric: state.metric,
          level: state.level, level_from: state.levelFrom, level_to: state.levelTo, crew_count: state.crewCount
        }
      }).then(function () { setError(''); el('tuning-scenario-name').value = ''; loadScenarios(); })
        .catch(function (error) { setError(error.message); })
        .finally(function () { saveBtn.disabled = false; saveBtn.classList.remove('is-busy'); });
    });
    var scenarioList = el('tuning-scenario-list');
    if (scenarioList) scenarioList.addEventListener('click', function (event) {
      var load = event.target.closest('[data-scenario]');
      if (load) {
        var config = {};
        try { config = JSON.parse(load.getAttribute('data-scenario')); } catch (ignore) { return; }
        applyScenario(config);
        return;
      }
      var remove = event.target.closest('[data-scenario-delete]');
      if (!remove) return;
      request('/api/admin/game-tuning/scenarios.php', { action: 'delete', id: Number(remove.getAttribute('data-scenario-delete')) })
        .then(loadScenarios).catch(function (error) { setError(error.message); });
    });
  }

  function boot() {
    if (state.loaded || !can('game_tuning.view')) return;
    state.loaded = true;
    request('/api/admin/game-tuning/catalog.php').then(function (data) {
      catalog = data;
      state.levelTo = data.max_level;
      el('tuning-level-to').max = data.max_level;
      el('tuning-level-from').max = data.max_level;
      el('tuning-fixed-level').max = data.max_level;
      el('tuning-crew').innerHTML = '<option value="">Choose a crew member</option>'
        + (data.crew || []).map(function (c) {
          return '<option value="' + Number(c.id) + '">' + esc(c.name + ' · ' + c.role) + '</option>';
        }).join('');
      el('tuning-metric').innerHTML = Object.keys(data.metrics || {}).map(function (key) {
        return '<option value="' + esc(key) + '">' + esc(data.metrics[key].label) + '</option>';
      }).join('');
      // Opens on something rather than nothing: the first crew member and the
      // first enabled operation, which is the comparison most often wanted.
      if ((data.crew || []).length) state.crewId = Number(data.crew[0].id);
      var firstMission = (data.missions || []).filter(function (m) { return m.is_enabled; })[0];
      if (firstMission) state.missionIds = [Number(firstMission.id)];
      syncInputs();
      renderSlots(); renderItems(); renderStatReference(); renderItemLevelCeilings(); renderResearch(); renderMissions(); loadScenarios(); run();
    }).catch(function (error) { setError(error.message); });
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (!el('section-game-tuning')) return;
    wire();
  });
  /* Loaded on first view rather than on page load -- the catalogue is four
   * queries the other admin sections have no use for. showSection() calls
   * this, the same hook every other section script in this console exposes. */
  window.loadGameTuning = function () { return boot(); };
}());
