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
  var profileCard = document.getElementById('mission-profile-card');
  var resultModal = document.getElementById('mission-result-modal');
  var resultInner = document.getElementById('mission-result-inner');
  var resultBody = document.getElementById('mission-result-body');

  function escapeHtml(value) { var node = document.createElement('div'); node.textContent = value == null ? '' : String(value); return node.innerHTML; }
  function apiDate(value) { return value ? new Date(String(value).replace(' ', 'T') + 'Z') : null; }
  function formatDate(value) { var date = apiDate(value); return date && !isNaN(date) ? date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—'; }
  function formatDuration(seconds) { seconds = Math.max(0, Number(seconds) || 0); var hours = Math.floor(seconds / 3600); var minutes = Math.floor((seconds % 3600) / 60); var rest = seconds % 60; return (hours ? String(hours).padStart(2, '0') + ':' : '') + String(minutes).padStart(2, '0') + ':' + String(rest).padStart(2, '0'); }
  function missionDuration(seconds) { var minutes = Math.round(Number(seconds) / 60); return minutes >= 60 ? (minutes / 60) + ' hour' + (minutes === 60 ? '' : 's') : minutes + ' minutes'; }
  function post(url, payload) { return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }).then(function (response) { return response.json(); }).then(function (data) { if (!data.ok) throw new Error(data.error || 'The mission command could not be completed.'); return data; }); }
  function safeImage(url) { return /^(?:images\/[a-zA-Z0-9._-]+|\/uploads\/mission-crew-images\/img_[a-f0-9]{16}\.jpg)$/.test(String(url || '')) ? url : ''; }
  function availableCrew() { return (state.data && state.data.crew || []).filter(function (crew) { return crew.status === 'available' && crew.definition_enabled; }); }
  function setStatus(message, isError) { statusMessage.textContent = message || ''; statusMessage.classList.toggle('is-error', !!isError); }
  function credits(value) { return (Math.max(0, Number(value) || 0)).toLocaleString(); }

  /* Commander card in the right rail. The avatar is the site-wide
   * /uploads/avatars/<id>.jpg convention the header chip already uses, with the
   * same onerror fallback to an initial -- there is no avatar field on the
   * mission payload because there is nothing for the server to resolve. */
  function renderProfile(data) {
    if (!profileCard) return;
    var player = data.player;
    if (!player) { profileCard.innerHTML = ''; return; }
    var reputation = player.reputation || {};
    var name = String(player.display_name || 'Commander');
    var rank = reputation.level_name || 'Unranked';
    var rankColor = reputation.level_color || '#c7ccd6';
    var progress = Math.max(0, Math.min(100, Number(reputation.progress_percent) || 0));
    var nextLine = reputation.next_level_name
      ? Number(reputation.points || 0).toLocaleString() + ' / ' + Number(reputation.next_level_threshold || 0).toLocaleString() + ' to ' + reputation.next_level_name
      : Number(reputation.points || 0).toLocaleString() + ' reputation · highest standing reached';
    profileCard.innerHTML = '<span class="eyebrow">Commander</span>'
      + '<div class="mission-profile-head">'
        + '<span class="mission-profile-avatar" style="--rank-color:' + escapeHtml(rankColor) + '">'
          + '<img src="/uploads/avatars/' + encodeURIComponent(player.id) + '.jpg" alt="" onerror="this.hidden=true">'
          + '<span class="mission-profile-avatar-fallback">' + escapeHtml(name.charAt(0).toUpperCase()) + '</span></span>'
        + '<span class="mission-profile-identity"><strong>' + escapeHtml(name) + '</strong>'
          + '<span class="mission-profile-rank" style="color:' + escapeHtml(rankColor) + '">'
          + (reputation.level_number ? '<i>' + reputation.level_number + '</i>' : '') + escapeHtml(rank) + '</span></span>'
      + '</div>'
      + '<div class="mission-profile-rep"><span class="mission-profile-rep-track"><i style="width:' + progress + '%;background:' + escapeHtml(rankColor) + '"></i></span>'
        + '<small>' + escapeHtml(nextLine) + '</small></div>'
      + (player.credits_ready
        ? '<div class="mission-profile-credits"><span>Total credits</span><strong>' + credits(player.credits) + '</strong></div>'
        : '<div class="mission-profile-credits is-pending"><span>Total credits</span><small>Available once the credits migration has been run.</small></div>');
  }

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

  /* The campaign track drawn inside a chain's card. One block per operation:
   * cleared blocks are filled, the block in progress fills by the share of
   * successful runs it still needs, and every later block stays blank and
   * unnamed -- it is the only acknowledgement that further operations exist. */
  function campaignTrackMarkup(campaign) {
    var blocks = campaign.steps.map(function (step) {
      var fill = step.is_complete ? 100 : step.state === 'current' && step.runs_required > 0
        ? Math.min(100, Math.round((step.runs_done / step.runs_required) * 100))
        : 0;
      var label = step.name
        ? 'Operation ' + step.position + ': ' + step.name
        : 'Operation ' + step.position + ': sealed until reached';
      return '<span class="mission-campaign-block is-' + step.state + '" title="' + escapeHtml(label) + '"><i style="width:' + fill + '%"></i></span>';
    }).join('');
    return '<div class="mission-campaign-track" role="img" aria-label="Campaign progress: '
      + campaign.completed_steps + ' of ' + campaign.total_steps + ' operations complete.">' + blocks + '</div>';
  }

  function campaignStateMarkup(campaign) {
    var current = campaign.steps[campaign.current_index];
    var line = campaign.is_complete
      ? 'Campaign complete — all ' + campaign.total_steps + ' operations secured.'
      : 'Operation ' + current.position + ' of ' + campaign.total_steps + ' · '
        + current.runs_done + ' / ' + current.runs_required + ' successful run' + (current.runs_required === 1 ? '' : 's');
    return '<div class="mission-campaign-strip' + (campaign.is_complete ? ' is-complete' : '') + '">'
      + '<div class="mission-campaign-strip-head"><span>Campaign track</span><strong>' + campaign.completed_steps + ' / ' + campaign.total_steps + '</strong></div>'
      + campaignTrackMarkup(campaign)
      + '<small class="mission-campaign-strip-note">' + escapeHtml(line) + '</small></div>';
  }

  /* A mission that can fail must say so before a crew is committed. The base
   * chance comes from the operation; the crew's Strength is added on top, and
   * the server rolls the real figure at claim. */
  function missionRiskMarkup(mission) {
    var base = Number(mission.base_success_percent);
    if (!isFinite(base)) base = 100;
    var bonus = state.data && state.data.roster_effects ? Number(state.data.roster_effects.success_percent) || 0 : 0;
    var parts = [];
    if (base < 100) {
      parts.push('<span class="mission-risk-chance" title="' + escapeHtml('This operation succeeds ' + base + '% of the time before your crew is counted. Strength across the assigned crew adds to that, and the result is rolled when you claim. A failed mission returns your crew with no rewards and does not count towards a campaign unlock.') + '" tabindex="0">Success ' + base + '%'
        + (bonus > 0 ? ' <em>+' + fmt(bonus) + '% roster</em>' : '') + '</span>');
    }
    if (Number(mission.loot_rolls) > 0) {
      parts.push('<span class="mission-risk-loot" title="' + escapeHtml('Recovers ' + mission.loot_rolls + ' item' + (mission.loot_rolls === 1 ? '' : 's') + ' on success. Cunning adds extra draws and Science can promote a drop to a higher tier.') + '" tabindex="0">' + mission.loot_rolls + ' loot roll' + (mission.loot_rolls === 1 ? '' : 's') + '</span>');
    }
    return parts.length ? '<p class="mission-risk">' + parts.join('') + '</p>' : '';
  }

  /* Each entry is one slot: a standalone mission, or a campaign track showing
   * only its current step. The server sends nothing about a sealed operation,
   * so there is no locked-card branch here by design. */
  function renderDefinitions(data) {
    var available = availableCrew().length;
    if (!data.missions.length) { definitionList.innerHTML = '<p class="missions-empty">No Neoh operations are available at the moment.</p>'; return; }
    definitionList.innerHTML = data.missions.map(function (mission) {
      var campaign = mission.campaign;

      if (mission.is_offline) {
        return '<article class="mission-definition-card is-offline"><div class="mission-card-top"><span class="mission-type">Campaign</span></div>'
          + '<h3>Operation offline</h3><p>Command has taken this operation off the roster for now. Your progress is held.</p>'
          + (campaign ? campaignStateMarkup(campaign) : '') + '</article>';
      }

      var canLaunch = available >= mission.min_crew;
      return '<article class="mission-definition-card' + (campaign ? ' has-campaign' : '') + '"><div class="mission-card-top"><span class="mission-type">' + escapeHtml(mission.mission_type) + '</span><span class="mission-duration">' + missionDuration(mission.duration_seconds) + '</span></div><h3>' + escapeHtml(mission.name) + '</h3><p>' + escapeHtml(mission.description) + '</p>'
        + (campaign ? campaignStateMarkup(campaign) : '<p class="mission-unlock-state is-base">Available immediately</p>')
        + '<dl class="mission-definition-meta"><div><dt>Crew</dt><dd>' + mission.min_crew + (mission.max_crew !== mission.min_crew ? '–' + mission.max_crew : '') + '</dd></div><div><dt>XP</dt><dd>+' + mission.xp_reward + ' per crew</dd></div><div><dt>Reputation</dt><dd>' + (mission.reputation_reward ? '+' + mission.reputation_reward : '—') + '</dd></div>'
          + (Number(mission.credit_reward) > 0 ? '<div><dt>Credits</dt><dd class="is-credits">+' + credits(mission.credit_reward) + '</dd></div>' : '') + '</dl>'
        + missionRiskMarkup(mission)
        + '<button type="button" class="btn mission-launch-btn" data-mission-id="' + mission.id + '"' + (canLaunch ? '' : ' disabled') + '>' + (canLaunch ? 'Select Crew' : 'Crew Unavailable') + '</button></article>';
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

  /* Stat card shown under each portrait. The four stats are allocated
   * automatically on level up, so this is a readout, never a control. Every
   * figure here is recomputed on the server before it affects a mission --
   * these numbers explain the maths, they do not decide it. */
  var STAT_INFO = {
    strength: { label: 'Strength', short: 'STR', effect: function (v) { return '+' + fmt(v * 0.5) + '% mission success'; },
      copy: 'Raises the chance a mission succeeds at all. 0.5% per point, added to the operation’s own base chance and shared across the whole assigned crew.' },
    cunning: { label: 'Cunning', short: 'CUN', effect: function (v) { return '+' + fmt(v * 1) + '% loot'; },
      copy: 'Buys extra draws from the loot pool. 1% per point; every whole 100% is one guaranteed extra item, and the remainder is the chance of one more.' },
    science: { label: 'Science', short: 'SCI', effect: function (v) { return '+' + fmt(v * 1.5) + '% tier upgrade'; },
      copy: 'A luck roll on each item recovered. 1.5% per point for that drop to be promoted one rarity tier higher.' },
    charisma: { label: 'Charisma', short: 'CHA', effect: function (v) { return '+' + fmt(v * 0.5) + '% XP'; },
      copy: 'Adds to the experience the whole crew earns from a mission. 0.5% per point, stacking with the Pathfinder role bonus.' }
  };
  var ROLE_INFO = {
    Engineer: { stat: 'science', effect: function (l) { return '−' + fmt(l * 0.05) + '% mission time'; },
      copy: 'Engineers shorten every operation they join by 0.05% per level. This stacks across the crew, so three level-2 Engineers cut 0.30% from the clock.' },
    Pathfinder: { stat: 'charisma', effect: function (l) { return '+' + fmt(l * 0.10) + '% crew XP'; },
      copy: 'Pathfinders raise the experience the whole crew earns by 0.10% per level, on top of their own Charisma.' },
    Vanguard: { stat: 'strength', effect: function (l) { return '+' + fmt(l * 0.05) + ' reputation'; },
      copy: 'Vanguards add 0.05 flat reputation per level to a successful mission, on top of the operation’s own reward.' }
  };
  function fmt(value) {
    var rounded = Math.round(value * 100) / 100;
    return String(rounded % 1 === 0 ? rounded : rounded.toFixed(2).replace(/0$/, ''));
  }

  function crewStatCard(crew) {
    var maxStat = Number(crew.max_stat) || 50;
    var cells = ['strength', 'cunning', 'science', 'charisma'].map(function (key) {
      var info = STAT_INFO[key];
      var value = Math.max(0, Number(crew[key]) || 0);
      var pct = Math.min(100, Math.round((value / maxStat) * 100));
      var capped = value >= maxStat;
      /* The effect sentence lives only in the tooltip. Printed in every cell it
       * cost four lines per card to say "+0%" three times, since a crew member
       * only ever has two stats above zero. */
      var tip = info.label + ' ' + value + ' / ' + maxStat + (capped ? ' (max)' : '') + ' — ' + info.effect(value) + '. ' + info.copy;
      return '<div class="crew-stat' + (capped ? ' is-max' : '') + ' is-' + key + '" tabindex="0" title="' + escapeHtml(tip) + '">'
        + '<span class="crew-stat-key">' + info.short + '</span>'
        + '<span class="crew-stat-value">' + value + '</span>'
        + '<span class="crew-stat-bar"><i style="width:' + pct + '%"></i></span></div>';
    }).join('');

    var role = ROLE_INFO[crew.role];
    var roleLine = role
      ? '<p class="crew-stat-role" tabindex="0" title="' + escapeHtml(role.copy) + '"><span>' + escapeHtml(crew.role) + ' bonus</span><strong>' + escapeHtml(role.effect(Number(crew.level) || 0)) + '</strong></p>'
      : '';
    return '<div class="crew-stat-card"><div class="crew-stat-grid">' + cells + '</div>' + roleLine + '</div>';
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
      /* One line rather than two, and sitting under the name instead of in a
       * labelled block at the foot of the card. */
      var missionCopy = deployed && crew.active_mission_name ? '<p class="crew-mission-copy">' + escapeHtml(crew.active_mission_name) + ' · <span class="mission-countdown" data-completes-at="' + escapeHtml(crew.active_mission_completes_at) + '">Calculating…</span></p>' : '';
      var profile = crewRoleProfile(crew.role);
      var maxLevel = Number(crew.max_level) || 50;
      var atMaxLevel = (Number(crew.level) || 0) >= maxLevel;
      var totalXp = Math.max(0, Number(crew.xp) || 0);
      var cycleXp = totalXp % 100;
      // At the level ceiling the XP cycle keeps turning but buys nothing, so
      // the bar reads full rather than restarting from a misleading zero.
      var progress = atMaxLevel ? 100 : (totalXp > 0 && cycleXp === 0 ? 100 : cycleXp);
      /* No "N XP to next threshold" line: it is exactly 100 minus the figure
       * already shown beside it, so it cost a line per card to restate one. */
      var rankValue = atMaxLevel ? 'Level ' + maxLevel : progress + ' / 100 XP';
      var portraitMarkup = portrait
        ? '<img src="' + escapeHtml(portrait) + '" alt="" class="mission-crew-portrait">'
        : '<div class="mission-crew-portrait mission-crew-fallback" aria-hidden="true">' + escapeHtml(crew.name.charAt(0)) + '</div>';
      /* Status is a dot on the portrait rather than a labelled block. It keeps
       * an accessible name, so the state is still announced and hoverable. */
      var statusDot = '<span class="crew-status-dot is-' + availability + '" role="img" tabindex="0" title="' + escapeHtml(status) + '" aria-label="Status: ' + escapeHtml(status) + '"></span>';
      return '<article class="mission-crew-card ' + (deployed ? 'is-deployed' : '') + (availability === 'unavailable' ? ' is-unavailable' : '') + '">'
        + '<span class="mission-crew-portrait-wrap">' + portraitMarkup + statusDot + '</span>'
        + '<div class="mission-crew-copy"><span class="crew-role">' + escapeHtml(crew.role) + '</span><h3>' + escapeHtml(crew.name) + '</h3>' + missionCopy + '<p>' + escapeHtml(crew.description) + '</p>'
        + '<div class="crew-progression ' + profile.className + (atMaxLevel ? ' is-max-level' : '') + '"><div class="crew-rank-insignia" aria-label="' + escapeHtml(crew.role) + ' level ' + crew.level + '"><span>' + profile.code + '</span><small>L' + crew.level + '</small></div><div class="crew-progression-copy"><div><span>' + profile.rankLabel + '</span><strong>' + rankValue + '</strong></div><div class="crew-xp-track"><span style="width:' + progress + '%"></span></div></div></div>'
        + crewStatCard(crew) + '</div></article>';
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
      // A failed run is archived alongside a claimed one, but it paid nothing,
      // so its rewards column states that rather than printing the operation's
      // advertised figures as though they had been collected.
      var failed = mission.status === 'failed';
      var rewards = failed
        ? 'No rewards recovered'
        : '+' + mission.xp_reward + ' XP · +' + mission.reputation_reward + ' rep' + (mission.credits_awarded > 0 ? ' · +' + credits(mission.credits_awarded) + ' cr' : '');
      return '<article class="mission-history-row' + (failed ? ' is-failed' : '') + '"><div><span class="mission-world">' + escapeHtml(mission.world_key) + '</span><strong>' + escapeHtml(mission.name) + '</strong><p>' + escapeHtml((mission.crew_names || []).join(' · ')) + '</p></div><div><small>Completed</small><span>' + formatDate(mission.completed_at) + '</span></div><div><small>Rewards</small><span>' + escapeHtml(rewards) + '</span></div><div><span class="mission-history-status' + (failed ? ' is-failed' : '') + '">' + (failed ? 'Failed' : 'Claimed') + '</span></div></article>';
    }).join('');
  }

  function render(data) {
    state.data = data;
    var server = apiDate(data.server_time);
    state.serverOffset = server && !isNaN(server) ? server.getTime() - Date.now() : 0;
    renderProfile(data); renderStats(data); renderCommandFeed(data); renderActive(data); renderDefinitions(data); renderCrew(data); renderHistory(data); tickCountdowns();
  }

  /* Debrief shown after a claim. The server has already resolved everything by
   * the time this runs -- the roll, the payment and the loot draw all happened
   * inside one transaction -- so this only reports an outcome, never decides
   * one. The status line still receives the same summary for screen readers and
   * for anyone who dismisses the report before reading it. */
  function showResult(result) {
    if (!resultModal || !resultBody) return;
    var failed = result.succeeded === false;
    resultInner.classList.toggle('is-failed', failed);
    resultInner.classList.toggle('is-success', !failed);
    var title = failed ? 'Mission failed' : 'Mission complete';
    var lead = failed
      ? 'The operation broke down at ' + result.success_percent + '% success. Your crew is back at command with nothing recovered, and this run does not count towards a campaign unlock.'
      : 'Your crew has returned. Command has logged the following against your record.';

    var rows = [];
    if (!failed) {
      var xpNote = result.xp_bonus_percent > 0 ? 'includes +' + fmt(result.xp_bonus_percent) + '% crew bonus' : 'per crew member';
      rows.push({ key: 'xp', label: 'Experience', value: '+' + result.xp_awarded_per_crew + ' XP', note: xpNote });
      rows.push({ key: 'rep', label: 'Reputation', value: result.reputation_awarded > 0 ? '+' + result.reputation_awarded : '—', note: result.reputation_awarded > 0 ? 'added to your standing' : 'this operation pays no reputation' });
      if (result.credits_ready) {
        rows.push({ key: 'credits', label: 'Credits', value: result.credits_awarded > 0 ? '+' + credits(result.credits_awarded) : '—', note: 'total ' + credits(result.credits_total) });
      }
    } else {
      rows.push({ key: 'xp', label: 'Experience', value: '—', note: 'no experience awarded' });
      rows.push({ key: 'rep', label: 'Reputation', value: '—', note: 'no reputation awarded' });
      if (result.credits_ready) rows.push({ key: 'credits', label: 'Credits', value: '—', note: 'total ' + credits(result.credits_total) });
    }

    var grid = rows.map(function (row) {
      return '<div class="mission-result-stat is-' + row.key + '"><span>' + escapeHtml(row.label) + '</span><strong>' + escapeHtml(row.value) + '</strong><small>' + escapeHtml(row.note) + '</small></div>';
    }).join('');

    var extras = '';
    if (!failed && result.loot && result.loot.length) {
      extras += '<div class="mission-result-block"><h4>Recovered</h4><ul class="mission-result-loot">'
        + result.loot.map(function (item) {
          return '<li class="is-' + escapeHtml(item.tier) + '"><span>' + escapeHtml(item.name) + '</span><em>' + escapeHtml(item.tier) + (item.upgraded ? ' · upgraded' : '') + '</em></li>';
        }).join('') + '</ul></div>';
    }
    if (!failed && result.level_ups && result.level_ups.length) {
      extras += '<div class="mission-result-block"><h4>Promotions</h4><ul class="mission-result-levels">'
        + result.level_ups.map(function (member) {
          return '<li><span>' + escapeHtml(member.name || 'Crew member') + '</span><em>Level ' + member.level + '</em></li>';
        }).join('') + '</ul></div>';
    }

    resultBody.innerHTML = '<span class="eyebrow">' + (failed ? 'Debrief · loss' : 'Debrief · recovery') + '</span>'
      + '<h2 id="mission-result-title">' + escapeHtml(title) + '</h2>'
      + '<p class="mission-result-mission">' + escapeHtml(result.mission_name || '') + '</p>'
      + '<p class="mission-result-lead">' + escapeHtml(lead) + '</p>'
      + '<div class="mission-result-grid">' + grid + '</div>' + extras;
    if (typeof resultModal.showModal === 'function') resultModal.showModal(); else resultModal.setAttribute('open', '');
  }
  function closeResult() { if (resultModal.open && typeof resultModal.close === 'function') resultModal.close(); else resultModal.removeAttribute('open'); }

  function claimSummary(result) {
    if (result.succeeded === false) {
      return 'Mission failed at ' + result.success_percent + '% success. Your crew returned without rewards, and this run does not count towards a campaign unlock.';
    }
    var parts = ['Rewards claimed: +' + result.xp_awarded_per_crew + ' XP per crew'];
    if (result.xp_bonus_percent > 0) parts[0] += ' (includes +' + fmt(result.xp_bonus_percent) + '% crew bonus)';
    if (result.reputation_awarded > 0) parts.push('+' + result.reputation_awarded + ' reputation');
    if (result.credits_awarded > 0) parts.push('+' + credits(result.credits_awarded) + ' credits (total ' + credits(result.credits_total) + ')');
    if (result.level_ups && result.level_ups.length) parts.push(result.level_ups.length + ' crew levelled up');
    if (result.loot && result.loot.length) {
      var names = result.loot.map(function (item) { return item.name + (item.upgraded ? ' (upgraded)' : ''); });
      parts.push('recovered ' + names.join(', '));
    }
    return parts.join(' · ') + '.';
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
  document.getElementById('mission-result-close').addEventListener('click', closeResult);
  document.getElementById('mission-result-dismiss').addEventListener('click', closeResult);
  resultModal.addEventListener('click', function (event) { if (event.target === resultModal) closeResult(); });
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
    post('/api/missions/' + action + '.php', { mission_id: missionId, csrf: window.PW_AUTH.csrf }).then(function (result) {
      /* load() clears the status line synchronously before its own fetch
       * resolves, so the summary has to be written after it -- set before, it
       * was wiped in the same tick and no mission action has ever actually
       * reported an outcome there. */
      load();
      if (action === 'claim') { setStatus(claimSummary(result), result.succeeded === false); showResult(result); }
      else { setStatus('Mission completed. Rewards are ready to claim.'); }
    }).catch(function (error) { setStatus(error.message, true); button.disabled = false; button.classList.remove('is-busy'); });
  });
  [crewRoleFilter, crewLevelFilter, crewWorldFilter, crewStatusFilter, crewSort].forEach(function (control) {
    control.addEventListener('change', function () { if (state.data) renderCrew(state.data); });
  });
  document.addEventListener('pw-auth-ready', load); window.setInterval(tickCountdowns, 1000); window.setInterval(tickCommandFeed, 1000); load();
}());
