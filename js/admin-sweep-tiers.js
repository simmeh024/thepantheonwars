/* Sweep Tiers: one Salvage Sweep sector per reputation rank.
 *
 * A flat CRUD over game_sweep_tiers, keyed by rank rather than by id -- there
 * is exactly one sector per rank and the save endpoint upserts on that, so the
 * ladder can be filled in any order without an id round trip. That matters
 * when there are forty rungs to author.
 */
(function () {
  'use strict';

  var tiers = [], ranks = [], lootTables = [], conditionTypes = [], limits = { max_rows: 8, max_cols: 8, max_picks: 40 };
  var current = null;
  var list = document.getElementById('sweep-tier-list');
  var count = document.getElementById('sweep-tier-count');
  var modal = document.getElementById('sweep-tier-modal');
  var rankSelect = document.getElementById('sweep-tier-rank');
  var lootSelect = document.getElementById('sweep-tier-loot-table');
  var conditionSelect = document.getElementById('sweep-tier-condition');
  var conditionPreview = document.getElementById('sweep-tier-condition-preview');
  var hazardHint = document.getElementById('sweep-tier-hazard-hint');
  if (!list) return;

  function can(key) { return typeof window.pwHasPermission === 'function' && window.pwHasPermission(key); }
  function esc(value) { var node = document.createElement('div'); node.textContent = value == null ? '' : String(value); return node.innerHTML; }
  function el(id) { return document.getElementById(id); }
  function setError(message) { el('sweep-tier-error').textContent = message || ''; }
  function setNotice(message) { el('sweep-tier-status').textContent = message || ''; }

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
      if (!data.ok) throw new Error(data.error || 'The request could not be completed.');
      return data;
    });
  }

  function rankName(number) {
    for (var i = 0; i < ranks.length; i++) if (ranks[i].number === number) return ranks[i].name;
    return 'Rank ' + number;
  }

  function conditionFor(key) {
    for (var i = 0; i < conditionTypes.length; i++) if (conditionTypes[i].key === key) return conditionTypes[i];
    return conditionTypes[0] || { key: 'clear', label: 'Nominal field', warning: 'Nominal conditions.', effect: 'No sector penalty.', template: 'nominal' };
  }
  function conditionTemplate(condition) { return String((condition || {}).template || 'nominal').replace(/[^a-z0-9-]/gi, ''); }

  function render() {
    if (!tiers.length) {
      list.innerHTML = '<p class="admin-list-empty">No sweep sectors yet. Add one for the lowest rank a sweep should be available at.</p>';
      count.textContent = '';
      return;
    }
    list.innerHTML = tiers.map(function (tier) {
      /* The manifest is the one field a sector cannot work without, so a
         missing or disabled one is called out on the row rather than only
         discovered when a player is refused at the board. */
      var manifest = tier.loot_table_id
        ? (tier.loot_table_enabled ? esc(tier.loot_table_name) : '<b class="admin-pill is-warn">' + esc(tier.loot_table_name) + ' (disabled)</b>')
        : '<b class="admin-pill is-warn">No manifest</b>';
      var condition = tier.condition || conditionFor(tier.condition_key);
      return '<button type="button" class="admin-row sweep-tier-row" data-sweep-rank="' + tier.rank_number + '">'
        + '<span class="sweep-tier-rank"><b>' + tier.rank_number + '</b><small>' + esc(rankName(tier.rank_number)) + '</small></span>'
        + '<span class="sweep-tier-name">' + esc(tier.name || 'Sector ' + tier.rank_number) + '</span>'
        + '<span class="sweep-tier-manifest">' + manifest + '</span>'
        + '<span class="sweep-tier-condition is-template-' + conditionTemplate(condition) + '" title="' + esc(condition.warning) + '">' + esc(condition.label) + '</span>'
        + '<span class="sweep-tier-shape">' + tier.grid_rows + '&times;' + tier.grid_cols + ' &middot; ' + tier.base_picks + ' scans &middot; ' + tier.hazard_count + ' collapses</span>'
        + '<span class="sweep-tier-state">' + (tier.is_enabled ? '<b class="admin-pill is-on">Enabled</b>' : '<b class="admin-pill">Disabled</b>') + '</span>'
        + '</button>';
    }).join('');
    count.textContent = tiers.length + (tiers.length === 1 ? ' sector' : ' sectors');
  }

  function fillRanks(selected) {
    var used = {};
    tiers.forEach(function (tier) { used[tier.rank_number] = true; });
    var options = [];
    var maxRank = Math.max(40, ranks.length);
    for (var number = 1; number <= maxRank; number++) {
      // A rank that already has a sector is still listed when it is the one
      // being edited, or the editor could not reopen its own row.
      if (used[number] && number !== selected) continue;
      options.push('<option value="' + number + '"' + (number === selected ? ' selected' : '') + '>'
        + number + ' &middot; ' + esc(rankName(number)) + '</option>');
    }
    rankSelect.innerHTML = options.join('');
    rankSelect.disabled = selected !== null && selected !== undefined;
  }

  function fillLootTables(selected) {
    /* Grouped, not filtered: a table that has not been marked as a sweep
       manifest is still a legal choice, so hiding it would be wrong -- but with
       forty sectors to author, the ones written for this purpose need to be the
       ones at the top. */
    function option(table) {
      return '<option value="' + table.id + '"' + (Number(selected) === table.id ? ' selected' : '') + '>'
        + esc(table.name) + (table.is_enabled ? '' : ' (disabled)') + '</option>';
    }
    var sweep = lootTables.filter(function (table) { return table.is_sweep_only; });
    var rest = lootTables.filter(function (table) { return !table.is_sweep_only; });
    lootSelect.innerHTML = '<option value="">No manifest</option>'
      + (sweep.length ? '<optgroup label="Sweep manifests">' + sweep.map(option).join('') + '</optgroup>' : '')
      + (rest.length ? '<optgroup label="Other loot tables">' + rest.map(option).join('') + '</optgroup>' : '');
  }

  function fillConditions(selected) {
    conditionSelect.innerHTML = conditionTypes.map(function (condition) {
      return '<option value="' + esc(condition.key) + '"' + (condition.key === selected ? ' selected' : '') + '>'
        + esc(condition.label) + '</option>';
    }).join('');
  }

  function syncConditionPreview() {
    var condition = conditionFor(conditionSelect.value);
    conditionPreview.className = 'sweep-tier-condition-preview is-template-' + conditionTemplate(condition);
    conditionPreview.innerHTML = '<strong>' + esc(condition.label) + '</strong><span>' + esc(condition.warning) + '</span><small>' + esc(condition.effect) + '</small>';
  }

  function syncHazardHint() {
    var cells = (Number(el('sweep-tier-rows').value) || 0) * (Number(el('sweep-tier-cols').value) || 0);
    var max = Math.max(0, cells - 2);
    el('sweep-tier-hazards').max = max;
    hazardHint.textContent = 'Between 0 and ' + max + ' on this field: two cells must stay safe.';
  }

  function lastTier() {
    /* The endpoint orders tiers by rank, but use a reduction here so this
       remains correct if a future list presentation groups or reorders them. */
    return tiers.reduce(function (latest, tier) {
      return !latest || Number(tier.rank_number) > Number(latest.rank_number) ? tier : latest;
    }, null);
  }

  function openModal(tier) {
    if (!tier && !can('sweep_tiers.manage')) return;
    /* New sectors start as a true copy of the highest existing sector. Rank is
       intentionally excluded: it identifies the record, must be unique, and
       is the one choice an author needs to make for the new board. */
    var source = tier || lastTier();
    var copying = !tier && !!source;
    current = tier || null;
    el('sweep-tier-modal-title').textContent = tier ? 'Sector ' + tier.rank_number : 'New sector';
    el('sweep-tier-modal-sub').textContent = tier
      ? 'Opens for anyone holding rank ' + tier.rank_number + ' or above, until a higher sector does.'
      : (copying
        ? 'Copied from sector ' + source.rank_number + '. Pick the new rank it opens at.'
        : 'One board per rank. Pick the rank it opens at.');
    fillRanks(tier ? tier.rank_number : null);
    fillLootTables(source ? source.loot_table_id : '');
    fillConditions(source && source.condition ? source.condition.key : (source ? source.condition_key : 'clear'));
    el('sweep-tier-name').value = source ? source.name : '';
    el('sweep-tier-rows').value = source ? source.grid_rows : 5;
    el('sweep-tier-cols').value = source ? source.grid_cols : 5;
    el('sweep-tier-picks').value = source ? source.base_picks : 5;
    el('sweep-tier-hazards').value = source ? source.hazard_count : 4;
    el('sweep-tier-cache').value = source ? source.cache_credits : 120;
    el('sweep-tier-fatigue').value = source ? source.fatigue_cost : 20;
    el('sweep-tier-xp').value = source ? source.xp_reward : 30;
    el('sweep-tier-enabled').checked = source ? source.is_enabled : true;
    syncHazardHint();
    syncConditionPreview();
    setError(''); setNotice('');
    el('sweep-tier-save-btn').disabled = !can('sweep_tiers.manage');
    el('sweep-tier-delete-btn').hidden = !tier || !can('sweep_tiers.manage');
    modal.hidden = false;
  }

  function closeModal() { modal.hidden = true; current = null; }

  function load() {
    return request('/api/admin/sweep-tiers/list.php?refresh=' + Date.now()).then(function (data) {
      tiers = data.tiers || [];
      ranks = data.ranks || [];
      lootTables = data.loot_tables || [];
      conditionTypes = data.condition_types || [];
      limits = data.limits || limits;
      render();
    }).catch(function (error) {
      list.innerHTML = '<p class="admin-list-empty">' + esc(error.message) + '</p>';
      count.textContent = '';
    });
  }

  function save() {
    if (!can('sweep_tiers.manage')) return;
    var button = el('sweep-tier-save-btn');
    button.disabled = true; button.classList.add('is-busy');
    setError(''); setNotice('');
    request('/api/admin/sweep-tiers/save.php', {
      rank_number: rankSelect.value,
      name: el('sweep-tier-name').value,
      loot_table_id: lootSelect.value,
      grid_rows: el('sweep-tier-rows').value,
      grid_cols: el('sweep-tier-cols').value,
      base_picks: el('sweep-tier-picks').value,
      hazard_count: el('sweep-tier-hazards').value,
      cache_credits: el('sweep-tier-cache').value,
      fatigue_cost: el('sweep-tier-fatigue').value,
      xp_reward: el('sweep-tier-xp').value,
      condition_key: conditionSelect.value,
      is_enabled: el('sweep-tier-enabled').checked
    }).then(function () {
      return load().then(function () { closeModal(); });
    }).catch(function (error) {
      setError(error.message);
    }).then(function () {
      button.disabled = !can('sweep_tiers.manage'); button.classList.remove('is-busy');
    });
  }

  function remove() {
    if (!can('sweep_tiers.manage') || !current) return;
    if (!window.confirm('Remove the sweep sector for rank ' + current.rank_number + '? Sweeps already in play keep their board.')) return;
    var button = el('sweep-tier-delete-btn');
    button.disabled = true; button.classList.add('is-busy');
    request('/api/admin/sweep-tiers/delete.php', { rank_number: current.rank_number }).then(function () {
      return load().then(function () { closeModal(); });
    }).catch(function (error) {
      setError(error.message);
    }).then(function () {
      button.disabled = false; button.classList.remove('is-busy');
    });
  }

  list.addEventListener('click', function (event) {
    var row = event.target.closest('[data-sweep-rank]');
    if (!row) return;
    var rank = Number(row.getAttribute('data-sweep-rank'));
    var tier = tiers.filter(function (item) { return item.rank_number === rank; })[0];
    if (tier) openModal(tier);
  });
  el('sweep-tier-add-btn').addEventListener('click', function () { openModal(null); });
  el('sweep-tier-save-btn').addEventListener('click', save);
  el('sweep-tier-delete-btn').addEventListener('click', remove);
  el('sweep-tier-cancel-btn').addEventListener('click', closeModal);
  el('sweep-tier-rows').addEventListener('input', syncHazardHint);
  el('sweep-tier-cols').addEventListener('input', syncHazardHint);
  conditionSelect.addEventListener('change', syncConditionPreview);

  window.PW_LOAD_SWEEP_TIERS = load;
}());
