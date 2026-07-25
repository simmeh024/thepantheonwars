(function () {
  'use strict';

  var definitions = [];
  var crew = [];
  var playerMissions = [];
  var activePanel = 'definitions';
  var currentDefinition = null;
  var currentCrew = null;

  var definitionList = document.getElementById('mission-definition-list');
  var crewList = document.getElementById('mission-crew-list');
  var playerMissionList = document.getElementById('mission-player-missions-list');
  var count = document.getElementById('mission-admin-count');
  var definitionModal = document.getElementById('mission-definition-modal');
  var crewModal = document.getElementById('mission-crew-modal');
  var imageModal = document.getElementById('mission-crew-image-modal');

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

  function refreshCount() {
    if (!count) return;
    var total = activePanel === 'definitions' ? definitions.length : activePanel === 'crew' ? crew.length : playerMissions.length;
    count.textContent = total + (activePanel === 'definitions' ? ' mission' : activePanel === 'crew' ? ' crew member' : ' player mission') + (total === 1 ? '' : 's');
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
      if (can('missions.edit')) {
        row.addEventListener('click', function () { openDefinition(mission); });
        row.addEventListener('keydown', function (event) { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openDefinition(mission); } });
      }
      definitionList.appendChild(row);
    });
  }

  function renderCrew() {
    if (!crewList) return;
    if (!crew.length) { blank(crewList, 'No crew definitions yet.'); return; }
    crewList.innerHTML = '';
    crew.forEach(function (member) {
      var row = document.createElement('div');
      row.className = 'admin-row mission-admin-row mission-admin-crew-columns';
      row.setAttribute('aria-disabled', can('missions.edit') ? 'false' : 'true');
      if (can('missions.edit')) { row.tabIndex = 0; row.setAttribute('role', 'button'); }
      var portrait = member.portrait_url ? '<img class="mission-admin-portrait" src="' + escapeHtml(assetUrl(member.portrait_url)) + '" alt="">' : '';
      row.innerHTML =
        '<div class="mission-admin-title">' + portrait + '<div><strong>' + escapeHtml(member.name) + '</strong><small>Level ' + member.starting_level + ' · ' + escapeHtml(member.slug) + '</small></div></div>' +
        '<span>' + escapeHtml(member.role) + '</span>' +
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
    ['definitions', 'crew', 'player-missions'].forEach(function (name) {
      var isActive = name === panel;
      var view = document.getElementById('mission-admin-' + name + '-panel');
      if (view) view.hidden = !isActive;
      var tab = document.querySelector('[data-mission-panel="' + name + '"]');
      if (tab) { tab.classList.toggle('active', isActive); tab.setAttribute('aria-selected', isActive ? 'true' : 'false'); }
    });
    document.getElementById('mission-definition-create-btn').hidden = panel !== 'definitions' || !can('missions.edit');
    document.getElementById('mission-crew-create-btn').hidden = panel !== 'crew' || !can('missions.edit');
    refreshCount();
  }

  function loadDefinitions() {
    return request('/api/admin/missions/definitions-list.php').then(function (data) { definitions = data.missions || []; renderDefinitions(); refreshCount(); }).catch(function (error) { blank(definitionList, error.message || 'Could not load mission definitions. Run the Missions V0 migration first.'); });
  }

  function loadCrew() {
    return request('/api/admin/missions/crew-list.php').then(function (data) { crew = data.crew || []; renderCrew(); refreshCount(); }).catch(function (error) { blank(crewList, error.message || 'Could not load crew definitions.'); });
  }

  function loadPlayerMissions() {
    if (!can('missions.player_missions')) { renderPlayerMissions(); return Promise.resolve(); }
    return request('/api/admin/missions/player-missions-list.php').then(function (data) { playerMissions = data.missions || []; renderPlayerMissions(); refreshCount(); }).catch(function (error) { blank(playerMissionList, error.message || 'Could not load player mission diagnostics.'); });
  }

  window.loadMissionControl = function () {
    switchPanel(activePanel);
    return Promise.all([loadDefinitions(), loadCrew(), loadPlayerMissions()]);
  };

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

  function definitionValues(mission) {
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
    populateMissionSuccessionOptions(mission);
    document.getElementById('mission-definition-unlock-completions').value = mission && mission.unlocks_after_mission_id ? mission.unlocks_after_completion_count : 0;
    document.getElementById('mission-definition-campaign-final').checked = mission ? !!mission.is_campaign_final : false;
    syncMissionSuccessionFields();
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
    resetModalMessage('mission-definition-modal');
    definitionModal.hidden = false;
    setTimeout(function () { document.getElementById('mission-definition-name').focus(); }, 25);
  }

  function closeDefinition() { definitionModal.hidden = true; currentDefinition = null; }

  function definitionPayload() {
    return {
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
      unlocks_after_mission_id: document.getElementById('mission-definition-unlocks-after').value,
      unlocks_after_completion_count: document.getElementById('mission-definition-unlock-completions').value,
      is_campaign_final: document.getElementById('mission-definition-campaign-final').checked
    };
  }

  function updatePortraitPreview() {
    var preview = document.getElementById('mission-crew-portrait-preview');
    var value = document.getElementById('mission-crew-portrait').value.trim();
    preview.hidden = !value;
    if (value) preview.src = assetUrl(value);
  }

  function crewValues(member) {
    document.getElementById('mission-crew-name').value = member ? member.name : '';
    document.getElementById('mission-crew-slug').value = member ? member.slug : '';
    document.getElementById('mission-crew-description').value = member ? member.description : '';
    document.getElementById('mission-crew-role').value = member ? member.role : 'Vanguard';
    document.getElementById('mission-crew-portrait').value = member ? member.portrait_url : '';
    document.getElementById('mission-crew-starting-level').value = member ? member.starting_level : 1;
    document.getElementById('mission-crew-world-affinity').value = member ? member.world_affinity : 'neoh';
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

  document.getElementById('mission-crew-portrait').addEventListener('input', updatePortraitPreview);
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
  document.getElementById('mission-crew-portrait-browse').addEventListener('click', function () {
    var list = document.getElementById('mission-crew-image-list');
    list.innerHTML = '<div class="admin-list-empty">Loading images&hellip;</div>';
    imageModal.hidden = false;
    request('/api/admin/missions/list-images.php').then(function (data) {
      var images = (data.uploaded || []).concat(data.site || []);
      if (!images.length) { blank(list, 'No compatible images were found. Upload a JPEG to start the crew library.'); return; }
      list.innerHTML = '';
      images.forEach(function (image) {
        var button = document.createElement('button');
        button.type = 'button'; button.className = 'admin-image-choice';
        button.innerHTML = '<img src="' + escapeHtml(assetUrl(image.url)) + '" alt=""><span>' + escapeHtml(image.name) + '</span>';
        button.addEventListener('click', function () {
          document.getElementById('mission-crew-portrait').value = image.url;
          updatePortraitPreview(); closeImageModal();
        });
        list.appendChild(button);
      });
    }).catch(function (error) { blank(list, error.message || 'Could not load the image library.'); });
  });
  document.getElementById('mission-crew-portrait-upload').addEventListener('click', function () { document.getElementById('mission-crew-portrait-file').click(); });
  document.getElementById('mission-crew-portrait-file').addEventListener('change', function () {
    if (!this.files || !this.files[0]) return;
    var form = new FormData(); form.append('image', this.files[0]); form.append('csrf', window.PW_AUTH && window.PW_AUTH.csrf ? window.PW_AUTH.csrf : '');
    var uploadButton = document.getElementById('mission-crew-portrait-upload'); uploadButton.disabled = true;
    fetch('/api/admin/missions/upload-image.php', { method: 'POST', credentials: 'same-origin', body: form })
      .then(function (response) { return response.json().catch(function () { return {}; }); })
      .then(function (data) {
        if (!data.ok) throw new Error(data.error || 'Could not upload this portrait.');
        document.getElementById('mission-crew-portrait').value = data.url; updatePortraitPreview();
      }).catch(function (error) { showModalError('mission-crew-modal-error', error.message); })
      .then(function () { uploadButton.disabled = !can('missions.edit'); document.getElementById('mission-crew-portrait-file').value = ''; });
  });
}());
