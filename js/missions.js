(function () {
  'use strict';

  var state = { data: null, serverOffset: 0, launchMission: null, refreshQueued: false, feedSlot: null };
  var gate = document.getElementById('missions-gate');
  var content = document.getElementById('missions-content');
  var statusMessage = document.getElementById('missions-status-message');
  var activeList = document.getElementById('missions-active-list');
  var definitionList = document.getElementById('missions-definition-list');
  var crewList = document.getElementById('missions-crew-list');
  var crewRoleFilter = document.getElementById('missions-crew-role-filter');
  var crewLevelFilter = document.getElementById('missions-crew-level-filter');
  var crewWorldFilter = document.getElementById('missions-crew-world-filter');
  var crewStatusFilter = document.getElementById('missions-crew-status-filter');
  var crewSort = document.getElementById('missions-crew-sort');
  var crewFilterSummary = document.getElementById('missions-crew-filter-summary');
  var historyList = document.getElementById('missions-history-list');
  var commandFeedList = document.getElementById('mission-feed-list');
  var launchModal = document.getElementById('mission-launch-modal');
  var launchTitle = document.getElementById('mission-launch-title');
  var launchCopy = document.getElementById('mission-launch-copy');
  var launchCrew = document.getElementById('mission-launch-crew');
  var launchError = document.getElementById('mission-launch-error');
  var launchConfirm = document.getElementById('mission-launch-confirm');

  function escapeHtml(value) { var node = document.createElement('div'); node.textContent = value == null ? '' : String(value); return node.innerHTML; }
  function apiDate(value) { return value ? new Date(String(value).replace(' ', 'T') + 'Z') : null; }
  function formatDate(value) { var date = apiDate(value); return date && !isNaN(date) ? date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—'; }
  function formatDuration(seconds) { seconds = Math.max(0, Number(seconds) || 0); var hours = Math.floor(seconds / 3600); var minutes = Math.floor((seconds % 3600) / 60); var rest = seconds % 60; return (hours ? String(hours).padStart(2, '0') + ':' : '') + String(minutes).padStart(2, '0') + ':' + String(rest).padStart(2, '0'); }
  function missionDuration(seconds) { var minutes = Math.round(Number(seconds) / 60); return minutes >= 60 ? (minutes / 60) + ' hour' + (minutes === 60 ? '' : 's') : minutes + ' minutes'; }
  function post(url, payload) { return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }).then(function (response) { return response.json(); }).then(function (data) { if (!data.ok) throw new Error(data.error || 'The mission command could not be completed.'); return data; }); }
  function safeImage(url) { return /^(?:images\/[a-zA-Z0-9._-]+|\/uploads\/mission-crew-images\/img_[a-f0-9]{16}\.jpg)$/.test(String(url || '')) ? url : ''; }
  function availableCrew() { return (state.data && state.data.crew || []).filter(function (crew) { return crew.status === 'available' && crew.definition_enabled; }); }
  function setStatus(message, isError) { statusMessage.textContent = message || ''; statusMessage.classList.toggle('is-error', !!isError); }

  function renderStats(data) {
    document.getElementById('missions-stat-active').textContent = data.stats.active_missions;
    document.getElementById('missions-stat-crew').textContent = data.stats.available_crew;
    document.getElementById('missions-stat-completed').textContent = data.stats.completed_missions;
    document.getElementById('missions-stat-total').textContent = data.stats.total_missions;
    document.getElementById('missions-active-count').textContent = data.stats.active_missions + (data.stats.active_missions === 1 ? ' operation' : ' operations');
    document.getElementById('missions-crew-count').textContent = data.crew.length + (data.crew.length === 1 ? ' member' : ' members');
    document.getElementById('missions-command-copy').textContent = data.stats.active_missions ? 'Your crews are transmitting from the field. Mission time is verified by command.' : 'No active deployments. Review Neoh operations and assign an available crew.';
  }

  function renderActive(data) {
    if (!data.active_missions.length) { activeList.innerHTML = '<p class="missions-empty">No crews are currently in the field.</p>'; return; }
    activeList.innerHTML = data.active_missions.map(function (mission) {
      var isCompleted = mission.status === 'completed';
      var action = isCompleted
        ? '<button type="button" class="btn btn-solid mission-action" data-action="claim" data-mission-id="' + mission.id + '">Claim Rewards</button>'
        : mission.is_ready
          ? '<button type="button" class="btn btn-solid mission-action" data-action="complete" data-mission-id="' + mission.id + '">Complete Mission</button>'
          : '<span class="mission-in-progress">Transmission active</span>';
      return '<article class="mission-active-card is-' + escapeHtml(mission.status) + '"><div class="mission-card-top"><span class="mission-type">' + escapeHtml(mission.mission_type) + '</span><span class="mission-world">' + escapeHtml(mission.world_key) + '</span></div><h3>' + escapeHtml(mission.name) + '</h3><p class="mission-crew-line">' + escapeHtml((mission.crew_names || []).join(' · ')) + '</p><div class="mission-active-footer"><div><strong class="mission-countdown" data-completes-at="' + escapeHtml(mission.completes_at) + '">' + (isCompleted ? 'Mission complete' : 'Calculating…') + '</strong><small>' + (isCompleted ? 'Ready for reward claim' : 'Completion verified by command') + '</small></div>' + action + '</div></article>';
    }).join('');
  }

  function renderDefinitions(data) {
    var available = availableCrew().length;
    if (!data.missions.length) { definitionList.innerHTML = '<p class="missions-empty">No Neoh operations are available at the moment.</p>'; return; }
    definitionList.innerHTML = data.missions.map(function (mission) {
      var unlocked = !!mission.is_unlocked;
      var canLaunch = unlocked && available >= mission.min_crew;
      var unlockState = mission.unlocks_after_mission_id
        ? '<p class="mission-unlock-state ' + (unlocked ? 'is-unlocked' : 'is-locked') + '">' + (unlocked
          ? 'Unlocked through ' + escapeHtml(mission.unlocks_after_mission_name || 'a previous operation')
          : 'Locked: ' + escapeHtml(mission.unlocks_after_mission_name || 'previous operation') + ' — ' + Number(mission.unlocks_after_completed_count || 0) + ' / ' + Number(mission.unlocks_after_completion_count || 0) + ' successful runs') + '</p>'
        : '<p class="mission-unlock-state is-base">Available immediately</p>';
      var launchLabel = !unlocked ? 'Mission Locked' : canLaunch ? 'Select Crew' : 'Crew Unavailable';
      return '<article class="mission-definition-card ' + (unlocked ? '' : 'is-locked') + '"><div class="mission-card-top"><span class="mission-type">' + escapeHtml(mission.mission_type) + '</span><span class="mission-duration">' + missionDuration(mission.duration_seconds) + '</span></div><h3>' + escapeHtml(mission.name) + '</h3><p>' + escapeHtml(mission.description) + '</p>' + unlockState + '<dl class="mission-definition-meta"><div><dt>Crew</dt><dd>' + mission.min_crew + (mission.max_crew !== mission.min_crew ? '–' + mission.max_crew : '') + '</dd></div><div><dt>XP</dt><dd>+' + mission.xp_reward + ' per crew</dd></div><div><dt>Reputation</dt><dd>' + (mission.reputation_reward ? '+' + mission.reputation_reward : '—') + '</dd></div></dl><button type="button" class="btn mission-launch-btn" data-mission-id="' + mission.id + '"' + (canLaunch ? '' : ' disabled') + '>' + launchLabel + '</button></article>';
    }).join('');
  }

  function crewAvailability(crew) {
    if (!crew.definition_enabled) return 'unavailable';
    if (crew.status === 'on_mission') return 'deployed';
    return crew.status === 'available' ? 'available' : 'unavailable';
  }

  function populateCrewFilter(select, entries, allLabel, formatLabel) {
    var current = select.value || 'all';
    select.replaceChildren();
    var allOption = document.createElement('option'); allOption.value = 'all'; allOption.textContent = allLabel; select.appendChild(allOption);
    entries.forEach(function (entry) {
      var option = document.createElement('option'); option.value = String(entry); option.textContent = formatLabel(entry); select.appendChild(option);
    });
    select.value = entries.map(String).indexOf(current) !== -1 ? current : 'all';
  }

  function updateCrewFilterOptions(crew) {
    var roles = Array.from(new Set(crew.map(function (member) { return member.role; }).filter(Boolean))).sort();
    var levels = Array.from(new Set(crew.map(function (member) { return Number(member.level); }).filter(function (level) { return level > 0; }))).sort(function (a, b) { return a - b; });
    var worlds = Array.from(new Set(crew.map(function (member) { return member.world_affinity; }).filter(Boolean))).sort();
    populateCrewFilter(crewRoleFilter, roles, 'All roles', function (role) { return role; });
    populateCrewFilter(crewLevelFilter, levels, 'All levels', function (level) { return 'Level ' + level; });
    populateCrewFilter(crewWorldFilter, worlds, 'All worlds', function (world) { return String(world).charAt(0).toUpperCase() + String(world).slice(1); });
  }

  function filteredCrew(crew) {
    var role = crewRoleFilter.value;
    var level = crewLevelFilter.value;
    var world = crewWorldFilter.value;
    var status = crewStatusFilter.value;
    var visible = crew.filter(function (member) {
      return (role === 'all' || member.role === role)
        && (level === 'all' || Number(member.level) === Number(level))
        && (world === 'all' || member.world_affinity === world)
        && (status === 'all' || crewAvailability(member) === status);
    });
    var sort = crewSort.value;
    if (sort === 'default') return visible;
    var statusOrder = { available: 0, deployed: 1, unavailable: 2 };
    return visible.slice().sort(function (left, right) {
      var comparison = 0;
      if (sort === 'name-asc') comparison = String(left.name).localeCompare(String(right.name));
      if (sort === 'level-desc') comparison = Number(right.level) - Number(left.level);
      if (sort === 'level-asc') comparison = Number(left.level) - Number(right.level);
      if (sort === 'status') comparison = statusOrder[crewAvailability(left)] - statusOrder[crewAvailability(right)];
      return comparison || String(left.name).localeCompare(String(right.name));
    });
  }

  function renderCrew(data) {
    if (!data.crew.length) { crewFilterSummary.textContent = ''; crewList.innerHTML = '<p class="missions-empty">Crew records are being prepared.</p>'; return; }
    updateCrewFilterOptions(data.crew);
    var visibleCrew = filteredCrew(data.crew);
    document.getElementById('missions-crew-count').textContent = visibleCrew.length === data.crew.length
      ? data.crew.length + (data.crew.length === 1 ? ' member' : ' members')
      : visibleCrew.length + ' of ' + data.crew.length + ' members';
    crewFilterSummary.textContent = visibleCrew.length === data.crew.length ? 'Showing your full roster.' : 'Showing ' + visibleCrew.length + ' matching crew member' + (visibleCrew.length === 1 ? '.' : 's.');
    if (!visibleCrew.length) { crewList.innerHTML = '<p class="missions-empty">No crew members match these filters.</p>'; return; }
    crewList.innerHTML = visibleCrew.map(function (crew) {
      var portrait = safeImage(crew.portrait_url);
      var availability = crewAvailability(crew);
      var deployed = availability === 'deployed';
      var status = availability === 'unavailable' ? 'Unavailable' : deployed ? 'On mission' : 'Available';
      var missionCopy = deployed && crew.active_mission_name ? '<p class="crew-mission-copy">' + escapeHtml(crew.active_mission_name) + '<span class="mission-countdown" data-completes-at="' + escapeHtml(crew.active_mission_completes_at) + '">Calculating…</span></p>' : '';
      var profile = crewRoleProfile(crew.role);
      var totalXp = Math.max(0, Number(crew.xp) || 0);
      var cycleXp = totalXp % 100;
      var progress = totalXp > 0 && cycleXp === 0 ? 100 : cycleXp;
      var thresholdCopy = progress === 100 ? 'Cycle threshold reached' : (100 - progress) + ' XP to next threshold';
      return '<article class="mission-crew-card ' + (deployed ? 'is-deployed' : '') + (availability === 'unavailable' ? ' is-unavailable' : '') + '">' + (portrait ? '<img src="' + escapeHtml(portrait) + '" alt="" class="mission-crew-portrait">' : '<div class="mission-crew-portrait mission-crew-fallback" aria-hidden="true">' + escapeHtml(crew.name.charAt(0)) + '</div>') + '<div class="mission-crew-copy"><span class="crew-role">' + escapeHtml(crew.role) + '</span><h3>' + escapeHtml(crew.name) + '</h3><p>' + escapeHtml(crew.description) + '</p><div class="crew-progression ' + profile.className + '"><div class="crew-rank-insignia" aria-label="' + escapeHtml(crew.role) + ' level ' + crew.level + '"><span>' + profile.code + '</span><small>L' + crew.level + '</small></div><div class="crew-progression-copy"><div><span>' + profile.rankLabel + '</span><strong>' + progress + ' / 100 XP</strong></div><div class="crew-xp-track"><span style="width:' + progress + '%"></span></div><small>' + thresholdCopy + '</small></div></div><div class="crew-status"><small>Status</small><strong>' + status + '</strong></div>' + missionCopy + '</div></article>';
    }).join('');
  }

  function crewRoleProfile(role) {
    var profiles = {
      Vanguard: { className: 'is-vanguard', code: 'VG', rankLabel: 'Frontline rank' },
      Pathfinder: { className: 'is-pathfinder', code: 'PF', rankLabel: 'Signal rank' },
      Engineer: { className: 'is-engineer', code: 'EN', rankLabel: 'Relay rank' }
    };
    return profiles[role] || { className: 'is-crew', code: 'CR', rankLabel: 'Crew rank' };
  }

  function commandTime() {
    return new Date(Date.now() + state.serverOffset).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false }) + ' UTC';
  }

  function renderCommandFeed(data) {
    if (!commandFeedList || !data) return;
    var active = data.active_missions || [];
    var ambient = [
      { code: 'RELAY 17', text: 'Signal lattice holding across the upper district.', type: 'is-clear' },
      { code: 'NEOH GRID', text: 'Static interference remains within expedition tolerance.', type: 'is-watch' },
      { code: 'ARCHIVE LINK', text: data.missions.length + ' operations available to command.', type: 'is-clear' },
      { code: 'VEIL SCAN', text: 'No unregistered crew signatures detected.', type: 'is-muted' }
    ];
    var ambientEntry = ambient[Math.floor((Date.now() + state.serverOffset) / 12000) % ambient.length];
    var entries = [
      active.length
        ? { code: 'CREW LINK', text: active[0].name + (active[0].status === 'completed' ? ' has opened a recovery channel.' : ' is transmitting from the field.'), type: active[0].status === 'completed' ? 'is-ready' : 'is-live' }
        : { code: 'CREW LINK', text: 'No crews are currently deployed from Neoh command.', type: 'is-muted' },
      { code: 'FORCE STATUS', text: data.stats.available_crew + ' crew member' + (data.stats.available_crew === 1 ? '' : 's') + ' ready for assignment.', type: 'is-clear' },
      ambientEntry
    ];
    commandFeedList.innerHTML = entries.map(function (entry) {
      return '<li class="mission-feed-entry ' + entry.type + '"><time>' + commandTime() + '</time><span class="mission-feed-code">' + escapeHtml(entry.code) + '</span><p>' + escapeHtml(entry.text) + '</p></li>';
    }).join('');
  }

  function renderHistory(data) {
    if (!data.history.length) { historyList.innerHTML = '<p class="missions-empty">Your completed operations will be recorded here.</p>'; return; }
    historyList.innerHTML = data.history.map(function (mission) {
      return '<article class="mission-history-row"><div><span class="mission-world">' + escapeHtml(mission.world_key) + '</span><strong>' + escapeHtml(mission.name) + '</strong><p>' + escapeHtml((mission.crew_names || []).join(' · ')) + '</p></div><div><small>Completed</small><span>' + formatDate(mission.completed_at) + '</span></div><div><small>Rewards</small><span>+' + mission.xp_reward + ' XP · +' + mission.reputation_reward + ' rep</span></div><div><span class="mission-history-status">Claimed</span></div></article>';
    }).join('');
  }

  function render(data) {
    state.data = data;
    var server = apiDate(data.server_time);
    state.serverOffset = server && !isNaN(server) ? server.getTime() - Date.now() : 0;
    renderStats(data); renderCommandFeed(data); renderActive(data); renderDefinitions(data); renderCrew(data); renderHistory(data); tickCountdowns();
  }

  function tickCountdowns() {
    document.querySelectorAll('.mission-countdown[data-completes-at]').forEach(function (element) {
      var completes = apiDate(element.getAttribute('data-completes-at'));
      if (!completes || isNaN(completes)) return;
      var remaining = Math.max(0, Math.ceil((completes.getTime() - (Date.now() + state.serverOffset)) / 1000));
      element.textContent = remaining > 0 ? formatDuration(remaining) + ' remaining' : 'Ready for completion';
      if (remaining === 0 && !state.refreshQueued) { state.refreshQueued = true; window.setTimeout(function () { state.refreshQueued = false; load(); }, 1500); }
    });
  }

  function tickCommandFeed() {
    if (!state.data) return;
    var slot = Math.floor((Date.now() + state.serverOffset) / 12000);
    if (slot !== state.feedSlot) { state.feedSlot = slot; renderCommandFeed(state.data); }
  }

  function load() {
    if (!window.PW_AUTH || !window.PW_AUTH.loggedIn) { gate.hidden = false; content.hidden = true; return; }
    gate.hidden = true; content.hidden = false; setStatus('');
    fetch('/api/missions/overview.php', { credentials: 'same-origin' }).then(function (response) { return response.json(); }).then(function (data) { if (!data.ok) throw new Error(data.error || 'Mission command is unavailable.'); render(data); }).catch(function (error) { activeList.innerHTML = '<p class="missions-empty">' + escapeHtml(error.message || 'Mission command is unavailable.') + '</p>'; });
  }

  function openLaunch(missionId) {
    var mission = (state.data && state.data.missions || []).find(function (item) { return item.id === Number(missionId); });
    if (!mission) return;
    state.launchMission = mission; launchError.textContent = '';
    launchTitle.textContent = mission.name; launchCopy.textContent = 'Choose ' + mission.min_crew + (mission.max_crew !== mission.min_crew ? ' to ' + mission.max_crew : '') + ' available crew member' + (mission.max_crew === 1 ? '' : 's') + '.';
    var crew = availableCrew();
    launchCrew.innerHTML = crew.map(function (member) { return '<label class="mission-launch-crew-choice"><input type="checkbox" value="' + member.id + '"><span><strong>' + escapeHtml(member.name) + '</strong><small>' + escapeHtml(member.role) + ' · Level ' + member.level + '</small></span></label>'; }).join('') || '<p class="missions-empty">No crew members are available.</p>';
    if (typeof launchModal.showModal === 'function') launchModal.showModal(); else launchModal.setAttribute('open', '');
  }
  function closeLaunch() { if (launchModal.open && typeof launchModal.close === 'function') launchModal.close(); else launchModal.removeAttribute('open'); state.launchMission = null; }

  definitionList.addEventListener('click', function (event) { var button = event.target.closest('.mission-launch-btn'); if (button && !button.disabled) openLaunch(button.getAttribute('data-mission-id')); });
  launchCrew.addEventListener('change', function (event) {
    if (!state.launchMission) return;
    var selected = launchCrew.querySelectorAll('input:checked');
    if (selected.length > state.launchMission.max_crew) { event.target.checked = false; launchError.textContent = 'This mission allows a maximum of ' + state.launchMission.max_crew + ' crew members.'; } else { launchError.textContent = ''; }
  });
  document.getElementById('mission-launch-close').addEventListener('click', closeLaunch); document.getElementById('mission-launch-cancel').addEventListener('click', closeLaunch);
  launchModal.addEventListener('click', function (event) { if (event.target === launchModal) closeLaunch(); });
  launchConfirm.addEventListener('click', function () {
    if (!state.launchMission) return;
    var crewIds = Array.prototype.map.call(launchCrew.querySelectorAll('input:checked'), function (input) { return Number(input.value); });
    if (crewIds.length < state.launchMission.min_crew || crewIds.length > state.launchMission.max_crew) { launchError.textContent = 'Choose the required number of crew members before launching.'; return; }
    launchConfirm.disabled = true; launchConfirm.classList.add('is-busy'); launchError.textContent = '';
    post('/api/missions/start.php', { mission_id: state.launchMission.id, crew_ids: crewIds, csrf: window.PW_AUTH.csrf }).then(function () { closeLaunch(); setStatus('Mission launched. Your crew is now in the field.'); load(); }).catch(function (error) { launchError.textContent = error.message; }).finally(function () { launchConfirm.disabled = false; launchConfirm.classList.remove('is-busy'); });
  });
  activeList.addEventListener('click', function (event) {
    var button = event.target.closest('.mission-action'); if (!button) return;
    var action = button.getAttribute('data-action'); var missionId = Number(button.getAttribute('data-mission-id'));
    button.disabled = true; button.classList.add('is-busy');
    post('/api/missions/' + action + '.php', { mission_id: missionId, csrf: window.PW_AUTH.csrf }).then(function (result) { setStatus(action === 'claim' ? 'Rewards claimed: +' + result.xp_awarded_per_crew + ' XP per crew and +' + result.reputation_awarded + ' reputation.' : 'Mission completed. Rewards are ready to claim.'); load(); }).catch(function (error) { setStatus(error.message, true); button.disabled = false; button.classList.remove('is-busy'); });
  });
  [crewRoleFilter, crewLevelFilter, crewWorldFilter, crewStatusFilter, crewSort].forEach(function (control) {
    control.addEventListener('change', function () { if (state.data) renderCrew(state.data); });
  });
  document.addEventListener('pw-auth-ready', load); window.setInterval(tickCountdowns, 1000); window.setInterval(tickCommandFeed, 1000); load();
}());
