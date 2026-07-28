(function () {
  'use strict';

  var definitions = [];
  /* Overlord roster and contract readiness, both supplied by definitions-list. */
  var overlords = [], contractsReady = false, contestedContractsReady = false, salvageRecoveryContractsReady = false, overlordClearancesReady = false, contractProgressionReady = false, contractRank = 10;
  var missionGearSlots = [];
  var crew = [];
  var gear = [];
  var gearMeta = null;
  var playerMissions = [];
  var activePanel = 'definitions';
  var activeCrewRarity = 'all';
  var activeGearCategory = 'all';
  /* 'all' or a role name. */
  var activeCrewRole = 'all';
  var crewRoles = [];
  /* 'all', 'unrestricted' (no role requirement at all), or a role name. */
  var activeGearRole = 'all';
  /* The state each list was last built from, so a re-entry that changed nothing
   * can leave the existing rows (and their already-decoded art) alone. */
  var renderedCrewKey = null;
  var renderedGearKey = null;
  var currentDefinition = null;
  var currentCrew = null;
  var currentGear = null;
  var draftProgressionPreview = null;
  var progressionPreviewTimer = null;
  var progressionPreviewSequence = 0;

  var definitionList = document.getElementById('mission-definition-list');
  var crewList = document.getElementById('mission-crew-list');
  var gearList = document.getElementById('mission-gear-list');
  var playerMissionList = document.getElementById('mission-player-missions-list');
  var missionCount = document.getElementById('mission-admin-count');
  var crewCount = document.getElementById('crew-management-count');
  var gearCount = document.getElementById('gear-management-count');
  var definitionModal = document.getElementById('mission-definition-modal');
  var crewModal = document.getElementById('mission-crew-modal');
  var gearModal = document.getElementById('mission-gear-modal');
  var imageModal = document.getElementById('mission-crew-image-modal');

  function can(permission) {
    return typeof window.pwHasPermission === 'function' && window.pwHasPermission(permission);
  }

  /* One 42px list thumbnail.
   *
   * The endpoint sends a 96px copy where one exists and an empty string
   * otherwise -- a freshly uploaded image whose thumbnail has not been written
   * yet, or a site image outside the upload library, both of which fall back to
   * the original rather than showing an empty cell. Lazy either way: a
   * catalogue is longer than a viewport, and 42px is the size actually drawn,
   * so declaring it stops the browser reserving room for the source dimensions. */
  function rowThumbnail(thumbUrl, fullUrl) {
    var source = thumbUrl || fullUrl;
    return '<img class="mission-admin-portrait" src="' + escapeHtml(assetUrl(source))
      + '" alt="" width="42" height="42" loading="lazy" decoding="async">';
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
      if (!data.ok) throw new Error(data.error || 'The mission request could not be completed.');
      return data;
    });
  }

  function assetUrl(url) {
    if (!url) return '';
    return url.indexOf('images/') === 0 ? '../' + url : url;
  }

  function duration(seconds) {
    seconds = Number(seconds) || 0;
    if (seconds % 3600 === 0) return (seconds / 3600) + 'h';
    if (seconds >= 60 && seconds % 60 === 0) return (seconds / 60) + ' min';
    return seconds + ' sec';
  }

  function dateTime(value) {
    if (!value) return '—';
    var date = new Date(String(value).replace(' ', 'T') + 'Z');
    if (isNaN(date.getTime())) return String(value);
    return date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
  }

  function statusPill(enabled, activeLabel, inactiveLabel) {
    return '<span class="report-status ' + (enabled ? 'report-status-resolved' : 'report-status-dismissed') + '">' + escapeHtml(enabled ? activeLabel : inactiveLabel) + '</span>';
  }

  function blank(list, message) {
    list.innerHTML = '<div class="admin-list-empty">' + escapeHtml(message) + '</div>';
  }

  function refreshMissionCount() {
    if (!missionCount) return;
    // Presentation is a settings form, not a list, so there is nothing to
    // count -- printing "0 player missions" beside it would be wrong.
    if (activePanel === 'presentation') { missionCount.textContent = ''; return; }
    var counts = {
      definitions: [definitions.length, ' mission'],
      'player-missions': [playerMissions.length, ' player mission']
    };
    var entry = counts[activePanel] || counts.definitions;
    missionCount.textContent = entry[0] + entry[1] + (entry[0] === 1 ? '' : 's');
  }

  /* What the rendered rows are a function of. The active filter is part of the
   * key because switching it changes which rows belong on screen without
   * changing the catalogue, and the readiness flags are part of the gear key
   * because they decide which columns a row prints at all. */
  function crewRenderKey(visible) {
    return activeCrewRarity + '|' + activeCrewRole + '|' + can('missions.edit') + '|' + JSON.stringify(visible);
  }

  function gearRenderKey(visible) {
    return activeGearCategory + '|' + activeGearRole + '|' + can('missions.edit') + '|'
      + (gearMeta ? [gearMeta.item_levels_ready, gearMeta.field_grade_ready, gearMeta.stims_ready].join(',') : '')
      + '|' + JSON.stringify(visible);
  }

  /* Rarity and role compose, the same way the two gear filters do: "which
   * legendary Engineers exist" is the question a progression band is tuned
   * against, and neither filter answers it alone. */
  function filteredCrew() {
    return crew.filter(function (member) {
      if (activeCrewRarity !== 'all' && member.tier !== activeCrewRarity) return false;
      return activeCrewRole === 'all' || member.role === activeCrewRole;
    });
  }

  function crewFiltersActive() {
    return activeCrewRarity !== 'all' || activeCrewRole !== 'all';
  }

  /* "legendary Engineer crew members", "epic crew members", "Engineer crew
   * members" -- a role is something a crew member is, so it reads as an
   * adjective here rather than as the "for <role>" a gear requirement needs. */
  function crewFilterLabel() {
    var parts = [];
    if (activeCrewRarity !== 'all') parts.push(activeCrewRarity);
    if (activeCrewRole !== 'all') parts.push(activeCrewRole);
    parts.push('crew members');
    return parts.join(' ');
  }

  /** One button per crew role, from the list crew-list.php sends. */
  function renderCrewRoleFilters() {
    var container = document.getElementById('crew-management-role-filters');
    if (!container) return;
    container.querySelectorAll('[data-crew-role-filter]').forEach(function (button) {
      if (button.getAttribute('data-crew-role-filter') !== 'all') button.remove();
    });
    crewRoles.forEach(function (role) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'admin-filter-tab';
      button.setAttribute('data-crew-role-filter', role);
      button.setAttribute('role', 'tab');
      button.textContent = role;
      container.appendChild(button);
    });
    /* A role retired from the engine while it was the active filter would
     * otherwise leave the roster empty with no button left to clear it. */
    if (activeCrewRole !== 'all' && crewRoles.indexOf(activeCrewRole) === -1) activeCrewRole = 'all';
    syncCrewRoleFilterState();
  }

  function syncCrewRoleFilterState() {
    document.querySelectorAll('[data-crew-role-filter]').forEach(function (button) {
      var isActive = button.getAttribute('data-crew-role-filter') === activeCrewRole;
      button.classList.toggle('active', isActive);
      button.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
  }

  /* Category and role compose, deliberately: "which stim does a Fixer need" is a
   * real question, and either filter alone cannot answer it. Salvage and stims
   * carry no role requirement, so pairing one with a named role is legitimately
   * empty rather than broken -- refreshGearCount() and the empty message both
   * name the pair so that reads as a filter result and not a missing catalogue. */
  function filteredGear() {
    return gear.filter(function (item) {
      if (activeGearCategory !== 'all' && item.category !== activeGearCategory) return false;
      if (activeGearRole === 'all') return true;
      var required = item.required_role || '';
      return activeGearRole === 'unrestricted' ? required === '' : required === activeGearRole;
    });
  }

  function gearFiltersActive() {
    return activeGearCategory !== 'all' || activeGearRole !== 'all';
  }

  /* What the current pair of filters is asking for, as a phrase that reads
   * correctly for either filter alone and for both together: "equipment for
   * Fixer", "stims", "items without a role requirement". */
  function gearFilterLabel() {
    var nouns = { gear: 'equipment', salvage: 'salvage', stim: 'stims' };
    var noun = nouns[activeGearCategory] || 'items';
    if (activeGearRole === 'unrestricted') return noun + ' without a role requirement';
    if (activeGearRole !== 'all') return noun + ' for ' + activeGearRole;
    return noun;
  }

  /**
   * One button per crew role, from the role list the endpoint already sends.
   *
   * Built rather than written into the markup so adding a role to the engine
   * needs no HTML edit -- the same reason the tier and role selects in the
   * editor are populated from this response. The two static options stay in the
   * markup because they are not roles and always apply.
   */
  function renderGearRoleFilters() {
    var container = document.getElementById('gear-management-role-filters');
    if (!container || !gearMeta) return;
    var roles = gearMeta.roles || [];
    container.querySelectorAll('[data-gear-role-filter]').forEach(function (button) {
      var value = button.getAttribute('data-gear-role-filter');
      if (value !== 'all' && value !== 'unrestricted') button.remove();
    });
    roles.forEach(function (role) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'admin-filter-tab';
      button.setAttribute('data-gear-role-filter', role);
      button.setAttribute('role', 'tab');
      button.textContent = role;
      container.appendChild(button);
    });
    /* A role removed from the engine while it was the active filter would
     * otherwise leave the list permanently empty with no button to clear it. */
    if (activeGearRole !== 'all' && activeGearRole !== 'unrestricted' && roles.indexOf(activeGearRole) === -1) {
      activeGearRole = 'all';
    }
    syncGearRoleFilterState();
  }

  function syncGearRoleFilterState() {
    document.querySelectorAll('[data-gear-role-filter]').forEach(function (button) {
      var isActive = button.getAttribute('data-gear-role-filter') === activeGearRole;
      button.classList.toggle('active', isActive);
      button.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
  }

  function refreshCrewCount() {
    if (!crewCount) return;
    var visible = filteredCrew().length;
    crewCount.textContent = !crewFiltersActive()
      ? visible + ' crew member' + (visible === 1 ? '' : 's')
      : visible + ' of ' + crew.length + ' crew member' + (crew.length === 1 ? '' : 's');
  }

  /**
   * Total and average iLvl for whatever the filters are currently showing.
   *
   * Counted over the items that actually carry an item level -- an iLvl belongs
   * to equipment, and the row itself prints an em-dash for slotless salvage and
   * stims. Averaging those in as zeros would report a Salvage view as "avg 0",
   * which is a wrong number rather than an absent one, so the note says how many
   * items the figures came from and the card reads em-dashes when none did.
   *
   * Hidden entirely until the iLvl migration has been run, matching the editor's
   * own field: there is no honest figure to show while the column does not exist.
   */
  function refreshGearIlvlSummary() {
    var card = document.getElementById('gear-management-ilvl-summary');
    if (!card) return;
    if (!gearMeta || gearMeta.item_levels_ready === false) { card.hidden = true; return; }
    card.hidden = false;
    var rated = filteredGear().filter(function (item) {
      return item.slot && Number(item.item_level) > 0;
    });
    var total = rated.reduce(function (sum, item) { return sum + Number(item.item_level); }, 0);
    var totalCell = document.getElementById('gear-management-ilvl-total');
    var averageCell = document.getElementById('gear-management-ilvl-average');
    var note = document.getElementById('gear-management-ilvl-note');
    if (!rated.length) {
      totalCell.innerHTML = '&mdash;';
      averageCell.innerHTML = '&mdash;';
      /* Spelled out in this case only: two em-dashes need explaining, where a
       * real figure only needs its sample size. */
      note.textContent = 'No iLvl in this view.';
      return;
    }
    totalCell.textContent = String(total);
    /* One decimal: a set of 7 items rarely averages to a whole number, and
     * rounding to one would hide a real difference between two filters. */
    averageCell.textContent = String(Math.round((total / rated.length) * 10) / 10);
    note.textContent = 'from ' + rated.length + ' item' + (rated.length === 1 ? '' : 's');
  }

  function refreshGearCount() {
    if (!gearCount) return;
    var visible = filteredGear().length;
    gearCount.textContent = !gearFiltersActive()
      ? visible + ' item' + (visible === 1 ? '' : 's')
      : visible + ' of ' + gear.length + ' item' + (gear.length === 1 ? '' : 's');
  }

  function renderDefinitions() {
    if (!definitionList) return;
    if (!definitions.length) { blank(definitionList, 'No mission definitions yet.'); return; }
    definitionList.innerHTML = '';
    definitions.forEach(function (mission) {
      var row = document.createElement('div');
      row.className = 'admin-row mission-admin-row';
      row.setAttribute('aria-disabled', can('missions.edit') ? 'false' : 'true');
      if (can('missions.edit')) { row.tabIndex = 0; row.setAttribute('role', 'button'); }
      row.innerHTML =
        '<div class="mission-admin-title"><div><strong>' + escapeHtml(mission.name) + '</strong><small>' + escapeHtml(mission.slug) + ' · ' + escapeHtml(mission.world_key) + '</small></div></div>' +
        '<span class="mission-admin-type">' + escapeHtml(mission.mission_type) + '</span>' +
        '<span>' + duration(mission.duration_seconds) + '</span>' +
        '<span>' + mission.min_crew + '–' + mission.max_crew + '</span>' +
        '<span class="mission-admin-reward">+' + mission.xp_reward + ' XP <small class="mission-admin-cell-sub">+' + mission.reputation_reward + ' rep</small></span>' +
        statusPill(mission.is_enabled, 'Enabled', 'Disabled');
      var detail = row.querySelector('.mission-admin-title small');
      if (mission.unlocks_after_mission_name && detail) {
        detail.textContent += ' | Unlocks after ' + mission.unlocks_after_mission_name + ' × ' + mission.unlocks_after_completion_count;
      }
      if (mission.is_campaign_final && detail) detail.textContent += ' | Campaign finale';
      if (mission.requires_research_unlock && detail) detail.textContent += ' | Research locked';
      if (mission.overlord_name && detail) detail.textContent += ' | Contract: ' + mission.overlord_name;
      if (mission.is_contested && detail) detail.textContent += ' | Contested by ' + (mission.rival_faction_name || 'a rival recovery team');
      if (mission.is_salvage_recovery_contract && detail) detail.textContent += ' | Sweep recovery pool';
      if (mission.requires_overlord_clearance && detail) detail.textContent += ' | Blocked tile';
      if (contractProgressionReady && detail) {
        detail.textContent += ' | Tier ' + (Number(mission.contract_tier) || 1);
        if (Number(mission.recommended_item_level) > 0) detail.textContent += ' · rec. iLvl ' + Number(mission.recommended_item_level);
        if (Number(mission.reward_item_level_min) > 0 && Number(mission.reward_item_level_max) > 0) detail.textContent += ' · rewards iLvl ' + Number(mission.reward_item_level_min) + '–' + Number(mission.reward_item_level_max);
      }
      if (can('missions.edit')) {
        row.addEventListener('click', function () { openDefinition(mission); });
        row.addEventListener('keydown', function (event) { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openDefinition(mission); } });
      }
      definitionList.appendChild(row);
    });
  }

  function renderCrew() {
    if (!crewList) return;
    var visibleCrew = filteredCrew();
    refreshCrewCount();
    if (!visibleCrew.length) {
      blank(crewList, crewFiltersActive() ? 'No ' + crewFilterLabel() + ' yet.' : 'No crew definitions yet.');
      return;
    }
    /* Entering a section re-fetches by design (see showSection), but rebuilding
     * identical rows is not free: it discards every <img> and makes the browser
     * decode the full-resolution art again. Skip when both the data and the
     * active filter are unchanged and the rows are still on screen. */
    if (renderedCrewKey === crewRenderKey(visibleCrew) && crewList.querySelector('.admin-row')) return;
    renderedCrewKey = crewRenderKey(visibleCrew);
    crewList.innerHTML = '';
    visibleCrew.forEach(function (member) {
      var row = document.createElement('div');
      row.className = 'admin-row mission-admin-row mission-admin-crew-columns';
      row.setAttribute('aria-disabled', can('missions.edit') ? 'false' : 'true');
      if (can('missions.edit')) { row.tabIndex = 0; row.setAttribute('role', 'button'); }
      var portrait = member.portrait_url ? rowThumbnail(member.portrait_thumb_url, member.portrait_url) : '';
      row.innerHTML =
        '<div class="mission-admin-title">' + portrait + '<div><strong>' + escapeHtml(member.name) + '</strong><small>Level ' + member.starting_level + ' · ' + escapeHtml(member.slug) + '</small></div></div>' +
        '<span>' + escapeHtml(member.role) + '</span>' +
        '<span class="mission-crew-tier is-' + escapeHtml(member.tier || 'common') + '">' + escapeHtml(member.tier || 'common') + '</span>' +
        '<span>' + escapeHtml(member.world_affinity) + '</span>' +
        '<span>' + (member.is_starter ? 'Yes' : 'No') + '</span>' +
        '<span>' + member.player_count + ' player' + (member.player_count === 1 ? '' : 's') + '</span>' +
        statusPill(member.is_enabled, 'Enabled', 'Disabled');
      if (can('missions.edit')) {
        row.addEventListener('click', function () { openCrew(member); });
        row.addEventListener('keydown', function (event) { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openCrew(member); } });
      }
      crewList.appendChild(row);
    });
  }

  function renderPlayerMissions() {
    if (!playerMissionList) return;
    refreshMissionCount();
    if (!can('missions.player_missions')) { blank(playerMissionList, 'You do not have permission to view player mission diagnostics.'); return; }
    if (!playerMissions.length) { blank(playerMissionList, 'No player missions have been launched yet.'); return; }
    playerMissionList.innerHTML = '';
    playerMissions.forEach(function (mission) {
      var row = document.createElement('div');
      row.className = 'admin-row mission-admin-row mission-admin-player-columns';
      row.setAttribute('aria-disabled', 'true');
      row.innerHTML =
        '<div class="mission-admin-title"><div><strong>' + escapeHtml(mission.display_name || mission.username) + '</strong><small>@' + escapeHtml(mission.username) + '</small></div></div>' +
        '<span>' + escapeHtml(mission.mission_name) + '</span>' +
        '<span class="mission-admin-cell-sub">' + escapeHtml((mission.crew_names || []).join(', ') || 'No crew recorded') + '</span>' +
        '<span class="mission-admin-cell-sub">' + escapeHtml(dateTime(mission.started_at)) + '</span>' +
        '<span class="mission-admin-cell-sub">' + escapeHtml(dateTime(mission.completes_at)) + '</span>' +
        statusPill(mission.status === 'claimed', mission.status, mission.status);
      playerMissionList.appendChild(row);
    });
  }

  function switchPanel(panel) {
    activePanel = panel;
    ['definitions', 'player-missions', 'presentation'].forEach(function (name) {
      var isActive = name === panel;
      var view = document.getElementById('mission-admin-' + name + '-panel');
      if (view) view.hidden = !isActive;
      var tab = document.querySelector('[data-mission-panel="' + name + '"]');
      if (tab) { tab.classList.toggle('active', isActive); tab.setAttribute('aria-selected', isActive ? 'true' : 'false'); }
    });
    document.getElementById('mission-definition-create-btn').hidden = panel !== 'definitions' || !can('missions.edit');
    refreshMissionCount();
  }

  function loadDefinitions() {
    return request('/api/admin/missions/definitions-list.php').then(function (data) { definitions = data.missions || []; overlords = data.overlords || []; contractsReady = !!data.contracts_ready; contestedContractsReady = !!data.contested_contracts_ready; salvageRecoveryContractsReady = !!data.salvage_recovery_contracts_ready; overlordClearancesReady = !!data.overlord_clearances_ready; contractProgressionReady = !!data.contract_progression_ready; missionGearSlots = data.gear_slots || []; contractRank = Number(data.contract_rank) || 10; renderDefinitions(); refreshMissionCount(); }).catch(function (error) { blank(definitionList, error.message || 'Could not load mission definitions. Run the Missions V0 migration first.'); });
  }

  function loadCrew() {
    return request('/api/admin/missions/crew-list.php').then(function (data) {
      crew = data.crew || [];
      crewRoles = data.roles || [];
      /* Before renderCrew(), since this can retire the active role filter and
       * the render has to reflect that rather than the value it just cleared. */
      renderCrewRoleFilters();
      renderCrew();
    }).catch(function (error) { blank(crewList, error.message || 'Could not load crew definitions.'); });
  }

  /* Gear is loot with a slot, so this list is every loot definition -- Gear
   * Management is the only management surface game_loot_definitions has ever had, and
   * hiding the slotless items would leave the existing salvage unreachable. */
  function loadGear() {
    return request('/api/admin/missions/gear-list.php').then(function (data) {
      gear = data.gear || [];
      gearMeta = data;
      populateGearOptions();
      /* Before renderGear(), since it can retire the active role filter and the
       * render has to reflect that rather than the value it just cleared. */
      renderGearRoleFilters();
      renderGear();
    }).catch(function (error) { blank(gearList, error.message || 'Could not load equipment. Run sql/migration_mission_gear.sql first.'); });
  }

  function loadPlayerMissions() {
    if (!can('missions.player_missions')) { renderPlayerMissions(); return Promise.resolve(); }
    return request('/api/admin/missions/player-missions-list.php').then(function (data) { playerMissions = data.missions || []; renderPlayerMissions(); }).catch(function (error) { blank(playerMissionList, error.message || 'Could not load player mission diagnostics.'); });
  }

  window.loadMissionControl = function () {
    switchPanel(activePanel);
    applyPresentationPermissions();
    return Promise.all([loadDefinitions(), loadPlayerMissions(), loadWatermark()]);
  };

  window.loadCrewManagement = function () {
    document.getElementById('mission-crew-create-btn').hidden = !can('missions.edit');
    return loadCrew();
  };

  window.loadGearManagement = function () {
    document.getElementById('mission-gear-create-btn').hidden = !can('missions.edit');
    return loadGear();
  };

  /* A view-only session may inspect the watermark settings but not change them.
   * Computed here rather than left to the static data-requires-permission
   * sweep, because updateWatermarkPreview() re-evaluates the toggle's disabled
   * state on every change and would otherwise re-enable it. */
  function applyPresentationPermissions() {
    var editable = can('missions.edit');
    ['mission-watermark-upload', 'mission-watermark-browse', 'mission-watermark-clear', 'mission-watermark-save-btn'].forEach(function (id) {
      var element = document.getElementById(id);
      if (element) element.disabled = !editable;
    });
    document.getElementById('mission-watermark-url').readOnly = !editable;
    document.getElementById('mission-watermark-opacity').disabled = !editable;
  }

  function populateMissionSuccessionOptions(mission) {
    var select = document.getElementById('mission-definition-unlocks-after');
    var selected = mission && mission.unlocks_after_mission_id ? String(mission.unlocks_after_mission_id) : '';
    select.replaceChildren();
    var baseOption = document.createElement('option'); baseOption.value = ''; baseOption.textContent = 'No prerequisite — available immediately'; select.appendChild(baseOption);
    definitions.filter(function (candidate) { return !mission || candidate.id !== mission.id; }).forEach(function (candidate) {
      var option = document.createElement('option'); option.value = String(candidate.id);
      option.textContent = candidate.name + ' · ' + candidate.world_key;
      select.appendChild(option);
    });
    select.value = Array.prototype.some.call(select.options, function (option) { return option.value === selected; }) ? selected : '';
  }

  function syncMissionSuccessionFields() {
    var prerequisite = document.getElementById('mission-definition-unlocks-after').value;
    var completions = document.getElementById('mission-definition-unlock-completions');
    var hint = document.getElementById('mission-definition-unlock-hint');
    var hasPrerequisite = prerequisite !== '';
    completions.disabled = !hasPrerequisite;
    if (!hasPrerequisite) {
      completions.value = 0;
      hint.textContent = 'Choose a previous mission to make this a tougher follow-up operation.';
    } else {
      if (Number(completions.value) < 1) completions.value = 1;
      hint.textContent = 'Players must claim this many successful runs of the selected mission before this operation unlocks.';
    }
  }

  function syncMissionContestedFields() {
    var field = document.getElementById('mission-definition-contested-field');
    var overlord = document.getElementById('mission-definition-overlord');
    var toggle = document.getElementById('mission-definition-contested');
    var faction = document.getElementById('mission-definition-rival-faction');
    var recovery = document.getElementById('mission-definition-salvage-recovery');
    if (!field || !overlord || !toggle || !faction) return;
    field.hidden = !contestedContractsReady;
    var isContract = contractsReady && overlord.value !== '';
    if (!isContract || (recovery && recovery.checked)) toggle.checked = false;
    toggle.disabled = !isContract || !!(recovery && recovery.checked);
    faction.disabled = !isContract || !toggle.checked;
    if (!isContract) faction.value = '';
  }

  function syncMissionSalvageRecoveryField() {
    var field = document.getElementById('mission-definition-salvage-recovery-field');
    var toggle = document.getElementById('mission-definition-salvage-recovery');
    var overlord = document.getElementById('mission-definition-overlord');
    var contested = document.getElementById('mission-definition-contested');
    if (!field || !toggle || !overlord) return;
    field.hidden = !salvageRecoveryContractsReady;
    var isOrdinary = contractsReady && overlord.value === '';
    if (!isOrdinary || (contested && contested.checked)) toggle.checked = false;
    toggle.disabled = !isOrdinary || !!(contested && contested.checked);
  }

  function syncMissionOverlordClearanceField() {
    var field = document.getElementById('mission-definition-overlord-clearance-field');
    var toggle = document.getElementById('mission-definition-overlord-clearance');
    var overlord = document.getElementById('mission-definition-overlord');
    if (!field || !toggle || !overlord) return;
    field.hidden = !overlordClearancesReady;
    var isContract = contractsReady && overlord.value !== '';
    if (!isContract) toggle.checked = false;
    toggle.disabled = !isContract;
  }

  function featuredMissionSlots() {
    return Array.prototype.map.call(document.querySelectorAll('#mission-definition-featured-slots input:checked'), function (input) { return input.value; });
  }

  function syncFeaturedMissionSlotLimit() {
    var selected = featuredMissionSlots();
    document.querySelectorAll('#mission-definition-featured-slots input').forEach(function (input) {
      input.disabled = !input.checked && selected.length >= 2;
    });
  }

  function renderMissionProgressionSlots(mission) {
    var section = document.getElementById('mission-definition-progression-fields');
    var target = document.getElementById('mission-definition-featured-slots');
    if (!section || !target) return;
    section.hidden = !contractProgressionReady;
    if (!contractProgressionReady) { target.replaceChildren(); return; }
    var selected = mission && Array.isArray(mission.featured_slots) ? mission.featured_slots : [];
    target.innerHTML = missionGearSlots.map(function (slot) {
      var key = String(slot.key || '');
      var checked = selected.indexOf(key) !== -1 ? ' checked' : '';
      return '<label class="mission-progression-slot-option"><input type="checkbox" value="' + escapeHtml(key) + '"' + checked + '><span>' + escapeHtml(slot.label || key) + '</span></label>';
    }).join('');
    target.querySelectorAll('input').forEach(function (input) {
      input.addEventListener('change', function () {
        if (featuredMissionSlots().length > 2) input.checked = false;
        syncFeaturedMissionSlotLimit();
        updateMissionProgressionPreview(currentDefinition);
        scheduleMissionProgressionPreview();
      });
    });
    syncFeaturedMissionSlotLimit();
  }

  function authoredMissionProgression() {
    return {
      tier: Math.max(1, Number(document.getElementById('mission-definition-contract-tier').value) || 1),
      recommended: Math.max(0, Number(document.getElementById('mission-definition-recommended-ilvl').value) || 0),
      minimum: Math.max(0, Number(document.getElementById('mission-definition-reward-ilvl-min').value) || 0),
      maximum: Math.max(0, Number(document.getElementById('mission-definition-reward-ilvl-max').value) || 0),
      featured: featuredMissionSlots()
    };
  }

  function updateMissionProgressionPreview(mission, livePreview) {
    var preview = document.getElementById('mission-definition-progression-preview');
    if (!preview || !contractProgressionReady) return;
    var progression = authoredMissionProgression();
    var labels = {};
    missionGearSlots.forEach(function (slot) { labels[slot.key] = slot.label; });
    var playerLines = ['<strong>Player-facing brief</strong>', '<span>Tier ' + progression.tier + ' contract'
      + (progression.recommended ? ' · recommended avg iLvl ' + progression.recommended : '') + '.</span>'];
    playerLines.push('<span>' + (progression.minimum && progression.maximum
      ? 'Wearable rewards: iLvl ' + progression.minimum + '–' + progression.maximum + '.'
      : progression.minimum || progression.maximum
        ? 'Wearable rewards: complete both ends of the iLvl band before saving.'
        : 'Wearable rewards: open iLvl band.') + '</span>');
    playerLines.push('<span>' + (progression.featured.length
      ? 'Featured slots: ' + escapeHtml(progression.featured.map(function (slot) { return labels[slot] || slot; }).join(' · ')) + ' (2× drop priority).'
      : 'No featured slots selected.') + '</span>');
    var saved = livePreview || (mission && mission.progression_preview);
    if (!saved) {
      playerLines.push('<span class="mission-progression-preview-warning">Calculating the current world-pool and loot-table reward coverage&hellip;</span>');
      preview.innerHTML = playerLines.join('');
      return;
    }
    var savedLines = ['<strong class="mission-progression-preview-saved">' + (livePreview ? 'Live reward check' : 'Saved reward check') + '</strong>'];
    if (!saved.ready) savedLines.push('<span class="mission-progression-preview-warning">The gear and item-level migrations are required before table coverage can be calculated.</span>');
    else if (!Number(saved.wearable_entries)) savedLines.push('<span class="mission-progression-preview-warning">No enabled wearable entries are available through this contract&rsquo;s world pool or attached loot tables.</span>');
    else {
      var sources = [];
      if (Number(saved.world_pool_entries)) sources.push(Number(saved.world_pool_entries) + ' world-pool');
      if (Number(saved.linked_table_entries)) sources.push(Number(saved.linked_table_entries) + ' table ' + (Number(saved.linked_table_entries) === 1 ? 'entry' : 'entries') + ' across ' + Number(saved.linked_tables) + ' linked table' + (Number(saved.linked_tables) === 1 ? '' : 's'));
      savedLines.push('<span>' + Number(saved.eligible_entries) + ' of ' + Number(saved.wearable_entries) + ' wearable entries fit this band'
        + (sources.length ? ' (' + sources.join(' + ') + ')' : '')
        + (Number(saved.filtered_entries) ? '; ' + Number(saved.filtered_entries) + ' filtered out' : '') + '.</span>');
      savedLines.push('<span>iLvl spread ' + Number(saved.item_level_min) + '–' + Number(saved.item_level_max) + ' · typical iLvl ' + Number(saved.item_level_average || 0).toFixed(1)
        + ' · ' + Number(saved.roles_covered) + ' role' + (Number(saved.roles_covered) === 1 ? '' : 's') + ' covered.</span>');
      if (Number(saved.featured_entries)) savedLines.push('<span>' + Number(saved.featured_entries) + ' eligible reward entr' + (Number(saved.featured_entries) === 1 ? 'y is' : 'ies are') + ' in a featured slot.</span>');
    }
    if (saved.loot_tables_ready === false) savedLines.push('<span class="mission-progression-preview-warning">Loot-table coverage is unavailable until its gear migration is installed; this check currently reflects the ordinary world pool only.</span>');
    preview.innerHTML = playerLines.concat(savedLines).join('');
  }

  function scheduleMissionProgressionPreview() {
    if (!contractProgressionReady) return;
    if (progressionPreviewTimer) window.clearTimeout(progressionPreviewTimer);
    var sequence = ++progressionPreviewSequence;
    progressionPreviewTimer = window.setTimeout(function () {
      var progression = authoredMissionProgression();
      request('/api/admin/missions/progression-preview.php', {
        mission_id: currentDefinition ? currentDefinition.id : '',
        world_key: document.getElementById('mission-definition-world').value,
        loot_rolls: document.getElementById('mission-definition-loot-rolls').value,
        contract_tier: progression.tier,
        recommended_item_level: progression.recommended,
        reward_item_level_min: progression.minimum,
        reward_item_level_max: progression.maximum,
        featured_slots: progression.featured
      }).then(function (data) {
        if (sequence !== progressionPreviewSequence || definitionModal.hidden) return;
        draftProgressionPreview = data.preview || null;
        updateMissionProgressionPreview(currentDefinition, draftProgressionPreview);
      }).catch(function () {
        /* The saved check stays visible if a transient preview request fails;
         * saving still receives the normal, explicit server validation. */
      });
    }, 170);
  }

  function definitionValues(mission) {
    draftProgressionPreview = null;
    progressionPreviewSequence++;
    document.getElementById('mission-definition-name').value = mission ? mission.name : '';
    document.getElementById('mission-definition-slug').value = mission ? mission.slug : '';
    document.getElementById('mission-definition-description').value = mission ? mission.description : '';
    document.getElementById('mission-definition-world').value = mission ? mission.world_key : 'neoh';
    document.getElementById('mission-definition-type').value = mission ? mission.mission_type : 'recon';
    document.getElementById('mission-definition-duration').value = mission ? mission.duration_seconds : 300;
    document.getElementById('mission-definition-min-crew').value = mission ? mission.min_crew : 1;
    document.getElementById('mission-definition-max-crew').value = mission ? mission.max_crew : 1;
    document.getElementById('mission-definition-xp').value = mission ? mission.xp_reward : 20;
    document.getElementById('mission-definition-reputation').value = mission ? mission.reputation_reward : 0;
    document.getElementById('mission-definition-sort-order').value = mission ? mission.sort_order : 0;
    document.getElementById('mission-definition-enabled').checked = mission ? mission.is_enabled : true;
    document.getElementById('mission-definition-research-locked').checked = mission ? !!mission.requires_research_unlock : false;
    /* The Overlord picker is only populated once the contracts migration has
     * been run; the field hides entirely before that rather than offering a
     * control whose value the save endpoint would drop. */
    var overlordField = document.getElementById('mission-definition-overlord-field');
    var overlordSelect = document.getElementById('mission-definition-overlord');
    if (overlordField) overlordField.hidden = !contractsReady;
    if (overlordSelect) {
      overlordSelect.innerHTML = '<option value="">Not a contract — ordinary mission</option>'
        + overlords.map(function (overlord) {
          return '<option value="' + Number(overlord.id) + '">' + escapeHtml(overlord.name + (overlord.epithet ? ' · ' + overlord.epithet : '')) + '</option>';
        }).join('');
      overlordSelect.value = mission && mission.overlord_id ? String(mission.overlord_id) : '';
    }
    document.getElementById('mission-definition-contested').checked = mission ? !!mission.is_contested : false;
    document.getElementById('mission-definition-rival-faction').value = mission && mission.rival_faction_name ? mission.rival_faction_name : '';
    document.getElementById('mission-definition-salvage-recovery').checked = mission ? !!mission.is_salvage_recovery_contract : false;
    document.getElementById('mission-definition-overlord-clearance').checked = mission ? !!mission.requires_overlord_clearance : false;
    populateMissionSuccessionOptions(mission);
    document.getElementById('mission-definition-unlock-completions').value = mission && mission.unlocks_after_mission_id ? mission.unlocks_after_completion_count : 0;
    document.getElementById('mission-definition-campaign-final').checked = mission ? !!mission.is_campaign_final : false;
    document.getElementById('mission-definition-success').value = mission && mission.base_success_percent !== undefined ? mission.base_success_percent : 100;
    document.getElementById('mission-definition-loot-rolls').value = mission && mission.loot_rolls !== undefined ? mission.loot_rolls : 0;
    document.getElementById('mission-definition-credits').value = mission && mission.credit_reward !== undefined ? mission.credit_reward : 0;
    document.getElementById('mission-definition-contract-tier').value = mission && mission.contract_tier !== undefined ? mission.contract_tier : 1;
    document.getElementById('mission-definition-recommended-ilvl').value = mission && mission.recommended_item_level !== undefined ? mission.recommended_item_level : 0;
    document.getElementById('mission-definition-reward-ilvl-min').value = mission && mission.reward_item_level_min !== undefined ? mission.reward_item_level_min : 0;
    document.getElementById('mission-definition-reward-ilvl-max').value = mission && mission.reward_item_level_max !== undefined ? mission.reward_item_level_max : 0;
    renderMissionProgressionSlots(mission);
    updateMissionProgressionPreview(mission);
    scheduleMissionProgressionPreview();
    document.getElementById('mission-definition-watermark').value = mission && mission.watermark_url ? mission.watermark_url : '';
    document.getElementById('mission-definition-watermark-opacity').value = mission && mission.watermark_opacity ? mission.watermark_opacity : 10;
    updateMissionWatermarkPreview();
    syncMissionSuccessionFields();
    syncMissionContestedFields();
    syncMissionSalvageRecoveryField();
    syncMissionOverlordClearanceField();
  }

  function resetModalMessage(prefix) {
    ['error', 'status'].forEach(function (type) {
      var element = document.getElementById(prefix + '-' + type);
      element.textContent = ''; element.classList.remove('show');
    });
  }

  function openDefinition(mission) {
    if (!can('missions.edit')) return;
    currentDefinition = mission || null;
    definitionValues(currentDefinition);
    document.getElementById('mission-definition-modal-title').textContent = currentDefinition ? 'Edit Mission' : 'Add Mission';
    document.getElementById('mission-definition-modal-sub').textContent = currentDefinition ? 'Changes affect future launches only. Existing player missions keep their recorded completion time and rewards.' : 'The server validates the duration, crew requirement, and rewards before a player can launch this mission.';
    document.getElementById('mission-definition-delete-btn').hidden = !currentDefinition || !can('missions.delete');
    document.getElementById('mission-definition-save-btn').disabled = !can('missions.edit');
    /* Computed alongside the Save button rather than left to the static
     * data-requires-permission sweep, which runs once at load and would be
     * undone the next time this modal opens. */
    ['mission-definition-watermark-upload', 'mission-definition-watermark-browse', 'mission-definition-watermark-clear'].forEach(function (id) {
      document.getElementById(id).disabled = !can('missions.edit');
    });
    document.getElementById('mission-definition-watermark').readOnly = !can('missions.edit');
    document.getElementById('mission-definition-watermark-opacity').disabled = !can('missions.edit');
    resetModalMessage('mission-definition-modal');
    definitionModal.hidden = false;
    setTimeout(function () { document.getElementById('mission-definition-name').focus(); }, 25);
  }

  function closeDefinition() {
    definitionModal.hidden = true; currentDefinition = null; draftProgressionPreview = null;
    progressionPreviewSequence++;
    if (progressionPreviewTimer) { window.clearTimeout(progressionPreviewTimer); progressionPreviewTimer = null; }
  }

  function definitionPayload() {
    var payload = {
      name: document.getElementById('mission-definition-name').value.trim(),
      slug: document.getElementById('mission-definition-slug').value.trim(),
      description: document.getElementById('mission-definition-description').value.trim(),
      world_key: document.getElementById('mission-definition-world').value,
      mission_type: document.getElementById('mission-definition-type').value,
      duration_seconds: document.getElementById('mission-definition-duration').value,
      min_crew: document.getElementById('mission-definition-min-crew').value,
      max_crew: document.getElementById('mission-definition-max-crew').value,
      xp_reward: document.getElementById('mission-definition-xp').value,
      reputation_reward: document.getElementById('mission-definition-reputation').value,
      sort_order: document.getElementById('mission-definition-sort-order').value,
      is_enabled: document.getElementById('mission-definition-enabled').checked,
      requires_research_unlock: document.getElementById('mission-definition-research-locked').checked,
      overlord_id: contractsReady ? document.getElementById('mission-definition-overlord').value : '',
      is_contested: contestedContractsReady && document.getElementById('mission-definition-contested').checked,
      rival_faction_name: contestedContractsReady ? document.getElementById('mission-definition-rival-faction').value.trim() : '',
      is_salvage_recovery_contract: salvageRecoveryContractsReady && document.getElementById('mission-definition-salvage-recovery').checked,
      requires_overlord_clearance: overlordClearancesReady && document.getElementById('mission-definition-overlord-clearance').checked,
      unlocks_after_mission_id: document.getElementById('mission-definition-unlocks-after').value,
      unlocks_after_completion_count: document.getElementById('mission-definition-unlock-completions').value,
      is_campaign_final: document.getElementById('mission-definition-campaign-final').checked,
      base_success_percent: document.getElementById('mission-definition-success').value,
      loot_rolls: document.getElementById('mission-definition-loot-rolls').value,
      credit_reward: document.getElementById('mission-definition-credits').value,
      watermark_url: document.getElementById('mission-definition-watermark').value.trim(),
      watermark_opacity: document.getElementById('mission-definition-watermark-opacity').value
    };
    if (contractProgressionReady) {
      payload.contract_tier = document.getElementById('mission-definition-contract-tier').value;
      payload.recommended_item_level = document.getElementById('mission-definition-recommended-ilvl').value;
      payload.reward_item_level_min = document.getElementById('mission-definition-reward-ilvl-min').value;
      payload.reward_item_level_max = document.getElementById('mission-definition-reward-ilvl-max').value;
      payload.featured_slots = featuredMissionSlots();
    }
    return payload;
  }

  function updatePortraitPreview() {
    var preview = document.getElementById('mission-crew-portrait-preview');
    var value = document.getElementById('mission-crew-portrait').value.trim();
    preview.hidden = !value;
    if (value) preview.src = assetUrl(value);
  }

  /**
   * The editor's Role options, from the same list the filters use.
   *
   * These four were written into the markup, so a role added to
   * pw_missions_role_rates() could be filtered for but never authored -- and one
   * removed from it stayed offerable until somebody noticed. Rebuilt from the
   * endpoint instead, preserving the current selection the way
   * populateGearOptions() does.
   *
   * A stored role the engine no longer knows is kept as an option rather than
   * dropped: without it the select would fall back to its first entry and an
   * administrator opening the record to read it would silently reassign the
   * role on the next save. Labelled, so the state is visible; the server still
   * rejects it, which is the correct outcome for a value it cannot honour.
   */
  function populateCrewRoleOptions(member) {
    var select = document.getElementById('mission-crew-role');
    if (!select) return;
    var stored = member && member.role ? String(member.role) : '';
    var previous = select.value;
    var roles = crewRoles.length ? crewRoles.slice() : ['Vanguard', 'Pathfinder', 'Engineer', 'Fixer'];
    select.innerHTML = '';
    roles.forEach(function (role) {
      var option = document.createElement('option');
      option.value = role;
      option.textContent = role;
      select.appendChild(option);
    });
    if (stored !== '' && roles.indexOf(stored) === -1) {
      var retired = document.createElement('option');
      retired.value = stored;
      retired.textContent = stored + ' (not in engine)';
      select.appendChild(retired);
    }
    /* Restoring rather than resetting, so rebuilding the list never discards a
     * choice already made. crewValues() overwrites it a line later for a real
     * record; this matters if the options are ever rebuilt with the modal open. */
    if (previous && Array.prototype.some.call(select.options, function (option) { return option.value === previous; })) {
      select.value = previous;
    }
  }

  /** The role a new crew member starts on: the long-standing default if the
   *  engine still offers it, otherwise the first role it does. */
  function defaultCrewRole() {
    var roles = crewRoles.length ? crewRoles : ['Vanguard'];
    return roles.indexOf('Vanguard') !== -1 ? 'Vanguard' : roles[0];
  }

  function crewValues(member) {
    /* Before the value is applied below, so an existing member's own role is
     * among the options by the time it is selected. */
    populateCrewRoleOptions(member);
    document.getElementById('mission-crew-name').value = member ? member.name : '';
    document.getElementById('mission-crew-slug').value = member ? member.slug : '';
    document.getElementById('mission-crew-description').value = member ? member.description : '';
    document.getElementById('mission-crew-role').value = member ? member.role : defaultCrewRole();
    document.getElementById('mission-crew-portrait').value = member ? member.portrait_url : '';
    document.getElementById('mission-crew-starting-level').value = member ? member.starting_level : 1;
    document.getElementById('mission-crew-world-affinity').value = member ? member.world_affinity : 'neoh';
    document.getElementById('mission-crew-tier').value = member ? (member.tier || 'common') : 'common';
    document.getElementById('mission-crew-starter').checked = member ? member.is_starter : true;
    document.getElementById('mission-crew-enabled').checked = member ? member.is_enabled : true;
    updatePortraitPreview();
  }

  function openCrew(member) {
    if (!can('missions.edit')) return;
    currentCrew = member || null;
    crewValues(currentCrew);
    document.getElementById('mission-crew-modal-title').textContent = currentCrew ? 'Edit Crew Member' : 'Add Crew Member';
    document.getElementById('mission-crew-delete-btn').hidden = !currentCrew || !can('missions.delete');
    document.getElementById('mission-crew-save-btn').disabled = !can('missions.edit');
    resetModalMessage('mission-crew-modal');
    crewModal.hidden = false;
    setTimeout(function () { document.getElementById('mission-crew-name').focus(); }, 25);
  }

  function closeCrew() { crewModal.hidden = true; currentCrew = null; }

  function crewPayload() {
    return {
      name: document.getElementById('mission-crew-name').value.trim(),
      slug: document.getElementById('mission-crew-slug').value.trim(),
      description: document.getElementById('mission-crew-description').value.trim(),
      role: document.getElementById('mission-crew-role').value,
      portrait_url: document.getElementById('mission-crew-portrait').value.trim(),
      starting_level: document.getElementById('mission-crew-starting-level').value,
      world_affinity: document.getElementById('mission-crew-world-affinity').value,
      tier: document.getElementById('mission-crew-tier').value,
      is_starter: document.getElementById('mission-crew-starter').checked,
      is_enabled: document.getElementById('mission-crew-enabled').checked
    };
  }

  function showModalError(id, message) {
    var target = document.getElementById(id);
    target.textContent = message; target.classList.add('show');
  }

  function slugify(value) {
    return value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }

  document.querySelectorAll('[data-mission-panel]').forEach(function (tab) {
    tab.addEventListener('click', function () {
      if (tab.hasAttribute('data-requires-permission') && !can('missions.player_missions')) return;
      switchPanel(tab.getAttribute('data-mission-panel'));
    });
  });
  document.querySelectorAll('[data-crew-rarity-filter]').forEach(function (tab) {
    tab.addEventListener('click', function () {
      activeCrewRarity = tab.getAttribute('data-crew-rarity-filter') || 'all';
      document.querySelectorAll('[data-crew-rarity-filter]').forEach(function (button) {
        var isActive = button === tab;
        button.classList.toggle('active', isActive);
        button.setAttribute('aria-selected', isActive ? 'true' : 'false');
      });
      renderCrew();
    });
  });
  document.querySelectorAll('[data-gear-category-filter]').forEach(function (tab) {
    tab.addEventListener('click', function () {
      activeGearCategory = tab.getAttribute('data-gear-category-filter') || 'all';
      document.querySelectorAll('[data-gear-category-filter]').forEach(function (button) {
        var isActive = button === tab;
        button.classList.toggle('active', isActive);
        button.setAttribute('aria-selected', isActive ? 'true' : 'false');
      });
      renderGear();
    });
  });
  /* Both role groups are delegated, unlike the rarity and category groups: their
   * buttons are appended once the catalogue loads, so binding each one at
   * startup would only ever reach the options that ship in the markup. */
  var crewRoleFilters = document.getElementById('crew-management-role-filters');
  if (crewRoleFilters) {
    crewRoleFilters.addEventListener('click', function (event) {
      var tab = event.target.closest('[data-crew-role-filter]');
      if (!tab) return;
      activeCrewRole = tab.getAttribute('data-crew-role-filter') || 'all';
      syncCrewRoleFilterState();
      renderCrew();
    });
  }
  var gearRoleFilters = document.getElementById('gear-management-role-filters');
  if (gearRoleFilters) {
    gearRoleFilters.addEventListener('click', function (event) {
      var tab = event.target.closest('[data-gear-role-filter]');
      if (!tab) return;
      activeGearRole = tab.getAttribute('data-gear-role-filter') || 'all';
      syncGearRoleFilterState();
      renderGear();
    });
  }
  document.getElementById('mission-definition-create-btn').addEventListener('click', function () { openDefinition(null); });
  document.getElementById('mission-crew-create-btn').addEventListener('click', function () { openCrew(null); });
  document.getElementById('mission-definition-modal-close').addEventListener('click', closeDefinition);
  document.getElementById('mission-definition-cancel-btn').addEventListener('click', closeDefinition);
  definitionModal.querySelector('.admin-modal-backdrop').addEventListener('click', closeDefinition);
  document.getElementById('mission-crew-modal-close').addEventListener('click', closeCrew);
  document.getElementById('mission-crew-cancel-btn').addEventListener('click', closeCrew);
  crewModal.querySelector('.admin-modal-backdrop').addEventListener('click', closeCrew);

  document.getElementById('mission-definition-name').addEventListener('input', function () {
    var slug = document.getElementById('mission-definition-slug');
    if (!currentDefinition && !slug.dataset.touched) slug.value = slugify(this.value);
  });
  document.getElementById('mission-definition-slug').addEventListener('input', function () { this.dataset.touched = 'true'; });
  document.getElementById('mission-definition-unlocks-after').addEventListener('change', syncMissionSuccessionFields);
  document.getElementById('mission-definition-overlord').addEventListener('change', syncMissionContestedFields);
  document.getElementById('mission-definition-overlord').addEventListener('change', syncMissionSalvageRecoveryField);
  document.getElementById('mission-definition-overlord').addEventListener('change', syncMissionOverlordClearanceField);
  document.getElementById('mission-definition-contested').addEventListener('change', syncMissionContestedFields);
  document.getElementById('mission-definition-contested').addEventListener('change', syncMissionSalvageRecoveryField);
  document.getElementById('mission-definition-salvage-recovery').addEventListener('change', syncMissionContestedFields);
  document.getElementById('mission-definition-salvage-recovery').addEventListener('change', syncMissionSalvageRecoveryField);
  ['mission-definition-contract-tier', 'mission-definition-recommended-ilvl', 'mission-definition-reward-ilvl-min', 'mission-definition-reward-ilvl-max'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', function () { updateMissionProgressionPreview(currentDefinition); scheduleMissionProgressionPreview(); });
  });
  document.getElementById('mission-definition-loot-rolls').addEventListener('input', scheduleMissionProgressionPreview);
  document.getElementById('mission-definition-world').addEventListener('change', scheduleMissionProgressionPreview);
  document.getElementById('mission-crew-name').addEventListener('input', function () {
    var slug = document.getElementById('mission-crew-slug');
    if (!currentCrew && !slug.dataset.touched) slug.value = slugify(this.value);
  });
  document.getElementById('mission-crew-slug').addEventListener('input', function () { this.dataset.touched = 'true'; });

  document.getElementById('mission-definition-save-btn').addEventListener('click', function () {
    if (!can('missions.edit')) return;
    var button = this;
    var payload = definitionPayload();
    if (currentDefinition) payload.id = currentDefinition.id;
    button.disabled = true; button.classList.add('is-busy'); resetModalMessage('mission-definition-modal');
    request('/api/admin/missions/' + (currentDefinition ? 'definition-update.php' : 'definition-create.php'), payload)
      .then(function () { closeDefinition(); return loadDefinitions(); })
      .catch(function (error) { showModalError('mission-definition-modal-error', error.message); })
      .then(function () { button.disabled = !can('missions.edit'); button.classList.remove('is-busy'); });
  });
  document.getElementById('mission-definition-delete-btn').addEventListener('click', function () {
    if (!currentDefinition || !can('missions.delete') || !window.confirm('Delete this mission definition? Definitions already used by players are protected.')) return;
    request('/api/admin/missions/definition-delete.php', { id: currentDefinition.id })
      .then(function () { closeDefinition(); return loadDefinitions(); })
      .catch(function (error) { showModalError('mission-definition-modal-error', error.message); });
  });

  document.getElementById('mission-crew-save-btn').addEventListener('click', function () {
    if (!can('missions.edit')) return;
    var button = this;
    var payload = crewPayload();
    if (currentCrew) payload.id = currentCrew.id;
    button.disabled = true; button.classList.add('is-busy'); resetModalMessage('mission-crew-modal');
    request('/api/admin/missions/' + (currentCrew ? 'crew-update.php' : 'crew-create.php'), payload)
      .then(function () { closeCrew(); return loadCrew(); })
      .catch(function (error) { showModalError('mission-crew-modal-error', error.message); })
      .then(function () { button.disabled = !can('missions.edit'); button.classList.remove('is-busy'); });
  });
  document.getElementById('mission-crew-delete-btn').addEventListener('click', function () {
    if (!currentCrew || !can('missions.delete') || !window.confirm('Delete this crew definition? Crew already owned by a player are protected.')) return;
    request('/api/admin/missions/crew-delete.php', { id: currentCrew.id })
      .then(function () { closeCrew(); return loadCrew(); })
      .catch(function (error) { showModalError('mission-crew-modal-error', error.message); });
  });

  function closeImageModal() { imageModal.hidden = true; }
  document.getElementById('mission-crew-image-modal-close').addEventListener('click', closeImageModal);
  document.getElementById('mission-crew-image-cancel-btn').addEventListener('click', closeImageModal);
  imageModal.querySelector('.admin-modal-backdrop').addEventListener('click', closeImageModal);

  /* Upload + choose-from-library, wired once and reused. Crew portraits and the
   * page watermark are two libraries with the same three controls, so this
   * follows the shared IMAGE_FIELDS/wireImageField pattern the rest of the
   * console already uses rather than a second hand-copied block. */
  function wireImageField(config) {
    var input = document.getElementById(config.input);
    var uploadButton = document.getElementById(config.upload);
    var fileInput = document.getElementById(config.file);
    var kind = config.kind || 'crew';

    document.getElementById(config.browse).addEventListener('click', function () {
      var list = document.getElementById('mission-crew-image-list');
      document.getElementById('mission-image-modal-title').textContent = config.pickerTitle;
      document.getElementById('mission-image-modal-sub').textContent = config.pickerSub;
      list.innerHTML = '<div class="admin-list-empty">Loading images&hellip;</div>';
      imageModal.hidden = false;
      request('/api/admin/missions/list-images.php?kind=' + encodeURIComponent(kind)).then(function (data) {
        var images = (data.uploaded || []).concat(data.site || []);
        if (!images.length) { blank(list, config.emptyMessage); return; }
        list.innerHTML = '';
        images.forEach(function (image) {
          var button = document.createElement('button');
          button.type = 'button'; button.className = 'admin-image-choice';
          /* The library grid is a 260px scroll box over the whole upload folder,
           * so only the visible tiles are worth fetching at full resolution. */
          button.innerHTML = '<img src="' + escapeHtml(assetUrl(image.url)) + '" alt="" loading="lazy" decoding="async"><span>' + escapeHtml(image.name) + '</span>';
          button.addEventListener('click', function () {
            input.value = image.url;
            config.onChange(); closeImageModal();
          });
          list.appendChild(button);
        });
      }).catch(function (error) { blank(list, error.message || 'Could not load the image library.'); });
    });

    uploadButton.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
      if (!this.files || !this.files[0]) return;
      var form = new FormData();
      form.append('image', this.files[0]);
      form.append('kind', kind);
      form.append('csrf', window.PW_AUTH && window.PW_AUTH.csrf ? window.PW_AUTH.csrf : '');
      uploadButton.disabled = true; uploadButton.classList.add('is-busy');
      fetch('/api/admin/missions/upload-image.php', { method: 'POST', credentials: 'same-origin', body: form })
        .then(function (response) { return response.json().catch(function () { return {}; }); })
        .then(function (data) {
          if (!data.ok) throw new Error(data.error || config.uploadError);
          input.value = data.url; config.onChange();
        }).catch(function (error) { showModalError(config.errorTarget, error.message); })
        .then(function () {
          uploadButton.disabled = !can('missions.edit');
          uploadButton.classList.remove('is-busy');
          fileInput.value = '';
        });
    });
    input.addEventListener('input', config.onChange);
  }

  wireImageField({
    input: 'mission-crew-portrait', upload: 'mission-crew-portrait-upload',
    browse: 'mission-crew-portrait-browse', file: 'mission-crew-portrait-file',
    kind: 'crew', errorTarget: 'mission-crew-modal-error', onChange: updatePortraitPreview,
    pickerTitle: 'Choose Crew Portrait',
    pickerSub: 'Select a previously uploaded mission portrait or a compatible site image.',
    emptyMessage: 'No compatible images were found. Upload a JPEG to start the crew library.',
    uploadError: 'Could not upload this portrait.'
  });

  /* --- Per-mission watermark, inside the mission editor ------------------- */

  function updateMissionWatermarkPreview() {
    var url = document.getElementById('mission-definition-watermark').value.trim();
    var preview = document.getElementById('mission-definition-watermark-preview');
    preview.hidden = !url;
    if (url) preview.src = assetUrl(url);
    var opacity = Math.max(1, Math.min(40, Number(document.getElementById('mission-definition-watermark-opacity').value) || 10));
    var art = document.getElementById('mission-definition-watermark-demo-art');
    art.style.backgroundImage = url ? 'url("' + assetUrl(url) + '")' : 'none';
    art.style.opacity = String(opacity / 100);
    document.getElementById('mission-definition-watermark-demo').classList.toggle('is-empty', !url);
  }

  wireImageField({
    input: 'mission-definition-watermark', upload: 'mission-definition-watermark-upload',
    browse: 'mission-definition-watermark-browse', file: 'mission-definition-watermark-file',
    kind: 'watermark', errorTarget: 'mission-definition-modal-error', onChange: updateMissionWatermarkPreview,
    pickerTitle: 'Choose Mission Watermark',
    pickerSub: 'Select a previously uploaded watermark or a compatible site image. A transparent PNG reads best behind a card.',
    emptyMessage: 'No watermarks have been uploaded yet. Upload a transparent PNG to start the library.',
    uploadError: 'Could not upload this watermark.'
  });
  document.getElementById('mission-definition-watermark-opacity').addEventListener('input', updateMissionWatermarkPreview);
  document.getElementById('mission-definition-watermark-clear').addEventListener('click', function () {
    if (!can('missions.edit')) return;
    document.getElementById('mission-definition-watermark').value = '';
    updateMissionWatermarkPreview();
  });

  /* --- Presentation: the Missions page watermark ------------------------- */

  function updateWatermarkPreview() {
    var url = document.getElementById('mission-watermark-url').value.trim();
    var preview = document.getElementById('mission-watermark-preview');
    preview.hidden = !url;
    if (url) preview.src = assetUrl(url);
    var enabled = document.getElementById('mission-watermark-enabled');
    // Nothing to show means nothing to switch on, matching the server, which
    // stores "off" whenever the image is empty.
    enabled.disabled = !url || !can('missions.edit');
    if (!url) enabled.checked = false;
    var opacity = Math.max(1, Math.min(40, Number(document.getElementById('mission-watermark-opacity').value) || 8));
    var art = document.getElementById('mission-watermark-demo-art');
    art.style.backgroundImage = url && enabled.checked ? 'url("' + assetUrl(url) + '")' : 'none';
    art.style.opacity = String(opacity / 100);
    document.getElementById('mission-watermark-demo').classList.toggle('is-empty', !url || !enabled.checked);
  }

  function applyWatermark(watermark) {
    document.getElementById('mission-watermark-url').value = watermark && watermark.url ? watermark.url : '';
    document.getElementById('mission-watermark-opacity').value = watermark && watermark.opacity ? watermark.opacity : 8;
    document.getElementById('mission-watermark-enabled').checked = !!(watermark && watermark.enabled);
    updateWatermarkPreview();
  }

  function loadWatermark() {
    return request('/api/admin/missions/settings-get.php')
      .then(function (data) { applyWatermark(data.watermark); })
      .catch(function (error) { showModalError('mission-watermark-error', error.message || 'Could not load the mission presentation settings.'); });
  }

  wireImageField({
    input: 'mission-watermark-url', upload: 'mission-watermark-upload',
    browse: 'mission-watermark-browse', file: 'mission-watermark-file',
    kind: 'watermark', errorTarget: 'mission-watermark-error', onChange: updateWatermarkPreview,
    pickerTitle: 'Choose Watermark',
    pickerSub: 'Select a previously uploaded watermark or a compatible site image. A transparent PNG reads best behind the page.',
    emptyMessage: 'No watermarks have been uploaded yet. Upload a transparent PNG to start the library.',
    uploadError: 'Could not upload this watermark.'
  });
  document.getElementById('mission-watermark-opacity').addEventListener('input', updateWatermarkPreview);
  document.getElementById('mission-watermark-enabled').addEventListener('change', updateWatermarkPreview);
  document.getElementById('mission-watermark-clear').addEventListener('click', function () {
    if (!can('missions.edit')) return;
    document.getElementById('mission-watermark-url').value = '';
    updateWatermarkPreview();
  });
  document.getElementById('mission-watermark-save-btn').addEventListener('click', function () {
    if (!can('missions.edit')) return;
    var button = this;
    showModalError('mission-watermark-error', '');
    document.getElementById('mission-watermark-status').textContent = '';
    button.disabled = true; button.classList.add('is-busy');
    request('/api/admin/missions/settings-save.php', {
      url: document.getElementById('mission-watermark-url').value.trim(),
      opacity: document.getElementById('mission-watermark-opacity').value,
      enabled: document.getElementById('mission-watermark-enabled').checked
    }).then(function (data) {
      applyWatermark(data.watermark);
      document.getElementById('mission-watermark-status').textContent = 'Presentation saved.';
    }).catch(function (error) { showModalError('mission-watermark-error', error.message); })
      .then(function () { button.disabled = !can('missions.edit'); button.classList.remove('is-busy'); });
  });
  /* --- Gear ------------------------------------------------------------- */

  var GEAR_STATS = [
    { key: 'strength', short: 'STR' },
    { key: 'cunning', short: 'CUN' },
    { key: 'science', short: 'SCI' },
    { key: 'charisma', short: 'CHA' }
  ];

  function gearBonusSummary(item) {
    return GEAR_STATS.map(function (stat) {
      var value = Number(item['bonus_' + stat.key]) || 0;
      return value === 0 ? '' : (value > 0 ? '+' : '') + value + ' ' + stat.short;
    }).filter(Boolean).join(' · ');
  }

  function populateGearOptions() {
    if (!gearMeta) return;
    var slotSelect = document.getElementById('mission-gear-slot');
    var currentSlot = slotSelect.value;
    slotSelect.innerHTML = '<option value="">No slot — salvage only</option>';
    (gearMeta.slots || []).forEach(function (slot) {
      var option = document.createElement('option');
      option.value = slot.key; option.textContent = slot.label;
      slotSelect.appendChild(option);
    });
    slotSelect.value = currentSlot;

    var tierSelect = document.getElementById('mission-gear-tier');
    var currentTier = tierSelect.value;
    tierSelect.innerHTML = '';
    (gearMeta.tiers || []).forEach(function (tier) {
      var option = document.createElement('option');
      option.value = tier; option.textContent = tier.charAt(0).toUpperCase() + tier.slice(1);
      tierSelect.appendChild(option);
    });
    tierSelect.value = currentTier || 'common';

    var roleSelect = document.getElementById('mission-gear-required-role');
    var currentRole = roleSelect.value;
    roleSelect.innerHTML = '<option value="">Any role</option>';
    (gearMeta.roles || []).forEach(function (role) {
      var option = document.createElement('option');
      option.value = role; option.textContent = role;
      roleSelect.appendChild(option);
    });
    roleSelect.value = currentRole;

    var stimSelect = document.getElementById('mission-gear-stim-effect');
    var currentStim = stimSelect.value;
    stimSelect.innerHTML = '<option value="">Not a stim</option>';
    var stimTypes = gearMeta.stim_effect_types || {};
    Object.keys(stimTypes).forEach(function (key) {
      var option = document.createElement('option');
      option.value = key; option.textContent = stimTypes[key].label;
      stimSelect.appendChild(option);
    });
    stimSelect.value = currentStim;
    /* The whole block stands down until the inventory migration has been run:
     * gear-save.php will not write these columns before then, so offering the
     * fields would silently discard whatever was typed into them. */
    var stimsReady = gearMeta.stims_ready !== false;
    document.getElementById('mission-gear-stim-head').hidden = !stimsReady;
    document.getElementById('mission-gear-stim-row').hidden = !stimsReady;
    document.getElementById('mission-gear-stim-summary').hidden = !stimsReady;

    document.getElementById('mission-gear-cap-natural').textContent = String(gearMeta.max_stat || 50);
    document.getElementById('mission-gear-cap-total').textContent = String(gearMeta.max_gear_stat || 80);

    /* iLvl has its own migration because normal equipment must keep working if
     * a code deploy lands before a manual database update. Hide the authored
     * value until it can actually be saved instead of inviting an admin to type
     * into a field the server deliberately cannot persist yet. */
    var itemLevelRow = document.getElementById('mission-gear-ilvl-row');
    if (itemLevelRow) itemLevelRow.hidden = gearMeta.item_levels_ready === false;
    var fieldGradeRow = document.getElementById('mission-gear-field-grade-row');
    if (fieldGradeRow) fieldGradeRow.hidden = gearMeta.field_grade_ready === false;
  }

  function renderGear() {
    if (!gearList) return;
    var visibleGear = filteredGear();
    refreshGearCount();
    /* Above both early returns: the figures have to be right for an empty view
     * and for a re-entry that skips the rebuild, not only when rows are drawn. */
    refreshGearIlvlSummary();
    if (!visibleGear.length) {
      blank(gearList, gearFiltersActive()
        ? 'No ' + gearFilterLabel() + ' yet.'
        : 'No equipment, salvage, or stims defined yet.');
      return;
    }
    /* Same reasoning as renderCrew(): 46 catalogue rows carry 46 icons, and a
     * re-entry that changed nothing should not pay for them twice. */
    if (renderedGearKey === gearRenderKey(visibleGear) && gearList.querySelector('.admin-row')) return;
    renderedGearKey = gearRenderKey(visibleGear);
    gearList.innerHTML = '';
    visibleGear.forEach(function (item) {
      var row = document.createElement('div');
      row.className = 'admin-row mission-admin-row mission-admin-gear-columns';
      row.tabIndex = 0;
      var bonuses = gearBonusSummary(item);
      var requires = [];
      if (Number(item.required_level) > 1) requires.push('Level ' + item.required_level);
      if (item.required_role) requires.push(item.required_role);
      /* Held counts what players own; worn counts copies currently equipped.
       * Both matter before touching an item: the first blocks deletion, and the
       * second is what a slot change would return to inventory. */
      var held = item.owned_count + (item.equipped_count ? ' · ' + item.equipped_count + ' worn' : '');
      row.innerHTML =
        '<div class="mission-admin-title">' + (item.icon_url ? rowThumbnail(item.icon_thumb_url, item.icon_url) : '') +
        '<div><strong>' + escapeHtml(item.name) + '</strong><small>' + escapeHtml(item.slug) + '</small></div></div>' +
        '<span class="mission-admin-cell-sub">' + escapeHtml(item.slot_label || 'Salvage') + '</span>' +
        '<span class="mission-gear-tier is-' + escapeHtml(item.tier) + '">' + escapeHtml(item.tier) + '</span>' +
        '<span class="mission-admin-ilvl">' + (gearMeta && gearMeta.item_levels_ready === false ? '&mdash;' : (item.slot ? 'iLvl ' + Number(item.item_level || 0) : '&mdash;')) + '</span>' +
        '<span class="mission-admin-cell-sub">' + escapeHtml(bonuses || '—') + '</span>' +
        '<span class="mission-admin-cell-sub">' + escapeHtml(requires.length ? requires.join(' · ') : 'Anyone') + '</span>' +
        '<span class="mission-admin-cell-sub">' + escapeHtml(String(held)) + '</span>' +
        statusPill(item.is_enabled, 'Enabled', 'Disabled');
      row.addEventListener('click', function () { openGear(item); });
      row.addEventListener('keydown', function (event) { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openGear(item); } });
      gearList.appendChild(row);
    });
  }

  function updateGearIconPreview() {
    var preview = document.getElementById('mission-gear-icon-preview');
    var value = document.getElementById('mission-gear-icon').value.trim();
    var downloadButton = document.getElementById('mission-gear-icon-download');
    preview.hidden = !value;
    if (value) preview.src = assetUrl(value);
    downloadButton.disabled = !value;
    downloadButton.title = value ? 'Download the selected equipment image.' : 'Choose an image first.';
  }

  function downloadGearIcon() {
    var value = document.getElementById('mission-gear-icon').value.trim();
    if (!value) return;
    var url = assetUrl(value);
    var sourceName = url.split(/[?#]/)[0].split('/').pop();
    var link = document.createElement('a');
    link.href = url;
    link.download = sourceName || 'equipment-image';
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    link.remove();
  }

  /* Live feedback on what the item is worth, and on the one combination the
   * server rejects: bonuses on an item with no slot to be equipped into. */
  function updateGearBonusSummary() {
    var slot = document.getElementById('mission-gear-slot').value;
    var total = 0;
    var parts = GEAR_STATS.map(function (stat) {
      var value = Number(document.getElementById('mission-gear-bonus-' + stat.key).value) || 0;
      total += Math.abs(value);
      return value === 0 ? '' : (value > 0 ? '+' : '') + value + ' ' + stat.short;
    }).filter(Boolean);
    var summary = document.getElementById('mission-gear-bonus-summary');
    if (slot === '' && total > 0) {
      summary.textContent = 'An item with no slot can never be equipped, so it cannot carry bonuses. Choose a slot, or clear these values.';
      summary.classList.add('is-warning');
      return;
    }
    summary.classList.remove('is-warning');
    summary.textContent = parts.length
      ? 'While equipped: ' + parts.join(' · ') + '.'
      : slot === '' ? 'Salvage only — no slot, no bonuses.' : 'No bonuses yet, so this item is cosmetic in its slot.';
  }

  /* Live feedback on the stim fields, and on the two combinations the server
   * rejects: a stim that also occupies a slot, and a timed boost with no
   * duration to run for. */
  /* The calculator is a transparent authoring guide, never a write rule. A
   * new release can intentionally publish above it, which is how iLvl creates
   * a visible progression even when two releases use similar stat shapes. */
  function updateGearItemLevelCalculator() {
    var row = document.getElementById('mission-gear-ilvl-row');
    var field = document.getElementById('mission-gear-item-level');
    var fieldGradeInput = document.getElementById('mission-gear-field-grade');
    var output = document.getElementById('mission-gear-ilvl-calculation');
    if (!row || !field || !output || row.hidden) return;
    var slot = document.getElementById('mission-gear-slot').value;
    field.disabled = slot === '';
    if (fieldGradeInput) fieldGradeInput.disabled = slot === '';
    if (slot === '') {
      output.innerHTML = '<strong>Not applicable</strong><small>iLvl belongs to equipment. Salvage and stims remain at 0.</small>';
      return;
    }
    var bonuses = GEAR_STATS.map(function (stat) {
      return Number(document.getElementById('mission-gear-bonus-' + stat.key).value) || 0;
    });
    var positive = bonuses.reduce(function (total, value) { return total + Math.max(0, value); }, 0);
    var tradeoff = bonuses.reduce(function (total, value) { return total + Math.max(0, -value); }, 0);
    var peak = bonuses.reduce(function (largest, value) { return Math.max(largest, Math.max(0, value)); }, 0);
    var tier = document.getElementById('mission-gear-tier').value;
    var rarityBudget = { common: 0, uncommon: 8, rare: 18, epic: 32, legendary: 50 }[tier] || 0;
    var level = Math.max(1, Number(document.getElementById('mission-gear-required-level').value) || 1);
    var roleBonus = document.getElementById('mission-gear-required-role').value ? 4 : 0;
    var fieldGrade = fieldGradeInput && !fieldGradeInput.disabled ? Math.max(0, Math.min(50, Number(fieldGradeInput.value) || 0)) : 0;
    var fieldGradeBudget = fieldGrade * 0.8;
    var suggested = Math.max(1, Math.round(1 + ((level - 1) * 0.8) + (positive * 2.4) + (peak * 1.6) + rarityBudget + roleBonus + fieldGradeBudget - (tradeoff * 1.2)));
    output.innerHTML = '<strong>Suggested iLvl ' + suggested + '</strong><small>'
      + 'Rarity +' + rarityBudget + ' &middot; bonuses +' + Math.round((positive * 2.4) + (peak * 1.6))
      + ' &middot; level gate +' + Math.round((level - 1) * 0.8)
      + (roleBonus ? ' &middot; role gate +4' : '')
      + (fieldGrade ? ' &middot; Field Grade +' + Math.round(fieldGradeBudget) : '')
      + (tradeoff ? ' &middot; trade-off -' + Math.round(tradeoff * 1.2) : '')
      + '. Enter the release iLvl manually; this suggestion never overwrites it.</small>';
  }

  function updateGearStimSummary() {
    var summary = document.getElementById('mission-gear-stim-summary');
    var effect = document.getElementById('mission-gear-stim-effect').value;
    var value = Number(document.getElementById('mission-gear-stim-value').value) || 0;
    var minutes = Number(document.getElementById('mission-gear-stim-duration').value) || 0;
    var slot = document.getElementById('mission-gear-slot').value;
    var meta = (gearMeta && gearMeta.stim_effect_types || {})[effect];
    summary.classList.remove('is-warning');
    if (!effect) { summary.textContent = 'Not a stim. This item is ordinary equipment or salvage.'; return; }
    if (slot !== '') {
      summary.textContent = 'A stim is consumed, so it cannot also occupy a slot. Clear the slot, or clear the stim effect.';
      summary.classList.add('is-warning');
      return;
    }
    if (value <= 0) {
      summary.textContent = 'A stim needs a strength above zero, or it does nothing when used.';
      summary.classList.add('is-warning');
      return;
    }
    if (meta && meta.timed && minutes < 1) {
      summary.textContent = 'A timed boost needs a duration, or it would expire the instant it started.';
      summary.classList.add('is-warning');
      return;
    }
    var unit = meta && meta.unit === '%' ? '%' : ' fatigue';
    summary.textContent = meta && meta.timed
      ? 'On use: +' + value + unit + ' for ' + minutes + ' minute' + (minutes === 1 ? '' : 's') + ', across the whole expedition.'
      : 'On use: restores ' + value + unit + ' to one resting crew member.';
  }

  function gearValues(item) {
    document.getElementById('mission-gear-name').value = item ? item.name : '';
    document.getElementById('mission-gear-slug').value = item ? item.slug : '';
    document.getElementById('mission-gear-description').value = item ? (item.description || '') : '';
    document.getElementById('mission-gear-slot').value = item ? item.slot : '';
    document.getElementById('mission-gear-tier').value = item ? item.tier : 'common';
    document.getElementById('mission-gear-drop-weight').value = item ? item.drop_weight : 100;
    GEAR_STATS.forEach(function (stat) {
      document.getElementById('mission-gear-bonus-' + stat.key).value = item ? item['bonus_' + stat.key] : 0;
    });
    document.getElementById('mission-gear-required-level').value = item ? item.required_level : 1;
    document.getElementById('mission-gear-required-role').value = item ? (item.required_role || '') : '';
    var itemLevelInput = document.getElementById('mission-gear-item-level');
    if (itemLevelInput) itemLevelInput.value = item ? (Number(item.item_level) || 1) : 1;
    var fieldGradeInput = document.getElementById('mission-gear-field-grade');
    if (fieldGradeInput) fieldGradeInput.value = item ? (Number(item.field_grade) || 0) : 0;
    document.getElementById('mission-gear-world').value = item ? item.world_key : 'neoh';
    document.getElementById('mission-gear-icon').value = item ? (item.icon_url || '') : '';
    document.getElementById('mission-gear-stim-effect').value = item ? (item.stim_effect || '') : '';
    document.getElementById('mission-gear-stim-value').value = item ? (Number(item.stim_value) || 0) : 0;
    // Stored in seconds, authored in minutes: a boost is a session-length thing
    // and a seconds field invites a three-second stim by typo.
    document.getElementById('mission-gear-stim-duration').value = item ? Math.round((Number(item.stim_duration_seconds) || 0) / 60) : 0;
    document.getElementById('mission-gear-enabled').checked = item ? item.is_enabled : true;
    var usage = document.getElementById('mission-gear-usage');
    usage.textContent = item
      ? 'Players hold ' + item.owned_count + ' cop' + (item.owned_count === 1 ? 'y' : 'ies') + ' of this item, '
        + item.equipped_count + ' of them currently equipped.'
        + (item.owned_count > 0 ? ' A held item cannot be deleted — disable it instead.' : '')
        + (item.equipped_count > 0 ? ' Changing the slot returns every equipped copy to its owner.' : '')
      : '';
    updateGearIconPreview();
    updateGearBonusSummary();
    updateGearItemLevelCalculator();
    updateGearStimSummary();
  }

  function openGear(item) {
    /* A view-only session may open an existing item to read it, matching the
     * Members module's precedent -- only Save and Delete are withheld. Creating
     * refuses outright, so a stale trigger cannot open an empty editor. */
    if (!item && !can('missions.edit')) return;
    currentGear = item || null;
    populateGearOptions();
    gearValues(currentGear);
    document.getElementById('mission-gear-modal-title').textContent = currentGear ? 'Edit Equipment' : 'Add Equipment';
    document.getElementById('mission-gear-delete-btn').hidden = !currentGear || !can('missions.delete') || currentGear.owned_count > 0;
    document.getElementById('mission-gear-save-btn').disabled = !can('missions.edit');
    var copyButton = document.getElementById('mission-gear-copy-btn');
    copyButton.hidden = !currentGear;
    copyButton.disabled = !currentGear || !can('missions.edit');
    resetModalMessage('mission-gear-modal');
    gearModal.hidden = false;
    setTimeout(function () { document.getElementById('mission-gear-name').focus(); }, 25);
  }

  function closeGear() { gearModal.hidden = true; currentGear = null; }

  function gearPayload() {
    var payload = {
      name: document.getElementById('mission-gear-name').value.trim(),
      slug: document.getElementById('mission-gear-slug').value.trim(),
      description: document.getElementById('mission-gear-description').value.trim(),
      slot: document.getElementById('mission-gear-slot').value,
      tier: document.getElementById('mission-gear-tier').value,
      drop_weight: document.getElementById('mission-gear-drop-weight').value,
      required_level: document.getElementById('mission-gear-required-level').value,
      required_role: document.getElementById('mission-gear-required-role').value,
      world_key: document.getElementById('mission-gear-world').value,
      icon_url: document.getElementById('mission-gear-icon').value.trim(),
      stim_effect: document.getElementById('mission-gear-stim-effect').value,
      stim_value: document.getElementById('mission-gear-stim-value').value,
      stim_duration_seconds: Math.round((Number(document.getElementById('mission-gear-stim-duration').value) || 0) * 60),
      is_enabled: document.getElementById('mission-gear-enabled').checked
    };
    var itemLevelRow = document.getElementById('mission-gear-ilvl-row');
    var itemLevelInput = document.getElementById('mission-gear-item-level');
    if (itemLevelRow && itemLevelInput && !itemLevelRow.hidden) {
      payload.item_level = itemLevelInput.value;
    }
    var fieldGradeRow = document.getElementById('mission-gear-field-grade-row');
    var fieldGradeInput = document.getElementById('mission-gear-field-grade');
    if (fieldGradeRow && fieldGradeInput && !fieldGradeRow.hidden) {
      payload.field_grade = fieldGradeInput.value;
    }
    GEAR_STATS.forEach(function (stat) {
      payload['bonus_' + stat.key] = document.getElementById('mission-gear-bonus-' + stat.key).value;
    });
    return payload;
  }

  /* Duplication keeps all authored mechanics -- including iLvl, rarity,
   * requirements, bonuses and art -- but gives the new row a human-readable
   * name and a locally unique slug. The latter remains server-validated, so a
   * simultaneous copy from another admin can never overwrite anything. */
  function copiedGearName(value) {
    var suffix = ' Copy';
    var name = String(value || '').trim() || 'Equipment';
    return name.slice(0, 120 - suffix.length) + suffix;
  }

  function copiedGearSlug(value) {
    var stem = slugify(value) || 'equipment';
    var suffix = '-copy';
    var attempt = 1;
    var exists = function (candidate) {
      return gear.some(function (item) { return String(item.slug || '').toLowerCase() === candidate.toLowerCase(); });
    };
    var candidate = stem.slice(0, 120 - suffix.length) + suffix;
    while (exists(candidate)) {
      attempt++;
      suffix = '-copy-' + attempt;
      candidate = stem.slice(0, 120 - suffix.length) + suffix;
    }
    return candidate;
  }

  function showModalStatus(id, message) {
    var target = document.getElementById(id);
    target.textContent = message; target.classList.add('show');
  }

  document.getElementById('mission-gear-create-btn').addEventListener('click', function () { openGear(null); });
  document.getElementById('mission-gear-modal-close').addEventListener('click', closeGear);
  document.getElementById('mission-gear-cancel-btn').addEventListener('click', closeGear);
  gearModal.querySelector('.admin-modal-backdrop').addEventListener('click', closeGear);
  document.getElementById('mission-gear-name').addEventListener('input', function () {
    var slug = document.getElementById('mission-gear-slug');
    if (!currentGear && !slug.dataset.touched) slug.value = slugify(this.value);
  });
  document.getElementById('mission-gear-slug').addEventListener('input', function () { this.dataset.touched = 'true'; });
  document.getElementById('mission-gear-slot').addEventListener('change', function () {
    updateGearBonusSummary();
    updateGearItemLevelCalculator();
    updateGearStimSummary();
  });
  ['mission-gear-tier', 'mission-gear-required-level', 'mission-gear-required-role', 'mission-gear-field-grade'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', updateGearItemLevelCalculator);
    document.getElementById(id).addEventListener('change', updateGearItemLevelCalculator);
  });
  ['mission-gear-stim-effect', 'mission-gear-stim-value', 'mission-gear-stim-duration'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', updateGearStimSummary);
  });
  GEAR_STATS.forEach(function (stat) {
    document.getElementById('mission-gear-bonus-' + stat.key).addEventListener('input', function () {
      updateGearBonusSummary();
      updateGearItemLevelCalculator();
    });
  });
  document.getElementById('mission-gear-icon-clear').addEventListener('click', function () {
    if (!can('missions.edit')) return;
    document.getElementById('mission-gear-icon').value = '';
    updateGearIconPreview();
  });
  document.getElementById('mission-gear-icon-download').addEventListener('click', downloadGearIcon);
  wireImageField({
    input: 'mission-gear-icon', upload: 'mission-gear-icon-upload',
    browse: 'mission-gear-icon-browse', file: 'mission-gear-icon-file',
    kind: 'crew', errorTarget: 'mission-gear-modal-error', onChange: updateGearIconPreview,
    pickerTitle: 'Choose Equipment Icon',
    pickerSub: 'Select a previously uploaded image or a compatible site image. Left empty, the loadout draws the built-in glyph for this slot.',
    emptyMessage: 'No compatible images were found. Upload one to start the library.',
    uploadError: 'Could not upload this icon.'
  });

  document.getElementById('mission-gear-save-btn').addEventListener('click', function () {
    if (!can('missions.edit')) return;
    var button = this;
    var payload = gearPayload();
    if (currentGear) payload.id = currentGear.id;
    button.disabled = true; button.classList.add('is-busy'); resetModalMessage('mission-gear-modal');
    request('/api/admin/missions/gear-save.php', payload)
      .then(function (result) {
        closeGear();
        if (result.unequipped > 0) {
          /* Says which edit caused it: "slot changed" on a requirement change
           * would send an administrator looking for a slot they never touched. */
          var cause = result.unequipped_reason === 'requirement'
            ? 'Crew who no longer meet the requirements had it removed, so '
            : 'Slot changed, so ';
          window.alert(cause + (result.unequipped === 1
            ? '1 equipped copy was returned to its owner.'
            : result.unequipped + ' equipped copies were returned to their owners.'));
        }
        return loadGear();
      })
      .catch(function (error) { showModalError('mission-gear-modal-error', error.message); })
      .then(function () { button.disabled = !can('missions.edit'); button.classList.remove('is-busy'); });
  });
  document.getElementById('mission-gear-copy-btn').addEventListener('click', function () {
    if (!currentGear || !can('missions.edit')) return;
    var button = this;
    var payload = gearPayload();
    payload.name = copiedGearName(payload.name);
    payload.slug = copiedGearSlug(payload.slug);
    button.disabled = true; button.classList.add('is-busy'); resetModalMessage('mission-gear-modal');
    request('/api/admin/missions/gear-save.php', payload)
      .then(function (result) {
        return loadGear().then(function () {
          currentGear = gear.filter(function (item) { return Number(item.id) === Number(result.id); })[0] || null;
          if (!currentGear) throw new Error('The copy was saved, but could not be reloaded.');
          gearValues(currentGear);
          document.getElementById('mission-gear-modal-title').textContent = 'Edit Equipment Copy';
          document.getElementById('mission-gear-delete-btn').hidden = !can('missions.delete');
          showModalStatus('mission-gear-modal-status', 'Equipment copy created. Adjust it if needed, then save your changes.');
        });
      })
      .catch(function (error) { showModalError('mission-gear-modal-error', error.message); })
      .then(function () { button.disabled = !can('missions.edit'); button.classList.remove('is-busy'); });
  });
  document.getElementById('mission-gear-delete-btn').addEventListener('click', function () {
    if (!currentGear || !can('missions.delete') || !window.confirm('Delete this item? Items any player already holds are protected.')) return;
    request('/api/admin/missions/gear-delete.php', { id: currentGear.id })
      .then(function () { closeGear(); return loadGear(); })
      .catch(function (error) { showModalError('mission-gear-modal-error', error.message); });
  });
}());
