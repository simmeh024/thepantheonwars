(function () {
  'use strict';

  var state = { data: null, serverOffset: 0, launchMission: null, launchProjection: null, launchPenaltyAck: false,
    loadoutCrewId: null, loadoutSlot: null, loadoutAutoRunning: false, crewPage: 1, refreshQueued: false, feedSlot: null,
    /* When the current payload was received. Fatigue arrives already caught up
     * to that instant, so the page ages it forward from here rather than
     * polling the server for a value it can derive. */
    loadedAt: 0,
    /* The crew selection: one entry per max_crew slot, a crew id or null. The
     * source of truth for the launch modal -- see the rack section below. */
    launchSlots: [], dragCrewId: null,
    /* Mission id and crew of the run just claimed, for the debrief's re-run. */
    resultRerun: null };
  var gate = document.getElementById('missions-gate');
  var content = document.getElementById('missions-content');
  var statusMessage = document.getElementById('missions-status-message');
  var activeList = document.getElementById('missions-active-list');
  var definitionList = document.getElementById('missions-definition-list');
  var crewList = document.getElementById('missions-crew-list');
  var crewRoleFilter = document.getElementById('missions-crew-role-filter');
  var crewLevelFilter = document.getElementById('missions-crew-level-filter');
  var crewFavoriteFilter = document.getElementById('missions-crew-favorite-filter');
  var crewStatusFilter = document.getElementById('missions-crew-status-filter');
  var crewSort = document.getElementById('missions-crew-sort');
  var crewFilterSummary = document.getElementById('missions-crew-filter-summary');
  var crewPagination = document.getElementById('missions-crew-pagination');
  var crewCapacity = document.getElementById('missions-crew-capacity');
  var crewOffers = document.getElementById('missions-crew-offers');
  var historyList = document.getElementById('missions-history-list');
  var commandFeedList = document.getElementById('mission-feed-list');
  var launchModal = document.getElementById('mission-launch-modal');
  var launchTitle = document.getElementById('mission-launch-title');
  var launchCopy = document.getElementById('mission-launch-copy');
  var launchCrew = document.getElementById('mission-launch-crew');
  var launchError = document.getElementById('mission-launch-error');
  var launchConfirm = document.getElementById('mission-launch-confirm');
  var launchBrief = document.getElementById('mission-launch-brief');
  var launchSlots = document.getElementById('mission-launch-slots');
  var launchRecommend = document.getElementById('mission-launch-recommend');
  var launchRack = document.getElementById('mission-launch-rack');
  var launchRackNote = document.getElementById('mission-launch-rack-note');
  var launchRepeat = document.getElementById('mission-launch-repeat');
  var launchTrayCount = document.getElementById('mission-launch-tray-count');
  var launchFilterRole = document.getElementById('mission-launch-filter-role');
  var launchFilterOpen = document.getElementById('mission-launch-filter-open');
  var launchSort = document.getElementById('mission-launch-sort');
  var launchProjection = document.getElementById('mission-launch-projection');
  var weatherCard = document.getElementById('mission-weather-card');
  var loadoutModal = document.getElementById('mission-loadout-modal');
  var loadoutTitle = document.getElementById('mission-loadout-title');
  var loadoutCopy = document.getElementById('mission-loadout-copy');
  var loadoutSummary = document.getElementById('mission-loadout-summary');
  var loadoutBody = document.getElementById('mission-loadout-body');
  var loadoutSlots = document.getElementById('mission-loadout-slots');
  var loadoutOptions = document.getElementById('mission-loadout-options');
  var loadoutPickerHead = document.getElementById('mission-loadout-picker-head');
  var loadoutDeltaBox = document.getElementById('mission-loadout-delta');
  var loadoutError = document.getElementById('mission-loadout-error');
  var loadoutBest = document.getElementById('mission-loadout-best');
  var loadoutAuto = document.getElementById('mission-loadout-auto');
  var inventorySection = document.getElementById('missions-inventory-section');
  var inventoryList = document.getElementById('missions-inventory-list');
  var inventoryCount = document.getElementById('missions-inventory-count');
  var inventorySummary = document.getElementById('missions-inventory-summary');
  var inventoryTypeFilter = document.getElementById('missions-inventory-type-filter');
  var inventorySlotFilter = document.getElementById('missions-inventory-slot-filter');
  var inventoryTierFilter = document.getElementById('missions-inventory-tier-filter');
  var inventoryStateFilter = document.getElementById('missions-inventory-state-filter');
  var profileCard = document.getElementById('mission-profile-card');
  var dailyCard = document.getElementById('mission-daily-card');
  var resultModal = document.getElementById('mission-result-modal');
  var resultInner = document.getElementById('mission-result-inner');
  var resultBody = document.getElementById('mission-result-body');
  var resultRerun = document.getElementById('mission-result-rerun');
  var resultError = document.getElementById('mission-result-error');

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

  /* The page watermark, configured once in Mission Control and the same for
   * every player. Applied as two custom properties on the page element rather
   * than as an <img>: it is decoration with no meaning to convey, so it stays
   * out of the accessibility tree and out of the way of pointer events. The URL
   * is re-validated here against the same allow-list the server applies --
   * this value goes straight into a CSS url(), and a second check costs
   * nothing. */
  var WATERMARK_URL = /^(?:images\/[a-zA-Z0-9._-]{1,220}|\/uploads\/mission-images\/img_[a-f0-9]{16}\.(?:jpg|png))$/;

  /* A mission's own emblem, drawn inside that mission's card. Returned as the
   * class and inline custom properties a card needs, or an empty string when
   * the mission has none -- so a card with no watermark carries no extra
   * markup at all. Same root-relative rule as the page watermark: a url()
   * inside a custom property resolves against the stylesheet, not the page. */
  function missionWatermark(mission) {
    var url = String(mission && mission.watermark_url ? mission.watermark_url : '');
    if (!WATERMARK_URL.test(url)) return { className: '', style: '' };
    var opacity = Math.max(1, Math.min(40, Number(mission.watermark_opacity) || 10)) / 100;
    return {
      className: ' has-card-watermark',
      style: ' style="--card-watermark:url(&quot;' + escapeHtml(url.charAt(0) === '/' ? url : '/' + url)
        + '&quot;);--card-watermark-opacity:' + opacity + '"'
    };
  }
  function applyWatermark(watermark) {
    var page = document.querySelector('.missions-page');
    if (!page) return;
    var url = watermark && watermark.enabled ? String(watermark.url || '') : '';
    if (!WATERMARK_URL.test(url)) {
      page.classList.remove('has-watermark');
      page.style.removeProperty('--mission-watermark');
      page.style.removeProperty('--mission-watermark-opacity');
      return;
    }
    /* Root-relative, not document-relative. A url() carried through a custom
     * property resolves against the stylesheet that consumes it, not the page,
     * so a stored "images/sigil.png" was being fetched from /css/images/ and
     * silently failed -- only uploaded watermarks, which already start with a
     * slash, would ever have appeared. */
    page.style.setProperty('--mission-watermark', 'url("' + (url.charAt(0) === '/' ? url : '/' + url) + '")');
    page.style.setProperty('--mission-watermark-opacity', String(Math.max(1, Math.min(40, Number(watermark.opacity) || 8)) / 100));
    page.classList.add('has-watermark');
  }

  /* Today's objective, under the commander card in the same rail. One a day,
   * chosen on the server and stable for the whole UTC day, so this is a
   * readout: the client never picks the objective or its reward, and claiming
   * sends nothing but a CSRF token. */
  function rewardLabel(daily) {
    return daily.reward_type === 'reputation'
      ? '+' + daily.reward_amount + ' reputation'
      : '+' + credits(daily.reward_amount) + ' credits';
  }

  function renderDaily(data) {
    if (!dailyCard) return;
    var daily = data.daily;
    // Hidden rather than empty while the migration is pending -- an objective
    // card with nothing in it is worse than no card.
    if (!daily) { dailyCard.hidden = true; dailyCard.innerHTML = ''; dailyCard.classList.remove('is-complete'); return; }
    dailyCard.hidden = false;
    dailyCard.classList.toggle('is-complete', !!daily.is_complete);
    var pct = daily.target > 0 ? Math.min(100, Math.round((daily.progress / daily.target) * 100)) : 0;
    var action = daily.claimed
      ? '<p class="mission-daily-done">Reward claimed — ' + escapeHtml(rewardLabel(daily)) + '</p>'
      : daily.is_complete
        ? '<button type="button" class="btn btn-solid" id="mission-daily-claim">Claim ' + escapeHtml(rewardLabel(daily)) + '</button>'
        : '';
    dailyCard.innerHTML = '<div class="mission-daily-head"><span class="eyebrow">Daily objective</span>'
        + '<span class="mission-daily-reset" data-daily-reset="' + escapeHtml(daily.resets_at) + '">Resets in —</span></div>'
      + '<p class="mission-daily-label">' + escapeHtml(daily.label) + '</p>'
      + '<p class="mission-daily-detail">' + escapeHtml(daily.detail) + '</p>'
      + '<span class="mission-daily-track" role="img" aria-label="Progress: ' + daily.progress + ' of ' + daily.target + '"><i style="width:' + pct + '%"></i></span>'
      + '<p class="mission-daily-progress"><span>' + daily.progress + ' / ' + daily.target + '</span><strong>' + escapeHtml(rewardLabel(daily)) + '</strong></p>'
      + action
      + '<p class="mission-daily-error" id="mission-daily-error" role="alert"></p>';
    tickDailyReset();
  }

  // Counts down to the objective's own UTC-midnight reset, on the same ticker
  // the mission countdowns already use.
  function tickDailyReset() {
    var element = dailyCard && dailyCard.querySelector('[data-daily-reset]');
    if (!element) return;
    var resets = apiDate(element.getAttribute('data-daily-reset'));
    if (!resets || isNaN(resets)) { element.textContent = ''; return; }
    var remaining = Math.max(0, Math.floor((resets.getTime() - (Date.now() + state.serverOffset)) / 1000));
    var hours = Math.floor(remaining / 3600);
    var minutes = Math.floor((remaining % 3600) / 60);
    element.textContent = 'Resets in ' + (hours > 0 ? hours + 'h ' + minutes + 'm' : minutes + 'm');
  }

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
    renderCrewCapacity(data);
    document.getElementById('missions-command-copy').textContent = data.stats.active_missions ? 'Your crews are transmitting from the field. Mission time is verified by command.' : 'No active deployments. Review Neoh operations and assign an available crew.';
  }

  function crewOfferMarkup(offer) {
    var available = (state.data && state.data.crew || []).filter(function (member) { return member.status === 'available'; });
    var replacement = available.length
      ? '<label><span>Replace</span><select data-crew-offer-replace>' + available.map(function (member) { return '<option value="' + Number(member.id) + '">' + escapeHtml(member.name) + ' · Level ' + Number(member.level) + '</option>'; }).join('') + '</select></label><button type="button" class="btn" data-crew-offer-action="replace" data-crew-offer-id="' + Number(offer.id || offer.offer_id) + '">Replace crew</button>'
      : '<span class="mission-crew-offer-unavailable">No available crew member can be replaced while the roster is deployed.</span>';
    return '<article class="mission-crew-offer is-' + escapeHtml(offer.tier || 'common') + '" data-crew-offer="' + Number(offer.id || offer.offer_id) + '"><div class="mission-crew-offer-head"><span><small>Recruit signal held</small><strong>' + escapeHtml(offer.name) + '</strong><em>' + escapeHtml(offer.role) + ' · ' + escapeHtml(offer.tier || 'common') + '</em></span><b>' + Number(offer.roster_count || 0) + ' / ' + Number(offer.capacity || 8) + '</b></div><p>You do not have enough crew member space. Expand your berths in Research Facility, replace an available crew member, or sell this recruit.</p><div class="mission-crew-offer-actions">' + (offer.can_accept ? '<button type="button" class="btn btn-solid" data-crew-offer-action="accept" data-crew-offer-id="' + Number(offer.id || offer.offer_id) + '">Accept recruit</button>' : '<a class="btn btn-solid" href="research.html">Open Research Facility</a>') + replacement + '<button type="button" class="mission-result-destroy" data-crew-offer-action="sell" data-crew-offer-id="' + Number(offer.id || offer.offer_id) + '">Sell · ' + credits(offer.sale_credits) + ' cr</button></div><span class="mission-crew-offer-status" role="status" aria-live="polite"></span></article>';
  }

  function renderCrewCapacity(data) {
    if (!crewCapacity) return;
    var capacity = data.crew_capacity || {};
    var used = Number(capacity.used);
    if (!isFinite(used)) used = (data.crew || []).length;
    var max = Math.max(8, Number(capacity.capacity) || 8);
    var markerCount = Math.min(16, max);
    var filled = Math.min(markerCount, Math.round((used / max) * markerCount));
    document.getElementById('missions-crew-count').textContent = used + ' / ' + max;
    crewCapacity.classList.toggle('is-full', used >= max);
    var track = crewCapacity.querySelector('.mission-crew-capacity-track');
    track.style.gridTemplateColumns = 'repeat(' + markerCount + ', minmax(4px, 1fr))';
    track.innerHTML = Array.apply(null, Array(markerCount)).map(function (_, index) { return '<i class="' + (index < filled ? 'is-filled' : '') + '"></i>'; }).join('');
    crewCapacity.setAttribute('aria-label', used + ' of ' + max + ' crew berths occupied');
  }

  function renderCrewOffers(data) {
    if (!crewOffers) return;
    var offers = (data.crew_capacity && data.crew_capacity.offers) || [];
    crewOffers.innerHTML = offers.map(crewOfferMarkup).join('');
    crewOffers.hidden = !offers.length;
  }

  /* Each active operation gets the same clear rising route toward its endpoint.
   * The points are intentionally authored rather than random, so the trace
   * reads as a plotted Neoh approach and remains visually calm on refresh.
   * Its lit section is calculated from the same timestamps as the countdown. */
  function missionRouteProgress(startedAt, completesAt, isCompleted) {
    if (isCompleted) return 100;
    var started = apiDate(startedAt);
    var completes = apiDate(completesAt);
    if (!started || !completes || isNaN(started) || isNaN(completes) || completes <= started) return 0;
    var now = Date.now() + state.serverOffset;
    return Math.max(0, Math.min(100, ((now - started.getTime()) / (completes.getTime() - started.getTime())) * 100));
  }

  function updateMissionRouteProgress() {
    document.querySelectorAll('.mission-route[data-started-at][data-completes-at]').forEach(function (route) {
      var progress = missionRouteProgress(route.getAttribute('data-started-at'), route.getAttribute('data-completes-at'), route.classList.contains('is-complete'));
      var path = route.querySelector('.mission-route-progress');
      if (path) path.style.strokeDasharray = progress.toFixed(3) + ' 100';
      var packet = route.querySelector('.mission-route-packet');
      if (!packet || !path || typeof path.getTotalLength !== 'function') return;
      var length = path.getTotalLength();
      if (!length) return;
      var point = path.getPointAtLength(length * (progress / 100));
      packet.setAttribute('cx', point.x.toFixed(2));
      packet.setAttribute('cy', point.y.toFixed(2));
    });
  }

  function missionRouteMarkup(mission, isCompleted) {
    var points = [
      { x: 16, y: 144 },
      { x: 60, y: 113 },
      { x: 108, y: 78 },
      { x: 164, y: 55 },
      { x: 246, y: 28 }
    ];
    var start = points[0];
    var end = points[points.length - 1];
    var path = 'M' + points.map(function (point) { return point.x + ' ' + point.y; }).join(' L');
    var nodes = points.slice(0, -1).map(function (point, index) {
      return '<circle class="mission-route-node' + (index === 0 ? ' is-origin' : '') + '" cx="' + point.x + '" cy="' + point.y + '" r="2.4" />';
    }).join('');
    var progress = missionRouteProgress(mission.started_at, mission.completes_at, isCompleted);
    var packet = isCompleted ? '' : '<circle class="mission-route-packet" cx="' + start.x + '" cy="' + start.y + '" r="3" />';
    return '<div class="mission-route' + (isCompleted ? ' is-complete' : '') + '" data-started-at="' + escapeHtml(mission.started_at || '') + '" data-completes-at="' + escapeHtml(mission.completes_at || '') + '" aria-hidden="true"><svg viewBox="0 0 260 160" preserveAspectRatio="none" focusable="false">'
      + '<path class="mission-route-base" pathLength="100" d="' + path + '" />'
      + '<path class="mission-route-progress" pathLength="100" d="' + path + '" style="stroke-dasharray:' + progress.toFixed(3) + ' 100" />'
      + nodes + packet + '<circle class="mission-route-endpoint" cx="' + end.x + '" cy="' + end.y + '" r="4" /><circle class="mission-route-endpoint-pulse" cx="' + end.x + '" cy="' + end.y + '" r="7" />'
      + '</svg></div>';
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
      // The same mission keeps its emblem while a crew is in the field, so the
      // operation reads as the same thing on both cards.
      var watermark = missionWatermark(mission);
      return '<article class="mission-active-card is-' + escapeHtml(mission.status) + watermark.className + '"' + watermark.style + '>' + missionRouteMarkup(mission, isCompleted) + '<div class="mission-card-top"><span class="mission-type">' + escapeHtml(mission.mission_type) + '</span><span class="mission-world">' + escapeHtml(mission.world_key) + '</span></div><h3>' + escapeHtml(mission.name) + '</h3><p class="mission-crew-line">' + escapeHtml((mission.crew_names || []).join(' · ')) + '</p><div class="mission-active-footer"><div><strong class="mission-countdown" data-completes-at="' + escapeHtml(mission.completes_at) + '">' + (isCompleted ? 'Mission complete' : 'Calculating…') + '</strong><small>' + (isCompleted ? 'Ready for reward claim' : 'Completion verified by command') + '</small></div>' + action + '</div></article>';
    }).join('');
    updateMissionRouteProgress();
  }

  /* The campaign track drawn inside a chain's card. One block per operation:
   * cleared blocks are filled, the block in progress fills by the share of
   * successful runs it still needs, and every later block stays blank and
   * unnamed -- it is the only acknowledgement that further operations exist. */
  function campaignStepLabel(step) {
    if (step.state === 'offline') return 'Operation ' + step.position + ': ' + (step.name || 'sealed') + ' — off the roster';
    if (!step.name) return 'Operation ' + step.position + ': sealed until reached';
    return 'Operation ' + step.position + ': ' + step.name
      + (step.is_complete ? ' — complete' : step.state === 'current'
        ? ' — ' + step.runs_done + ' / ' + step.runs_required + ' run' + (step.runs_required === 1 ? '' : 's')
        : '');
  }

  function campaignStateLine(campaign) {
    /* The track has rolled back to an earlier operation because the next one is
     * off the roster. Say so plainly -- being handed an operation you already
     * cleared, with no explanation, reads as lost progress. */
    if (campaign.rolled_back) {
      var offline = campaign.steps[campaign.offline_index];
      var shown = campaign.steps[campaign.display_index];
      return 'Operation ' + offline.position + ' is off the roster. Operation ' + shown.position
        + ' is available to run again in the meantime — your progress is held.';
    }
    if (campaign.is_complete) return 'Campaign complete — all ' + campaign.total_steps + ' operations secured.';
    var current = campaign.steps[campaign.current_index];
    return 'Operation ' + current.position + ' of ' + campaign.total_steps + ' · '
      + current.runs_done + ' / ' + current.runs_required + ' successful run' + (current.runs_required === 1 ? '' : 's');
  }

  /* Everything the old heading and note said, moved into one hover/focus
   * tooltip on the bar itself. Deliberately one target rather than a title per
   * block: a block is only a few pixels wide, and a browser shows the innermost
   * title, so per-block tooltips would leave the summary reachable only in the
   * 3px gaps between them. */
  function campaignDetailText(campaign) {
    var lines = ['Campaign track — ' + campaign.completed_steps + ' / ' + campaign.total_steps + ' operations complete'];
    campaign.steps.forEach(function (step) { lines.push(campaignStepLabel(step)); });
    lines.push(campaignStateLine(campaign));
    return lines.join('\n');
  }

  /* The campaign track drawn under a chain's card. One block per operation:
   * cleared blocks are filled, the block in progress fills by the share of
   * successful runs it still needs, and every later block stays blank and
   * unnamed -- it is the only acknowledgement that further operations exist. */
  function campaignBarMarkup(campaign) {
    var blocks = campaign.steps.map(function (step) {
      var fill = step.is_complete ? 100 : step.state === 'current' && step.runs_required > 0
        ? Math.min(100, Math.round((step.runs_done / step.runs_required) * 100))
        : 0;
      return '<span class="mission-campaign-block is-' + step.state + '"><i style="width:' + fill + '%"></i></span>';
    }).join('');
    var detail = campaignDetailText(campaign);
    return '<div class="mission-campaign-track mission-campaign-bar' + (campaign.is_complete ? ' is-complete' : '')
      + (campaign.rolled_back ? ' is-rolled-back' : '') + '" tabindex="0" title="' + escapeHtml(detail)
      + '" role="img" aria-label="' + escapeHtml(detail.split('\n').join(' ')) + '">' + blocks + '</div>';
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
  function definitionCardMarkup(mission, available) {
    var campaign = mission.campaign;

    if (mission.is_offline) {
      return '<article class="mission-definition-card is-offline">'
        + '<h3>Operation offline</h3><p>Command has taken this operation off the roster for now. Your progress is held.</p>'
        + (campaign ? campaignBarMarkup(campaign) : '') + '</article>';
    }

    var canLaunch = available >= mission.min_crew;
    var watermark = missionWatermark(mission);
    /* No type pill on the card: the group heading above it already says which
     * kind of operation this is. */
    return '<article class="mission-definition-card' + (campaign ? ' has-campaign' : '') + watermark.className + '"' + watermark.style + '><div class="mission-card-top"><span class="mission-duration">' + missionDuration(mission.duration_seconds) + '</span></div><h3>' + escapeHtml(mission.name) + '</h3><p>' + escapeHtml(mission.description) + '</p>'
      + (campaign ? '' : '<p class="mission-unlock-state is-base">Available immediately</p>')
      + '<dl class="mission-definition-meta"><div><dt>Crew</dt><dd>' + mission.min_crew + (mission.max_crew !== mission.min_crew ? '–' + mission.max_crew : '') + '</dd></div><div><dt>XP</dt><dd>+' + mission.xp_reward + ' per crew</dd></div><div><dt>Reputation</dt><dd>' + (mission.reputation_reward ? '+' + mission.reputation_reward : '—') + '</dd></div>'
        + (Number(mission.credit_reward) > 0 ? '<div><dt>Credits</dt><dd class="is-credits">+' + credits(mission.credit_reward) + '</dd></div>' : '')
        /* Stated on the card, before any crew is chosen, because that is the
         * figure start.php charges -- it is a property of the operation's
         * authored length, not of who is sent on it. */
        + (Number(mission.fatigue_cost) > 0 ? '<div><dt>Fatigue</dt><dd class="is-fatigue">−' + Number(mission.fatigue_cost) + ' per crew</dd></div>' : '') + '</dl>'
      + missionRiskMarkup(mission)
      + '<button type="button" class="btn mission-launch-btn" data-mission-id="' + mission.id + '"' + (canLaunch ? '' : ' disabled') + '>' + (canLaunch ? 'Select Crew' : 'Crew Unavailable') + '</button>'
      + (campaign ? campaignBarMarkup(campaign) : '') + '</article>';
  }

  /* Grouped by operation type (Recon, Survey, Salvage, ...) so the roster reads
   * as a few short shelves instead of one long run of cards. Group order
   * follows the server's own sort_order via first appearance, never
   * alphabetical -- the roster is authored in a deliberate order. An offline
   * slot carries no mission_type (the server sends only its bar), so it gets
   * its own group rather than a guessed one. */
  function renderDefinitions(data) {
    var available = availableCrew().length;
    if (!data.missions.length) { definitionList.innerHTML = '<p class="missions-empty">No Neoh operations are available at the moment.</p>'; return; }
    var order = [];
    var groups = {};
    data.missions.forEach(function (mission) {
      var label = mission.is_offline ? 'Off the roster' : String(mission.mission_type || 'Operations');
      if (!groups[label]) { groups[label] = []; order.push(label); }
      groups[label].push(mission);
    });
    definitionList.innerHTML = order.map(function (label) {
      var cards = groups[label].map(function (mission) { return definitionCardMarkup(mission, available); }).join('');
      return '<details class="mission-type-group" open><summary class="mission-type-group-head">'
        + '<span class="mission-type-group-name">' + escapeHtml(label) + '</span>'
        + '<span class="mission-type-group-count">' + groups[label].length + ' operation' + (groups[label].length === 1 ? '' : 's') + '</span>'
        + '</summary><div class="missions-definition-grid">' + cards + '</div></details>';
    }).join('');
  }

  function crewAvailability(crew) {
    /* Kept as a floor rather than removed: the roster query withdraws a
     * switched-off crew member before it reaches this page, and status is only
     * ever available or on_mission, so nothing here should resolve to
     * unavailable any more. The Unavailable filter option was dropped for that
     * reason -- an option that can never match anything is a dead control --
     * but the state itself stays handled so an unexpected status value renders
     * as a disabled row instead of an available one. */
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
    populateCrewFilter(crewRoleFilter, roles, 'All roles', function (role) { return role; });
    populateCrewFilter(crewLevelFilter, levels, 'All levels', function (level) { return 'Level ' + level; });
  }

  function filteredCrew(crew) {
    var role = crewRoleFilter.value;
    var level = crewLevelFilter.value;
    var favorites = crewFavoriteFilter.value;
    var status = crewStatusFilter.value;
    var visible = crew.filter(function (member) {
      return (role === 'all' || member.role === role)
        && (level === 'all' || Number(member.level) === Number(level))
        && (favorites === 'all' || !!member.is_favorite)
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

  function renderCrewPagination(pageCount) {
    if (!crewPagination) return;
    if (pageCount <= 1) { crewPagination.hidden = true; crewPagination.innerHTML = ''; return; }
    crewPagination.hidden = false;
    crewPagination.innerHTML = '<button type="button" class="missions-crew-page" data-crew-page="previous"' + (state.crewPage === 1 ? ' disabled' : '') + '>Previous</button>'
      + '<span class="missions-crew-page-status" aria-live="polite">Page ' + state.crewPage + ' of ' + pageCount + '</span>'
      + '<button type="button" class="missions-crew-page" data-crew-page="next"' + (state.crewPage === pageCount ? ' disabled' : '') + '>Next</button>';
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
      /* The server sends the total the crew member actually fights with,
       * including equipment. The card deliberately prints that one true number
       * instead of a base value followed by a second add-on that can be read as
       * a different total. The tooltip still explains any gear contribution. */
      var gearPart = crew.gear_bonus ? Number(crew.gear_bonus[key]) || 0 : 0;
      var pct = Math.min(100, Math.round((value / maxStat) * 100));
      var capped = value >= maxStat;
      /* The effect sentence lives only in the tooltip. Printed in every cell it
       * cost four lines per card to say "+0%" three times, since a crew member
       * only ever has two stats above zero. */
      var tip = info.label + ' ' + value + ' / ' + maxStat + (capped ? ' (max)' : '') + ' — ' + info.effect(value) + '. ' + info.copy
        + (gearPart !== 0 ? ' Equipment accounts for ' + (gearPart > 0 ? '+' : '') + gearPart + ' of this.' : '');
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
    if (!data.crew.length) {
      crewFilterSummary.textContent = '';
      crewList.innerHTML = '<p class="missions-empty">Crew records are being prepared.</p>';
      renderCrewPagination(0);
      return;
    }
    updateCrewFilterOptions(data.crew);
    if (crewFavoriteFilter) crewFavoriteFilter.disabled = !data.crew_favorites_ready;
    var visibleCrew = filteredCrew(data.crew);
    var pageCount = Math.ceil(visibleCrew.length / 4);
    if (state.crewPage > pageCount) state.crewPage = Math.max(1, pageCount);
    var pageStart = (state.crewPage - 1) * 4;
    var pageCrew = visibleCrew.slice(pageStart, pageStart + 4);
    var pageEnd = pageStart + pageCrew.length;
    if (!visibleCrew.length) {
      crewFilterSummary.textContent = crewFavoriteFilter.value === 'favorites' ? 'No favourite crew members yet.' : 'No crew members match these filters.';
      crewList.innerHTML = '<p class="missions-empty">No crew members match these filters.</p>';
      renderCrewPagination(0);
      return;
    }
    crewFilterSummary.textContent = pageCount > 1
      ? 'Showing ' + (pageStart + 1) + '–' + pageEnd + ' of ' + visibleCrew.length + ' crew members.'
      : (visibleCrew.length === data.crew.length ? 'Showing your full roster.' : 'Showing ' + visibleCrew.length + ' matching crew member' + (visibleCrew.length === 1 ? '.' : 's.'));
    renderCrewPagination(pageCount);
    crewList.innerHTML = pageCrew.map(function (crew) {
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
      /* Levelling is exponential, so the span of the current level is resolved
       * server-side by pw_missions_xp_progress() against the same curve the
       * claim path levels from -- the card must never re-derive it from a fixed
       * per-level figure. At the ceiling the bar reads full because further XP
       * buys nothing. */
      var xpSpan = Math.max(0, Number(crew.xp_for_next_level) || 0);
      var xpInto = Math.max(0, Number(crew.xp_into_level) || 0);
      var progress = atMaxLevel || !xpSpan ? 100 : Math.max(0, Math.min(100, Number(crew.xp_percent) || 0));
      var rankValue = atMaxLevel || !xpSpan ? 'Level ' + maxLevel : xpInto + ' / ' + xpSpan + ' XP';
      var portraitMarkup = portrait
        ? '<img src="' + escapeHtml(portrait) + '" alt="" class="mission-crew-portrait">'
        : '<div class="mission-crew-portrait mission-crew-fallback" aria-hidden="true">' + escapeHtml(crew.name.charAt(0)) + '</div>';
      /* Status is a dot on the portrait rather than a labelled block. It keeps
       * an accessible name, so the state is still announced and hoverable. */
      var statusDot = '<span class="crew-status-dot is-' + availability + '" role="img" tabindex="0" title="' + escapeHtml(status) + '" aria-label="Status: ' + escapeHtml(status) + '"></span>';
      var favorite = !!crew.is_favorite;
      var favoriteReady = !!data.crew_favorites_ready;
      var favoriteLabel = favorite ? 'Remove ' + crew.name + ' from favourites' : 'Add ' + crew.name + ' to favourites';
      var favoriteHint = favoriteReady ? favoriteLabel : 'Crew favourites are being prepared';
      var favoriteButton = '<button type="button" class="mission-crew-favorite' + (favorite ? ' is-favorite' : '') + '" data-crew-favorite="' + crew.id + '" aria-pressed="' + (favorite ? 'true' : 'false') + '" aria-label="' + escapeHtml(favoriteHint) + '" title="' + escapeHtml(favoriteHint) + '"' + (favoriteReady ? '' : ' disabled') + '><span aria-hidden="true">★</span></button>';
      return '<article class="mission-crew-card ' + (deployed ? 'is-deployed' : '') + (availability === 'unavailable' ? ' is-unavailable' : '') + (favorite ? ' is-favorite' : '') + '">'
        + favoriteButton
        + '<div class="mission-crew-visual"><span class="mission-crew-portrait-wrap">' + portraitMarkup + statusDot + '</span>' + crewLoadoutStrip(crew) + '</div>'
        + '<div class="mission-crew-copy"><span class="crew-role">' + escapeHtml(crew.role) + '</span><h3>' + escapeHtml(crew.name) + '</h3>' + missionCopy + '<p>' + escapeHtml(crew.description) + '</p>'
        + '<div class="crew-progression ' + profile.className + (atMaxLevel ? ' is-max-level' : '') + '"><div class="crew-rank-insignia" aria-label="' + escapeHtml(crew.role) + ' level ' + crew.level + '"><span>' + profile.code + '</span><small>L' + crew.level + '</small></div><div class="crew-progression-copy"><div><span>' + profile.rankLabel + '</span><strong>' + rankValue + '</strong></div><div class="crew-xp-track"><span style="width:' + progress + '%"></span></div></div></div>'
        + fatigueMarkup(crew)
        + crewStatCard(crew) + '</div></article>';
    }).join('');
  }

  /* ----------------------------------------------------------------------
   * Crew fatigue.
   *
   * The server sends each crew member's pool already caught up to the moment
   * the payload was built, plus the regeneration rate. These read it forward
   * from there so a countdown can tick between loads without asking the server
   * for a number that is a pure function of elapsed time. Every figure here is
   * display only -- api/missions/start.php recomputes the pool from the stored
   * row and refuses an exhausted crew member regardless of what this decides.
   * -------------------------------------------------------------------- */
  function fatigueReady(member) {
    return !!(member && member.fatigue_ready);
  }

  function crewFatigue(member) {
    if (!fatigueReady(member)) return 0;
    var stored = Math.max(0, Number(member.fatigue) || 0);
    var max = Math.max(1, Number(member.fatigue_max) || 100);
    // Rest only accrues while available, exactly as the server resolves it.
    if (crewAvailability(member) !== 'available') return Math.min(max, stored);
    var rate = Number(member.fatigue_regen_per_minute) || 0;
    var minutes = Math.floor(Math.max(0, Date.now() - (state.loadedAt || Date.now())) / 60000);
    return Math.min(max, stored + Math.floor(minutes * rate));
  }

  function fatigueRecoverySeconds(member, cost) {
    var rate = Number(member.fatigue_regen_per_minute) || 0;
    var short = cost - crewFatigue(member);
    if (short <= 0 || rate <= 0) return 0;
    return Math.ceil(short / rate * 60);
  }

  /** True when this crew member cannot afford an operation right now. */
  function crewIsResting(member, cost) {
    return fatigueReady(member) && Number(cost) > 0 && crewFatigue(member) < Number(cost);
  }

  function fatigueMarkup(member) {
    if (!fatigueReady(member)) return '';
    var max = Math.max(1, Number(member.fatigue_max) || 100);
    var value = crewFatigue(member);
    var percent = Math.max(0, Math.min(100, Math.round(value / max * 100)));
    var tone = percent >= 60 ? ' is-rested' : percent >= 25 ? ' is-worn' : ' is-spent';
    var hint = value + ' of ' + max + ' fatigue. Operations spend 10 for every whole 10 minutes of their length, '
      + 'and crew recover ' + fmt(Number(member.fatigue_regen_per_minute) || 0) + ' per minute while available.';
    return '<div class="crew-fatigue' + tone + '" title="' + escapeHtml(hint) + '">'
      + '<span class="crew-fatigue-label">Fatigue</span>'
      + '<span class="crew-fatigue-track"><span style="width:' + percent + '%"></span></span>'
      + '<b>' + value + ' / ' + max + '</b></div>';
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
    applyWatermark(data.watermark); renderWeather(data); renderProfile(data); renderDaily(data); renderStats(data); renderCrewOffers(data); renderCommandFeed(data); renderActive(data); renderDefinitions(data); renderCrew(data); renderInventory(data); renderHistory(data);
    /* The launch modal stays open across a background refresh -- equipping gear
     * from a slot's upgrade warning reloads the whole payload underneath it.
     * Re-resolve the mission against the new data and redraw the picker, or it
     * keeps rendering the gear, stats and fatigue from before the change while
     * the player is looking straight at it. The rack itself is state, not DOM,
     * so the selection survives; ensureRack() drops anyone who has become
     * ineligible in the meantime. */
    if (state.launchMission) {
      var freshMission = (data.missions || []).filter(function (item) { return Number(item.id) === Number(state.launchMission.id); })[0];
      if (freshMission) { state.launchMission = freshMission; renderLaunchCrew(); }
    }
    tickCountdowns();
  }

  /* A recovery is useful only when it answers the immediate question: can this
   * replace something the player already owns? The comparison deliberately
   * considers the lowest-ranked compatible equipped item first, then the
   * lowest-ranked compatible inventory item. Requirements are included on the
   * reward payload so a role-locked drop is never advertised to the wrong crew. */
  /* ----------------------------------------------------------------------
   * Mission debrief.
   *
   * The payoff screen of the whole loop, so it does three jobs rather than
   * one: it reports what happened (including the roll, not only the verdict),
   * it lets the player act on what they were given without leaving it, and on
   * a loss it says what would have helped.
   *
   * Every figure shown here was computed by api/missions/claim.php. Nothing is
   * re-derived from a reward value, and the two actions this modal offers
   * (equip, destroy) go through the same endpoints the loadout modal uses.
   * -------------------------------------------------------------------- */

  function resultGearFitsCrew(item, crew) {
    return Number(crew.level) >= Number(item.required_level || 1)
      && (!item.required_role || item.required_role === crew.role);
  }

  /* The crew member this item most improves, and by how much.
   *
   * An empty slot counts. The previous version required a crew member to
   * already hold something in the slot before it would report anything, so a
   * player whose crew were carrying nothing -- exactly the player who most
   * needs the advice -- was told nothing at all about any drop.
   *
   * Deployed crew are excluded: gear-equip.php refuses a crew member in the
   * field, so offering their name on a button that cannot work is worse than
   * offering no button. */
  function bestEquipTarget(item) {
    if (!item || !item.slot || !state.data) return null;
    var score = gearPower(item);
    var best = null;
    (state.data.crew || []).forEach(function (crew) {
      if (!resultGearFitsCrew(item, crew)) return;
      if (crewAvailability(crew) !== 'available') return;
      var current = crew.gear && crew.gear[item.slot] ? crew.gear[item.slot] : null;
      var gain = score - gearPower(current);
      if (gain <= 0) return;
      /* An empty slot wins ties against an equal-power replacement: filling a
       * gap is worth more than swapping like for like. */
      var rank = gain + (current ? 0 : 0.5);
      if (!best || rank > best.rank) best = { crew: crew, current: current, gain: gain, rank: rank };
    });
    return best;
  }

  /* "+1 STR" says nothing about whether it is an improvement. This states the
   * move: what the target has now and what they would have. */
  function gearDeltaText(item, target) {
    if (!target) return '';
    if (!target.current) return 'Fills an empty ' + slotLabel(item.slot).toLowerCase() + ' slot for ' + target.crew.name + '.';
    return 'Replaces ' + target.current.name + ' on ' + target.crew.name + '.';
  }

  var TIER_ORDER = { common: 1, uncommon: 2, rare: 3, legendary: 4 };

  /* Identical drops are one row with a count. Three separate rows for three
   * copies of the same item buries the one genuinely different thing in a
   * haul. Keyed by loot definition id, which is what every action here sends. */
  function stackLoot(items) {
    var order = [];
    var byId = {};
    items.forEach(function (item) {
      var key = String(item.id);
      if (!byId[key]) { byId[key] = { item: item, count: 0, upgraded: 0 }; order.push(key); }
      byId[key].count++;
      if (item.upgraded) byId[key].upgraded++;
    });
    return order.map(function (key) { return byId[key]; });
  }

  function resultLootRow(entry) {
    var item = entry.item;
    var target = bestEquipTarget(item);
    var bonus = gearBonusText(item.bonus);
    var meta = [slotLabel(item.slot), item.tier];
    if (entry.upgraded) meta.push(entry.upgraded === entry.count ? 'upgraded' : entry.upgraded + ' upgraded');
    var advice = target
      ? '<span class="mission-result-gear-upgrade is-' + (target.current ? 'crew' : 'empty') + '">'
        + escapeHtml(gearDeltaText(item, target)) + '</span>'
      : '<span class="mission-result-gear-upgrade is-none">No crew member is improved by this.</span>';
    /* Equip is the primary action and Destroy a quiet one beside it. They were
     * the other way round -- Destroy was the only action and the loudest thing
     * on the card, on a screen whose whole purpose is a reward. */
    /* The button says only "Equip": the advice line directly above it already
     * names the crew member, and repeating a long name inside the button
     * squeezed the copy column until that advice clipped. The name stays in the
     * accessible label, where it is not competing for width. */
    var equip = target
      ? '<button type="button" class="btn btn-solid mission-result-equip" data-loot-equip="' + Number(item.id) + '" data-equip-crew="' + Number(target.crew.id) + '"'
        + ' aria-label="' + escapeHtml('Equip ' + item.name + ' on ' + target.crew.name) + '">Equip</button>'
      : '';
    return '<li class="mission-result-gear is-' + escapeHtml(item.tier) + '" data-loot-definition-id="' + Number(item.id) + '">'
      + '<span class="mission-result-gear-icon">' + gearIconHtml(item.slot, item.icon_url) + '</span>'
      + '<span class="mission-result-gear-copy"><strong>' + escapeHtml(item.name)
      + (entry.count > 1 ? '<b class="mission-result-gear-count">&times;' + entry.count + '</b>' : '') + '</strong>'
      + '<small>' + escapeHtml(meta.join(' · ')) + '</small>'
      + (bonus ? '<b>' + escapeHtml(bonus) + '</b>' : '<b class="is-neutral">No stat bonus</b>')
      + advice
      + '<i class="mission-result-gear-status" role="status" aria-live="polite"></i></span>'
      + '<span class="mission-result-gear-actions">' + equip
      + '<button type="button" class="mission-result-destroy" data-gear-destroy="' + Number(item.id) + '">Destroy</button></span></li>';
  }

  /* The rarest thing recovered, promoted above the list. A haul of four reads
   * as four identical lines otherwise, whatever was actually in it. */
  function bestFindMarkup(entries) {
    if (entries.length < 2) return '';
    var best = entries.slice().sort(function (a, b) {
      return (TIER_ORDER[b.item.tier] || 0) - (TIER_ORDER[a.item.tier] || 0) || gearPower(b.item) - gearPower(a.item);
    })[0];
    if (!best || (TIER_ORDER[best.item.tier] || 0) < 2) return '';
    return '<p class="mission-result-bestfind is-' + escapeHtml(best.item.tier) + '">'
      + '<span>Best find</span><strong>' + escapeHtml(best.item.name) + '</strong>'
      + '<em>' + escapeHtml(best.item.tier) + '</em></p>';
  }

  /* The roll, against the odds it was rolled against. Shown whether it was won
   * or lost: a loss at 90% is bad luck and a win at 40% is an escape, and
   * neither reads that way from the verdict alone. */
  function rollMarkup(result) {
    var chance = Number(result.success_percent);
    if (!isFinite(chance)) return '';
    if (result.roll_percent === null || result.roll_percent === undefined) {
      return '<p class="mission-result-roll is-certain"><span>Outcome</span>'
        + '<strong>Guaranteed</strong><em>This operation carried no risk of failure.</em></p>';
    }
    var roll = Number(result.roll_percent);
    var won = result.succeeded !== false;
    var margin = Math.abs(chance - roll);
    var flavour = won
      ? (margin <= 5 ? 'A narrow success.' : 'Comfortably inside the odds.')
      : (margin <= 5 ? 'Missed by a fraction.' : 'Well outside the odds.');
    return '<p class="mission-result-roll ' + (won ? 'is-won' : 'is-lost') + '">'
      + '<span>Roll</span><strong>' + fmt(roll) + ' against ' + fmt(chance) + '%</strong>'
      + '<em>' + escapeHtml(flavour) + '</em>'
      + '<span class="mission-result-roll-track" aria-hidden="true">'
      + '<i class="mission-result-roll-fill" style="width:' + Math.max(0, Math.min(100, chance)) + '%"></i>'
      + '<i class="mission-result-roll-marker" style="left:' + Math.max(0, Math.min(100, roll)) + '%"></i></span></p>';
  }

  /* On a loss, what would have moved the number. The three levers that
   * actually exist, reported only when they applied -- a debrief that lists
   * advice the player already followed teaches nothing. */
  function failureFactorsMarkup(result) {
    var factors = [];
    var affinity = result.affinity;
    if (affinity && affinity.penalty) {
      factors.push('No ' + (affinity.preferred_roles || []).join(' or ') + ' was assigned, costing '
        + fmt(affinity.penalty_success_percent) + '% of the odds.');
    }
    if (result.weather && result.weather.active && result.weather.storm) {
      factors.push(result.weather.condition + ' cost this run part of its chance of success.');
    }
    var bonus = Number(result.success_bonus_percent) || 0;
    factors.push(bonus > 0
      ? 'Your crew added +' + fmt(bonus) + '% from Strength and specialism. More Strength, or better equipment carrying it, raises this further.'
      : 'Your crew added nothing to the odds. Strength raises them by 0.5% a point, from levels and from equipment.');
    return '<div class="mission-result-block is-factors"><h4>What would have helped</h4><ul class="mission-result-factors">'
      + factors.map(function (line) { return '<li>' + escapeHtml(line) + '</li>'; }).join('') + '</ul></div>';
  }

  /* What the run did to the crew: how close each one now is to their next
   * level, and how long before they can go out again. Both were invisible --
   * a promotion was listed but a near miss was not, and fatigue was never
   * mentioned anywhere in the debrief at all. */
  function crewAftermathMarkup(result) {
    var crew = result.crew_results || [];
    if (!crew.length) return '';
    var rows = crew.map(function (member) {
      var atCeiling = !Number(member.xp_for_next_level);
      var xpLine = atCeiling
        ? 'Level ' + member.level + ' — fully trained'
        : member.xp_into_level + ' / ' + member.xp_for_next_level + ' XP to level ' + (Number(member.level) + 1);
      var rest = '';
      if (member.fatigue_ready) {
        rest = Number(member.fatigue_recovery_seconds) > 0
          ? '<span class="mission-result-crew-rest">' + escapeHtml(member.fatigue + ' / ' + member.fatigue_max + ' fatigue · full in ')
            + '<span class="mission-fatigue-countdown" data-ready-at="' + (Date.now() + Number(member.fatigue_recovery_seconds) * 1000) + '">…</span></span>'
          : '<span class="mission-result-crew-rest is-rested">' + escapeHtml(member.fatigue + ' / ' + member.fatigue_max + ' fatigue · rested') + '</span>';
      }
      return '<li class="mission-result-crew' + (member.levelled_up ? ' is-promoted' : '') + '">'
        + '<span class="mission-result-crew-head"><strong>' + escapeHtml(member.name) + '</strong>'
        + (member.levelled_up
          ? '<em class="mission-result-crew-promo">' + escapeHtml(member.levels_gained > 1 ? '+' + member.levels_gained + ' levels · now ' + member.level : 'Promoted to level ' + member.level) + '</em>'
          : '<em>' + escapeHtml(member.role + ' · Level ' + member.level) + '</em>') + '</span>'
        + '<span class="mission-result-crew-xp"><i style="width:' + Math.max(0, Math.min(100, Number(member.xp_percent) || 0)) + '%"></i></span>'
        + '<span class="mission-result-crew-meta">' + escapeHtml(xpLine) + rest + '</span></li>';
    }).join('');
    return '<div class="mission-result-block is-crew"><h4>Crew</h4><ul class="mission-result-crewlist">' + rows + '</ul></div>';
  }

  function showResult(result) {
    if (!resultModal || !resultBody) return;
    state.resultRerun = result.mission_id && (result.crew_ids || []).length
      ? { missionId: Number(result.mission_id), crewIds: (result.crew_ids || []).map(Number) }
      : null;
    var failed = result.succeeded === false;
    resultInner.classList.toggle('is-failed', failed);
    resultInner.classList.toggle('is-success', !failed);
    var title = failed ? 'Mission failed' : 'Mission complete';
    var lead = failed
      ? 'Your crew is back at command with nothing recovered, and this run does not count towards a campaign unlock.'
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

    /* What the crew's specialism was worth here. Reported rather than left to be
     * inferred: the figures above are the adjusted ones, and a player never sees
     * the contract's own baseline again once the crew is out. */
    var affinity = result.affinity;
    var affinityNote = '';
    if (affinity && affinity.type) {
      if (affinity.penalty) {
        affinityNote = 'No ' + (affinity.preferred_roles || []).join(' or ') + ' was assigned to this ' + affinity.type
          + ' operation, so it ran ' + fmt(affinity.penalty_duration_percent) + '% long at ' + fmt(affinity.penalty_success_percent) + '% worse odds.';
      } else if (affinity.matched_count > 0) {
        var gains = [
          { value: affinity.credit_percent, label: 'credits' },
          { value: affinity.xp_percent, label: 'XP' },
          { value: affinity.reputation_percent, label: 'reputation' },
          { value: affinity.duration_percent, label: 'off the clock' },
          { value: affinity.success_percent, label: 'success' },
          { value: affinity.upgrade_percent, label: 'loot quality' }
        ].filter(function (gain) { return Number(gain.value) > 0; })
          .map(function (gain) { return '+' + fmt(gain.value) + '% ' + gain.label; });
        affinityNote = affinity.matched_count + ' specialist' + (affinity.matched_count === 1 ? '' : 's')
          + ' suited to ' + affinity.type + ' work' + (gains.length ? ': ' + gains.join(', ') : '') + '.';
      }
    }
    /* The conditions this run was actually resolved against -- the ones recorded
     * when the crew launched, which on a long operation may not be today's. */
    var weather = result.weather;
    var weatherLine = weather && weather.active && (weather.severe || weather.storm)
      ? '<p class="mission-result-affinity is-weather">' + escapeHtml(weather.condition
          + (weather.storm
            ? ' interfered with the operation’s odds and the quality of what came back.'
            : ' held the crew up on the way out.')) + '</p>'
      : '';

    /* Its own paragraph above the stat grid, not inside it -- that container is
     * a grid and a stray paragraph would be laid out as one of its cells. */
    var affinityLine = affinityNote
      ? '<p class="mission-result-affinity' + (affinity.penalty ? ' is-warning' : '') + '">' + escapeHtml(affinityNote) + '</p>'
      : '';

    var extras = '';
    /* A character award is the rarest thing a mission can produce, so it leads
     * the extras rather than sitting under the item list. A roll that hit a
     * character the player already has is reported too -- saying nothing would
     * read as the table having failed. */
    if (!failed && result.crew_recruited && result.crew_recruited.length) {
      extras += '<div class="mission-result-block is-recruit"><h4>' + (result.crew_recruited.length === 1 ? 'New crew member' : 'New crew members') + '</h4><ul class="mission-result-recruits">'
        + result.crew_recruited.map(function (member) {
          return '<li><span>' + escapeHtml(member.name) + '</span><em>' + escapeHtml(member.role) + '</em></li>';
        }).join('') + '</ul></div>';
    }
    if (!failed && result.crew_duplicates && result.crew_duplicates.length) {
      var duplicateNames = result.crew_duplicates.map(function (member) { return member.name; }).join(', ');
      extras += '<p class="mission-result-note">' + escapeHtml(duplicateNames) + (result.crew_duplicates.length === 1 ? ' was' : ' were')
        + ' already on your roster, so nothing was added.</p>';
    }
    if (!failed && result.crew_capacity_offers && result.crew_capacity_offers.length) {
      extras += '<div class="mission-result-block is-recruit"><h4>Crew berth required</h4>'
        + result.crew_capacity_offers.map(function (offer) { return crewOfferMarkup(offer); }).join('') + '</div>';
    }
    var recoveredSalvage = !failed && result.loot ? result.loot.filter(function (item) { return !item.slot; }) : [];
    if (recoveredSalvage.length) {
      extras += '<div class="mission-result-block"><h4>Recovered</h4><ul class="mission-result-loot">'
        + stackLoot(recoveredSalvage).map(function (entry) {
          return '<li class="is-' + escapeHtml(entry.item.tier) + '"><span>' + escapeHtml(entry.item.name)
            + (entry.count > 1 ? ' <b class="mission-result-gear-count">&times;' + entry.count + '</b>' : '') + '</span>'
            + '<em>' + escapeHtml(entry.item.tier) + (entry.upgraded ? ' · upgraded' : '') + '</em></li>';
        }).join('') + '</ul></div>';
    }
    if (!failed && result.loot && result.loot.length) {
      var recoveredGear = stackLoot(result.loot.filter(function (item) { return !!item.slot; }));
      if (recoveredGear.length) {
        extras += '<div class="mission-result-block is-loot">' + bestFindMarkup(recoveredGear)
          + '<h4>Equipment recovered</h4><ul class="mission-result-loot is-gear">'
          + recoveredGear.map(resultLootRow).join('') + '</ul></div>';
      }
    }
    extras += crewAftermathMarkup(result);
    if (failed) extras += failureFactorsMarkup(result);

    resultBody.innerHTML = '<span class="eyebrow">' + (failed ? 'Debrief · loss' : 'Debrief · recovery') + '</span>'
      + '<h2 id="mission-result-title">' + escapeHtml(title) + '</h2>'
      + '<p class="mission-result-mission">' + escapeHtml(result.mission_name || '') + '</p>'
      + '<p class="mission-result-lead">' + escapeHtml(lead) + '</p>'
      + rollMarkup(result)
      + affinityLine + weatherLine
      + '<div class="mission-result-grid">' + grid + '</div>' + extras;

    /* The reveal order. Each block carries its own index so the CSS can stagger
     * them; the whole effect is skipped under prefers-reduced-motion, where the
     * blocks are simply present from the first frame. */
    Array.prototype.forEach.call(resultBody.children, function (node, index) {
      node.style.setProperty('--reveal-index', Math.min(index, 9));
    });
    if (resultRerun) {
      resultRerun.hidden = !state.resultRerun;
      resultRerun.disabled = false;
      resultRerun.classList.remove('is-busy');
      resultRerun.textContent = failed ? 'Try again with the same crew' : 'Run again with the same crew';
    }
    tickCountdowns();
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
    if (result.crew_recruited && result.crew_recruited.length) {
      parts.push('recruited ' + result.crew_recruited.map(function (member) { return member.name; }).join(', '));
    }
    if (result.crew_capacity_offers && result.crew_capacity_offers.length) {
      parts.push(result.crew_capacity_offers.map(function (offer) { return offer.name + ' is awaiting a crew berth'; }).join(', '));
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
    /* "3 minutes until recovered", counted down in place. When it reaches zero
     * the launch list is redrawn rather than only re-enabled, because the row's
     * affinity tag and ordering both change once the crew member is eligible. */
    document.querySelectorAll('.mission-fatigue-countdown[data-ready-at]').forEach(function (element) {
      var readyAt = Number(element.getAttribute('data-ready-at'));
      if (!readyAt) return;
      var remaining = Math.max(0, Math.ceil((readyAt - Date.now()) / 1000));
      element.textContent = remaining > 0 ? formatDuration(remaining) + ' until recovered' : 'Recovered';
      if (remaining === 0 && state.launchMission) renderLaunchCrew();
    });
    updateMissionRouteProgress();
    tickDailyReset();
  }

  function tickCommandFeed() {
    if (!state.data) return;
    var slot = Math.floor((Date.now() + state.serverOffset) / 12000);
    if (slot !== state.feedSlot) { state.feedSlot = slot; renderCommandFeed(state.data); }
  }

  function load() {
    if (!window.PW_AUTH || !window.PW_AUTH.loggedIn) { gate.hidden = false; content.hidden = true; return Promise.resolve(); }
    gate.hidden = true; content.hidden = false; setStatus('');
    /* Returns its promise: a loadout change has to wait for the reloaded stat
     * totals before it redraws, since the server owns those figures. */
    return fetch('/api/missions/overview.php', { credentials: 'same-origin' }).then(function (response) { return response.json(); }).then(function (data) { if (!data.ok) throw new Error(data.error || 'Mission command is unavailable.'); state.loadedAt = Date.now(); render(data); }).catch(function (error) { activeList.innerHTML = '<p class="missions-empty">' + escapeHtml(error.message || 'Mission command is unavailable.') + '</p>'; });
  }

  /* ----------------------------------------------------------------------
   * Operating conditions.
   *
   * Neoh's weather is generated once on the server, from the same profile and
   * the same deterministic generator that draws the World Record's own forecast
   * card -- this only renders today's reading and states what it costs. The
   * icon paths are the five from js/world-detail.js, hand-duplicated per this
   * codebase's no-shared-module convention so the storm on this page is
   * unmistakably the storm on that one.
   * -------------------------------------------------------------------- */
  function weatherIconHtml(icon) {
    var paths = {
      'acid-rain': '<path d="M13 35h34a10 10 0 0 0 1-20 16 16 0 0 0-30-3 12 12 0 0 0-5 23z"/><path d="m20 43-4 9m15-9-4 9m15-9-4 9"/><path d="M18 55h22"/>',
      storm: '<path d="M13 34h34a10 10 0 0 0 1-20 16 16 0 0 0-30-3 12 12 0 0 0-5 23z"/><path d="m34 38-8 11h7l-4 10 13-15h-8z"/>',
      smog: '<path d="M13 31h34a10 10 0 0 0 1-20 16 16 0 0 0-30-3 12 12 0 0 0-5 23z"/><path d="M10 40h36M18 47h34M8 54h31"/>',
      clear: '<circle cx="32" cy="32" r="11"/><path d="M32 8v8m0 32v8M8 32h8m32 0h8M15 15l6 6m22 22 6 6m0-34-6 6M21 43l-6 6"/>',
      overcast: '<path d="M13 37h34a10 10 0 0 0 1-20 16 16 0 0 0-30-3 12 12 0 0 0-5 23z"/>'
    };
    return '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (paths[icon] || paths.overcast) + '</svg>';
  }

  function weatherEffectLines(effects) {
    if (!effects) return [];
    var lines = [];
    if (effects.duration_percent > 0) {
      lines.push({ tone: 'is-slow', text: '+' + fmt(effects.duration_percent) + '% operation time',
        tip: 'Severe conditions slow every operation launched in them. Applied when the crew leaves, so the countdown you watch is the real one.' });
    }
    if (effects.success_percent > 0 || effects.upgrade_percent > 0) {
      var parts = [];
      if (effects.success_percent > 0) parts.push('−' + fmt(effects.success_percent) + '% success');
      if (effects.upgrade_percent > 0) parts.push('−' + fmt(effects.upgrade_percent) + '% loot quality');
      lines.push({ tone: 'is-luck', text: parts.join(' · '),
        tip: 'A static storm interferes with both of an operation’s chance rolls: whether it succeeds at all, and whether a recovered item is promoted a rarity tier.' });
    }
    return lines;
  }

  /* The card in the left rail. Hidden entirely rather than shown empty when the
   * world has no weather to read -- a locked world, a disabled profile, or a
   * database without the weather tables. */
  function renderWeather(data) {
    if (!weatherCard) return;
    var weather = data.weather;
    if (!weather) { weatherCard.hidden = true; weatherCard.innerHTML = ''; return; }
    var effects = weather.effects || {};
    var lines = weatherEffectLines(effects);
    var world = (data.world && data.world.name) || 'Neoh';
    weatherCard.hidden = false;
    weatherCard.className = 'mission-weather-card is-' + escapeHtml(weather.icon) + (weather.severe ? ' is-severe' : '');
    weatherCard.innerHTML = '<span class="eyebrow">Operating conditions</span>'
      + '<div class="mission-weather-head"><span class="mission-weather-icon">' + weatherIconHtml(weather.icon) + '</span>'
      + '<span class="mission-weather-read"><strong>' + escapeHtml(weather.condition) + '</strong>'
      + '<small>' + escapeHtml(world) + ' · ' + weather.temperature_c + '°C · ' + weather.wind_kph + ' kph</small></span></div>'
      + (weather.severe && weather.severity_label
        ? '<p class="mission-weather-severity">' + escapeHtml(weather.severity_label) + '</p>' : '')
      + (lines.length
        ? '<ul class="mission-weather-effects">' + lines.map(function (line) {
            return '<li class="' + line.tone + '" tabindex="0" title="' + escapeHtml(line.tip) + '">' + escapeHtml(line.text) + '</li>';
          }).join('') + '</ul>'
        : '<p class="mission-weather-clear">Conditions are not affecting operations.</p>')
      + (weather.hazard_note ? '<p class="mission-weather-hazard">' + escapeHtml(weather.hazard_note) + '</p>' : '')
      + '<a class="mission-weather-link" href="world.html?slug=neoh">Full forecast</a>';
  }

  /* ----------------------------------------------------------------------
   * Role affinity, as the launch screen shows it.
   *
   * The matrix itself is never written here: api/missions/overview.php sends
   * affinity_rules, so which role suits which operation type -- and what each
   * match is worth -- has exactly one definition, on the server that enforces
   * it. This code only reads those rules and displays the result.
   * -------------------------------------------------------------------- */
  function affinityRule(missionType) {
    var rules = (state.data && state.data.affinity_rules) || {};
    return rules[String(missionType || '').trim().toLowerCase()] || null;
  }
  function affinityFor(missionType, role) {
    var rule = affinityRule(missionType);
    return rule && rule.preferred && rule.preferred[role] ? rule.preferred[role] : null;
  }

  /* What a given selection would produce, mirroring pw_missions_crew_effects()
   * and pw_missions_effective_duration()/_success(). Deliberately computed from
   * the same per-level and per-point rates already published in ROLE_INFO and
   * STAT_INFO above rather than by summing the server's per-member role_effect
   * objects: those are each individually rounded and floored, so summing them
   * would quietly under-report a flat reputation bonus. This is a projection --
   * the server recomputes every figure at launch and again at claim, and is the
   * only thing that decides an outcome. */
  function projectLaunch(mission, crew) {
    var rule = affinityRule(mission.mission_type);
    var affinity = { credit_percent: 0, xp_percent: 0, reputation_percent: 0, duration_percent: 0, upgrade_percent: 0, success_percent: 0 };
    var matched = 0;
    var totals = { strength: 0, cunning: 0, science: 0, charisma: 0 };
    var durationPercent = 0, xpPercent = 0, reputationFlat = 0;

    crew.forEach(function (member) {
      var level = Math.max(0, Math.min(Number(member.max_level) || 50, Number(member.level) || 0));
      Object.keys(totals).forEach(function (stat) { totals[stat] += Math.max(0, Number(member[stat]) || 0); });
      if (member.role === 'Engineer') durationPercent += level * 0.05;
      if (member.role === 'Pathfinder') xpPercent += level * 0.10;
      if (member.role === 'Vanguard') reputationFlat += level * 0.05;
      var match = rule && rule.preferred ? rule.preferred[member.role] : null;
      if (match) { affinity[match.effect] += Number(match.percent) || 0; matched++; }
    });

    var penalty = !!(rule && crew.length && matched === 0);
    /* Today's conditions apply to every operation, so they join the affinity
     * penalty in the same two pools the server adds them to -- one slowdown
     * figure and one success figure, whatever produced them. */
    var conditions = (state.data && state.data.weather && state.data.weather.effects) || null;
    var weatherDuration = conditions ? Number(conditions.duration_percent) || 0 : 0;
    var weatherSuccess = conditions ? Number(conditions.success_percent) || 0 : 0;
    var weatherUpgrade = conditions ? Number(conditions.upgrade_percent) || 0 : 0;
    var research = (state.data && state.data.research && state.data.research.effects) || {};
    var researchSpeed = Number(research.mission_speed_percent) || 0;
    var researchXp = Number(research.xp_percent) || 0;
    var researchReputation = Number(research.reputation_percent) || 0;
    var researchLuck = Number(research.luck_percent) || 0;
    var researchCredits = Number(research.credit_percent) || 0;
    var penaltyDuration = (penalty ? Number(rule.penalty.duration_percent) || 0 : 0) + weatherDuration;
    var penaltySuccess = (penalty ? Number(rule.penalty.success_percent) || 0 : 0) + weatherSuccess;
    durationPercent = Math.min(90, durationPercent + affinity.duration_percent + researchSpeed);
    xpPercent += (totals.charisma * 0.5) + affinity.xp_percent + researchXp;

    var baseSeconds = Number(mission.duration_seconds) || 0;
    var seconds = Math.round(baseSeconds * (1 - (durationPercent / 100)) * (1 + (penaltyDuration / 100)));
    var baseSuccess = isFinite(Number(mission.base_success_percent)) ? Number(mission.base_success_percent) : 100;

    return {
      crew: crew,
      matched: matched,
      penalty: penalty,
      affinity: affinity,
      weather: conditions,
      penalty_duration_percent: penaltyDuration,
      penalty_success_percent: penaltySuccess,
      duration_seconds: Math.max(30, Math.min(Math.round(baseSeconds * (1 + (penaltyDuration / 100))), seconds)),
      base_duration_seconds: baseSeconds,
      success_percent: Math.max(5, Math.min(100, Math.round(baseSuccess + (totals.strength * 0.5) + affinity.success_percent - penaltySuccess))),
      base_success_percent: baseSuccess,
      credits: Math.round((Number(mission.credit_reward) || 0) * (1 + ((affinity.credit_percent + researchCredits) / 100))),
      base_credits: Number(mission.credit_reward) || 0,
      reputation: Math.round((Number(mission.reputation_reward) || 0) * (1 + ((affinity.reputation_percent + researchReputation) / 100))) + Math.floor(reputationFlat),
      base_reputation: Number(mission.reputation_reward) || 0,
      xp: Math.round((Number(mission.xp_reward) || 0) * (1 + (xpPercent / 100))),
      base_xp: Number(mission.xp_reward) || 0,
      loot_percent: totals.cunning * 1.0,
      upgrade_percent: Math.min(95, Math.max(0, Math.min(95, (totals.science * 1.5) + affinity.upgrade_percent) - weatherUpgrade) + researchLuck)
    };
  }

  /* One projection row. The base figure is shown alongside the projected one
   * whenever they differ, since "+340 credits" says nothing about whether this
   * crew improved on the contract or cost you part of it. */
  function projectionRow(label, base, value, formatValue, higherIsBetter, preview) {
    var changed = value !== base;
    var better = higherIsBetter ? value > base : value < base;
    var tone = !changed ? '' : better ? ' is-better' : ' is-worse';
    /* When a crew member is being previewed into a slot, the cell shows where
     * that figure would land instead of the contract baseline -- "what does
     * adding them do" is the question being asked, and the baseline is already
     * legible from the committed value beside it. */
    var previewMarkup = '';
    if (preview != null && preview !== value) {
      var previewBetter = higherIsBetter ? preview > value : preview < value;
      previewMarkup = '<span class="mission-projection-preview ' + (previewBetter ? 'is-better' : 'is-worse') + '">'
        + '&rarr; ' + escapeHtml(formatValue(preview)) + '</span>';
    }
    return '<div class="mission-projection-cell' + tone + (previewMarkup ? ' has-preview' : '') + '"><dt>' + escapeHtml(label) + '</dt><dd>'
      + (changed ? '<s>' + escapeHtml(formatValue(base)) + '</s> ' : '')
      + '<strong>' + escapeHtml(formatValue(value)) + '</strong>' + previewMarkup + '</dd></div>';
  }

  function renderLaunchProjection(projection, preview) {
    var mission = state.launchMission;
    if (!launchProjection || !mission) return;
    if (!projection.crew.length && !preview) {
      launchProjection.innerHTML = '<p class="mission-projection-empty">Assign a crew to project this operation.</p>';
      return;
    }
    /* An empty rack with a hover preview still has figures worth showing: the
     * preview becomes the whole answer rather than a delta from nothing. */
    if (!projection.crew.length && preview) projection = preview;
    var peek = function (field) { return preview ? preview[field] : null; };
    var clock = function (seconds) { return formatDuration(seconds); };
    var pct = function (value) { return value + '%'; };
    var rows = projectionRow('Time', projection.base_duration_seconds, projection.duration_seconds, clock, false, peek('duration_seconds'))
      + projectionRow('Success', projection.base_success_percent, projection.success_percent, pct, true, peek('success_percent'))
      + projectionRow('XP each', projection.base_xp, projection.xp, function (v) { return '+' + v; }, true, peek('xp'))
      + projectionRow('Reputation', projection.base_reputation, projection.reputation, function (v) { return v ? '+' + v : '—'; }, true, peek('reputation'))
      + (projection.base_credits > 0 ? projectionRow('Credits', projection.base_credits, projection.credits, function (v) { return '+' + credits(v); }, true, peek('credits')) : '');
    var note = projection.penalty
      ? 'No ' + (affinityRule(mission.mission_type).preferred ? Object.keys(affinityRule(mission.mission_type).preferred).join(' or ') : 'specialist')
        + ' assigned — this run takes ' + fmt(projection.penalty_duration_percent) + '% longer and is ' + fmt(projection.penalty_success_percent) + '% less likely to succeed.'
      : projection.matched > 0
        ? projection.matched + ' specialist' + (projection.matched === 1 ? '' : 's') + ' suited to this operation.'
        : '';
    /* Conditions get their own line rather than being folded into the affinity
     * note: one is a choice the player just made and the other is the weather,
     * and blaming a lost mission on the wrong one is worse than saying nothing. */
    var conditionLines = weatherEffectLines(projection.weather);
    var weatherNote = conditionLines.length && state.data.weather
      ? state.data.weather.condition + ' over Neoh: ' + conditionLines.map(function (line) { return line.text; }).join(', ') + '.'
      : '';
    launchProjection.innerHTML = '<dl class="mission-projection-grid">' + rows + '</dl>'
      + (note ? '<p class="mission-projection-note' + (projection.penalty ? ' is-warning' : ' is-good') + '">' + escapeHtml(note) + '</p>' : '')
      + (weatherNote ? '<p class="mission-projection-note is-weather">' + escapeHtml(weatherNote) + '</p>' : '')
      + '<p class="mission-projection-caveat">Projected from the crew you have chosen. Command confirms the final figures on return.</p>';
  }

  /* ----------------------------------------------------------------------
   * Crew selection: a slot rack plus a roster tray.
   *
   * The selection lives in state.launchSlots -- a fixed-length array of crew
   * ids or nulls, one entry per max_crew -- rather than in the checked state of
   * a list of checkboxes. That inversion is what makes the rest of this work:
   * the fatigue ticker can redraw the whole modal without losing the choice,
   * a slot can be previewed before it is committed, and a crew member has a
   * position rather than only a membership.
   *
   * Slots are role-HINTED, never role-typed. Affinity stacks -- each mission
   * type prefers two of the three roles and every matching crew member adds the
   * bonus again -- so two Vanguards on a recon is a legitimate choice, and the
   * penalty fires only when NEITHER preferred role is present. Every empty slot
   * therefore shows the same whole-mission hint. Labelling slots individually
   * would teach a one-of-each rule the game does not have.
   *
   * Nothing here decides anything: api/missions/start.php re-validates crew
   * count, ownership, availability and fatigue on its own.
   * -------------------------------------------------------------------- */

  function rackSize(mission) {
    return Math.max(1, Number(mission && mission.max_crew) || 1);
  }

  function memberById(id) {
    var list = (state.data && state.data.crew) || [];
    for (var i = 0; i < list.length; i++) if (Number(list[i].id) === Number(id)) return list[i];
    return null;
  }

  /* Why a crew member cannot take THIS operation, or null when they can. The
   * order matters: a withdrawn definition outranks deployment, which outranks
   * fatigue, because that is the order in which the reason stops being fixable
   * by waiting. */
  function launchEligibility(mission, member) {
    var availability = crewAvailability(member);
    if (availability === 'deployed') {
      return { kind: 'deployed', label: escapeHtml(member.active_mission_name || 'On mission')
        + ' · <span class="mission-countdown" data-completes-at="' + escapeHtml(member.active_mission_completes_at || '') + '">Calculating…</span>' };
    }
    if (availability !== 'available') return { kind: 'unavailable', label: 'Unavailable' };
    var cost = Number(mission.fatigue_cost) || 0;
    if (crewIsResting(member, cost)) {
      var readyAt = Date.now() + fatigueRecoverySeconds(member, cost) * 1000;
      return { kind: 'resting', label: 'Recovering · <span class="mission-fatigue-countdown" data-ready-at="'
        + escapeHtml(readyAt) + '">Calculating…</span>' };
    }
    return null;
  }

  /* ---- Equipment advice (idea 7) --------------------------------------
   * How many of this crew member's slots hold nothing, or hold something a
   * spare item in the inventory would beat. Reuses the loadout modal's own
   * bestLoadoutCandidate()/gearPower(), so "better" means exactly what the
   * Equip best action already means -- there is one definition of better gear
   * in this file, not two.
   *
   * Known imprecision, accepted: spare counts are global, so if one spare item
   * would improve two different crew members both are flagged. Equipping it on
   * one recomputes the other on the next load. Over-offering an upgrade is a
   * far cheaper error here than silently hiding one. */
  function gearUpgrades(crew) {
    /* Deployed crew are excluded because openLoadout() refuses them -- their
     * loadout is frozen in the field. Offering an upgrade whose button does
     * nothing is worse than not offering it. Resting crew are included: they
     * are available, so their equipment can still be changed. */
    if (!gearReady() || !crew || crewAvailability(crew) !== 'available') return { count: 0, empty: 0 };
    var count = 0, empty = 0;
    gearSlots().forEach(function (slot) {
      var current = crew.gear && crew.gear[slot.key] ? crew.gear[slot.key] : null;
      if (!current) empty++;
      var best = bestLoadoutCandidate(crew, slot.key);
      if (best && !best.equipped && gearPower(best.item) > gearPower(current)) count++;
    });
    return { count: count, empty: empty };
  }

  function gearWarningMarkup(crew, compact) {
    var upgrades = gearUpgrades(crew);
    if (!upgrades.count) return '';
    var text = upgrades.count + (upgrades.count === 1 ? ' slot' : ' slots') + ' can be upgraded';
    var hint = crew.name + ' has better equipment available in your inventory for '
      + upgrades.count + (upgrades.count === 1 ? ' slot' : ' slots')
      + (upgrades.empty ? ', including ' + upgrades.empty + ' still empty' : '') + '.';
    return '<button type="button" class="mission-gear-warning' + (compact ? ' is-compact' : '') + '" data-gear-warning="' + Number(crew.id) + '"'
      + ' title="' + escapeHtml(hint) + '" aria-label="' + escapeHtml(hint + ' Open loadout.') + '">'
      + '<span aria-hidden="true">▲</span>' + escapeHtml(compact ? String(upgrades.count) : text) + '</button>';
  }

  /* ---- Per-slot contribution (idea 10) ---------------------------------
   * What this one crew member adds, as opposed to what the team totals. Capped
   * at three lines: the affinity match, their role's per-level rate, and their
   * strongest stat. The full breakdown is the projection below the rack. */
  function slotContribution(mission, member) {
    var lines = [];
    var match = affinityFor(mission.mission_type, member.role);
    if (match) lines.push({ tone: 'is-affinity', text: match.label });
    var role = ROLE_INFO[member.role];
    var level = Math.max(0, Number(member.level) || 0);
    if (role && level > 0) lines.push({ tone: '', text: role.effect(level) });
    var best = null;
    ['strength', 'cunning', 'science', 'charisma'].forEach(function (key) {
      var value = Math.max(0, Number(member[key]) || 0);
      if (value > 0 && (!best || value > best.value)) best = { key: key, value: value };
    });
    if (best) lines.push({ tone: '', text: STAT_INFO[best.key].effect(best.value) });
    return lines.slice(0, 3);
  }

  /* ---- Rack state ------------------------------------------------------ */

  /* Sizes the rack to the mission and drops anyone who is no longer eligible.
   * Re-validated on every render rather than only at open: a crew member can be
   * sent out from another tab, and the fatigue ticker redraws this modal on its
   * own schedule. */
  function ensureRack(mission) {
    var size = rackSize(mission);
    var slots = state.launchSlots || [];
    var next = [];
    for (var i = 0; i < size; i++) {
      var id = slots[i] != null ? Number(slots[i]) : null;
      var member = id != null ? memberById(id) : null;
      next.push(member && !launchEligibility(mission, member) ? Number(member.id) : null);
    }
    state.launchSlots = next;
    return next;
  }

  function rackIds() {
    return (state.launchSlots || []).filter(function (id) { return id != null; }).map(Number);
  }

  function rackIndexOf(crewId) {
    return (state.launchSlots || []).indexOf(Number(crewId));
  }

  function firstEmptySlot() {
    return (state.launchSlots || []).indexOf(null);
  }

  /* Assigning a crew member who is already racked moves them rather than
   * duplicating them, and swaps with whoever occupied the target. Dragging one
   * slot onto another is a reorder, which the rack has to support because slot
   * order is visible even though the server treats the crew as a set. */
  function assignToSlot(index, crewId) {
    var slots = state.launchSlots || [];
    if (index < 0 || index >= slots.length) return;
    var from = rackIndexOf(crewId);
    var displaced = slots[index];
    slots[index] = Number(crewId);
    if (from !== -1 && from !== index) slots[from] = displaced != null ? displaced : null;
  }

  function clearSlot(index) {
    if (state.launchSlots && index >= 0 && index < state.launchSlots.length) state.launchSlots[index] = null;
  }

  /* ---- Tray ------------------------------------------------------------ */

  function trayCrew(mission) {
    var roster = (state.data && state.data.crew || []).slice();
    var role = launchFilterRole ? launchFilterRole.value : 'all';
    var openOnly = launchFilterOpen ? launchFilterOpen.checked : false;
    var sort = launchSort ? launchSort.value : 'suited';
    var filtered = roster.filter(function (member) {
      if (role !== 'all' && member.role !== role) return false;
      if (openOnly && launchEligibility(mission, member)) return false;
      return true;
    });
    return filtered.sort(function (a, b) {
      var aOpen = launchEligibility(mission, a) ? 1 : 0;
      var bOpen = launchEligibility(mission, b) ? 1 : 0;
      if (aOpen !== bOpen) return aOpen - bOpen;
      if (sort === 'level') return Number(b.level) - Number(a.level) || String(a.name).localeCompare(String(b.name));
      if (sort === 'name') return String(a.name).localeCompare(String(b.name));
      if (sort === 'fatigue') return crewFatigue(b) - crewFatigue(a) || String(a.name).localeCompare(String(b.name));
      var aMatch = affinityFor(mission.mission_type, a.role) ? 0 : 1;
      var bMatch = affinityFor(mission.mission_type, b.role) ? 0 : 1;
      if (aMatch !== bMatch) return aMatch - bMatch;
      if (Number(b.level) !== Number(a.level)) return Number(b.level) - Number(a.level);
      return String(a.name).localeCompare(String(b.name));
    });
  }

  function launchStatStrip(member) {
    return '<span class="mission-launch-crew-stats">' + ['strength', 'cunning', 'science', 'charisma'].map(function (key) {
      var info = STAT_INFO[key];
      var value = Math.max(0, Number(member[key]) || 0);
      return '<span class="mission-launch-stat is-' + key + '" title="' + escapeHtml(info.label + ' ' + value + ' — ' + info.effect(value)) + '">'
        + '<i>' + info.short + '</i>' + value + '</span>';
    }).join('') + '</span>';
  }

  function crewPortraitMarkup(member) {
    var portrait = safeImage(member.portrait_url);
    return portrait
      ? '<img src="' + escapeHtml(portrait) + '" alt="">'
      : '<span class="mission-launch-crew-fallback" aria-hidden="true">' + escapeHtml(String(member.name).charAt(0)) + '</span>';
  }

  function fatiguePill(member) {
    if (!fatigueReady(member)) return '';
    var max = Math.max(1, Number(member.fatigue_max) || 100);
    var value = crewFatigue(member);
    var percent = Math.max(0, Math.min(100, Math.round(value / max * 100)));
    return '<span class="mission-launch-crew-fatigue" title="' + escapeHtml(value + ' of ' + max + ' fatigue') + '">'
      + '<span style="width:' + percent + '%"></span></span>';
  }

  /* A tray card is a real <button>: assignment has to work on keyboard and on
   * touch, where there is no drag at all. Dragging is the enhancement layered
   * over it, never the only route. */
  function trayCardMarkup(mission, member) {
    var blocked = launchEligibility(mission, member);
    var racked = rackIndexOf(member.id) !== -1;
    var match = affinityFor(mission.mission_type, member.role);
    var tag = blocked
      ? '<span class="mission-launch-crew-tag is-' + escapeHtml(blocked.kind === 'resting' ? 'resting' : 'unavailable') + '">' + blocked.label + '</span>'
      : match
        ? '<span class="mission-launch-crew-tag is-affinity" title="' + escapeHtml(member.role + 's are suited to ' + String(mission.mission_type).toLowerCase() + ' work. Every one you assign adds this bonus again.') + '">' + escapeHtml(match.label) + '</span>'
        : '<span class="mission-launch-crew-tag is-neutral" title="' + escapeHtml('No affinity with this operation type. A crew carrying none of its preferred roles takes a time and success penalty.') + '">No affinity</span>';
    return '<div class="mission-launch-tray-card' + (blocked ? ' is-unavailable' : '') + (blocked && blocked.kind === 'resting' ? ' is-resting' : '')
      + (racked ? ' is-racked' : '') + (match && !blocked ? ' is-affinity' : '') + '"'
      + (blocked || racked ? '' : ' draggable="true"') + ' data-tray-crew="' + Number(member.id) + '">'
      + '<button type="button" class="mission-launch-tray-select" data-tray-select="' + Number(member.id) + '"'
      + (blocked ? ' disabled' : '') + ' aria-label="' + escapeHtml((racked ? 'Assigned. ' : '') + 'Assign ' + member.name + ', ' + member.role + ' level ' + member.level) + '">'
      + '<span class="mission-launch-crew-portrait">' + crewPortraitMarkup(member) + '</span>'
      + '<span class="mission-launch-crew-copy"><strong>' + escapeHtml(member.name) + (racked ? '<em>Assigned</em>' : '') + '</strong>'
      + '<small>' + escapeHtml(member.role) + ' · Level ' + member.level + '</small>'
      + launchStatStrip(member) + tag + fatiguePill(member) + '</span></button>'
      + gearWarningMarkup(member, false) + '</div>';
  }

  function renderTray() {
    var mission = state.launchMission;
    if (!mission || !launchCrew) return;
    var roster = trayCrew(mission);
    var openCount = roster.filter(function (member) { return !launchEligibility(mission, member); }).length;
    launchCrew.innerHTML = roster.length
      ? roster.map(function (member) { return trayCardMarkup(mission, member); }).join('')
      : '<p class="missions-empty">No crew members match these filters.</p>';
    if (launchTrayCount) {
      launchTrayCount.textContent = openCount + ' ready' + (roster.length !== openCount ? ' · ' + (roster.length - openCount) + ' unavailable' : '');
    }
    if (launchRecommend) launchRecommend.disabled = openCount === 0 || firstEmptySlot() === -1;
  }

  /* ---- Rack rendering -------------------------------------------------- */

  /* The same hint on every empty slot, for the reason set out at the top of
   * this section: affinity is a property of the team, not of a position. */
  function slotHintMarkup(mission) {
    var rule = affinityRule(mission.mission_type);
    if (!rule || !rule.preferred) return '<span class="mission-slot-hint">Any crew member</span>';
    return '<span class="mission-slot-hint">' + Object.keys(rule.preferred).map(function (role) {
      return '<span class="mission-slot-hint-role">' + escapeHtml(role) + ' <em>' + escapeHtml(rule.preferred[role].label) + '</em></span>';
    }).join('') + '</span>';
  }

  function slotMarkup(mission, index) {
    var id = (state.launchSlots || [])[index];
    var member = id != null ? memberById(id) : null;
    var required = index < Number(mission.min_crew);
    var classes = 'mission-launch-slot' + (member ? ' is-filled' : ' is-empty') + (required ? ' is-required' : ' is-optional');
    if (!member) {
      return '<div class="' + classes + '" data-slot-index="' + index + '">'
        + '<span class="mission-slot-badge">' + (required ? 'Required' : 'Optional') + '</span>'
        + '<span class="mission-slot-placeholder" aria-hidden="true">+</span>'
        + slotHintMarkup(mission)
        + '<span class="mission-slot-drop">Drop or select a crew member</span></div>';
    }
    var contribution = slotContribution(mission, member).map(function (line) {
      return '<span class="mission-slot-line ' + line.tone + '">' + escapeHtml(line.text) + '</span>';
    }).join('');
    return '<div class="' + classes + '" data-slot-index="' + index + '" draggable="true" data-slot-crew="' + Number(member.id) + '">'
      + '<span class="mission-slot-badge">' + (required ? 'Required' : 'Optional') + '</span>'
      + '<button type="button" class="mission-slot-clear" data-slot-clear="' + index + '" aria-label="' + escapeHtml('Remove ' + member.name + ' from this slot') + '" title="Remove from slot">&times;</button>'
      + '<span class="mission-slot-portrait">' + crewPortraitMarkup(member) + '</span>'
      + '<span class="mission-slot-copy"><strong>' + escapeHtml(member.name) + '</strong>'
      + '<small>' + escapeHtml(member.role) + ' · Level ' + member.level + '</small>'
      + '<span class="mission-slot-lines">' + contribution + '</span>'
      + gearWarningMarkup(member, true) + fatiguePill(member) + '</span></div>';
  }

  function renderRack() {
    var mission = state.launchMission;
    if (!mission || !launchRack) return;
    var slots = ensureRack(mission);
    var markup = '';
    for (var i = 0; i < slots.length; i++) markup += slotMarkup(mission, i);
    launchRack.innerHTML = markup;
    if (launchRackNote) {
      var rule = affinityRule(mission.mission_type);
      var chosen = rackIds();
      var matched = chosen.filter(function (id) {
        var member = memberById(id);
        return member && affinityFor(mission.mission_type, member.role);
      }).length;
      launchRackNote.textContent = !rule || !chosen.length ? ''
        : matched === 0
          ? 'No ' + Object.keys(rule.preferred).join(' or ') + ' assigned — this team takes the mismatch penalty.'
          : matched + ' specialist' + (matched === 1 ? '' : 's') + ' assigned. Each one adds its bonus again.';
      launchRackNote.className = 'mission-launch-rack-note' + (!rule || !chosen.length ? '' : matched === 0 ? ' is-warning' : ' is-good');
    }
  }

  /* ---- Projection, with speculative preview (idea 4) ------------------- */

  /* preview is {crewId, slotIndex} for "what would this crew member do here",
   * or null for the committed selection. Runs the real projectLaunch() over a
   * hypothetical rack rather than approximating, so the previewed numbers are
   * the numbers you get. */
  function projectionFor(mission, preview) {
    var ids = (state.launchSlots || []).slice();
    if (preview && preview.crewId != null) {
      var index = preview.slotIndex;
      if (index == null || index < 0 || index >= ids.length) {
        index = ids.indexOf(Number(preview.crewId));
        if (index === -1) index = ids.indexOf(null);
      }
      if (index !== -1) {
        var from = ids.indexOf(Number(preview.crewId));
        if (from !== -1 && from !== index) ids[from] = ids[index];
        ids[index] = Number(preview.crewId);
      }
    }
    var chosen = ids.filter(function (id) { return id != null; }).map(memberById).filter(Boolean);
    return projectLaunch(mission, chosen);
  }

  function updateLaunchState(preview) {
    var mission = state.launchMission;
    if (!mission) return;
    var chosenCount = rackIds().length;
    if (launchSlots) {
      launchSlots.textContent = chosenCount + ' of ' + mission.max_crew + ' chosen'
        + (chosenCount < mission.min_crew ? ' · ' + mission.min_crew + ' needed' : '');
      launchSlots.classList.toggle('is-ready', chosenCount >= mission.min_crew);
    }
    var committed = projectionFor(mission, null);
    state.launchProjection = committed;
    renderLaunchProjection(committed, preview ? projectionFor(mission, preview) : null);
    // Any change to the crew invalidates a mismatch the player already accepted.
    if (!preview) {
      state.launchPenaltyAck = false;
      launchConfirm.textContent = 'Launch Mission';
    }
    launchConfirm.disabled = chosenCount < mission.min_crew;
  }

  /* ---- Fill actions (ideas 8 and 9) ------------------------------------ */

  /* Fills only the empty slots, leaving deliberate choices alone. Overwriting
   * the whole rack is what this used to do, and it is almost never what is
   * wanted once one pick has been made by hand. */
  function recommendLaunchCrew() {
    var mission = state.launchMission;
    if (!mission) return;
    var taken = rackIds();
    var picks = trayCrew(mission).filter(function (member) {
      return !launchEligibility(mission, member) && taken.indexOf(Number(member.id)) === -1;
    });
    /* trayCrew() honours the player's chosen sort. Suitability is what this
     * action means, so it re-sorts by affinity then experience regardless. */
    picks.sort(function (a, b) {
      var aMatch = affinityFor(mission.mission_type, a.role) ? 0 : 1;
      var bMatch = affinityFor(mission.mission_type, b.role) ? 0 : 1;
      if (aMatch !== bMatch) return aMatch - bMatch;
      return Number(b.level) - Number(a.level) || String(a.name).localeCompare(String(b.name));
    });
    var slots = state.launchSlots || [];
    for (var i = 0; i < slots.length && picks.length; i++) {
      if (slots[i] == null) slots[i] = Number(picks.shift().id);
    }
    launchError.textContent = '';
    renderLaunchCrew();
  }

  /* Re-fields the team that last ran this operation, skipping anyone since
   * retired, deployed or too tired. A partial restore is deliberate: filling
   * three of four slots is more useful than refusing because one crew member is
   * out, and the empty slot is visible. */
  function repeatLastCrew() {
    var mission = state.launchMission;
    if (!mission) return;
    var ids = (mission.last_crew_ids || []).map(Number);
    var slots = state.launchSlots || [];
    var placed = 0, skipped = 0;
    for (var i = 0; i < slots.length; i++) slots[i] = null;
    ids.forEach(function (id) {
      var member = memberById(id);
      if (!member || launchEligibility(mission, member)) { skipped++; return; }
      var index = firstEmptySlot();
      if (index === -1) return;
      slots[index] = Number(member.id);
      placed++;
    });
    launchError.textContent = !placed
      ? 'None of the crew from the last run of this operation are available.'
      : skipped
        ? placed + ' of the last team re-assigned. ' + skipped + ' unavailable.'
        : '';
    renderLaunchCrew();
  }

  function openLaunch(missionId) {
    var mission = (state.data && state.data.missions || []).find(function (item) { return item.id === Number(missionId); });
    if (!mission) return;
    state.launchMission = mission; state.launchPenaltyAck = false; launchError.textContent = '';
    // A fresh rack per open. Carrying a selection between operations would put
    // crew into slots chosen against a different mission's affinity and cost.
    state.launchSlots = [];
    launchTitle.textContent = mission.name;
    launchCopy.textContent = 'Assign ' + mission.min_crew + (mission.max_crew !== mission.min_crew ? ' to ' + mission.max_crew : '') + ' crew member' + (mission.max_crew === 1 ? '' : 's') + ' to the slots below.';
    var rule = affinityRule(mission.mission_type);
    if (launchBrief) {
      launchBrief.innerHTML = rule
        ? '<span class="mission-launch-brief-label">' + escapeHtml(String(mission.mission_type).toUpperCase()) + ' prefers</span>'
          + Object.keys(rule.preferred).map(function (role) {
            return '<span class="mission-launch-brief-role">' + escapeHtml(role) + ' <em>' + escapeHtml(rule.preferred[role].label) + '</em></span>';
          }).join('')
          + '<span class="mission-launch-brief-penalty">Neither assigned: +' + fmt(rule.penalty.duration_percent) + '% time, \u2212' + fmt(rule.penalty.success_percent) + '% success</span>'
        : '';
      launchBrief.hidden = !rule;
    }
    if (launchRepeat) {
      var previous = (mission.last_crew_ids || []).length;
      launchRepeat.hidden = !previous;
      launchRepeat.textContent = 'Repeat last crew' + (previous ? ' (' + previous + ')' : '');
    }
    if (launchModal) launchModal.classList.toggle('is-single-slot', rackSize(mission) === 1);
    renderLaunchCrew();
    tickCountdowns();
    if (typeof launchModal.showModal === 'function') launchModal.showModal(); else launchModal.setAttribute('open', '');
  }

  /* One redraw for the whole picker. Named as it was because the fatigue ticker
   * calls it the moment a resting crew member becomes eligible; unlike the old
   * checkbox version it no longer has to preserve the selection by hand, since
   * the selection lives in state.launchSlots rather than in the DOM. */
  function renderLaunchCrew() {
    if (!state.launchMission) return;
    renderRack();
    renderTray();
    updateLaunchState(null);
  }

  function closeLaunch() {
    if (launchModal.open && typeof launchModal.close === 'function') launchModal.close(); else launchModal.removeAttribute('open');
    state.launchMission = null; state.launchProjection = null; state.launchPenaltyAck = false;
    launchConfirm.textContent = 'Launch Mission';
  }

  definitionList.addEventListener('click', function (event) { var button = event.target.closest('.mission-launch-btn'); if (button && !button.disabled) openLaunch(button.getAttribute('data-mission-id')); });
  /* ---- Crew selection input ---------------------------------------------
   * Three routes to the same state, in this order of priority:
   *   click/keyboard  -- the primary one, and the only one that works on touch
   *                      and for a keyboard user. Every tray card and every
   *                      slot control is a real <button>.
   *   drag and drop   -- an enhancement over the top, never the only route.
   *   the fill actions above.
   * ---------------------------------------------------------------------- */

  function assignFromTray(crewId) {
    var mission = state.launchMission;
    if (!mission) return;
    var member = memberById(crewId);
    if (!member || launchEligibility(mission, member)) return;
    if (rackIndexOf(crewId) !== -1) { clearSlot(rackIndexOf(crewId)); renderLaunchCrew(); return; }
    var index = firstEmptySlot();
    if (index === -1) {
      /* Full rack. Refusing silently reads as a broken button, so say why --
       * the alternative, evicting an arbitrary slot, throws away a choice the
       * player made deliberately. */
      launchError.textContent = 'Every slot is filled. Remove a crew member first, or drop this one onto a slot to swap.';
      return;
    }
    launchError.textContent = '';
    assignToSlot(index, crewId);
    renderLaunchCrew();
  }

  launchCrew.addEventListener('click', function (event) {
    var warning = event.target.closest('[data-gear-warning]');
    if (warning) { openLoadout(warning.getAttribute('data-gear-warning')); return; }
    var select = event.target.closest('[data-tray-select]');
    if (select && !select.disabled) assignFromTray(Number(select.getAttribute('data-tray-select')));
  });

  /* Hovering a tray card previews it into the first empty slot. Pointer events
   * only: a keyboard user gets the same preview from focus below, and a touch
   * user gets no hover at all, which is why the committed figures are never
   * hidden behind it. */
  launchCrew.addEventListener('pointerover', function (event) {
    var card = event.target.closest('[data-tray-crew]');
    if (!card || !state.launchMission) return;
    var id = Number(card.getAttribute('data-tray-crew'));
    var member = memberById(id);
    if (!member || launchEligibility(state.launchMission, member)) return;
    updateLaunchState({ crewId: id, slotIndex: null });
  });
  launchCrew.addEventListener('pointerout', function (event) {
    if (event.relatedTarget && launchCrew.contains(event.relatedTarget)) return;
    if (state.launchMission) updateLaunchState(null);
  });
  launchCrew.addEventListener('focusin', function (event) {
    var card = event.target.closest('[data-tray-crew]');
    if (!card || !state.launchMission) return;
    var member = memberById(Number(card.getAttribute('data-tray-crew')));
    if (!member || launchEligibility(state.launchMission, member)) return;
    updateLaunchState({ crewId: Number(card.getAttribute('data-tray-crew')), slotIndex: null });
  });
  launchCrew.addEventListener('focusout', function (event) {
    if (event.relatedTarget && launchCrew.contains(event.relatedTarget)) return;
    if (state.launchMission) updateLaunchState(null);
  });

  if (launchRack) {
    launchRack.addEventListener('click', function (event) {
      var warning = event.target.closest('[data-gear-warning]');
      if (warning) { openLoadout(warning.getAttribute('data-gear-warning')); return; }
      var clear = event.target.closest('[data-slot-clear]');
      if (!clear) return;
      launchError.textContent = '';
      clearSlot(Number(clear.getAttribute('data-slot-clear')));
      renderLaunchCrew();
    });
  }

  if (launchFilterRole) launchFilterRole.addEventListener('change', renderTray);
  if (launchSort) launchSort.addEventListener('change', renderTray);
  if (launchFilterOpen) launchFilterOpen.addEventListener('change', renderTray);
  if (launchRecommend) launchRecommend.addEventListener('click', recommendLaunchCrew);
  if (launchRepeat) launchRepeat.addEventListener('click', repeatLastCrew);

  /* ---- Drag and drop ----------------------------------------------------
   * Plain HTML5 drag events. The dragged crew id is held in state as well as in
   * dataTransfer: Safari does not expose dataTransfer.getData() during
   * dragover, and the drop target needs to know who is coming to preview them.
   * -------------------------------------------------------------------- */
  function dragCrewId(event) {
    var card = event.target.closest ? event.target.closest('[data-tray-crew], [data-slot-crew]') : null;
    if (!card) return null;
    return Number(card.getAttribute('data-tray-crew') || card.getAttribute('data-slot-crew'));
  }

  function startDrag(event) {
    var id = dragCrewId(event);
    var mission = state.launchMission;
    if (!id || !mission) return;
    var member = memberById(id);
    if (!member || launchEligibility(mission, member)) { event.preventDefault(); return; }
    state.dragCrewId = id;
    if (event.dataTransfer) {
      event.dataTransfer.effectAllowed = 'move';
      // Required for the drag to start at all in Firefox.
      try { event.dataTransfer.setData('text/plain', String(id)); } catch (error) { /* older browsers */ }
    }
    if (launchModal) launchModal.classList.add('is-dragging');
  }

  function endDrag() {
    state.dragCrewId = null;
    if (launchModal) launchModal.classList.remove('is-dragging');
    if (launchRack) Array.prototype.forEach.call(launchRack.querySelectorAll('.is-drop-target'), function (slot) {
      slot.classList.remove('is-drop-target');
    });
    if (state.launchMission) updateLaunchState(null);
  }

  launchCrew.addEventListener('dragstart', startDrag);
  launchCrew.addEventListener('dragend', endDrag);

  if (launchRack) {
    launchRack.addEventListener('dragstart', startDrag);
    launchRack.addEventListener('dragend', endDrag);
    launchRack.addEventListener('dragover', function (event) {
      if (!state.dragCrewId) return;
      var slot = event.target.closest('[data-slot-index]');
      if (!slot) return;
      // preventDefault is what marks this a valid drop target.
      event.preventDefault();
      if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
      if (!slot.classList.contains('is-drop-target')) {
        Array.prototype.forEach.call(launchRack.querySelectorAll('.is-drop-target'), function (other) { other.classList.remove('is-drop-target'); });
        slot.classList.add('is-drop-target');
        updateLaunchState({ crewId: state.dragCrewId, slotIndex: Number(slot.getAttribute('data-slot-index')) });
      }
    });
    launchRack.addEventListener('drop', function (event) {
      var slot = event.target.closest('[data-slot-index]');
      if (!slot || !state.dragCrewId) return;
      event.preventDefault();
      var id = state.dragCrewId;
      state.dragCrewId = null;
      launchError.textContent = '';
      assignToSlot(Number(slot.getAttribute('data-slot-index')), id);
      if (launchModal) launchModal.classList.remove('is-dragging');
      renderLaunchCrew();
    });
  }

  /* Dropping a slotted crew member back onto the tray removes them, which is
   * the gesture people try before they find the small clear button. */
  launchCrew.addEventListener('dragover', function (event) {
    if (state.dragCrewId && rackIndexOf(state.dragCrewId) !== -1) event.preventDefault();
  });
  launchCrew.addEventListener('drop', function (event) {
    if (!state.dragCrewId) return;
    var index = rackIndexOf(state.dragCrewId);
    if (index === -1) return;
    event.preventDefault();
    state.dragCrewId = null;
    clearSlot(index);
    if (launchModal) launchModal.classList.remove('is-dragging');
    renderLaunchCrew();
  });
  document.getElementById('mission-result-close').addEventListener('click', closeResult);
  document.getElementById('mission-result-dismiss').addEventListener('click', closeResult);
  /* Relaunch the operation that was just claimed with the same crew, closing
   * the loop without a round trip through the mission list. The rack's own
   * eligibility rules are not re-run here: start.php is the authority, and it
   * refuses a crew member who is deployed or too tired with a message that says
   * which one -- which is more useful than this button quietly dropping them. */
  if (resultRerun) resultRerun.addEventListener('click', function () {
    var rerun = state.resultRerun;
    if (!rerun) return;
    resultRerun.disabled = true;
    resultRerun.classList.add('is-busy');
    if (resultError) resultError.textContent = '';
    post('/api/missions/start.php', { mission_id: rerun.missionId, crew_ids: rerun.crewIds, csrf: window.PW_AUTH.csrf })
      .then(function () { closeResult(); setStatus('Mission relaunched. Your crew is back in the field.'); load(); })
      .catch(function (error) { if (resultError) resultError.textContent = error.message; })
      .finally(function () { resultRerun.disabled = false; resultRerun.classList.remove('is-busy'); });
  });
  function resolveCrewOffer(button) {
    if (!button || button.disabled) return;
    var offerId = Number(button.getAttribute('data-crew-offer-id'));
    var action = button.getAttribute('data-crew-offer-action');
    if (!offerId || !action) return;
    var card = button.closest('[data-crew-offer]');
    var status = card && card.querySelector('.mission-crew-offer-status');
    var payload = { offer_id: offerId, action: action, csrf: window.PW_AUTH && window.PW_AUTH.csrf ? window.PW_AUTH.csrf : '' };
    if (action === 'replace') {
      var replacement = card && card.querySelector('[data-crew-offer-replace]');
      payload.replace_player_crew_id = replacement ? replacement.value : '';
    }
    button.disabled = true;
    if (status) status.textContent = action === 'sell' ? 'Selling recruit…' : 'Updating roster…';
    post('/api/missions/crew-offer-resolve.php', payload).then(function (result) {
      if (status) status.textContent = result.message || 'Roster updated.';
      if (card) card.classList.add('is-resolved');
      setStatus(result.message || 'Crew roster updated.');
      return load();
    }).catch(function (error) {
      button.disabled = false;
      if (status) status.textContent = error.message || 'Could not update this recruit.';
    });
  }
  if (crewOffers) crewOffers.addEventListener('click', function (event) {
    var button = event.target.closest('[data-crew-offer-action]');
    if (button && crewOffers.contains(button)) resolveCrewOffer(button);
  });
  resultModal.addEventListener('click', function (event) {
    if (event.target === resultModal) { closeResult(); return; }
    var offerButton = event.target.closest('[data-crew-offer-action]');
    if (offerButton && resultModal.contains(offerButton)) { resolveCrewOffer(offerButton); return; }
    /* Equip straight from the debrief. Before this the only per-item action was
     * Destroy, and putting a new item on a crew member meant closing the modal,
     * finding them and opening their loadout -- three steps away from the
     * moment the item was handed over. Goes through gear-equip.php exactly as
     * the loadout modal does, so every requirement is still checked there. */
    var equip = event.target.closest('[data-loot-equip]');
    if (equip && resultModal.contains(equip)) {
      if (equip.disabled) return;
      var equipItemId = Number(equip.getAttribute('data-loot-equip'));
      var equipCrewId = Number(equip.getAttribute('data-equip-crew'));
      var equipRow = equip.closest('.mission-result-gear');
      var equipStatus = equipRow && equipRow.querySelector('.mission-result-gear-status');
      if (!isFinite(equipItemId) || !isFinite(equipCrewId)) return;
      equip.disabled = true;
      equip.classList.add('is-busy');
      if (equipStatus) equipStatus.textContent = '';
      post('/api/missions/gear-equip.php', { crew_id: equipCrewId, loot_definition_id: equipItemId, csrf: window.PW_AUTH.csrf })
        .then(function (result) {
          equip.textContent = 'Equipped';
          equip.classList.add('is-done');
          if (equipRow) equipRow.classList.add('is-equipped');
          if (equipStatus) equipStatus.textContent = result.message || 'Equipped.';
          /* Reloads so a second copy in the same haul re-evaluates its own best
           * target against the roster this equip just changed. */
          return load();
        })
        .catch(function (error) {
          equip.disabled = false;
          equip.textContent = 'Equip';
          if (equipStatus) equipStatus.textContent = error.message;
        })
        .finally(function () { equip.classList.remove('is-busy'); });
      return;
    }

    var destroy = event.target.closest('[data-gear-destroy]');
    if (!destroy || destroy.disabled) return;
    var itemId = Number(destroy.getAttribute('data-gear-destroy'));
    if (!isFinite(itemId) || itemId < 1) return;
    var row = destroy.closest('.mission-result-gear');
    var status = row && row.querySelector('.mission-result-gear-status');
    /* Destroying is permanent and was one unconfirmed click on an item the
     * player had just been given. A second deliberate click is required, the
     * same pattern the launch button already uses to accept a mismatch penalty
     * rather than a blocking window.confirm(), which has stalled flows on this
     * page before. The armed state times out so it cannot be triggered later by
     * a stray click on a button the player has forgotten about. */
    if (!destroy.classList.contains('is-arming')) {
      Array.prototype.forEach.call(resultModal.querySelectorAll('.mission-result-destroy.is-arming'), function (other) {
        other.classList.remove('is-arming');
        other.textContent = 'Destroy';
        if (other.pwDisarmTimer) window.clearTimeout(other.pwDisarmTimer);
      });
      destroy.classList.add('is-arming');
      destroy.textContent = 'Destroy for good?';
      if (status) status.textContent = 'This cannot be undone.';
      destroy.pwDisarmTimer = window.setTimeout(function () {
        destroy.classList.remove('is-arming');
        destroy.textContent = 'Destroy';
        if (status) status.textContent = '';
      }, 5000);
      return;
    }
    if (destroy.pwDisarmTimer) window.clearTimeout(destroy.pwDisarmTimer);
    destroy.classList.remove('is-arming');
    destroy.disabled = true;
    destroy.textContent = 'Destroying…';
    if (status) status.textContent = '';
    post('/api/missions/gear-destroy.php', { loot_definition_id: itemId, csrf: window.PW_AUTH.csrf }).then(function (result) {
      destroy.textContent = 'Destroyed';
      destroy.classList.add('is-destroyed');
      if (row) row.classList.add('is-destroyed');
      if (status) status.textContent = result.message || 'Removed from inventory.';
      load();
    }).catch(function (error) {
      destroy.disabled = false;
      destroy.textContent = 'Destroy';
      if (status) status.textContent = error.message;
    });
  });
  document.getElementById('mission-launch-close').addEventListener('click', closeLaunch); document.getElementById('mission-launch-cancel').addEventListener('click', closeLaunch);
  launchModal.addEventListener('click', function (event) { if (event.target === launchModal) closeLaunch(); });
  launchConfirm.addEventListener('click', function () {
    if (!state.launchMission) return;
    var crewIds = rackIds();
    if (crewIds.length < state.launchMission.min_crew || crewIds.length > state.launchMission.max_crew) { launchError.textContent = 'Choose the required number of crew members before launching.'; return; }
    /* Launching into a mismatch takes a second, deliberate click rather than a
     * window.confirm(): the penalty is already spelled out in the projection
     * above, and a blocking dialog has stalled this page's flows before. */
    if (state.launchProjection && state.launchProjection.penalty && !state.launchPenaltyAck) {
      state.launchPenaltyAck = true;
      launchConfirm.textContent = 'Launch Anyway';
      launchError.textContent = 'This crew has no specialist for this operation. Launch again to accept the penalty.';
      return;
    }
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
      if (action === 'claim' && result.reputation_awarded > 0 && typeof window.refreshAuthNav === 'function') window.refreshAuthNav();
      if (action === 'claim') { setStatus(claimSummary(result), result.succeeded === false); showResult(result); }
      else { setStatus('Mission completed. Rewards are ready to claim.'); }
    }).catch(function (error) { setStatus(error.message, true); button.disabled = false; button.classList.remove('is-busy'); });
  });
  /* Delegated, because the card is rebuilt on every refresh and a listener
   * bound to the button itself would be thrown away with it. */
  if (dailyCard) dailyCard.addEventListener('click', function (event) {
    var button = event.target.closest('#mission-daily-claim');
    if (!button || button.disabled) return;
    var error = document.getElementById('mission-daily-error');
    if (error) error.textContent = '';
    button.disabled = true; button.classList.add('is-busy');
    post('/api/missions/daily-claim.php', { csrf: window.PW_AUTH.csrf }).then(function (result) {
      load();
      if (result.reward_type === 'reputation' && result.reputation_awarded > 0 && typeof window.refreshAuthNav === 'function') window.refreshAuthNav();
      setStatus(result.reward_type === 'reputation'
        ? 'Daily objective complete: +' + result.reputation_awarded + ' reputation.'
        : 'Daily objective complete: +' + credits(result.credits_awarded) + ' credits (total ' + credits(result.credits_total) + ').');
    }).catch(function (caught) {
      var target = document.getElementById('mission-daily-error');
      if (target) target.textContent = caught.message;
      button.disabled = false; button.classList.remove('is-busy');
    });
  });

  [crewRoleFilter, crewLevelFilter, crewFavoriteFilter, crewStatusFilter, crewSort].forEach(function (control) {
    control.addEventListener('change', function () {
      state.crewPage = 1;
      if (state.data) renderCrew(state.data);
    });
  });
  if (crewPagination) {
    crewPagination.addEventListener('click', function (event) {
      var button = event.target.closest('[data-crew-page]');
      if (!button || button.disabled || !state.data) return;
      state.crewPage += button.getAttribute('data-crew-page') === 'next' ? 1 : -1;
      renderCrew(state.data);
    });
  }
  if (crewList) {
    crewList.addEventListener('click', function (event) {
      var button = event.target.closest('[data-crew-favorite]');
      if (!button || button.disabled || !state.data) return;
      var crewId = Number(button.getAttribute('data-crew-favorite'));
      var crew = (state.data.crew || []).filter(function (member) { return Number(member.id) === crewId; })[0];
      if (!crew) return;
      button.disabled = true;
      post('/api/missions/crew-favorite.php', { crew_id: crewId, is_favorite: !crew.is_favorite, csrf: window.PW_AUTH.csrf }).then(function (result) {
        crew.is_favorite = !!result.is_favorite;
        renderCrew(state.data);
        setStatus(crew.name + (crew.is_favorite ? ' added to favourites.' : ' removed from favourites.'));
      }).catch(function (error) {
        button.disabled = false;
        setStatus(error.message, true);
      });
    });
  }
  /* ----------------------------------------------------------------------
   * Gear.
   *
   * Equipment is loot that carries a slot, and its bonuses are the same four
   * stats levelling already grants -- so nothing here computes an effect. The
   * server folds equipped bonuses into each crew member's stats before it
   * calculates anything, which is why a loadout change shows up in the launch
   * projection and the payout without either of them knowing gear exists.
   * -------------------------------------------------------------------- */

  /* One line glyph per slot, drawn the same way the weather icons are. An
   * administrator may upload real artwork per item; without it these keep every
   * slot legible rather than leaving a bare square. */
  var GEAR_SLOT_ICONS = {
    head: '<path d="M32 10c11 0 18 7.5 18 18v10c0 8-8 14-18 14s-18-6-18-14V28c0-10.5 7-18 18-18z"/><path d="M22 34h9"/><circle cx="41" cy="34" r="4"/>',
    chest: '<path d="M22 14 32 19l10-5 12 6-4 11-4-1v20H26V30l-4 1-4-11z"/>',
    main_hand: '<path d="M16 44 40 20l6 6L22 50z"/><path d="m38 18 8 8"/><path d="M14 42v8h8"/>',
    off_hand: '<path d="M32 12l18 6v14c0 11-7.5 17.5-18 21-10.5-3.5-18-10-18-21V18z"/><path d="M32 22v20"/>',
    legs: '<path d="M22 12h20v14l-3 26h-8l-1-20-1 20h-8l-2-26z"/>',
    feet: '<path d="M20 16h10v18l16 8v8H20z"/><path d="M20 40h16"/>',
    utility: '<rect x="22" y="14" width="20" height="36" rx="3"/><path d="M28 10h8v4h-8z"/><path d="M28 24h8"/>'
  };

  function gearIconHtml(slotKey, iconUrl) {
    if (iconUrl) return '<img src="' + escapeHtml(iconUrl) + '" alt="">';
    var path = GEAR_SLOT_ICONS[slotKey] || GEAR_SLOT_ICONS.utility;
    return '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + path + '</svg>';
  }

  function gearSlots() {
    return (state.data && state.data.gear_slots) || [];
  }
  function gearReady() {
    return !!(state.data && state.data.gear_ready && gearSlots().length);
  }
  function slotLabel(key) {
    var match = gearSlots().filter(function (slot) { return slot.key === key; })[0];
    return match ? match.label : key;
  }

  /* "+2 STR · +1 CUN". Empty when an item grants nothing, so a cosmetic piece
   * reads as having no effect rather than as a row of zeroes. */
  function gearBonusText(bonus) {
    if (!bonus) return '';
    return ['strength', 'cunning', 'science', 'charisma'].map(function (key) {
      var value = Number(bonus[key]) || 0;
      return value === 0 ? '' : (value > 0 ? '+' : '') + value + ' ' + STAT_INFO[key].short;
    }).filter(Boolean).join(' · ');
  }

  function gearTooltip(item) {
    var parts = [item.name, item.tier.charAt(0).toUpperCase() + item.tier.slice(1) + ' · ' + item.slot_label];
    var bonus = gearBonusText(item.bonus);
    parts.push(bonus ? 'While equipped: ' + bonus : 'No stat bonus');
    if (item.description) parts.push(item.description);
    return parts.join('\n');
  }

  /* The read-only silhouette under a crew portrait. Seven squares, filled or
   * dashed, each its own hover/focus target -- the tooltip is the whole point
   * of the grid, so every square is reachable by keyboard as well as pointer. */
  function crewLoadoutStrip(crew) {
    if (!gearReady()) return '';
    var equipped = crew.gear || {};
    var filled = 0;
    var squares = gearSlots().map(function (slot) {
      var item = equipped[slot.key];
      if (item) filled++;
      var label = item ? gearTooltip(item) : 'Empty — ' + slot.label;
      return '<span class="mission-gear-slot mission-gear-slot--' + escapeHtml(slot.key.replace(/_/g, '-'))
        + (item ? ' is-filled is-' + escapeHtml(item.tier) : ' is-empty') + '"'
        + ' tabindex="0" role="img" title="' + escapeHtml(label) + '" aria-label="' + escapeHtml(label.split('\n').join('. ')) + '">'
        + gearIconHtml(slot.key, item ? item.icon_url : '') + '</span>';
    }).join('');
    var deployed = crewAvailability(crew) !== 'available';
    return '<div class="mission-crew-loadout">'
      + '<div class="mission-crew-loadout-meta"><span class="mission-crew-loadout-label">Loadout</span>'
      + '<span class="mission-crew-loadout-count">' + filled + ' / ' + gearSlots().length + '</span></div>'
      + '<span class="mission-gear-slots">' + squares + '</span>'
      + '<button type="button" class="btn mission-loadout-btn" data-crew-id="' + crew.id + '"' + (deployed ? ' disabled' : '')
      + ' title="' + escapeHtml(deployed ? 'A crew member in the field cannot change equipment.' : 'Assign equipment to ' + crew.name) + '">'
      + (deployed ? 'In field' : 'Loadout') + '</button></div>';
  }

  /* ---- Loadout modal --------------------------------------------------- */

  function loadoutCrew() {
    if (!state.loadoutCrewId) return null;
    return (state.data && state.data.crew || []).filter(function (member) {
      return Number(member.id) === Number(state.loadoutCrewId);
    })[0] || null;
  }

  /* Items that fit the selected slot, with the reason any of them cannot be
   * used by this crew member. Requirements are re-checked on the server at
   * equip time -- this only explains, it never decides. */
  function loadoutCandidates(crew, slotKey) {
    return (state.data && state.data.loot || []).filter(function (item) {
      return item.slot === slotKey;
    }).map(function (item) {
      var equippedHere = crew.gear && crew.gear[slotKey] && crew.gear[slotKey].loot_definition_id === item.id;
      var spare = Number(item.quantity) - Number(item.equipped_count);
      var reason = '';
      if (Number(crew.level) < Number(item.required_level)) reason = 'Needs level ' + item.required_level;
      else if (item.required_role && item.required_role !== crew.role) reason = item.required_role + ' only';
      else if (!equippedHere && spare < 1) reason = 'Every copy is in use';
      return { item: item, equipped: !!equippedHere, spare: spare, reason: reason };
    });
  }

  /* What equipping a candidate would do to the four stats, computed from the
   * item being swapped out as well as the one going in -- a straight "+2 SCI"
   * would be a lie whenever the slot already holds something. */
  function loadoutDelta(crew, candidate) {
    var current = crew.gear && crew.gear[state.loadoutSlot] ? crew.gear[state.loadoutSlot].bonus : null;
    var rows = ['strength', 'cunning', 'science', 'charisma'].map(function (key) {
      var now = Math.max(0, Number(crew[key]) || 0);
      var next = now - (current ? Number(current[key]) || 0 : 0) + (candidate ? Number(candidate.bonus[key]) || 0 : 0);
      next = Math.max(0, Math.min(Number(state.data.max_gear_stat) || 80, next));
      if (next === now) return '';
      return '<span class="mission-loadout-delta-cell' + (next > now ? ' is-better' : ' is-worse') + '">'
        + STAT_INFO[key].short + ' <s>' + now + '</s> <strong>' + next + '</strong></span>';
    }).filter(Boolean);
    return rows.length ? rows.join('') : '<span class="mission-loadout-delta-cell">No change to this crew member’s stats</span>';
  }

  function openLoadout(crewId) {
    if (!gearReady()) return;
    var crew = (state.data && state.data.crew || []).filter(function (member) { return Number(member.id) === Number(crewId); })[0];
    if (!crew || crewAvailability(crew) !== 'available') return;
    state.loadoutCrewId = Number(crewId);
    state.loadoutSlot = gearSlots()[0].key;
    loadoutError.textContent = '';
    renderLoadout();
    if (typeof loadoutModal.showModal === 'function') loadoutModal.showModal(); else loadoutModal.setAttribute('open', '');
  }

  function closeLoadout() {
    if (!loadoutModal) return;
    if (loadoutModal.open && typeof loadoutModal.close === 'function') loadoutModal.close(); else loadoutModal.removeAttribute('open');
    state.loadoutCrewId = null;
  }

  /* Enhanced loadout command surface. These helpers keep the crew silhouette,
   * recommendation logic and loadout-wide actions on the same
   * server-authoritative equip endpoints as an individual gear change. */
  function gearPower(item) {
    if (!item) return 0;
    var bonus = item.bonus || {};
    var score = (Number(bonus.strength) || 0) * 0.5
      + (Number(bonus.cunning) || 0)
      + (Number(bonus.science) || 0) * 1.5
      + (Number(bonus.charisma) || 0) * 0.5;
    var tierValue = { common: 1, uncommon: 2, rare: 3, legendary: 4 }[item.tier] || 0;
    return score * 100 + tierValue;
  }

  function bestLoadoutCandidate(crew, slotKey) {
    return loadoutCandidates(crew, slotKey).filter(function (entry) {
      return !entry.reason;
    }).sort(function (a, b) {
      return gearPower(b.item) - gearPower(a.item) || Number(b.item.id) - Number(a.item.id);
    })[0] || null;
  }

  function updateLoadoutConnector() {
    if (!loadoutBody || !loadoutSlots) return;
    window.requestAnimationFrame(function () {
      var active = loadoutSlots.querySelector('.mission-loadout-slot.is-active');
      var bodyRect = loadoutBody.getBoundingClientRect();
      var slotRect = active && active.getBoundingClientRect();
      if (!slotRect || !bodyRect.width) return;
      loadoutBody.style.setProperty('--loadout-connector-y', (slotRect.top - bodyRect.top + slotRect.height / 2) + 'px');
    });
  }

  function renderLoadoutSlots(crew) {
    var equipped = crew.gear || {};
    var portrait = safeImage(crew.portrait_url);
    loadoutSlots.style.setProperty('--loadout-crew-image', portrait ? 'url("' + portrait + '")' : 'none');
    loadoutSlots.innerHTML = gearSlots().map(function (slot) {
      var item = equipped[slot.key];
      var active = slot.key === state.loadoutSlot;
      var recommended = !item ? bestLoadoutCandidate(crew, slot.key) : null;
      var label = item ? gearTooltip(item) : 'Empty - ' + slot.label + (recommended ? '. Recommended: ' + recommended.item.name : '. No compatible equipment available.');
      return '<div class="mission-loadout-slot-card' + (active ? ' is-active' : '') + '">'
        + '<button type="button" class="mission-loadout-slot' + (item ? ' is-filled is-' + escapeHtml(item.tier) : ' is-empty')
        + (active ? ' is-active' : '') + '" data-slot="' + escapeHtml(slot.key) + '"'
        + ' title="' + escapeHtml(label) + '" aria-pressed="' + (active ? 'true' : 'false') + '">'
        + gearIconHtml(slot.key, item ? item.icon_url : '')
        + '<span class="mission-loadout-slot-name">' + escapeHtml(slot.label) + '</span>'
        + '<small>' + escapeHtml(item ? item.name : (recommended ? 'Recommended: ' + recommended.item.name : 'Empty')) + '</small></button>'
        + (item ? '<button type="button" class="mission-loadout-slot-remove" data-remove-slot="' + escapeHtml(slot.key) + '" aria-label="Unequip ' + escapeHtml(item.name) + '" title="Unequip ' + escapeHtml(item.name) + '">&times;</button>' : '')
        + '</div>';
    }).join('');
    updateLoadoutConnector();
  }

  function renderLoadoutSummary(crew) {
    if (!loadoutSummary) return;
    var equipped = crew.gear || {};
    var filled = gearSlots().filter(function (slot) { return !!equipped[slot.key]; }).length;
    var bonus = crew.gear_bonus || {};
    var bonusText = gearBonusText(bonus);
    var effects = ['strength', 'cunning', 'science', 'charisma'].map(function (key) {
      var value = Number(bonus[key]) || 0;
      return value ? STAT_INFO[key].effect(value) : '';
    }).filter(Boolean);
    loadoutSummary.innerHTML = '<span class="mission-loadout-summary-slots"><strong>' + filled + ' / ' + gearSlots().length + '</strong><small>slots fitted</small></span>'
      + '<span class="mission-loadout-summary-bonus"><strong>' + escapeHtml(bonusText || 'No gear bonus') + '</strong><small>'
      + escapeHtml(effects.length ? effects.join(' · ') : 'Fit equipment to improve mission outcomes') + '</small></span>';
  }

  function renderLoadoutOptions(crew) {
    var slotKey = state.loadoutSlot;
    loadoutPickerHead.textContent = 'Inventory · ' + slotLabel(slotKey).toLowerCase();
    var candidates = loadoutCandidates(crew, slotKey);
    var current = crew.gear && crew.gear[slotKey] ? crew.gear[slotKey] : null;
    var recommended = bestLoadoutCandidate(crew, slotKey);
    var rows = candidates.map(function (entry) {
      var bonus = gearBonusText(entry.item.bonus);
      var meta = [entry.item.tier.charAt(0).toUpperCase() + entry.item.tier.slice(1)];
      if (bonus) meta.push(bonus);
      if (entry.equipped) meta.push('equipped');
      else if (entry.item.quantity > 1) meta.push(entry.spare + ' spare of ' + entry.item.quantity);
      var isRecommended = recommended && recommended.item.id === entry.item.id && !entry.equipped;
      return '<button type="button" class="mission-loadout-option' + (entry.equipped ? ' is-equipped' : '')
        + (isRecommended ? ' is-recommended' : '')
        + (entry.reason && !entry.equipped ? ' is-blocked' : '') + ' is-' + escapeHtml(entry.item.tier) + '"'
        + ' data-item-id="' + entry.item.id + '"' + (entry.reason && !entry.equipped ? ' disabled' : '')
        + ' title="' + escapeHtml(gearTooltip(entry.item)) + '">'
        + '<span class="mission-loadout-option-icon">' + gearIconHtml(slotKey, entry.item.icon_url) + '</span>'
        + '<span class="mission-loadout-option-copy"><strong>' + escapeHtml(entry.item.name)
        + (isRecommended ? '<em>Best available</em>' : '') + '</strong>'
        + '<small>' + escapeHtml(meta.join(' · ')) + (entry.reason && !entry.equipped ? ' · ' + escapeHtml(entry.reason) : '') + '</small></span></button>';
    }).join('');
    loadoutOptions.innerHTML = (current
      ? '<button type="button" class="mission-loadout-option is-remove" data-item-id="0">'
        + '<span class="mission-loadout-option-copy"><strong>Remove ' + escapeHtml(current.name) + '</strong>'
        + '<small>Leave this slot empty. The item stays in your inventory.</small></span></button>'
      : '')
      + (rows || '<p class="missions-empty">Nothing in your inventory fits this slot yet.</p>');
    loadoutDeltaBox.innerHTML = '';
  }

  function renderLoadoutActions(crew) {
    var current = crew.gear && crew.gear[state.loadoutSlot] ? crew.gear[state.loadoutSlot] : null;
    var best = bestLoadoutCandidate(crew, state.loadoutSlot);
    var canImprove = best && !best.equipped && gearPower(best.item) > gearPower(current);
    if (loadoutBest) {
      loadoutBest.disabled = !canImprove || state.loadoutAutoRunning;
      loadoutBest.textContent = canImprove ? 'Equip best ' + slotLabel(state.loadoutSlot).toLowerCase() : 'Best ' + slotLabel(state.loadoutSlot).toLowerCase() + ' equipped';
    }
    if (loadoutAuto) loadoutAuto.disabled = state.loadoutAutoRunning;
    if (loadoutModal) loadoutModal.classList.toggle('is-auto-equipping', state.loadoutAutoRunning);
  }

  function renderLoadout() {
    var crew = loadoutCrew();
    if (!crew || !loadoutModal) return;
    loadoutTitle.textContent = crew.name;
    var bonus = gearBonusText(crew.gear_bonus);
    loadoutCopy.textContent = crew.role + ' · Level ' + crew.level
      + (bonus ? ' · equipment is worth ' + bonus : ' · carrying nothing yet');
    renderLoadoutSummary(crew);
    renderLoadoutSlots(crew);
    renderLoadoutOptions(crew);
    renderLoadoutActions(crew);
  }

  function requestLoadoutChange(crew, slot, itemId) {
    return post(itemId ? '/api/missions/gear-equip.php' : '/api/missions/gear-unequip.php', itemId
      ? { crew_id: crew.id, loot_definition_id: itemId, csrf: window.PW_AUTH.csrf }
      : { crew_id: crew.id, slot: slot, csrf: window.PW_AUTH.csrf });
  }

  function submitLoadout(itemId) {
    var crew = loadoutCrew();
    if (!crew || state.loadoutAutoRunning) return;
    var slot = state.loadoutSlot;
    loadoutError.textContent = '';
    loadoutOptions.querySelectorAll('button').forEach(function (button) { button.disabled = true; });
    return requestLoadoutChange(crew, slot, itemId).then(function () {
      return load();
    }).then(function () {
      renderLoadout();
      setStatus('Loadout updated.');
    }).catch(function (error) {
      loadoutError.textContent = error.message;
      renderLoadout();
    });
  }

  function autoEquipBestLoadout() {
    var crew = loadoutCrew();
    if (!crew || state.loadoutAutoRunning) return;
    state.loadoutAutoRunning = true;
    loadoutError.textContent = '';
    renderLoadout();
    var slots = gearSlots().map(function (slot) { return slot.key; });
    var changed = 0;
    function equipNext(index) {
      var currentCrew = loadoutCrew();
      if (!currentCrew || index >= slots.length) return Promise.resolve();
      var slot = slots[index];
      var current = currentCrew.gear && currentCrew.gear[slot] ? currentCrew.gear[slot] : null;
      var best = bestLoadoutCandidate(currentCrew, slot);
      if (!best || best.equipped || gearPower(best.item) <= gearPower(current)) return equipNext(index + 1);
      state.loadoutSlot = slot;
      renderLoadout();
      return requestLoadoutChange(currentCrew, slot, best.item.id).then(function () {
        changed++;
        return load();
      }).then(function () {
        return equipNext(index + 1);
      });
    }
    equipNext(0).then(function () {
      state.loadoutAutoRunning = false;
      renderLoadout();
      setStatus(changed ? 'Best available equipment fitted to ' + crew.name + '.' : crew.name + ' already has the best available loadout.');
    }).catch(function (error) {
      state.loadoutAutoRunning = false;
      loadoutError.textContent = error.message;
      renderLoadout();
    });
  }

  function showLoadoutPreview(button) {
    var crew = loadoutCrew();
    if (!button || !crew) return;
    var id = Number(button.getAttribute('data-item-id'));
    var item = id ? (state.data.loot || []).filter(function (entry) { return Number(entry.id) === id; })[0] : null;
    loadoutSlots.classList.add('is-previewing');
    var active = loadoutSlots.querySelector('.mission-loadout-slot-card.is-active');
    if (active) active.classList.add('is-preview-target');
    loadoutDeltaBox.innerHTML = '<span class="mission-loadout-delta-label">' + escapeHtml(id ? 'Preview equip' : 'Preview removal') + '</span>' + loadoutDelta(crew, item);
  }

  function clearLoadoutPreview() {
    if (!loadoutSlots) return;
    loadoutSlots.classList.remove('is-previewing');
    loadoutSlots.querySelectorAll('.is-preview-target').forEach(function (slot) { slot.classList.remove('is-preview-target'); });
    loadoutDeltaBox.innerHTML = '';
  }

  if (loadoutSlots) {
    loadoutSlots.addEventListener('click', function (event) {
      var button = event.target.closest('.mission-loadout-slot');
      if (!button) return;
      state.loadoutSlot = button.getAttribute('data-slot');
      renderLoadout();
    });
    loadoutSlots.addEventListener('click', function (event) {
      var remove = event.target.closest('[data-remove-slot]');
      if (!remove || state.loadoutAutoRunning) return;
      state.loadoutSlot = remove.getAttribute('data-remove-slot');
      submitLoadout(0);
    });
  }
  if (loadoutOptions) {
    loadoutOptions.addEventListener('click', function (event) {
      var button = event.target.closest('.mission-loadout-option');
      if (!button || button.disabled) return;
      submitLoadout(Number(button.getAttribute('data-item-id')));
    });
    /* Hovering a candidate previews the swap. Focus counts too: a keyboard user
     * gets the same preview, which :hover alone would never give them. */
    ['mouseover', 'focusin'].forEach(function (type) {
      loadoutOptions.addEventListener(type, function (event) {
        var button = event.target.closest('.mission-loadout-option');
        var crew = loadoutCrew();
        if (!button || !crew) return;
        var id = Number(button.getAttribute('data-item-id'));
        var item = id ? (state.data.loot || []).filter(function (entry) { return entry.id === id; })[0] : null;
        loadoutDeltaBox.innerHTML = '<span class="mission-loadout-delta-label">'
          + escapeHtml(id ? 'If equipped' : 'If removed') + '</span>' + loadoutDelta(crew, item);
      });
    });
    loadoutOptions.addEventListener('mouseleave', function () { loadoutDeltaBox.innerHTML = ''; });
    ['mouseover', 'focusin'].forEach(function (type) {
      loadoutOptions.addEventListener(type, function (event) {
        showLoadoutPreview(event.target.closest('.mission-loadout-option'));
      });
    });
    loadoutOptions.addEventListener('mouseleave', clearLoadoutPreview);
    loadoutOptions.addEventListener('focusout', function (event) {
      if (!loadoutOptions.contains(event.relatedTarget)) clearLoadoutPreview();
    });
  }
  if (loadoutBest) {
    loadoutBest.addEventListener('click', function () {
      var crew = loadoutCrew();
      var best = crew && bestLoadoutCandidate(crew, state.loadoutSlot);
      if (best && !best.equipped) submitLoadout(best.item.id);
    });
  }
  if (loadoutAuto) loadoutAuto.addEventListener('click', autoEquipBestLoadout);
  if (loadoutModal) {
    document.getElementById('mission-loadout-close').addEventListener('click', closeLoadout);
    document.getElementById('mission-loadout-done').addEventListener('click', closeLoadout);
    loadoutModal.addEventListener('click', function (event) { if (event.target === loadoutModal) closeLoadout(); });
  }
  if (crewList) {
    crewList.addEventListener('click', function (event) {
      var button = event.target.closest('.mission-loadout-btn');
      if (button && !button.disabled) openLoadout(button.getAttribute('data-crew-id'));
    });
  }

  /* ---- Inventory ------------------------------------------------------- */

  function inventoryFilters() {
    return {
      type: inventoryTypeFilter ? inventoryTypeFilter.value : 'all',
      slot: inventorySlotFilter ? inventorySlotFilter.value : 'all',
      tier: inventoryTierFilter ? inventoryTierFilter.value : 'all',
      state: inventoryStateFilter ? inventoryStateFilter.value : 'all'
    };
  }

  function populateInventoryFilters(loot) {
    if (!inventorySlotFilter || !inventoryTierFilter) return;
    var slots = gearSlots();
    var currentSlot = inventorySlotFilter.value || 'all';
    inventorySlotFilter.innerHTML = '<option value="all">All slots</option>';
    slots.forEach(function (slot) {
      var option = document.createElement('option');
      option.value = slot.key; option.textContent = slot.label;
      inventorySlotFilter.appendChild(option);
    });
    inventorySlotFilter.value = slots.filter(function (slot) { return slot.key === currentSlot; }).length ? currentSlot : 'all';

    var tiers = [];
    loot.forEach(function (item) { if (tiers.indexOf(item.tier) === -1) tiers.push(item.tier); });
    var currentTier = inventoryTierFilter.value || 'all';
    inventoryTierFilter.innerHTML = '<option value="all">All rarities</option>';
    tiers.forEach(function (tier) {
      var option = document.createElement('option');
      option.value = tier; option.textContent = tier.charAt(0).toUpperCase() + tier.slice(1);
      inventoryTierFilter.appendChild(option);
    });
    inventoryTierFilter.value = tiers.indexOf(currentTier) !== -1 ? currentTier : 'all';
  }

  function renderInventory(data) {
    if (!inventorySection || !inventoryList) return;
    var loot = data.loot || [];
    /* Hidden entirely while the player owns nothing: an empty quartermaster
     * panel says less than no panel at all, and loot only arrives from a
     * successful operation. */
    if (!loot.length) { inventorySection.hidden = true; return; }
    inventorySection.hidden = false;
    populateInventoryFilters(loot);
    var filters = inventoryFilters();
    var visible = loot.filter(function (item) {
      var isGear = item.slot !== '';
      if (filters.type === 'gear' && !isGear) return false;
      if (filters.type === 'salvage' && isGear) return false;
      if (filters.slot !== 'all' && item.slot !== filters.slot) return false;
      if (filters.tier !== 'all' && item.tier !== filters.tier) return false;
      var spare = Number(item.quantity) - Number(item.equipped_count);
      if (filters.state === 'equipped' && Number(item.equipped_count) < 1) return false;
      if (filters.state === 'spare' && spare < 1) return false;
      return true;
    });
    var totalItems = loot.reduce(function (sum, item) { return sum + Number(item.quantity); }, 0);
    inventoryCount.textContent = totalItems + (totalItems === 1 ? ' item' : ' items');
    inventorySummary.textContent = visible.length === loot.length
      ? 'Showing everything you hold.'
      : 'Showing ' + visible.length + ' of ' + loot.length + ' entries.';
    if (!visible.length) { inventoryList.innerHTML = '<p class="missions-empty">Nothing matches these filters.</p>'; return; }
    inventoryList.innerHTML = visible.map(function (item) {
      var isGear = item.slot !== '';
      var bonus = gearBonusText(item.bonus);
      var spare = Number(item.quantity) - Number(item.equipped_count);
      var requires = [];
      if (Number(item.required_level) > 1) requires.push('Level ' + item.required_level);
      if (item.required_role) requires.push(item.required_role + ' only');
      return '<article class="mission-inventory-card is-' + escapeHtml(item.tier) + (isGear ? ' is-gear' : ' is-salvage') + '">'
        + '<span class="mission-inventory-icon">' + gearIconHtml(item.slot, item.icon_url) + '</span>'
        + '<div class="mission-inventory-copy"><h3>' + escapeHtml(item.name) + '</h3>'
        + '<p class="mission-inventory-meta"><span class="mission-inventory-tier">' + escapeHtml(item.tier) + '</span>'
        + (isGear ? ' · ' + escapeHtml(slotLabel(item.slot)) : ' · Salvage')
        + ' · x' + item.quantity + '</p>'
        + (bonus ? '<p class="mission-inventory-bonus">' + escapeHtml(bonus) + '</p>' : '')
        + (item.description ? '<p class="mission-inventory-desc">' + escapeHtml(item.description) + '</p>' : '')
        + (requires.length ? '<p class="mission-inventory-requires">' + escapeHtml(requires.join(' · ')) + '</p>' : '')
        + (isGear
          ? '<p class="mission-inventory-state' + (item.equipped_count > 0 ? ' is-active' : '') + '">'
            + escapeHtml(item.equipped_count > 0
              ? item.equipped_count + ' in use' + (spare > 0 ? ', ' + spare + ' spare' : '')
              : 'Not assigned to anyone')
            + '</p>'
          : '<p class="mission-inventory-state">Kept in storage</p>')
        + '</div></article>';
    }).join('');
  }

  [inventoryTypeFilter, inventorySlotFilter, inventoryTierFilter, inventoryStateFilter].forEach(function (control) {
    if (control) control.addEventListener('change', function () { if (state.data) renderInventory(state.data); });
  });

  document.addEventListener('pw-auth-ready', load); window.setInterval(tickCountdowns, 1000); window.setInterval(tickCommandFeed, 1000); load();
}());
