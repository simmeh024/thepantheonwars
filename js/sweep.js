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

  var state = { data: null, busy: false, result: null, scanningCell: null, recentCell: null, fieldMessage: '', ladderStart: null, ladderSignature: '' };
  var gate = document.getElementById('sweep-gate');
  var content = document.getElementById('sweep-content');
  var status = document.getElementById('sweep-status');
  var boardArea = document.getElementById('sweep-board-area');
  var boardTitle = document.getElementById('sweep-board-title');
  var boardMeta = document.getElementById('sweep-board-meta');
  var boardActions = document.getElementById('sweep-board-actions');
  var crewList = document.getElementById('sweep-crew-list');
  var crewCard = document.getElementById('sweep-crew-card');
  var loadoutCard = document.getElementById('sweep-loadout-card');
  var loadoutBlocks = document.getElementById('sweep-loadout-blocks');
  var ladder = document.getElementById('sweep-ladder');
  var ladderUp = document.getElementById('sweep-ladder-up');
  var ladderDown = document.getElementById('sweep-ladder-down');
  var sectorTitle = document.getElementById('sweep-sector-title');
  var sectorBody = document.getElementById('sweep-sector-body');
  var bankButton = document.getElementById('sweep-bank');
  var abandonButton = document.getElementById('sweep-abandon');
  var loginButton = document.getElementById('sweep-login');
  var profileCard = document.getElementById('sweep-profile-card');
  var stimBeltCard = document.getElementById('sweep-stim-belt-card');
  var recordCard = document.getElementById('sweep-record-card');
  var trophyList = document.getElementById('sweep-trophy-list');
  var crewSort = document.getElementById('sweep-crew-sort');
  var choiceModal = document.getElementById('sweep-choice-modal');
  var choiceOptions = document.getElementById('sweep-choice-options');
  var choiceError = document.getElementById('sweep-choice-error');
  var choiceConfirm = document.getElementById('sweep-choice-confirm');
  var choiceState = { resolve: null, options: [], selected: null, lastFocus: null };

  function esc(value) { var node = document.createElement('div'); node.textContent = value == null ? '' : String(value); return node.innerHTML; }
  function num(value) { return Number(value || 0).toLocaleString(); }
  function duration(value) {
    var seconds = Math.max(0, Number(value || 0));
    var minutes = Math.floor(seconds / 60);
    var remainder = seconds % 60;
    return minutes ? minutes + 'm ' + remainder + 's' : remainder + 's';
  }
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
      return '<button type="button" class="sweep-cell' + (known ? ' is-known is-known-' + known : '')
        + (state.scanningCell === index ? ' is-scanning' : '') + '"'
        + ' data-sweep-cell="' + index + '"' + (playable ? '' : ' disabled')
        + (known ? ' title="' + esc(PREVIEW_WORD[known]) + '"' : '')
        + ' aria-label="' + esc(label) + '">'
        + '<span aria-hidden="true">' + (known ? PREVIEW_GLYPH[known] : '?') + '</span></button>';
    }
    var label = cell.label || (cell.type === 'hazard' ? 'Collapse' : (cell.type === 'shrug' ? 'Braced through a collapse' : 'Nothing here'));
    /* The thing itself, when it has artwork. A glyph is the fallback for a
       definition with no image and for the outcomes that are not objects at
       all -- a collapse, an empty pocket, a braced escape. */
    var icon = cell.type === 'cache' ? 'images/credit-stick.webp'
      : (cell.type === 'hazard' ? 'images/Collapsed.webp' : safeImage(cell.icon));
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
    return '<div class="sweep-cell is-open is-' + esc(cell.type) + shiny + (state.recentCell === index ? ' is-recent' : '') + '" role="img" tabindex="0"'
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

  function fieldCrew(run) {
    var crew = ((state.data && state.data.crew) || []).filter(function (member) {
      return Number(member.id) === Number(run.player_crew_id);
    })[0];
    return crew || { name: 'Field operative', role: 'Sweep crew', portrait_url: '' };
  }

  function fieldFaceMarkup(crew) {
    var portrait = safeImage(crew.portrait_url);
    return portrait
      ? '<img src="' + esc(portrait) + '" alt="">'
      : '<span aria-hidden="true">' + esc(String(crew.name || '?').charAt(0)) + '</span>';
  }

  function fieldLocation(run) {
    var last = Number(run.last_cell_index);
    var hasPosition = Number.isInteger(last) && last >= 0;
    var cells = Math.max(1, Number(run.grid_rows) * Number(run.grid_cols));
    var position = hasPosition ? Math.min(cells - 1, last) : 0;
    var row = Math.floor(position / Number(run.grid_cols)) + 1;
    var col = position % Number(run.grid_cols) + 1;
    return {
      hasPosition: hasPosition,
      row: row,
      col: col,
      left: ((col - 0.5) / Number(run.grid_cols)) * 100,
      top: ((row - 0.5) / Number(run.grid_rows)) * 100
    };
  }

  function fieldPresenceMarkup(run) {
    var crew = fieldCrew(run);
    var location = fieldLocation(run);
    var scanTarget = state.scanningCell === null ? '' : 'Scanning cell ' + (Number(state.scanningCell) + 1) + '.';
    var message = scanTarget || state.fieldMessage || (location.hasPosition
      ? 'Holding at row ' + location.row + ', column ' + location.col + '. Awaiting your next scan.'
      : 'At the field edge. Awaiting your first scan.');
    return '<div class="sweep-field-presence">'
      + '<span class="sweep-field-face">' + fieldFaceMarkup(crew) + '</span><span class="sweep-field-copy"><small>Deployed crew</small><strong>' + esc(crew.name) + '</strong>'
      + '<em>' + esc(crew.role) + '</em><span class="sweep-field-radio" aria-live="polite">' + esc(message) + '</span></span></div>';
  }

  function fieldMarkerMarkup(run) {
    var crew = fieldCrew(run);
    var location = fieldLocation(run);
    return '<span class="sweep-field-marker' + (state.scanningCell !== null ? ' is-scanning' : '') + '"'
      + ' style="left:' + location.left.toFixed(3) + '%;top:' + location.top.toFixed(3) + '%" aria-hidden="true">'
      + fieldFaceMarkup(crew) + '</span>';
  }

  function clearRecentReveal() {
    var revealed = state.recentCell;
    window.setTimeout(function () {
      if (state.recentCell !== revealed) return;
      state.recentCell = null;
      renderBoard();
    }, 760);
  }

  /* Full field markup belongs exclusively to a finished run. The live board
     remains deliberately ignorant of unopened cells. */
  function resultCellMarkup(cell, cols, result) {
    var row = Math.floor(Number(cell.index) / cols) + 1;
    var col = Number(cell.index) % cols + 1;
    var type = String(cell.type || 'empty');
    var label = String(cell.label || (type === 'hazard' ? 'Collapse' : 'No recovery'));
    var icon = type === 'cache' ? 'images/credit-stick.webp'
      : (type === 'hazard' ? 'images/Collapsed.webp' : safeImage(cell.icon));
    var tier = String(cell.tier || '').toLowerCase();
    var itemLevel = type === 'gear' && Number(cell.item_level) > 0
      ? ' · iLvl ' + Math.round(Number(cell.item_level)) : '';
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
      + ' aria-label="' + esc('Row ' + row + ', column ' + col + ': ' + label + itemLevel + (isReward ? '; ' + stateLabel : '')) + '"'
      + ' title="' + esc('R' + row + ' C' + col + ': ' + label + itemLevel + (isReward ? ' — ' + stateLabel : '')) + '">'
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
      var itemLevel = String(cell.type) === 'gear' && Number(cell.item_level) > 0
        ? ' · iLvl ' + Math.round(Number(cell.item_level)) : '';
      return '<li class="' + (secured ? 'is-secured' : (tethered ? 'is-tethered' : 'is-missed')) + '">'
        + '<b>R' + row + ' C' + col + '</b><span>' + esc(String(cell.label || '') + itemLevel) + '</span><small>' + esc(resultState) + '</small></li>';
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

  function achievementMarkup(result) {
    var achievements = (result.payout && result.payout.achievements) || [];
    if (!achievements.length) return '';
    return '<section class="sweep-achievement-unlocks" aria-label="Achievements unlocked this sweep">'
      + '<span class="eyebrow">Achievement unlocked</span><div class="sweep-achievement-list">'
      + achievements.map(function (achievement) {
        var tier = String(achievement.tier || 'bronze').toLowerCase().replace(/[^a-z0-9-]/g, '');
        return '<article class="sweep-achievement is-tier-' + esc(tier) + '">'
          + '<span class="sweep-achievement-mark" aria-hidden="true">' + esc(achievement.icon || '\u25c6') + '</span>'
          + '<span><strong>' + esc(achievement.name) + '</strong><small>' + esc(achievement.description) + '</small></span></article>';
      }).join('') + '</div></section>';
  }

  /* The cabinet deliberately repeats only recoveries that actually made it
     home. The full field map remains the source of truth for what was left
     behind; this is the satisfying, tangible receipt for a successful haul. */
  function rewardCabinetMarkup(result) {
    var field = result.field || {};
    var tether = result.payout && result.payout.tether;
    var rewards = (field.cells || []).filter(function (cell) {
      var tethered = tether && Number(tether.cell_index) === Number(cell.index) && tether.state !== 'no_room';
      return ['gear', 'crew', 'cache'].indexOf(String(cell.type)) !== -1
        && ((field.status === 'banked' && !!cell.revealed) || tethered);
    });
    if (!rewards.length) return '';
    return '<section class="sweep-reward-cabinet" aria-labelledby="sweep-reward-cabinet-title">'
      + '<div class="sweep-reward-cabinet-head"><span class="eyebrow">Secured haul</span>'
      + '<h4 id="sweep-reward-cabinet-title">Recovered cargo</h4>'
      + '<p>Every recovery below has cleared the field and entered your stores.</p></div>'
      + '<div class="sweep-reward-cabinet-grid">' + rewards.map(function (cell, rewardIndex) {
        var type = String(cell.type || 'gear');
        var tier = String(cell.tier || 'common').toLowerCase().replace(/[^a-z0-9-]/g, '');
        var icon = type === 'cache' ? 'images/credit-stick.webp' : safeImage(cell.icon);
        var art = icon
          ? '<img src="' + esc(icon) + '" alt="">'
          : '<span aria-hidden="true">' + (CELL_GLYPH[type] || CELL_GLYPH.gear) + '</span>';
        var label = String(cell.label || (type === 'cache' ? 'Credits' : 'Recovery'));
        var itemLevel = type === 'gear' && Number(cell.item_level) > 0
          ? 'iLvl ' + Math.round(Number(cell.item_level)) + ' \u00b7 ' + String(cell.tier || 'common')
          : String(cell.tier || 'common');
        return '<article class="sweep-reward-cabinet-item is-' + esc(type) + ' is-tier-' + esc(tier) + '"'
          + ' style="--haul-delay:' + Math.min(rewardIndex, 9) * 70 + 'ms">'
          + '<span class="sweep-reward-cabinet-art">' + art + '</span><span class="sweep-reward-cabinet-copy">'
          + '<small>' + esc(type === 'cache' ? 'Credit cache' : type === 'crew' ? 'Crew recovery' : 'Equipment') + '</small>'
          + '<strong>' + esc(label) + '</strong><em>' + esc(itemLevel) + '</em></span></article>';
      }).join('') + '</div></section>';
  }

  function collapseMarkup(result) {
    var field = result.field || {};
    if (field.status !== 'lost') return '';
    var tether = result.payout && result.payout.tether;
    var saved = tether && tether.state !== 'no_room';
    var detail = saved
      ? (tether.name || 'One recovery') + ' was pulled clear by the emergency tether.'
      : (tether ? (tether.name || 'The tethered recovery') + ' reached the line, but could not be stored.' : 'The crew escaped, but the unsecured haul was swallowed by the collapse.');
    return '<aside class="sweep-collapse-alert' + (saved ? ' is-tethered' : '') + '" role="status">'
      + '<span class="sweep-collapse-mark" aria-hidden="true">!</span><span><small>Critical field event</small>'
      + '<strong>Sector collapse recorded</strong><p>' + esc(detail) + '</p></span></aside>';
  }

  /* A recovery lead is issued by the server only after the tether has failed
   * to save a rare-or-better item. It is shown in the collapse debrief rather
   * than as a vague later notification, so the player can immediately see why
   * a route to Missions has appeared. */
  function salvageRecoveryMarkup(result) {
    var recovery = result.payout && result.payout.salvage_recovery_contract;
    if (!recovery || !recovery.lost_item) return '';
    var item = recovery.lost_item;
    var tier = String(item.tier || 'rare').toLowerCase();
    var icon = safeImage(item.icon_url);
    return '<aside class="sweep-salvage-contract is-tier-' + esc(tier) + '" role="status">'
      + '<span class="eyebrow">Salvage contract issued</span>'
      + '<div>' + (icon ? '<img src="/' + esc(String(icon).replace(/^\//, '')) + '" alt="">' : '<span class="sweep-salvage-contract-glyph" aria-hidden="true">✦</span>')
      + '<span><strong>' + esc(item.name) + '</strong><p>This important ' + esc(tier) + ' item was lost in the collapse. Complete the recovery contract to bring back this exact item.</p></span></div>'
      + '<a class="btn btn-solid" href="missions.html#mission-salvage-recovery-section">Plan recovery</a></aside>';
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
    boardArea.innerHTML = '<section class="sweep-result is-' + (won ? 'won' : 'lost') + (field.status === 'lost' ? ' is-collapse' : '') + '" aria-labelledby="sweep-result-title">'
      + '<div class="sweep-result-head"><span class="eyebrow">' + (won ? 'Field debrief' : 'Recovery debrief') + '</span>'
      + '<h3 id="sweep-result-title">' + heading + '</h3><p>' + esc(copy) + '</p></div>'
      + collapseMarkup(result)
      + salvageRecoveryMarkup(result)
      + conditionMarkup(field.condition, true)
      + resultPayoutMarkup(result)
      + achievementMarkup(result)
      + rewardCabinetMarkup(result)
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
    state.scanningCell = null;
    state.recentCell = null;
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
      + '<div class="sweep-field-stage">' + fieldPresenceMarkup(run)
      + '<div class="sweep-field-grid-wrap"><div class="sweep-grid" style="--sweep-cols:' + run.grid_cols + '" role="group" aria-label="Salvage field">'
      + cells.join('') + '</div>' + fieldMarkerMarkup(run) + '</div></div>'
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

  function renderSectorRecords() {
    if (!recordCard) return;
    var tier = state.data && state.data.tier;
    var records = state.data && state.data.sector_records;
    if (!tier || !records) {
      recordCard.hidden = true;
      recordCard.innerHTML = '';
      return;
    }
    var runs = Number(records.runs_banked || 0);
    var rarest = String(records.rarest_tier || '');
    var values = [
      { label: 'Fastest secure', value: runs ? duration(records.fastest_seconds) : '\u2014', medal: '\u23f1' },
      { label: 'Best haul index', value: runs ? num(records.best_haul_value) : '\u2014', medal: '\u25c6' },
      { label: 'Longest safe run', value: runs ? num(records.longest_safe_scans) + ' scans' : '\u2014', medal: '\u25c8' },
      { label: 'Rarest recovery', value: rarest || '\u2014', medal: '\u2727', tier: rarest }
    ];
    recordCard.hidden = false;
    recordCard.innerHTML = '<span class="eyebrow">Sector records</span><h2 id="sweep-record-title">'
      + esc(tier.name || ('Sector ' + tier.rank_number)) + '</h2>'
      + '<p class="sweep-record-note">' + (runs
        ? num(runs) + ' banked run' + (runs === 1 ? '' : 's') + ' logged in this sector.'
        : 'Bank a sector recovery to write its first record.') + '</p>'
      + '<div class="sweep-record-grid">' + values.map(function (record) {
        var tierClass = record.tier ? ' is-tier-' + esc(record.tier) : '';
        return '<div class="sweep-record' + tierClass + '"><span class="sweep-record-medal" aria-hidden="true">'
          + record.medal + '</span><span><small>' + esc(record.label) + '</small><strong>'
          + esc(record.value) + '</strong></span></div>';
      }).join('') + '</div>';
  }

  /* How many rungs the ladder shows at once. Odd, so the current sector has a
   * real middle to sit in; the pager steps through the same window. */
  var LADDER_VISIBLE = 5;

  function ladderRankColor(value) {
    var color = String(value || '');
    return /^#[0-9a-f]{3,8}$/i.test(color) ? color : '#7ee3e8';
  }

  function renderLadder() {
    var rows = (state.data && state.data.ladder) || [];
    if (!rows.length) {
      ladder.innerHTML = '<li class="sweep-muted">No sectors have been surveyed yet.</li>';
      if (ladderUp) ladderUp.disabled = true;
      if (ladderDown) ladderDown.disabled = true;
      return;
    }
    var currentIndex = rows.findIndex(function (row) { return !!row.is_current; });
    if (currentIndex < 0) {
      currentIndex = 0;
      rows.forEach(function (row, index) { if (row.unlocked) currentIndex = index; });
    }
    var signature = rows.map(function (row) { return row.rank_number; }).join(',') + ':' + currentIndex;
    if (state.ladderSignature !== signature) {
      /* Opening the page centres the commander's current sector: two rungs of
         where they came from, two of what is ahead. Where you stand reads as a
         position on a ladder rather than the top of one, which the previous
         anchor-at-the-top window could not show. Deliberately computed rather
         than a scroll position the browser happens to remember. */
      state.ladderSignature = signature;
      state.ladderStart = currentIndex - Math.floor(LADDER_VISIBLE / 2);
    }
    /* The window keeps its full height at both ends: near the top it backfills
       forwards, near the bottom backwards, so the ladder never renders short of
       LADDER_VISIBLE rungs while that many sectors exist. The current sector
       then simply sits off-centre, which is the truth about where it is. */
    var maxStart = Math.max(0, rows.length - LADDER_VISIBLE);
    var start = Math.max(0, Math.min(maxStart, Number(state.ladderStart) || 0));
    state.ladderStart = start;
    if (ladderUp) ladderUp.disabled = start <= 0;
    if (ladderDown) ladderDown.disabled = start >= maxStart;
    ladder.innerHTML = rows.slice(start, start + LADDER_VISIBLE).map(function (row, visibleIndex) {
      var rowIndex = start + visibleIndex;
      var cls = row.is_current ? 'is-current' : (row.unlocked ? 'is-earned' : 'is-sealed');
      /* Tone falls away from the current rung in both directions now that it is
         centred, or the two sectors behind would out-shout the one you are on. */
      var distance = rowIndex - currentIndex;
      if (distance === 1) cls += ' is-next-one';
      if (distance === 2) cls += ' is-next-two';
      if (distance === -1) cls += ' is-prev-one';
      if (distance <= -2) cls += ' is-prev-two';
      /* The manifest is not named. What a sector pays is the thing worth
         finding out by sweeping it, and printing the loot table's name turned
         the ladder into a contents list. The board shape is the useful part
         and gives nothing away. */
      var detail = row.unlocked
        ? row.grid_rows + '\u00d7' + row.grid_cols + ' \u00b7 ' + row.hazard_count + ' collapse'
          + (Number(row.hazard_count) === 1 ? '' : 's') + ' \u00b7 ' + esc((row.condition || {}).label || 'Nominal')
        : 'Rank ' + row.rank_number + ' required';
      return '<li class="sweep-rung ' + cls + '" style="--rank-color:' + esc(ladderRankColor(row.rank_color)) + '"'
        + (row.is_current ? ' aria-current="step"' : '') + '>'
        + '<span class="sweep-rung-mark">' + row.rank_number + '</span>'
        + '<span class="sweep-rung-copy"><strong>' + esc(row.name) + '</strong><small>' + detail + '</small></span>'
        + (row.unlocked && row.sweeps_completed ? '<b>' + row.sweeps_completed + '</b>' : '')
        + '</li>';
    }).join('');
  }

  function moveLadder(direction) {
    var rows = (state.data && state.data.ladder) || [];
    if (!rows.length) return;
    if (state.ladderStart === null) renderLadder();
    var maxStart = Math.max(0, rows.length - LADDER_VISIBLE);
    state.ladderStart = Math.max(0, Math.min(maxStart, Number(state.ladderStart) + direction));
    renderLadder();
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

  /* The figures explain each stat in isolation; this small brief combines them
   * into a pre-flight read for the active sector. It is deliberately advisory:
   * the start endpoint owns all launch validation and applies the condition. */
  function sweepReadinessMarkup(member, tier) {
    if (!tier) return '';
    var projection = member.projection || {};
    var scans = Number(projection.picks_total) || 0;
    var brace = Number(projection.shrug_percent) || 0;
    var survey = Number(projection.hint_radius) || 0;
    var braceCap = Number(tuning().shrug_cap) || 60;
    var condition = tier.condition || {};
    var conditionKey = String(condition.key || 'clear');
    var score = (member.can_deploy ? 15 : 0)
      + Math.min(30, (scans / Math.max(1, Number(tier.base_picks) + 2)) * 30)
      + Math.min(25, (brace / braceCap) * 25)
      + Math.min(15, survey * 7.5)
      + (conditionKey === 'clear' ? 15 : 8);
    score = Math.round(Math.max(0, Math.min(100, score)));
    var grade = score >= 78 ? { key: 'a', label: 'Ready' }
      : score >= 62 ? { key: 'b', label: 'Steady' }
      : score >= 45 ? { key: 'c', label: 'Exposed' }
      : { key: 'd', label: 'At risk' };
    var risks = [];
    if (scans <= Number(tier.base_picks)) risks.push('Scan reserve: Cunning gear can add more field picks.');
    if (brace < 25) risks.push('Resilience: Strength gear improves the brace chance.');
    if (survey < 1) risks.push('Survey blind: Science gear unlocks collapse-count rings.');
    if (conditionKey === 'signal_interference') risks.unshift('Signal interference: cache previews are disabled in this sector.');
    if (conditionKey === 'unstable_structure') risks.unshift('Structural risk: this sector adds a collapse; favour Brace strength.');
    if (conditionKey === 'dense_debris') risks.unshift('Dense debris: the sector removes one scan after bonuses.');
    return '<section class="sweep-readiness is-' + grade.key + '"><div class="sweep-readiness-head"><span><small>Field readiness</small><strong>' + grade.label + '</strong></span><b>' + score + '<i>/100</i></b></div>'
      + '<div class="sweep-readiness-metrics"><span' + (scans <= Number(tier.base_picks) ? ' class="is-low"' : '') + '><small>Scans</small><strong>' + scans + '</strong></span><span' + (brace < 25 ? ' class="is-low"' : '') + '><small>Brace</small><strong>' + fmt(brace) + '%</strong></span><span' + (survey < 1 ? ' class="is-low"' : '') + '><small>Survey</small><strong>' + survey + ' ring' + (survey === 1 ? '' : 's') + '</strong></span></div>'
      + (risks.length ? '<p>' + esc(risks.slice(0, 2).join(' ')) + '</p>' : '<p class="is-clear">This loadout is covering the active sector well.</p>') + '</section>';
  }

  /* The server uses all seven loadout slots for the average, then resolves the
   * maximum from enabled gear this specific crew can currently equip. Green is
   * progress; legendary is reserved for that complete compatible loadout. */
  function sweepItemLevelMarkup(member) {
    if (!member || !member.item_level_ready || Number(member.item_level_catalogue_slots) < 1) return '';
    var current = Number(member.item_level_average) || 0;
    var maximum = Number(member.item_level_max_average) || 0;
    var format = function (value) { return String(Math.round(Math.max(0, Number(value) || 0))); };
    var maxed = !!member.item_level_maxed;
    var slots = Number(member.item_level_slots_at_max) || 0;
    var catalogueSlots = Number(member.item_level_catalogue_slots) || 0;
    var title = 'Average iLvl ' + format(current) + ' from ' + (Number(member.item_level_total) || 0) + ' equipped iLvl across all 7 slots. '
      + 'Enabled compatible catalogue ceiling: ' + format(maximum) + ' (' + slots + ' of ' + catalogueSlots + ' slot maxima equipped).';
    return '<span class="sweep-crew-ilvl ' + (maxed ? 'is-legendary' : 'is-progress') + '" title="' + esc(title) + '"><small>AVG iLvl</small><b>'
      + format(current) + '</b><em>' + (maxed ? 'MAXED' : '/ ' + format(maximum)) + '</em></span>';
  }

  /* One block per crew member, linking into that crew member's loadout on
   * Mission Control. Deliberately a link rather than a modal: gear lives on
   * that page, and a second equip surface here would be a second copy of the
   * rules deciding what fits. Every crew member appears, deployed or not --
   * equipment is worth reviewing before the next sweep as much as during one,
   * and readiness is what the roster below the board is for. */
  function renderCrewLoadouts() {
    if (!loadoutCard || !loadoutBlocks) return;
    var crew = (state.data && state.data.crew) || [];
    loadoutCard.hidden = !crew.length;
    if (!crew.length) { loadoutBlocks.innerHTML = ''; return; }
    var ordered = crew.slice().sort(function (left, right) {
      return (Number(right.level) || 0) - (Number(left.level) || 0)
        || String(left.name).localeCompare(String(right.name));
    });
    loadoutBlocks.innerHTML = ordered.map(function (member) {
      var portrait = safeImage(member.portrait_url);
      var face = portrait
        ? '<img src="' + esc(portrait) + '" alt="" loading="lazy" decoding="async" width="34" height="34">'
        : '<span aria-hidden="true">' + esc(String(member.name || '?').charAt(0)) + '</span>';
      var level = Math.max(0, Number(member.level) || 0);
      /* The average-iLvl figure when the server resolved one, the role when it
         did not -- a block that says nothing under the name reads as a value
         that failed to load rather than one that was never offered. */
      var ready = member.item_level_ready && Number(member.item_level_catalogue_slots) > 0;
      var detail = ready
        ? 'iLvl ' + String(Math.round(Math.max(0, Number(member.item_level_average) || 0)))
        : String(member.role || 'Crew');
      return '<a class="sweep-loadout-block" href="missions.html?loadout=' + encodeURIComponent(member.id) + '"'
        + ' title="' + esc('Open ' + member.name + '’s loadout in Mission Control') + '">'
        + '<span class="sweep-loadout-face">' + face + '</span>'
        + '<span class="sweep-loadout-copy"><strong>' + esc(member.name) + '</strong>'
        + '<small>' + esc('Lv ' + level + ' · ' + detail) + '</small></span></a>';
    }).join('');
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
      var contractFinished = member.status === 'on_mission'
        && member.assignment_mission_status === 'completed' && !!member.assignment_is_contract;
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
        + '<small class="sweep-crew-tier">' + esc(String(member.tier || 'common')) + '</small>' + sweepItemLevelMarkup(member) + '</span>'
        + '</div>'
        + '<div class="sweep-crew-projection">' + figures + '</div>'
        + sweepReadinessMarkup(member, tier)
        + '<div class="sweep-crew-foot">'
        + '<span class="sweep-crew-fatigue">' + fatigueBar + '<small>' + have + ' / ' + max + ' fatigue'
        + (cost ? ' &middot; costs ' + cost : '') + '</small></span>'
        + (member.can_deploy
          ? '<button type="button" class="btn btn-solid" data-sweep-send="' + member.id + '">Send</button>'
          : (contractFinished
            ? '<a class="sweep-crew-contract-finished" href="missions.html#missions-active-list" title="Open '
              + esc(member.assignment_mission_name || 'finished contract') + ' and claim its rewards">Contract finished <span aria-hidden="true">&rarr;</span></a>'
          : '<span class="sweep-crew-blocked">' + esc(why) + '</span>'))
        + '</div></div>';
    }).join('');
  }

  /* The commander card the missions page shows, rendered from the same block
     the state endpoint now sends. Hand-duplicated rather than shared, which is
     this codebase's standing convention for markup across pages. */
  function sweepCrewPowerMarkup(power) {
    if (!power || !power.ready || Number(power.crew_count) < 1 || Number(power.item_level_max_total) < 1) return '';
    var current = String(Math.round(Math.max(0, Number(power.item_level_average) || 0)));
    var maximum = String(Math.round(Math.max(0, Number(power.item_level_max_average) || 0)));
    var progress = Math.max(0, Math.min(100, Number(power.progress_percent) || 0));
    var maxed = !!power.item_level_maxed;
    var crewCount = Number(power.crew_count) || 0;
    var title = 'Crew power is the average equipped iLvl across all seven slots of all ' + crewCount + ' active crew member' + (crewCount === 1 ? '' : 's') + '. '
      + 'Current average: ' + current + '. Enabled catalogue target: ' + maximum + '.';
    return '<div class="sweep-profile-power' + (maxed ? ' is-maxed' : '') + '" title="' + esc(title) + '">'
      + '<span class="sweep-profile-power-head"><small>Crew power</small><strong>AVG iLvl ' + current + '</strong></span>'
      + '<span class="sweep-profile-power-track"><i style="width:' + progress + '%"></i></span>'
      + '<em>' + (maxed ? 'Maximum command power' : crewCount + ' crew · target ' + maximum) + '</em></div>';
  }

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
      + sweepCrewPowerMarkup(player.crew_power)
      + '<div class="sweep-profile-credits"><span>Total credits</span><strong>' + num(player.credits) + '</strong></div>';
  }

  function stimSummary(item) {
    var value = fmt(item.stim_value);
    if (item.stim_effect === 'fatigue') return 'Restores ' + value + ' fatigue.';
    var minutes = Math.max(1, Math.round((Number(item.stim_duration_seconds) || 0) / 60));
    var label = item.stim_effect === 'luck' ? 'luck' : 'speed';
    return '+' + value + '% ' + label + ' for ' + minutes + ' minute' + (minutes === 1 ? '' : 's') + '.';
  }

  function stimIconMarkup(item) {
    var icon = safeImage(item.icon_url);
    if (icon) return '<img src="' + esc(icon) + '" alt="">';
    return item.stim_effect === 'fatigue' ? '\u271a' : (item.stim_effect === 'luck' ? '\u2726' : '\u21af');
  }

  /* The right-rail Field Kit mirrors the Missions belt: all slots stay visible
     so a quick-slot research unlock is tangible, while the buttons remain the
     real API-backed actions rather than a static inventory preview. */
  function renderStimBelt() {
    if (!stimBeltCard) return;
    var belt = (state.data && state.data.stim_slots) || null;
    if (!belt || !belt.ready || !belt.capacity) { stimBeltCard.hidden = true; return; }
    stimBeltCard.hidden = false;
    var columns = Math.max(1, Number(belt.columns) || 4);
    var filled = (belt.slots || []).filter(function (slot) {
      return slot.item && slot.item.is_enabled && Number(slot.item.quantity) > 0;
    }).length;
    var cells = (belt.slots || []).map(function (slot) {
      var item = slot.item;
      var usable = item && item.is_enabled && Number(item.quantity) > 0;
      if (!usable) {
        var reason = !item ? 'Empty quick slot'
          : (!item.is_enabled ? item.name + ' — withdrawn from service' : item.name + ' — none left');
        return '<button type="button" class="sweep-stim-slot is-empty" data-sweep-stim-slot="' + Number(slot.slot_index) + '"'
          + ' title="' + esc(reason) + '" aria-label="' + esc(reason + '. Choose a stim.') + '"><span aria-hidden="true">+</span></button>';
      }
      var label = item.name + ' — ' + stimSummary(item) + ' ' + item.quantity + ' held.';
      return '<span class="sweep-stim-slot is-filled is-' + esc(item.tier || 'common')
        + ' is-effect-' + esc(item.stim_effect || '') + '">'
        + '<button type="button" class="sweep-stim-slot-use" data-sweep-stim-use="' + Number(item.id) + '"'
        + ' title="' + esc(label) + '" aria-label="' + esc('Use ' + label) + '">'
        + '<span class="sweep-stim-slot-icon">' + stimIconMarkup(item) + '</span><b>' + Number(item.quantity) + '</b></button>'
        + '<button type="button" class="sweep-stim-slot-clear" data-sweep-stim-slot-clear="' + Number(slot.slot_index) + '"'
        + ' title="Clear this quick slot" aria-label="' + esc('Clear ' + item.name + ' from quick slot ' + (Number(slot.slot_index) + 1)) + '">&times;</button></span>';
    }).join('');
    stimBeltCard.innerHTML = '<span class="eyebrow">Field kit</span><span class="sweep-stim-belt-head"><strong>Stim belt</strong>'
      + '<span>' + filled + ' / ' + Number(belt.capacity) + '</span></span>'
      + '<div class="sweep-stim-belt-grid" style="grid-template-columns:repeat(' + columns + ',minmax(0,1fr))">' + cells + '</div>'
      + '<p>Click a stim to use it. Research widens the belt.</p>';
  }

  function closeChoice(result) {
    var resolve = choiceState.resolve;
    choiceState.resolve = null;
    if (choiceModal && choiceModal.open && typeof choiceModal.close === 'function') choiceModal.close();
    else if (choiceModal) choiceModal.removeAttribute('open');
    if (choiceState.lastFocus && document.contains(choiceState.lastFocus)) {
      try { choiceState.lastFocus.focus(); } catch (error) { /* The belt may have re-rendered. */ }
    }
    choiceState.lastFocus = null;
    if (resolve) resolve(result || null);
  }

  function renderChoiceOptions() {
    if (!choiceOptions) return;
    choiceOptions.innerHTML = choiceState.options.map(function (option, index) {
      var active = String(option.value) === String(choiceState.selected);
      return '<button type="button" class="sweep-choice-option' + (active ? ' is-active' : '') + '"'
        + ' role="radio" aria-checked="' + (active ? 'true' : 'false') + '"'
        + ' tabindex="' + (active || (choiceState.selected === null && index === 0) ? '0' : '-1') + '"'
        + ' data-sweep-choice-value="' + esc(String(option.value)) + '"><span>' + esc(option.label) + '</span>'
        + (option.meta ? '<small>' + esc(option.meta) + '</small>' : '') + '</button>';
    }).join('');
  }

  function openChoice(config) {
    if (!choiceModal) return Promise.resolve(null);
    if (choiceState.resolve) closeChoice(null);
    choiceState.options = config.options || [];
    choiceState.selected = choiceState.options.length ? String(choiceState.options[0].value) : null;
    choiceState.lastFocus = document.activeElement;
    document.getElementById('sweep-choice-eyebrow').textContent = config.eyebrow || '';
    document.getElementById('sweep-choice-title').textContent = config.title || '';
    var copy = document.getElementById('sweep-choice-copy');
    copy.textContent = config.copy || '';
    copy.hidden = !config.copy;
    choiceError.textContent = '';
    choiceConfirm.textContent = config.confirmLabel || 'Confirm';
    renderChoiceOptions();
    if (typeof choiceModal.showModal === 'function') choiceModal.showModal();
    else choiceModal.setAttribute('open', '');
    window.setTimeout(function () {
      var first = choiceOptions.querySelector('[tabindex="0"]');
      (first || choiceConfirm).focus();
    }, 25);
    return new Promise(function (resolve) { choiceState.resolve = resolve; });
  }

  function assignStimSlot(button) {
    var slotIndex = Number(button.getAttribute('data-sweep-stim-slot'));
    var belt = (state.data && state.data.stim_slots) || { slots: [] };
    var slotted = {};
    (belt.slots || []).forEach(function (slot) { if (slot.item) slotted[Number(slot.item.id)] = true; });
    var candidates = ((state.data && state.data.stims) || []).filter(function (item) {
      return item.is_enabled && Number(item.quantity) > 0 && !slotted[Number(item.id)];
    });
    if (!candidates.length) {
      setStatus(Object.keys(slotted).length ? 'Every stim you hold is already on the belt.' : 'You are not carrying any stims yet.', true);
      return;
    }
    openChoice({
      eyebrow: 'Field kit', title: 'Quick slot ' + (slotIndex + 1),
      copy: 'Pick the stim to keep one click away in this slot.', confirmLabel: 'Assign',
      options: candidates.map(function (item) { return { value: item.id, label: item.name + ' ×' + item.quantity, meta: stimSummary(item) }; })
    }).then(function (choice) {
      if (!choice) return null;
      button.disabled = true;
      return request('/api/missions/stim-slot.php', { slot_index: slotIndex, loot_definition_id: Number(choice.value) })
        .then(function (result) { setStatus(result.message); return load(); })
        .catch(function (error) { button.disabled = false; setStatus(error.message, true); });
    });
  }

  function clearStimSlot(button) {
    button.disabled = true;
    request('/api/missions/stim-slot.php', { slot_index: Number(button.getAttribute('data-sweep-stim-slot-clear')), loot_definition_id: null })
      .then(function (result) { setStatus(result.message); return load(); })
      .catch(function (error) { button.disabled = false; setStatus(error.message, true); });
  }

  function sendStim(button, payload) {
    button.disabled = true;
    return request('/api/missions/stim-use.php', payload)
      .then(function (result) { setStatus(result.message); return load(); })
      .catch(function (error) { button.disabled = false; setStatus(error.message, true); });
  }

  function useStim(button) {
    var itemId = Number(button.getAttribute('data-sweep-stim-use'));
    var item = ((state.data && state.data.stims) || []).filter(function (entry) { return Number(entry.id) === itemId; })[0];
    if (!item) return;
    var payload = { loot_definition_id: itemId };
    if (item.stim_effect !== 'fatigue') { sendStim(button, payload); return; }
    var candidates = ((state.data && state.data.crew) || []).filter(function (member) {
      return member.status === 'available' && Number(member.fatigue) < Number(member.fatigue_max);
    });
    if (!candidates.length) { setStatus('Every crew member standing by is already fully rested.', true); return; }
    openChoice({
      eyebrow: 'Field kit', title: 'Give ' + item.name,
      copy: 'Only crew standing by are listed — rest does not accrue in the field.', confirmLabel: 'Give stim',
      options: candidates.map(function (member) { return { value: member.id, label: member.name, meta: member.fatigue + ' / ' + member.fatigue_max + ' fatigue' }; })
    }).then(function (choice) {
      if (!choice) return null;
      payload.crew_id = Number(choice.value);
      return sendStim(button, payload);
    });
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
    renderStimBelt();
    renderTrophies();
    renderSector();
    renderSectorRecords();
    renderLadder();
    renderBoard();
    renderCrew();
    renderCrewLoadouts();
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
      if (stimBeltCard) stimBeltCard.hidden = true;
      if (recordCard) recordCard.hidden = true;
      if (trophyList) trophyList.innerHTML = '<p class="sweep-muted">The vault could not be read.</p>';
      boardArea.innerHTML = '<p class="sweep-muted">' + esc(error.message) + '</p>';
      boardActions.hidden = true;
    });
  }

  function send(crewId) {
    if (state.busy) return;
    state.result = null;
    state.scanningCell = null;
    state.recentCell = null;
    state.fieldMessage = '';
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
    state.scanningCell = cell;
    state.fieldMessage = 'Scan pulse engaged. Reading the field.';
    state.busy = true;
    renderBoard();
    request('/api/missions/sweep/pick.php', { cell: cell }).then(function (data) {
      state.data.run = data.run;
      state.scanningCell = null;
      state.recentCell = cell;
      var find = data.find || {};
      if (data.ended === 'collapse') {
        var saved = data.tether;
        state.fieldMessage = saved
          ? 'The field gave way. The tether held \u2014 ' + (saved.name || 'one item')
            + (saved.state === 'no_room' ? ' came back, but there was no room for it.' : ' came back with the crew.')
          : 'The field gave way. The haul is lost.';
        setStatus(state.fieldMessage, !saved);
      }
      else if (data.shrugged) state.fieldMessage = 'A collapse — braced through it. That was the one chance.';
      else if (find.type === 'cache') state.fieldMessage = 'Recovered ' + find.label + '.';
      else if (find.label) state.fieldMessage = 'Recovered ' + find.label + '.';
      else state.fieldMessage = 'Nothing in that cell.';
      if (data.ended !== 'collapse') setStatus(state.fieldMessage);
      if (data.ended === 'collapse') {
        showResult(data.result, { tether: data.tether, achievements: data.achievements || [], salvage_recovery_contract: data.salvage_recovery_contract || null });
        return load();
      }
      clearRecentReveal();
      if (data.ended) return load();
    }).catch(function (error) {
      state.scanningCell = null;
      state.fieldMessage = 'Field link interrupted.';
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
        state.scanningCell = null;
        state.recentCell = null;
        state.fieldMessage = '';
        setStatus('');
        renderBoard();
        return;
      }
      var button = event.target.closest('[data-sweep-cell]');
      if (!button || button.disabled) return;
      pick(Number(button.getAttribute('data-sweep-cell')));
    });
  }
  if (ladderUp) ladderUp.addEventListener('click', function () { moveLadder(-1); });
  if (ladderDown) ladderDown.addEventListener('click', function () { moveLadder(1); });
  if (crewList) {
    crewList.addEventListener('click', function (event) {
      var button = event.target.closest('[data-sweep-send]');
      if (!button) return;
      send(Number(button.getAttribute('data-sweep-send')));
    });
  }
  if (stimBeltCard) {
    stimBeltCard.addEventListener('click', function (event) {
      var clear = event.target.closest('[data-sweep-stim-slot-clear]');
      if (clear && !clear.disabled) { clearStimSlot(clear); return; }
      var use = event.target.closest('[data-sweep-stim-use]');
      if (use && !use.disabled) { useStim(use); return; }
      var empty = event.target.closest('[data-sweep-stim-slot]');
      if (empty && !empty.disabled) assignStimSlot(empty);
    });
  }
  if (choiceModal) {
    choiceOptions.addEventListener('click', function (event) {
      var button = event.target.closest('[data-sweep-choice-value]');
      if (!button) return;
      choiceState.selected = button.getAttribute('data-sweep-choice-value');
      renderChoiceOptions();
      var active = choiceOptions.querySelector('.is-active');
      if (active) active.focus();
    });
    choiceOptions.addEventListener('keydown', function (event) {
      var keys = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 };
      var step = keys[event.key];
      if (!step) return;
      event.preventDefault();
      var buttons = Array.prototype.slice.call(choiceOptions.querySelectorAll('[data-sweep-choice-value]'));
      if (!buttons.length) return;
      var current = buttons.indexOf(document.activeElement);
      var next = buttons[(current + step + buttons.length) % buttons.length];
      choiceState.selected = next.getAttribute('data-sweep-choice-value');
      renderChoiceOptions();
      choiceOptions.querySelector('.is-active').focus();
    });
    choiceConfirm.addEventListener('click', function () {
      if (choiceState.selected === null) { choiceError.textContent = 'Choose one of the options first.'; return; }
      closeChoice({ value: choiceState.selected });
    });
    document.getElementById('sweep-choice-cancel').addEventListener('click', function () { closeChoice(null); });
    document.getElementById('sweep-choice-close').addEventListener('click', function () { closeChoice(null); });
    choiceModal.addEventListener('cancel', function (event) { event.preventDefault(); closeChoice(null); });
    choiceModal.addEventListener('click', function (event) { if (event.target === choiceModal) closeChoice(null); });
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
