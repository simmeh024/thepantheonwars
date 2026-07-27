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

  var state = { data: null, busy: false, lastFind: null };
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

  function cellMarkup(index, cell, playable) {
    if (!cell) {
      return '<button type="button" class="sweep-cell" data-sweep-cell="' + index + '"'
        + (playable ? '' : ' disabled')
        + ' aria-label="Scan cell ' + (index + 1) + '"><span aria-hidden="true">?</span></button>';
    }
    var glyph = CELL_GLYPH[cell.type] || CELL_GLYPH.empty;
    var label = cell.label || (cell.type === 'hazard' ? 'Collapse' : (cell.type === 'shrug' ? 'Braced through a collapse' : 'Nothing here'));
    /* The hint is only ever attached to a cell already turned over, and it is
       the count of hazards around it -- the reward for having spent a scan
       there, not a free look at the board. */
    var hint = cell.hint === null || cell.hint === undefined || cell.type === 'hazard'
      ? ''
      : '<b class="sweep-cell-hint' + (Number(cell.hint) > 0 ? ' is-warn' : '') + '">' + Number(cell.hint) + '</b>';
    return '<div class="sweep-cell is-open is-' + esc(cell.type) + '" role="img" tabindex="0"'
      + ' aria-label="' + esc('Cell ' + (index + 1) + ': ' + label) + '" title="' + esc(label) + '">'
      + '<span class="sweep-cell-glyph" aria-hidden="true">' + glyph + '</span>' + hint + '</div>';
  }

  function renderBoard() {
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
      + (run.hint_radius > 0 ? '<span><small>Survey</small><strong>' + run.hint_radius + ' ring' + (run.hint_radius === 1 ? '' : 's') + '</strong></span>' : '')
      + (run.shrug_percent > 0 ? '<span><small>Brace</small><strong>' + (run.shrug_used ? 'spent' : run.shrug_percent + '%') + '</strong></span>' : '');

    var open = {};
    (run.cells || []).forEach(function (cell) { open[Number(cell.index)] = cell; });
    var playable = run.status === 'active' && run.picks_left > 0 && !state.busy;
    var cells = [];
    for (var index = 0; index < run.grid_rows * run.grid_cols; index++) {
      cells.push(cellMarkup(index, open[index], playable));
    }
    boardArea.innerHTML = '<div class="sweep-grid" style="--sweep-cols:' + run.grid_cols + '" role="group" aria-label="Salvage field">'
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
      + '<p class="sweep-muted">Swept ' + num(state.data.sweeps_at_rank) + ' time' + (Number(state.data.sweeps_at_rank) === 1 ? '' : 's') + ' at this rank.</p>';
  }

  function renderLadder() {
    var rows = (state.data && state.data.ladder) || [];
    if (!rows.length) { ladder.innerHTML = '<li class="sweep-muted">No sectors have been surveyed yet.</li>'; return; }
    ladder.innerHTML = rows.map(function (row) {
      var cls = row.is_current ? 'is-current' : (row.unlocked ? 'is-earned' : 'is-sealed');
      /* A sealed rung shows its rank and its board shape but never its
         manifest name: what a field holds is the reward for reaching it. */
      var detail = row.unlocked
        ? (row.loot_table_name ? esc(row.loot_table_name) : 'No manifest filed')
        : 'Rank ' + row.rank_number + ' required';
      return '<li class="sweep-rung ' + cls + '">'
        + '<span class="sweep-rung-mark">' + row.rank_number + '</span>'
        + '<span class="sweep-rung-copy"><strong>' + esc(row.name) + '</strong><small>' + detail + '</small></span>'
        + (row.unlocked && row.sweeps_completed ? '<b>' + row.sweeps_completed + '</b>' : '')
        + '</li>';
    }).join('');
  }

  /* ---- Crew ------------------------------------------------------------- */

  function renderCrew() {
    var crew = (state.data && state.data.crew) || [];
    if (!crew.length) { crewList.innerHTML = '<p class="sweep-muted">No crew are available.</p>'; return; }
    var tier = state.data.tier;
    crewList.innerHTML = crew.map(function (member) {
      var projection = member.projection || {};
      var why = !tier ? 'No sector open'
        : (member.status !== 'available' ? 'On assignment'
        : (member.fatigue < tier.fatigue_cost ? 'Needs ' + tier.fatigue_cost + ' fatigue' : ''));
      return '<div class="sweep-crew is-tier-' + esc(member.tier) + (member.can_deploy ? '' : ' is-blocked') + '">'
        + '<div class="sweep-crew-head"><strong>' + esc(member.name) + '</strong><em>' + esc(member.role) + ' &middot; L' + member.level + '</em></div>'
        + '<div class="sweep-crew-projection">'
        + '<span><small>Scans</small><b>' + (projection.picks_total || 0) + '</b></span>'
        + '<span><small>Survey</small><b>' + (projection.hint_radius || 0) + '</b></span>'
        + '<span><small>Brace</small><b>' + (projection.shrug_percent || 0) + '%</b></span>'
        + '<span><small>XP</small><b>' + (projection.xp_reward || 0) + '</b></span>'
        + '</div>'
        + '<div class="sweep-crew-foot"><span class="sweep-crew-fatigue">' + member.fatigue + ' / ' + member.fatigue_max + ' fatigue</span>'
        + (member.can_deploy
          ? '<button type="button" class="btn btn-solid" data-sweep-send="' + member.id + '">Send</button>'
          : '<span class="sweep-crew-blocked">' + esc(why) + '</span>')
        + '</div></div>';
    }).join('');
  }

  function render() {
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
      boardArea.innerHTML = '<p class="sweep-muted">' + esc(error.message) + '</p>';
      boardActions.hidden = true;
    });
  }

  function send(crewId) {
    if (state.busy) return;
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
      if (data.ended === 'collapse') setStatus('The field gave way. The haul is lost.', true);
      else if (data.shrugged) setStatus('A collapse — braced through it. That was the one chance.');
      else if (find.type === 'cache') setStatus('Recovered ' + find.label + '.');
      else if (find.label) setStatus('Recovered ' + find.label + '.');
      else setStatus('Nothing in that cell.');
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
      return load();
    }).catch(function (error) {
      setStatus(error.message, true);
    }).then(function () { state.busy = false; renderBoard(); });
  }

  if (boardArea) {
    boardArea.addEventListener('click', function (event) {
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
