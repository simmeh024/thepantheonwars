/* Salvage Sweep.
 *
 * The board this file draws is deliberately ignorant: it knows how many cells
 * there are and which ones have been turned over, and nothing else. Every cell
 * is resolved by api/missions/sweep/pick.php at the moment it is picked, so
 * reading this script, or the network tab, tells a player nothing about where
 * the hazards are. Keep it that way -- the temptation to send the whole board
 * once and animate the reveals locally is the one change that would break it.
 */
(function () {
  'use strict';

  var state = { data: null, busy: false, lastFind: null, result: null };
  var gate = document.getElementById('sweep-gate');
  var content = document.getElementById('sweep-content');
  var status = document.getElementById('sweep-status');
  var boardArea = document.getElementById('sweep-board-area');
  var boardTitle = document.getElementById('sweep-board-title');
  var boardMeta = document.getElementById('sweep-board-meta');
  var boardActions = document.getElementById('sweep-board-actions');
  var crewList = document.getElementById('sweep-crew-list');
  var crewCard = document.getElementById('sweep-crew-card');
  var ladder = document.getElementById('sweep-ladder');
  var sectorTitle = document.getElementById('sweep-sector-title');
  var sectorBody = document.getElementById('sweep-sector-body');
  var bankButton = document.getElementById('sweep-bank');
  var abandonButton = document.getElementById('sweep-abandon');
  var loginButton = document.getElementById('sweep-login');
  var profileCard = document.getElementById('sweep-profile-card');
  var trophyList = document.getElementById('sweep-trophy-list');
  var crewSort = document.getElementById('sweep-crew-sort');

  function esc(value) { var node = document.createElement('div'); node.textContent = value == null ? '' : String(value); return node.innerHTML; }
  function num(value) { return Number(value || 0).toLocaleString(); }
  function setStatus(message, isError) {
    status.textContent = message || '';
    status.classList.toggle('is-error', !!isError);
  }
  function request(url, payload) {
    var options = { credentials: 'same-origin', cache: 'no-store' };
    if (payload) {
      payload.csrf = window.PW_AUTH && window.PW_AUTH.csrf ? window.PW_AUTH.csrf : '';
      options.method = 'POST';
      options.headers = { 'Content-Type': 'application/json' };
      options.body = JSON.stringify(payload);
    }
    return fetch(url, options).then(function (response) {
      return response.json().catch(function () { return {}; });
    }).then(function (data) {
      if (!data.ok) throw new Error(data.error || 'The sweep could not be reached.');
      return data;
    });
  }

  /* ---- The board -------------------------------------------------------- */

  var CELL_GLYPH = { gear: '◆', crew: '◉', cache: '¤', empty: '·', hazard: '✕', shrug: '✦' };

  CELL_GLYPH.stabilised = '\u25c6';
  var PREVIEW_GLYPH = { material: '\u25b3', credits: '\u00a4', equipment: '\u25c6', unknown: '?' };
  var PREVIEW_WORD = { material: 'Material', credits: 'Credits', equipment: 'Equipment', unknown: 'Unknown' };

  function cellMarkup(index, cell, playable, preview) {
    if (!cell) {
      /* An identified cell says what kind of thing is under it and nothing
         more -- the category, never the item, and never that it is safe by
         saying so out loud, since only safe cells are ever identified. */
      var known = preview && PREVIEW_WORD[preview] ? preview : '';
      var label = known
        ? 'Scan cell ' + (index + 1) + ', identified as ' + PREVIEW_WORD[known].toLowerCase()
        : 'Scan cell ' + (index + 1);
      return '<button type="button" class="sweep-cell' + (known ? ' is-known is-known-' + known : '') + '"'
        + ' data-sweep-cell="' + index + '"' + (playable ? '' : ' disabled')
        + (known ? ' title="' + esc(PREVIEW_WORD[known]) + '"' : '')
        + ' aria-label="' + esc(label) + '">'
        + '<span aria-hidden="true">' + (known ? PREVIEW_GLYPH[known] : '?') + '</span></button>';
    }
    var label = cell.label || (cell.type === 'hazard' ? 'Collapse' : (cell.type === 'shrug' ? 'Braced through a collapse' : 'Nothing here'));
    /* The thing itself, when it has artwork. A glyph is the fallback for a
       definition with no image and for the outcomes that are not objects at
       all -- a collapse, an empty pocket, a braced escape. */
    var icon = cell.type === 'cache' ? 'images/credit-stick.webp' : safeImage(cell.icon);
    var art = icon
      ? '<img class="sweep-cell-art" src="' + esc(icon) + '" alt="">'
      : '<span class="sweep-cell-glyph" aria-hidden="true">' + (CELL_GLYPH[cell.type] || CELL_GLYPH.empty) + '</span>';
    /* The hint is only ever attached to a cell already turned over, and it is
       the count of hazards around it -- the reward for having spent a scan
       there, not a free look at the board. */
    var hint = cell.hint === null || cell.hint === undefined || cell.type === 'hazard'
      ? ''
      : '<b class="sweep-cell-hint' + (Number(cell.hint) > 0 ? ' is-warn' : '') + '">' + Number(cell.hint) + '</b>';
    /* Rarity above common gets a shine, and the tier word goes in the label:
       a visual effect alone says "special" without saying how special, and
       says nothing at all to a screen reader. */
    var tier = String(cell.tier || '').toLowerCase();
    var shiny = tier && tier !== 'common' ? ' is-shiny is-tier-' + esc(tier) : '';
    var spoken = label + (tier && tier !== 'common' ? ' (' + tier + ')' : '');
    return '<div class="sweep-cell is-open is-' + esc(cell.type) + shiny + '" role="img" tabindex="0"'
      + ' aria-label="' + esc('Cell ' + (index + 1) + ': ' + spoken) + '" title="' + esc(spoken) + '">'
      + art + hint + '</div>';
  }

  function conditionMarkup(condition, compact) {
    condition = condition || {};
    var key = String(condition.key || 'clear').replace(/[^a-z0-9-]/gi, '');
    var template = String(condition.template || 'nominal').replace(/[^a-z0-9-]/gi, '');
    var label = String(condition.label || 'Nominal field');
    var warning = String(condition.warning || 'Nominal conditions. Field systems are operating within expected limits.');
    var effect = String(condition.effect || 'No sector penalty.');
    return '<aside class="sweep-condition is-template-' + esc(template) + (compact ? ' is-compact' : '') + '"'
      + ' data-sweep-condition="' + esc(key) + '"><span class="sweep-condition-label">Sector condition</span>'
      + '<strong>' + esc(label) + '</strong><p>' + esc(warning) + '</p>'
      + '<small>' + esc(effect) + '</small></aside>';
  }

  /* Full field markup belongs exclusively to a finished run. The live board
     remains deliberately ignorant of unopened cells. */
  function resultCellMarkup(cell, cols, result) {
    var row = Math.floor(Number(cell.index) / cols) + 1;
    var col = Number(cell.index) % cols + 1;
    var type = String(cell.type || 'empty');
    var label = String(cell.label || (type === 'hazard' ? 'Collapse' : 'No recovery'));
    var icon = type === 'cache' ? 'images/credit-stick.webp' : safeImage(cell.icon);
    var tier = String(cell.tier || '').toLowerCase();
    var shiny = tier && tier !== 'common' ? ' is-shiny is-tier-' + esc(tier) : '';
    var isReward = ['gear', 'crew', 'cache'].indexOf(type) !== -1;
    var tether = result.payout && result.payout.tether;
    var tethered = tether && Number(tether.cell_index) === Number(cell.index);
    var secured = result.field.status === 'banked' && !!cell.revealed;
    var stateLabel = secured ? 'secured' : (tethered && tether.state !== 'no_room' ? 'recovered by tether' : 'left in the field');
    var art = icon
      ? '<img class="sweep-cell-art" src="' + esc(icon) + '" alt="">'
      : '<span class="sweep-cell-glyph" aria-hidden="true">' + (CELL_GLYPH[type] || CELL_GLYPH.empty) + '</span>';
    return '<div class="sweep-cell is-open is-result is-' + esc(type) + shiny
      + (isReward && !secured && !tethered ? ' is-unrecovered' : '')
      + (tethered ? ' is-tethered' : '') + '" role="img" tabindex="0"'
      + ' aria-label="' + esc('Row ' + row + ', column ' + col + ': ' + label + (isReward ? '; ' + stateLabel : '')) + '"'
      + ' title="' + esc('R' + row + ' C' + col + ': ' + label + (isReward ? ' — ' + stateLabel : '')) + '">'
      + art + '<span class="sweep-result-cell-position" aria-hidden="true">' + row + ',' + col + '</span>'
      + (isReward ? '<span class="sweep-result-cell-state" aria-hidden="true">' + (secured ? '\u2713' : (tethered ? '\u2726' : '\u2014')) + '</span>' : '')
      + '</div>';
  }

  function resultManifestMarkup(result) {
    var cols = Number(result.field.grid_cols) || 1;
    var rewards = (result.field.cells || []).filter(function (cell) {
      return ['gear', 'crew', 'cache'].indexOf(String(cell.type)) !== -1;
    });
    if (!rewards.length) return '<p class="sweep-muted">No recoveries were present in this field.</p>';
    var tether = result.payout && result.payout.tether;
    return '<ul class="sweep-result-manifest">' + rewards.map(function (cell) {
      var row = Math.floor(Number(cell.index) / cols) + 1;
      var col = Number(cell.index) % cols + 1;
      var tethered = tether && Number(tether.cell_index) === Number(cell.index);
      var secured = result.field.status === 'banked' && !!cell.revealed;
      var resultState = secured ? 'Secured' : (tethered && tether.state !== 'no_room' ? 'Tethered' : 'Left behind');
      return '<li class="' + (secured ? 'is-secured' : (tethered ? 'is-tethered' : 'is-missed')) + '">'
        + '<b>R' + row + ' C' + col + '</b><span>' + esc(cell.label) + '</span><small>' + esc(resultState) + '</small></li>';
    }).join('') + '</ul>';
  }

  function resultPayoutMarkup(result) {
    var payout = result.payout || {};
    var field = result.field;
    var cards = [];
    if (field.status === 'banked') {
      if (payout.credits) cards.push({ label: 'Credits secured', value: num(payout.credits) });
      if ((payout.gear || []).length) cards.push({ label: 'Items banked', value: String((payout.gear || []).length) });
      if ((payout.crew_recruited || []).length) cards.push({ label: 'Crew joined', value: String((payout.crew_recruited || []).length) });
      if ((payout.crew_pending || []).length) cards.push({ label: 'Crew offers', value: String((payout.crew_pending || []).length) });
      if (payout.xp) cards.push({ label: 'Crew XP', value: num(payout.xp) });
      if (!cards.length) cards.push({ label: 'Haul secured', value: 'No payout' });
    } else if (payout.tether) {
      cards.push({ label: payout.tether.state === 'no_room' ? 'Tether return blocked' : 'Tether return', value: payout.tether.name || 'One recovery' });
    } else {
      cards.push({ label: field.status === 'abandoned' ? 'Haul withdrawn' : 'Haul lost', value: 'No rewards kept' });
    }
    var overflow = field.status === 'banked' && payout.skipped ? Object.keys(payout.skipped).reduce(function (sum, id) {
      return sum + Number(payout.skipped[id] || 0);
    }, 0) : 0;
    return '<div class="sweep-result-payout">' + cards.map(function (card) {
      return '<span><small>' + esc(card.label) + '</small><strong>' + esc(card.value) + '</strong></span>';
    }).join('') + '</div>'
      + (overflow ? '<p class="sweep-result-overflow">' + overflow + ' item' + (overflow === 1 ? '' : 's') + ' could not be stored because the relevant hold is full.</p>' : '');
  }

  function renderResult() {
    var result = state.result;
    var field = result.field;
    var won = field.status === 'banked';
    var abandoned = field.status === 'abandoned';
    var heading = won ? 'Recovery secured' : (abandoned ? 'Sweep withdrawn' : 'Field lost');
    var copy = won
      ? 'Your recovered haul has been processed. The complete field is now mapped below.'
      : (abandoned
        ? 'You withdrew before banking the haul. The complete field is mapped below for your debrief.'
        : 'The field collapsed before the haul could be banked. The complete field is mapped below.');
    var cells = (field.cells || []).map(function (cell) {
      return resultCellMarkup(cell, Number(field.grid_cols), result);
    }).join('');
    boardTitle.textContent = heading;
    boardMeta.innerHTML = '<span><small>Rewards on field</small><strong>' + Number(field.reward_count || 0) + '</strong></span>'
      + '<span><small>Left unopened</small><strong>' + Number(field.unrecovered_count || 0) + '</strong></span>'
      + '<span><small>Scans used</small><strong>' + Number(field.picks_used || 0) + ' / ' + Number(field.picks_total || 0) + '</strong></span>';
    boardArea.innerHTML = '<section class="sweep-result is-' + (won ? 'won' : 'lost') + '" aria-labelledby="sweep-result-title">'
      + '<div class="sweep-result-head"><span class="eyebrow">' + (won ? 'Field debrief' : 'Recovery debrief') + '</span>'
      + '<h3 id="sweep-result-title">' + heading + '</h3><p>' + esc(copy) + '</p></div>'
      + conditionMarkup(field.condition, true)
      + resultPayoutMarkup(result)
      + '<div class="sweep-result-legend"><span class="is-secured">\u2713 Secured</span><span class="is-missed">\u2014 Left in field</span>'
      + (result.payout && result.payout.tether ? '<span class="is-tethered">\u2726 Tethered</span>' : '') + '</div>'
      + '<div class="sweep-grid sweep-result-grid" style="--sweep-cols:' + Number(field.grid_cols) + '" role="group" aria-label="Complete Salvage Sweep field">' + cells + '</div>'
      + '<section class="sweep-result-manifest-wrap"><h4>Field manifest</h4><p>Every cache, item and crew recovery is listed by its row and column.</p>'
      + resultManifestMarkup(result) + '</section>'
      + '<div class="sweep-result-actions"><button type="button" class="btn btn-solid" data-sweep-result-close>Plan another sweep</button></div>'
      + '</section>';
    boardActions.hidden = true;
    if (crewCard) crewCard.hidden = true;
  }

  function showResult(field, payout) {
    if (!field) return;
    state.result = { field: field, payout: payout || {} };
  }

  function renderBoard() {
    if (state.result) {
      renderResult();
      return;
    }
    var run = state.data && state.data.run;
    if (!run) {
      boardTitle.textContent = 'No sweep under way';
      boardMeta.innerHTML = '';
      boardActions.hidden = true;
      var tier = state.data && state.data.tier;
      boardArea.innerHTML = '<p class="sweep-muted">' + (tier
        ? 'Choose a crew member below to open a field.'
        : 'No sector has been surveyed at or below your standing yet.') + '</p>';
      if (crewCard) crewCard.hidden = false;
      return;
    }
    boardTitle.textContent = 'Sector ' + run.rank_number;
    var spent = run.ended_reason === 'spent';
    boardMeta.innerHTML = '<span><small>Scans</small><strong>' + run.picks_left + ' / ' + run.picks_total + '</strong></span>'
      + '<span><small>Credits held</small><strong>' + num(run.credits_found) + '</strong></span>'
      + '<span><small>Condition</small><strong>' + esc((run.condition || {}).label || 'Nominal field') + '</strong></span>'
      + (run.hint_radius > 0 ? '<span><small>Survey</small><strong>' + run.hint_radius + ' ring' + (run.hint_radius === 1 ? '' : 's') + '</strong></span>' : '')
      + (run.shrug_percent > 0 ? '<span><small>Brace</small><strong>' + (run.shrug_used ? 'spent' : run.shrug_percent + '%') + '</strong></span>' : '')
      /* Momentum is shown as what it is worth right now rather than as its
         rate: "+18% credits" is the number the next cache will pay, which is
         what a player is deciding on. */
      + (run.momentum_percent > 0
        ? '<span><small>Momentum</small><strong>+' + Math.round(run.picks_used * run.momentum_percent) + '% credits</strong></span>' : '')
      + (run.tether_percent > 0 ? '<span><small>Tether</small><strong>' + run.tether_percent + '%</strong></span>' : '');

    var open = {};
    (run.cells || []).forEach(function (cell) { open[Number(cell.index)] = cell; });
    /* Cache Recognition marks a few unopened cells with what they hold, never
       which item and never a collapse. */
    var preview = {};
    (run.previews || []).forEach(function (row) { preview[Number(row.index)] = String(row.preview); });
    var playable = run.status === 'active' && run.picks_left > 0 && !state.busy;
    var cells = [];
    for (var index = 0; index < run.grid_rows * run.grid_cols; index++) {
      cells.push(cellMarkup(index, open[index], playable, preview[index]));
    }
    boardArea.innerHTML = conditionMarkup(run.condition, true)
      + '<div class="sweep-grid" style="--sweep-cols:' + run.grid_cols + '" role="group" aria-label="Salvage field">'
      + cells.join('') + '</div>'
      + (spent ? '<p class="sweep-muted sweep-spent">Every scan is spent. Withdraw to keep what you have.</p>' : '');
    boardActions.hidden = run.status !== 'active';
    bankButton.disabled = state.busy;
    abandonButton.disabled = state.busy;
    if (crewCard) crewCard.hidden = run.status === 'active';
  }

  /* ---- The sector and the ladder ---------------------------------------- */

  function renderSector() {
    var tier = state.data && state.data.tier;
    var rank = Number((state.data.reputation || {}).level_number) || 0;
    if (!tier) {
      sectorTitle.textContent = 'No sector open';
      sectorBody.innerHTML = '<p class="sweep-muted">No sector has been surveyed at or below rank ' + rank
        + ' yet. A sector opens for every rank from its own upward, until a higher one takes over.</p>';
      return;
    }
    sectorTitle.textContent = tier.name || ('Sector ' + tier.rank_number);
    sectorBody.innerHTML = '<dl class="sweep-facts">'
      + '<div><dt>Field</dt><dd>' + tier.grid_rows + ' &times; ' + tier.grid_cols + '</dd></div>'
      + '<div><dt>Collapses</dt><dd>' + tier.hazard_count + '</dd></div>'
      + '<div><dt>Base scans</dt><dd>' + tier.base_picks + '</dd></div>'
      + '<div><dt>Fatigue</dt><dd>' + tier.fatigue_cost + '</dd></div>'
      + '</dl>'
      + conditionMarkup(tier.condition, true)
      + '<p class="sweep-muted">Swept ' + num(state.data.sweeps_at_rank) + ' time' + (Number(state.data.sweeps_at_rank) === 1 ? '' : 's') + ' at this rank.</p>';
  }

  function renderLadder() {
    var rows = (state.data && state.data.ladder) || [];
    if (!rows.length) { ladder.innerHTML = '<li class="sweep-muted">No sectors have been surveyed yet.</li>'; return; }
    ladder.innerHTML = rows.map(function (row) {
      var cls = row.is_current ? 'is-current' : (row.unlocked ? 'is-earned' : 'is-sealed');
      /* A sealed rung shows its rank and its board shape but never its
         manifest name: what a field holds is the reward for reaching it. */
      /* The manifest is not named. What a sector pays is the thing worth
         finding out by sweeping it, and printing the loot table's name turned
         the ladder into a contents list. The board shape is the useful part
         and gives nothing away. */
      var detail = row.unlocked
        ? row.grid_rows + '\u00d7' + row.grid_cols + ' \u00b7 ' + row.hazard_count + ' collapse'
          + (Number(row.hazard_count) === 1 ? '' : 's') + ' \u00b7 ' + esc((row.condition || {}).label || 'Nominal')
        : 'Rank ' + row.rank_number + ' required';
      return '<li class="sweep-rung ' + cls + '">'
        + '<span class="sweep-rung-mark">' + row.rank_number + '</span>'
        + '<span class="sweep-rung-copy"><strong>' + esc(row.name) + '</strong><small>' + detail + '</small></span>'
        + (row.unlocked && row.sweeps_completed ? '<b>' + row.sweeps_completed + '</b>' : '')
        + '</li>';
    }).join('');
  }

  /* ---- Crew -------------------------------------------------------------
   * Each figure on a card is derived from one crew stat, so each says which
   * one and how much of it -- otherwise "Survey 0" for the whole roster reads
   * as broken rather than as nobody having enough Science yet, which is what
   * it actually means.
   * ---------------------------------------------------------------------- */

  // The roster's own portrait rule, hand-duplicated per this codebase's
  // no-shared-module convention. Anything else renders the fallback initial.
  function safeImage(url) {
    return /^(?:images\/[a-zA-Z0-9._-]+|\/uploads\/mission-crew-images\/img_[a-f0-9]{16}\.jpg)$/.test(String(url || ''))
      ? String(url) : '';
  }
  function tuning() {
    return (state.data && state.data.tuning) || {};
  }
  function fmt(value) {
    var rounded = Math.round(Number(value) * 100) / 100;
    return String(rounded);
  }

  /* One entry per figure: the stat behind it, and a sentence that reads the
     rate from the server rather than restating it. A tooltip carrying its own
     copy of "12" becomes a confident lie the first time that is retuned. */
  function crewFigures(member) {
    var p = member.projection || {};
    var t = tuning();
    var perPick = Number(t.cunning_per_pick) || 12;
    var perRing = Number(t.science_per_ring) || 18;
    var perShrug = Number(t.strength_shrug_per_point) || 1.4;
    var perXp = Number(t.charisma_xp_per_point) || 0.8;
    var maxRing = Number(t.max_hint_radius) || 2;
    var science = Number(member.science) || 0;
    var toNextRing = Math.max(0, (Math.floor(science / perRing) + 1) * perRing - science);
    return [
      { key: 'cunning', label: 'Scans', short: 'CUN', stat: Number(member.cunning) || 0,
        value: String(p.picks_total || 0),
        copy: 'Cells this crew member can turn over before the board closes. The sector supplies the base; '
          + 'Cunning adds one more per ' + fmt(perPick) + ' points.' },
      { key: 'science', label: 'Survey', short: 'SCI', stat: science,
        value: String(p.hint_radius || 0),
        copy: 'Rings of cells around each opened cell that report how many collapses border it. '
          + 'One ring per ' + fmt(perRing) + ' Science, up to ' + maxRing + '.'
          + (Number(p.hint_radius || 0) < maxRing ? ' ' + toNextRing + ' more Science for the next ring.' : '') },
      { key: 'strength', label: 'Brace', short: 'STR', stat: Number(member.strength) || 0,
        value: fmt(p.shrug_percent || 0) + '%',
        copy: 'Chance to survive the first collapse instead of losing the haul. Once per sweep. '
          + fmt(perShrug) + '% per point of Strength, capped at ' + fmt(t.shrug_cap || 60) + '%.' },
      { key: 'charisma', label: 'XP', short: 'CHA', stat: Number(member.charisma) || 0,
        value: String(p.xp_reward || 0),
        copy: 'Experience this crew member earns for a banked sweep. The sector sets it; '
          + 'Charisma adds ' + fmt(perXp) + '% per point.' }
    ];
  }

  function renderCrew() {
    var crew = (state.data && state.data.crew) || [];
    if (!crew.length) { crewList.innerHTML = '<p class="sweep-muted">No crew are available.</p>'; return; }
    var tier = state.data.tier;
    var favoritesReady = !!state.data.crew_favorites_ready;
    /* Deployable crew first under every sort. Four of six being unusable is
       normal once a few are out, and burying the two that can go under them is
       the difference between a roster and a list -- so readiness is the outer
       key and the chosen sort orders within it. "Ready first" is that rule with
       nothing on top, which is why it is the default. */
    var mode = (crewSort && crewSort.value) || 'ready';
    var stat = { 'scans-desc': 'picks_total', 'brace-desc': 'shrug_percent', 'survey-desc': 'hint_radius' }[mode];
    var ordered = crew.slice().sort(function (left, right) {
      var byReady = (right.can_deploy ? 1 : 0) - (left.can_deploy ? 1 : 0);
      if (byReady) return byReady;
      var by = 0;
      if (mode === 'favorites') by = (right.is_favorite ? 1 : 0) - (left.is_favorite ? 1 : 0);
      if (stat) by = (Number((right.projection || {})[stat]) || 0) - (Number((left.projection || {})[stat]) || 0);
      if (mode === 'fatigue-desc') by = (Number(right.fatigue) || 0) - (Number(left.fatigue) || 0);
      if (mode === 'level-desc') by = (Number(right.level) || 0) - (Number(left.level) || 0);
      if (mode === 'name-asc') by = String(left.name).localeCompare(String(right.name));
      // Level breaks a tie before the name, so the most developed of several
      // crew sharing a figure is not placed by alphabet.
      return by || (Number(right.level) || 0) - (Number(left.level) || 0)
        || String(left.name).localeCompare(String(right.name));
    });
    crewList.innerHTML = ordered.map(function (member) {
      var why = !tier ? 'No sector open'
        : (member.status !== 'available' ? 'On assignment'
        : (member.fatigue < tier.fatigue_cost ? 'Needs ' + tier.fatigue_cost + ' fatigue' : ''));
      var portrait = safeImage(member.portrait_url);
      var face = portrait
        ? '<img src="' + esc(portrait) + '" alt="">'
        : '<span aria-hidden="true">' + esc(String(member.name || '?').charAt(0)) + '</span>';
      var figures = crewFigures(member).map(function (figure) {
        var tip = figure.label + ' ' + figure.value + ' \u2014 ' + figure.copy
          + ' This crew member has ' + figure.stat + ' ' + figure.key.charAt(0).toUpperCase() + figure.key.slice(1) + '.';
        return '<span class="sweep-figure is-' + figure.key + '" tabindex="0" title="' + esc(tip) + '">'
          + '<small>' + figure.label + '</small><b>' + esc(figure.value) + '</b>'
          + '<i>' + figure.short + ' ' + figure.stat + '</i></span>';
      }).join('');
      /* The fatigue bar marks what this sector costs, so "can they go" is a
         glance rather than two numbers subtracted in the reader's head. */
      var max = Math.max(1, Number(member.fatigue_max) || 1);
      var have = Math.max(0, Math.min(max, Number(member.fatigue) || 0));
      var cost = tier ? Math.max(0, Math.min(max, Number(tier.fatigue_cost) || 0)) : 0;
      var fatigueBar = '<span class="sweep-crew-bar" role="img" aria-label="'
        + esc(have + ' of ' + max + ' fatigue' + (cost ? '; this sector costs ' + cost : '')) + '">'
        + '<i style="width:' + Math.round((have / max) * 100) + '%"></i>'
        + (cost ? '<u style="left:' + Math.round((cost / max) * 100) + '%"></u>' : '') + '</span>';
      /* The same favourites the missions roster keeps, so a star set there is
         set here. The control is hidden rather than shown-and-refused when the
         column has not been migrated, per the standing permission-aware rule. */
      var favorite = !!member.is_favorite;
      var star = favoritesReady
        ? '<button type="button" class="sweep-crew-star' + (favorite ? ' is-on' : '') + '" data-sweep-favorite="' + member.id + '"'
          + ' aria-pressed="' + (favorite ? 'true' : 'false') + '"'
          + ' title="' + esc((favorite ? 'Remove ' : 'Add ') + member.name + (favorite ? ' from' : ' to') + ' favourites') + '"'
          + ' aria-label="' + esc((favorite ? 'Remove ' : 'Add ') + member.name + (favorite ? ' from' : ' to') + ' favourites') + '">'
          + '<span aria-hidden="true">\u2605</span></button>'
        : '';
      return '<div class="sweep-crew is-tier-' + esc(member.tier) + (member.can_deploy ? '' : ' is-blocked')
        + (favorite ? ' is-favorite' : '') + '">' + star
        + '<div class="sweep-crew-head">'
        + '<span class="sweep-crew-face">' + face + '</span>'
        + '<span class="sweep-crew-name"><strong>' + esc(member.name) + '</strong>'
        + '<em>' + esc(member.role) + ' &middot; L' + member.level + '</em>'
        + '<small class="sweep-crew-tier">' + esc(String(member.tier || 'common')) + '</small></span>'
        + '</div>'
        + '<div class="sweep-crew-projection">' + figures + '</div>'
        + '<div class="sweep-crew-foot">'
        + '<span class="sweep-crew-fatigue">' + fatigueBar + '<small>' + have + ' / ' + max + ' fatigue'
        + (cost ? ' &middot; costs ' + cost : '') + '</small></span>'
        + (member.can_deploy
          ? '<button type="button" class="btn btn-solid" data-sweep-send="' + member.id + '">Send</button>'
          : '<span class="sweep-crew-blocked">' + esc(why) + '</span>')
        + '</div></div>';
    }).join('');
  }

  /* The commander card the missions page shows, rendered from the same block
     the state endpoint now sends. Hand-duplicated rather than shared, which is
     this codebase's standing convention for markup across pages. */
  function renderProfile() {
    if (!profileCard) return;
    var player = state.data && state.data.player;
    if (!player) { profileCard.innerHTML = ''; return; }
    var reputation = player.reputation || {};
    var name = String(player.display_name || 'Commander');
    var rankColor = reputation.level_color || '#c7ccd6';
    var progress = Math.max(0, Math.min(100, Number(reputation.progress_percent) || 0));
    var nextLine = reputation.next_level_name
      ? num(reputation.points) + ' / ' + num(reputation.next_level_threshold) + ' to ' + reputation.next_level_name
      : num(reputation.points) + ' reputation \u00b7 highest standing reached';
    profileCard.innerHTML = '<span class="eyebrow">Commander</span>'
      + '<div class="sweep-profile-head">'
      + '<span class="sweep-profile-avatar" style="--rank-color:' + esc(rankColor) + '">'
      + '<img src="/uploads/avatars/' + encodeURIComponent(player.id) + '.jpg" alt="" onerror="this.hidden=true">'
      + '<span class="sweep-profile-fallback">' + esc(name.charAt(0).toUpperCase()) + '</span></span>'
      + '<span class="sweep-profile-identity"><strong>' + esc(name) + '</strong>'
      + '<span class="sweep-profile-rank" style="color:' + esc(rankColor) + '">'
      + (reputation.level_number ? '<i>' + Number(reputation.level_number) + '</i>' : '')
      + esc(reputation.level_name || 'Unranked') + '</span></span></div>'
      + '<div class="sweep-profile-rep"><span class="sweep-profile-track"><i style="width:' + progress + '%;background:' + esc(rankColor) + '"></i></span>'
      + '<small>' + esc(nextLine) + '</small></div>'
      + '<div class="sweep-profile-credits"><span>Total credits</span><strong>' + num(player.credits) + '</strong></div>';
  }

  /* Banked epic and legendary finds only. A run that collapsed with a
     legendary on the board never won it, and this is the one panel whose whole
     job is to record what was kept. */
  function renderTrophies() {
    if (!trophyList) return;
    var rows = (state.data && state.data.trophies) || [];
    if (!rows.length) {
      trophyList.innerHTML = '<p class="sweep-muted">No epic or legendary recovery yet. They are logged here once a sweep carrying one is banked.</p>';
      return;
    }
    trophyList.innerHTML = '<ul class="sweep-trophies">' + rows.map(function (row) {
      var tier = String(row.tier || '').toLowerCase();
      var icon = safeImage(row.icon);
      var art = icon
        ? '<img src="' + esc(icon) + '" alt="">'
        : '<span aria-hidden="true">' + (row.kind === 'crew' ? '\u25c9' : '\u25c6') + '</span>';
      /* The name ellipses in a 268px rail, so the full one has to be reachable
         somewhere -- the title is the whole label, not just the name. */
      return '<li class="sweep-trophy is-tier-' + esc(tier) + '" title="'
        + esc(row.name + ' — ' + tier + ', recovered in sector ' + Number(row.rank_number)) + '">'
        + '<span class="sweep-trophy-art">' + art + '</span>'
        + '<span class="sweep-trophy-copy"><strong>' + esc(row.name) + '</strong>'
        + '<small>' + esc(tier) + ' \u00b7 sector ' + Number(row.rank_number) + '</small></span></li>';
    }).join('') + '</ul>';
  }

  function render() {
    renderProfile();
    renderTrophies();
    renderSector();
    renderLadder();
    renderBoard();
    renderCrew();
  }

  /* ---- Actions ---------------------------------------------------------- */

  function load() {
    if (!window.PW_AUTH || !window.PW_AUTH.resolved) return Promise.resolve();
    if (!window.PW_AUTH.loggedIn) { gate.hidden = false; content.hidden = true; return Promise.resolve(); }
    gate.hidden = true;
    content.hidden = false;
    return request('/api/missions/sweep/state.php').then(function (data) {
      state.data = data;
      render();
    }).catch(function (error) {
      /* A failed load used to leave every panel on its "Reading your
         standing..." placeholder, which reads as still working. Each one says
         what actually happened instead, and the thin status line is not the
         only place the error appears. */
      setStatus(error.message, true);
      sectorTitle.textContent = 'Sector unavailable';
      sectorBody.innerHTML = '<p class="sweep-muted">' + esc(error.message) + '</p>';
      ladder.innerHTML = '<li class="sweep-muted">The sector ladder could not be read.</li>';
      crewList.innerHTML = '<p class="sweep-muted">The roster could not be read.</p>';
      if (profileCard) profileCard.innerHTML = '<p class="sweep-muted">The commander record could not be read.</p>';
      if (trophyList) trophyList.innerHTML = '<p class="sweep-muted">The vault could not be read.</p>';
      boardArea.innerHTML = '<p class="sweep-muted">' + esc(error.message) + '</p>';
      boardActions.hidden = true;
    });
  }

  function send(crewId) {
    if (state.busy) return;
    state.result = null;
    state.busy = true;
    setStatus('Opening the field…');
    request('/api/missions/sweep/start.php', { crew_id: crewId }).then(function () {
      setStatus('');
      return load();
    }).catch(function (error) {
      setStatus(error.message, true);
    }).then(function () { state.busy = false; renderBoard(); });
  }

  function pick(cell) {
    if (state.busy) return;
    state.busy = true;
    renderBoard();
    request('/api/missions/sweep/pick.php', { cell: cell }).then(function (data) {
      state.data.run = data.run;
      var find = data.find || {};
      if (data.ended === 'collapse') {
        var saved = data.tether;
        setStatus(saved
          ? 'The field gave way. The tether held \u2014 ' + (saved.name || 'one item')
            + (saved.state === 'no_room' ? ' came back, but there was no room for it.' : ' came back with the crew.')
          : 'The field gave way. The haul is lost.', !saved);
      }
      else if (data.shrugged) setStatus('A collapse — braced through it. That was the one chance.');
      else if (find.type === 'cache') setStatus('Recovered ' + find.label + '.');
      else if (find.label) setStatus('Recovered ' + find.label + '.');
      else setStatus('Nothing in that cell.');
      if (data.ended === 'collapse') {
        showResult(data.result, { tether: data.tether });
        return load();
      }
      if (data.ended) return load();
    }).catch(function (error) {
      setStatus(error.message, true);
    }).then(function () { state.busy = false; renderBoard(); });
  }

  function finish(abandon) {
    if (state.busy) return;
    state.busy = true;
    renderBoard();
    request('/api/missions/sweep/bank.php', abandon ? { abandon: 1 } : {}).then(function (data) {
      if (data.abandoned) setStatus('Withdrew empty-handed.');
      else {
        var parts = [];
        if (data.credits) parts.push(num(data.credits) + ' credits');
        if ((data.gear || []).length) parts.push(data.gear.length + ' item' + (data.gear.length === 1 ? '' : 's'));
        if ((data.crew_recruited || []).length) parts.push(data.crew_recruited.length + ' new crew');
        if (data.xp) parts.push(data.xp + ' XP');
        setStatus(parts.length ? 'Banked ' + parts.join(', ') + '.' : 'Banked an empty haul.');
      }
      showResult(data.result, data);
      return load();
    }).catch(function (error) {
      setStatus(error.message, true);
    }).then(function () { state.busy = false; renderBoard(); });
  }

  if (boardArea) {
    boardArea.addEventListener('click', function (event) {
      var close = event.target.closest('[data-sweep-result-close]');
      if (close) {
        state.result = null;
        setStatus('');
        renderBoard();
        return;
      }
      var button = event.target.closest('[data-sweep-cell]');
      if (!button || button.disabled) return;
      pick(Number(button.getAttribute('data-sweep-cell')));
    });
  }
  if (crewList) {
    crewList.addEventListener('click', function (event) {
      var button = event.target.closest('[data-sweep-send]');
      if (!button) return;
      send(Number(button.getAttribute('data-sweep-send')));
    });
  }
  if (crewSort) crewSort.addEventListener('change', function () { renderCrew(); });
  if (crewList) {
    crewList.addEventListener('click', function (event) {
      var star = event.target.closest('[data-sweep-favorite]');
      if (!star) return;
      var id = Number(star.getAttribute('data-sweep-favorite'));
      star.disabled = true;
      /* The missions page owns this endpoint; the sweep just calls it, so one
         star means one thing on both screens. It takes the state to set rather
         than a toggle, which is what makes a double click idempotent instead
         of flipping twice. */
      var next = star.getAttribute('aria-pressed') !== 'true';
      request('/api/missions/crew-favorite.php', { crew_id: id, is_favorite: next }).then(function () {
        return load();
      }).catch(function (error) {
        setStatus(error.message, true);
        star.disabled = false;
      });
    });
  }
  if (bankButton) bankButton.addEventListener('click', function () { finish(false); });
  if (abandonButton) abandonButton.addEventListener('click', function () { finish(true); });
  if (loginButton) loginButton.addEventListener('click', function () {
    if (typeof window.openAuthModal === 'function') window.openAuthModal('login');
  });

  document.addEventListener('pw-auth-ready', load);
  /* Called directly as well: pw-auth-ready can fire before this script binds,
     and load() is inert until the session is actually resolved. */
  load();
}());
