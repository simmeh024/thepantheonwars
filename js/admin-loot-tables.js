/**
 * Loot Table Management (Admin Console -> Game Control -> Loot Tables).
 *
 * Two views over the same fetch: the loot tables themselves, and which missions
 * open them. Kept as its own file alongside js/admin-missions.js rather than
 * folded into admin/index.html, matching how Mission Control is already split
 * out of that already-large file.
 */
(function () {
  'use strict';

  var tables = [];
  var crewCatalogue = [];
  var missions = [];
  var activePanel = 'tables';
  var currentTable = null;   // null while the modal is closed, {} while creating
  var currentMission = null;
  var draftEntries = [];     // rows in the open loot-table modal
  var draftLinks = [];       // rows in the open mission modal

  var tableList = document.getElementById('loot-table-list');
  var missionList = document.getElementById('loot-mission-list');
  var count = document.getElementById('loot-admin-count');
  var tableModal = document.getElementById('loot-table-modal');
  var missionModal = document.getElementById('loot-mission-modal');

  function can(permission) {
    return typeof window.pwHasPermission === 'function' && window.pwHasPermission(permission);
  }

  function escapeHtml(value) {
    var node = document.createElement('div');
    node.textContent = value == null ? '' : String(value);
    return node.innerHTML;
  }

  function request(url, payload) {
    var options = { credentials: 'same-origin' };
    if (payload) {
      payload.csrf = window.PW_AUTH && window.PW_AUTH.csrf ? window.PW_AUTH.csrf : '';
      options.method = 'POST';
      options.headers = { 'Content-Type': 'application/json' };
      options.body = JSON.stringify(payload);
    }
    return fetch(url, options).then(function (response) {
      return response.json().catch(function () { return {}; });
    }).then(function (data) {
      if (!data.ok) throw new Error(data.error || 'The loot table request could not be completed.');
      return data;
    });
  }

  function assetUrl(url) {
    if (!url) return '';
    return url.indexOf('images/') === 0 ? '../' + url : url;
  }

  function blank(target, message) {
    if (target) target.innerHTML = '<div class="admin-list-empty">' + escapeHtml(message) + '</div>';
  }

  function showModalError(id, message) {
    var element = document.getElementById(id);
    if (element) element.textContent = message || '';
  }

  /* A chance is stored to three decimals but almost always typed as a whole
   * number, so trailing zeroes are trimmed for display -- "5%" rather than
   * "5.000%" -- while a genuinely fine-grained 0.05% keeps its precision. */
  function chance(value) {
    var number = Number(value) || 0;
    return String(Math.round(number * 1000) / 1000);
  }

  function crewById(id) {
    for (var i = 0; i < crewCatalogue.length; i++) {
      if (crewCatalogue[i].id === Number(id)) return crewCatalogue[i];
    }
    return null;
  }

  function tableById(id) {
    for (var i = 0; i < tables.length; i++) {
      if (tables[i].id === Number(id)) return tables[i];
    }
    return null;
  }

  function refreshCount() {
    if (!count) return;
    var total = activePanel === 'tables' ? tables.length : missions.length;
    count.textContent = total + (activePanel === 'tables'
      ? (total === 1 ? ' loot table' : ' loot tables')
      : (total === 1 ? ' mission' : ' missions'));
  }

  function switchPanel(panel) {
    activePanel = panel;
    ['tables', 'missions'].forEach(function (name) {
      var isActive = name === panel;
      var view = document.getElementById('loot-admin-' + name + '-panel');
      if (view) view.hidden = !isActive;
      var tab = document.querySelector('[data-loot-panel="' + name + '"]');
      if (tab) { tab.classList.toggle('active', isActive); tab.setAttribute('aria-selected', isActive ? 'true' : 'false'); }
    });
    document.getElementById('loot-table-create-btn').hidden = panel !== 'tables' || !can('loot_tables.edit');
    refreshCount();
  }

  function renderTables() {
    if (!tableList) return;
    if (!tables.length) { blank(tableList, 'No loot tables yet. Add one to start awarding characters from missions.'); return; }
    tableList.innerHTML = '';
    tables.forEach(function (table) {
      var entries = table.entries || [];
      // The strongest single drop is the figure that actually characterises a
      // table at a glance; a total would be misleading, since the entries are
      // independent rolls and can sum past 100%.
      var best = entries.reduce(function (highest, entry) { return Math.max(highest, Number(entry.chance_percent) || 0); }, 0);
      var names = entries.slice(0, 3).map(function (entry) { return entry.name || 'Missing character'; });
      var summary = entries.length
        ? escapeHtml(names.join(', ')) + (entries.length > 3 ? ' <em>+' + (entries.length - 3) + ' more</em>' : '')
        : '<em>No characters</em>';
      var row = document.createElement('button');
      row.type = 'button';
      row.className = 'admin-row loot-admin-row';
      row.innerHTML =
        '<div class="mission-admin-title"><div><strong>' + escapeHtml(table.name) + '</strong><small>' + escapeHtml(table.slug) + '</small></div></div>' +
        '<span class="loot-admin-cell">' + summary + '</span>' +
        '<span class="loot-admin-cell">' + (entries.length ? chance(best) + '%' : '&mdash;') + '</span>' +
        '<span class="loot-admin-cell">' + (table.mission_count ? table.mission_count + (table.mission_count === 1 ? ' mission' : ' missions') : '<em>Unused</em>') + '</span>' +
        '<span class="admin-pill ' + (table.is_enabled ? 'is-on' : 'is-off') + '">' + (table.is_enabled ? 'Enabled' : 'Disabled') + '</span>';
      row.addEventListener('click', function () { openTableModal(table); });
      tableList.appendChild(row);
    });
  }

  function renderMissions() {
    if (!missionList) return;
    if (!missions.length) { blank(missionList, 'No missions exist yet. Create one in Mission Control first.'); return; }
    missionList.innerHTML = '';
    missions.forEach(function (mission) {
      var links = mission.links || [];
      var summary = links.length
        ? links.map(function (link) {
            var table = tableById(link.loot_table_id);
            return escapeHtml((table ? table.name : 'Missing table') + ' (' + chance(link.chance_percent) + '%)');
          }).join(', ')
        : '<em>No loot tables</em>';
      var row = document.createElement('button');
      row.type = 'button';
      row.className = 'admin-row loot-mission-row';
      row.innerHTML =
        '<div class="mission-admin-title"><div><strong>' + escapeHtml(mission.name) + '</strong><small>' + escapeHtml(mission.world_key) + ' &middot; ' + escapeHtml(mission.slug) + '</small></div></div>' +
        '<span class="loot-admin-cell">' + summary + '</span>' +
        '<span class="admin-pill ' + (mission.is_enabled ? 'is-on' : 'is-off') + '">' + (mission.is_enabled ? 'Enabled' : 'Disabled') + '</span>';
      row.addEventListener('click', function () { openMissionModal(mission); });
      missionList.appendChild(row);
    });
  }

  /* --- Loot table modal --------------------------------------------------- */

  function renderDraftEntries() {
    var list = document.getElementById('loot-entry-list');
    var editable = can('loot_tables.edit');
    if (!draftEntries.length) {
      blank(list, 'No characters yet. Choose one below to add it to this table.');
      populateCrewPicker();
      return;
    }
    list.innerHTML = '';
    draftEntries.forEach(function (entry, index) {
      var crew = crewById(entry.crew_definition_id);
      var portrait = crew && crew.portrait_url
        ? '<img class="mission-admin-portrait" src="' + escapeHtml(assetUrl(crew.portrait_url)) + '" alt="">'
        : '';
      var row = document.createElement('div');
      row.className = 'loot-entry-row' + (portrait ? ' has-portrait' : '') + (crew && !crew.is_enabled ? ' is-disabled-crew' : '');
      row.innerHTML = portrait +
        '<div class="loot-entry-copy"><strong>' + escapeHtml(crew ? crew.name : 'Missing character') + '</strong>' +
        '<small>' + escapeHtml(crew ? crew.role : 'This character no longer exists') +
        (crew && !crew.is_enabled ? ' &middot; disabled, will never drop' : '') + '</small></div>' +
        '<label class="loot-entry-chance"><span>Chance</span><input type="number" min="0.01" max="100" step="0.01" value="' + escapeHtml(chance(entry.chance_percent)) + '"><i>%</i></label>' +
        '<button type="button" class="loot-entry-remove" aria-label="Remove ' + escapeHtml(crew ? crew.name : 'entry') + '">&times;</button>';
      var input = row.querySelector('input');
      input.disabled = !editable;
      input.addEventListener('input', function () { draftEntries[index].chance_percent = this.value; });
      var remove = row.querySelector('.loot-entry-remove');
      remove.hidden = !editable;
      remove.addEventListener('click', function () {
        if (!can('loot_tables.edit')) return;
        draftEntries.splice(index, 1);
        renderDraftEntries();
      });
      list.appendChild(row);
    });
    populateCrewPicker();
  }

  // Only characters not already in the table -- adding one twice would roll it
  // twice and quietly double its real chance, which the server rejects anyway.
  function populateCrewPicker() {
    var select = document.getElementById('loot-entry-crew');
    var chosen = draftEntries.map(function (entry) { return Number(entry.crew_definition_id); });
    select.replaceChildren();
    var available = crewCatalogue.filter(function (crew) { return chosen.indexOf(crew.id) === -1; });
    available.forEach(function (crew) {
      var option = document.createElement('option');
      option.value = String(crew.id);
      option.textContent = crew.name + ' — ' + crew.role + (crew.is_enabled ? '' : ' (disabled)');
      select.appendChild(option);
    });
    var addBtn = document.getElementById('loot-entry-add-btn');
    select.disabled = !available.length || !can('loot_tables.edit');
    addBtn.disabled = select.disabled;
    if (!available.length) {
      var option = document.createElement('option');
      option.textContent = crewCatalogue.length ? 'Every character is already in this table' : 'No characters exist yet';
      select.appendChild(option);
    }
  }

  function openTableModal(table) {
    if (!table && !can('loot_tables.edit')) return;
    currentTable = table || null;
    document.getElementById('loot-table-modal-title').textContent = table ? 'Edit Loot Table' : 'Add Loot Table';
    document.getElementById('loot-table-name').value = table ? table.name : '';
    document.getElementById('loot-table-slug').value = table ? table.slug : '';
    document.getElementById('loot-table-description').value = table && table.description ? table.description : '';
    document.getElementById('loot-table-enabled').checked = table ? !!table.is_enabled : true;
    draftEntries = (table && table.entries ? table.entries : []).map(function (entry) {
      return { crew_definition_id: entry.crew_definition_id, chance_percent: entry.chance_percent };
    });
    showModalError('loot-table-modal-error', '');
    document.getElementById('loot-table-modal-status').textContent = '';

    var editable = can('loot_tables.edit');
    ['loot-table-name', 'loot-table-slug', 'loot-table-description'].forEach(function (id) {
      document.getElementById(id).readOnly = !editable;
    });
    document.getElementById('loot-table-enabled').disabled = !editable;
    document.getElementById('loot-table-save-btn').disabled = !editable;
    // Delete stays hidden while creating and for anyone who cannot edit, and
    // is computed here rather than left to the static permission sweep, which
    // runs once and would be undone by this function.
    document.getElementById('loot-table-delete-btn').hidden = !table || !editable;

    renderDraftEntries();
    tableModal.hidden = false;
  }

  function closeTableModal() { tableModal.hidden = true; currentTable = null; }

  function saveTable() {
    if (!can('loot_tables.edit')) return;
    var button = document.getElementById('loot-table-save-btn');
    showModalError('loot-table-modal-error', '');
    button.disabled = true; button.classList.add('is-busy');
    request('/api/admin/loot-tables/save.php', {
      id: currentTable ? currentTable.id : null,
      name: document.getElementById('loot-table-name').value.trim(),
      slug: document.getElementById('loot-table-slug').value.trim(),
      description: document.getElementById('loot-table-description').value.trim(),
      is_enabled: document.getElementById('loot-table-enabled').checked,
      entries: draftEntries
    }).then(function () {
      closeTableModal();
      return load();
    }).catch(function (error) { showModalError('loot-table-modal-error', error.message); })
      .then(function () { button.disabled = !can('loot_tables.edit'); button.classList.remove('is-busy'); });
  }

  /* --- Mission assignment modal ------------------------------------------- */

  function renderDraftLinks() {
    var list = document.getElementById('loot-mission-link-list');
    var editable = can('loot_tables.edit');
    if (!draftLinks.length) {
      blank(list, 'This mission awards no characters. Attach a loot table below.');
      populateTablePicker();
      return;
    }
    list.innerHTML = '';
    draftLinks.forEach(function (link, index) {
      var table = tableById(link.loot_table_id);
      var entryCount = table && table.entries ? table.entries.length : 0;
      var row = document.createElement('div');
      row.className = 'loot-entry-row' + (table && !table.is_enabled ? ' is-disabled-crew' : '');
      row.innerHTML =
        '<div class="loot-entry-copy"><strong>' + escapeHtml(table ? table.name : 'Missing table') + '</strong>' +
        '<small>' + (table ? entryCount + (entryCount === 1 ? ' character' : ' characters') : 'This table no longer exists') +
        (table && !table.is_enabled ? ' &middot; disabled, will never drop' : '') + '</small></div>' +
        '<label class="loot-entry-chance"><span>Opens</span><input type="number" min="0.01" max="100" step="0.01" value="' + escapeHtml(chance(link.chance_percent)) + '"><i>%</i></label>' +
        '<button type="button" class="loot-entry-remove" aria-label="Detach ' + escapeHtml(table ? table.name : 'table') + '">&times;</button>';
      var input = row.querySelector('input');
      input.disabled = !editable;
      input.addEventListener('input', function () { draftLinks[index].chance_percent = this.value; });
      var remove = row.querySelector('.loot-entry-remove');
      remove.hidden = !editable;
      remove.addEventListener('click', function () {
        if (!can('loot_tables.edit')) return;
        draftLinks.splice(index, 1);
        renderDraftLinks();
      });
      list.appendChild(row);
    });
    populateTablePicker();
  }

  function populateTablePicker() {
    var select = document.getElementById('loot-mission-table');
    var chosen = draftLinks.map(function (link) { return Number(link.loot_table_id); });
    select.replaceChildren();
    var available = tables.filter(function (table) { return chosen.indexOf(table.id) === -1; });
    available.forEach(function (table) {
      var option = document.createElement('option');
      option.value = String(table.id);
      option.textContent = table.name + (table.is_enabled ? '' : ' (disabled)');
      select.appendChild(option);
    });
    var addBtn = document.getElementById('loot-mission-add-btn');
    select.disabled = !available.length || !can('loot_tables.edit');
    addBtn.disabled = select.disabled;
    if (!available.length) {
      var option = document.createElement('option');
      option.textContent = tables.length ? 'Every table is already attached' : 'No loot tables exist yet';
      select.appendChild(option);
    }
  }

  function openMissionModal(mission) {
    currentMission = mission;
    document.getElementById('loot-mission-modal-title').textContent = mission.name;
    draftLinks = (mission.links || []).map(function (link) {
      return { loot_table_id: link.loot_table_id, chance_percent: link.chance_percent };
    });
    showModalError('loot-mission-modal-error', '');
    document.getElementById('loot-mission-modal-status').textContent = '';
    document.getElementById('loot-mission-save-btn').disabled = !can('loot_tables.edit');
    renderDraftLinks();
    missionModal.hidden = false;
  }

  function closeMissionModal() { missionModal.hidden = true; currentMission = null; }

  function saveMissionLinks() {
    if (!can('loot_tables.edit') || !currentMission) return;
    var button = document.getElementById('loot-mission-save-btn');
    showModalError('loot-mission-modal-error', '');
    button.disabled = true; button.classList.add('is-busy');
    request('/api/admin/loot-tables/mission-tables-save.php', {
      mission_definition_id: currentMission.id,
      tables: draftLinks
    }).then(function () {
      closeMissionModal();
      return load();
    }).catch(function (error) { showModalError('loot-mission-modal-error', error.message); })
      .then(function () { button.disabled = !can('loot_tables.edit'); button.classList.remove('is-busy'); });
  }

  /* --- Wiring -------------------------------------------------------------- */

  document.querySelectorAll('[data-loot-panel]').forEach(function (tab) {
    tab.addEventListener('click', function () { switchPanel(tab.getAttribute('data-loot-panel')); });
  });
  document.getElementById('loot-table-create-btn').addEventListener('click', function () { openTableModal(null); });
  document.getElementById('loot-table-modal-close').addEventListener('click', closeTableModal);
  document.getElementById('loot-table-cancel-btn').addEventListener('click', closeTableModal);
  tableModal.querySelector('.admin-modal-backdrop').addEventListener('click', closeTableModal);
  document.getElementById('loot-table-save-btn').addEventListener('click', saveTable);
  document.getElementById('loot-entry-add-btn').addEventListener('click', function () {
    if (!can('loot_tables.edit')) return;
    var value = document.getElementById('loot-entry-crew').value;
    if (!value) return;
    draftEntries.push({ crew_definition_id: Number(value), chance_percent: 5 });
    renderDraftEntries();
  });
  document.getElementById('loot-table-delete-btn').addEventListener('click', function () {
    if (!can('loot_tables.edit') || !currentTable) return;
    if (!window.confirm('Delete "' + currentTable.name + '"? Its characters and chances are removed with it.')) return;
    var button = this;
    button.disabled = true; button.classList.add('is-busy');
    request('/api/admin/loot-tables/delete.php', { id: currentTable.id })
      .then(function () { closeTableModal(); return load(); })
      .catch(function (error) { showModalError('loot-table-modal-error', error.message); })
      .then(function () { button.disabled = false; button.classList.remove('is-busy'); });
  });

  document.getElementById('loot-mission-modal-close').addEventListener('click', closeMissionModal);
  document.getElementById('loot-mission-cancel-btn').addEventListener('click', closeMissionModal);
  missionModal.querySelector('.admin-modal-backdrop').addEventListener('click', closeMissionModal);
  document.getElementById('loot-mission-save-btn').addEventListener('click', saveMissionLinks);
  document.getElementById('loot-mission-add-btn').addEventListener('click', function () {
    if (!can('loot_tables.edit')) return;
    var value = document.getElementById('loot-mission-table').value;
    if (!value) return;
    draftLinks.push({ loot_table_id: Number(value), chance_percent: 100 });
    renderDraftLinks();
  });

  function load() {
    return request('/api/admin/loot-tables/list.php').then(function (data) {
      tables = data.tables || [];
      crewCatalogue = data.crew || [];
      missions = data.missions || [];
      renderTables();
      renderMissions();
      refreshCount();
    }).catch(function (error) {
      blank(tableList, error.message || 'Could not load loot tables. Run the Mission Loot Tables migration first.');
      blank(missionList, error.message || 'Could not load missions.');
    });
  }

  window.loadLootTables = function () {
    switchPanel(activePanel);
    return load();
  };
}());
