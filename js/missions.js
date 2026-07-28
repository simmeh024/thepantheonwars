(function () {
  'use strict';

  var state = { data: null, serverOffset: 0, launchMission: null, launchProjection: null, launchPenaltyAck: false, rivalApproach: '',
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
  var launchRival = document.getElementById('mission-launch-rival');
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
  var inventoryMeters = document.getElementById('missions-inventory-meters');
  var inventoryBoosts = document.getElementById('missions-inventory-boosts');
  var inventoryRailCard = document.getElementById('mission-quartermaster-card');
  var stimBeltCard = document.getElementById('mission-stim-belt-card');
  var researchAlert = document.getElementById('mission-research-alert');
  var profileCard = document.getElementById('mission-profile-card');
  var contractCard = document.getElementById('mission-contract-card');
  var dailyCard = document.getElementById('mission-daily-card');
  var resultModal = document.getElementById('mission-result-modal');
  var resultInner = document.getElementById('mission-result-inner');
  var resultBody = document.getElementById('mission-result-body');
  var resultRerun = document.getElementById('mission-result-rerun');
  var resultError = document.getElementById('mission-result-error');

  /* Kept as a small DOM insertion so an older cached missions.html can still
   * receive the new contract choices when the script has cache-busted. */
  if (!launchRival && launchBrief) {
    launchRival = document.createElement('div');
    launchRival.id = 'mission-launch-rival';
    launchRival.className = 'mission-launch-rival';
    launchRival.hidden = true;
    launchBrief.insertAdjacentElement('afterend', launchRival);
  }

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
  /* ----------------------------------------------------------------------
   * Overlord affinity and the daily contract.
   *
   * The quiz has always written users.overlord_affinity and no gameplay system
   * has ever read it. The command card names the patron; the rail card carries
   * the one contract they issue today.
   *
   * The sigils are hand-duplicated from js/members.js, which is this codebase's
   * established convention for a small shared map (js/overlord.html carries a
   * third copy). Any new Overlord has to be added to all of them.
   * -------------------------------------------------------------------- */
  var OVERLORD_SIGILS = {
    'syn-dravus': { name: 'Syn Dravus', color: 'rgb(154, 96, 238)', svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>' },
    'malric-thorne': { name: 'Malric Thorne', color: 'rgb(204, 72, 80)', svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 18h16"/><path d="M4 18 3 8l5 4 4-7 4 7 5-4-1 10Z"/></svg>' },
    'korrus-vale': { name: 'Korrus Vale', color: 'rgb(159, 224, 65)', svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 20.5 7v10L12 22 3.5 17V7Z"/><circle cx="12" cy="12" r="3"/></svg>' },
    'lysara-venthe': { name: 'Lysara Venthe', color: 'rgb(68, 150, 237)', svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9c2-3 4-3 6 0s4 3 6 0 4-3 6 0"/><path d="M2 15c2-3 4-3 6 0s4 3 6 0 4-3 6 0"/></svg>' },
    'zura-kaleth': { name: 'Zura Kaleth', color: 'rgb(59, 148, 83)', svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v8"/><path d="M12 11 6 21"/><path d="M12 11l6 10"/><path d="M12 11 4 15"/><path d="M12 11l8 4"/></svg>' },
    'maerion-thal': { name: 'Maerion Thal', color: 'rgb(184, 111, 66)', svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 16c4-2 6-8 9-11 3 3 5 9 9 11-4 2-7 0-9-3-2 3-5 5-9 3Z"/></svg>' }
  };

  function overlordSigil(slug) {
    return OVERLORD_SIGILS[String(slug || '')] || null;
  }

  /* Overlord Control owns the colour; the sigil map is the fallback for an
   * Overlord whose accent has never been set, and a neutral gold is the floor
   * so a card is never rendered against an empty custom property -- setting one
   * to '' still counts as set and defeats a var() default. */
  function overlordAccent(overlord) {
    if (!overlord) return '#c9a227';
    var accent = String(overlord.accent_color || '').trim();
    if (accent) return accent;
    var sigil = overlordSigil(overlord.slug);
    return sigil ? sigil.color : '#c9a227';
  }

  function overlordAffinityMarkup(player) {
    var overlord = player && player.overlord;
    if (!overlord) {
      return '<a class="mission-profile-affinity is-empty" href="quiz.html">'
        + '<span class="mission-profile-affinity-sigil" aria-hidden="true">?</span>'
        + '<span class="mission-profile-affinity-copy"><small>Overlord affinity</small>'
        + '<strong>Take the Overlord Affinity quiz</strong></span></a>';
    }
    var sigil = overlordSigil(overlord.slug);
    var accent = overlordAccent(overlord);
    return '<a class="mission-profile-affinity" href="overlord.html?slug=' + encodeURIComponent(overlord.slug) + '"'
      + ' style="--overlord-accent:' + escapeHtml(accent) + '">'
      + '<span class="mission-profile-affinity-sigil" aria-hidden="true">' + (sigil ? sigil.svg : '&#9670;') + '</span>'
      + '<span class="mission-profile-affinity-copy"><small>Overlord affinity</small>'
      + '<strong>' + escapeHtml(overlord.name) + '</strong>'
      + (overlord.epithet ? '<em>' + escapeHtml(overlord.epithet) + '</em>' : '') + '</span></a>';
  }

  /* The rail card. A minified contract, in its Overlord's colour, or the reason
   * there is not one -- a locked rank, a missing quiz result and an Overlord
   * with nothing authored are three different situations and the card says
   * which rather than simply being absent for all three. */
  function renderOverlordContract(data) {
    if (!contractCard) return;
    var state = data.overlord_contract;
    if (!state || !state.ready) { contractCard.hidden = true; return; }
    contractCard.hidden = false;

    var overlord = state.overlord;
    var accent = overlordAccent(overlord);
    contractCard.style.setProperty('--overlord-accent', accent);
    var sigil = overlord ? overlordSigil(overlord.slug) : null;
    var head = '<span class="eyebrow">Daily Overlord contract</span>'
      + '<span class="mission-contract-head">'
      + '<span class="mission-contract-sigil" aria-hidden="true">' + (sigil ? sigil.svg : '&#9670;') + '</span>'
      + '<strong>' + escapeHtml(overlord ? overlord.name : 'Unaligned') + '</strong></span>';

    if (!state.unlocked) {
      contractCard.className = 'mission-contract-card is-locked';
      contractCard.innerHTML = head
        + '<p class="mission-contract-copy">Contracts are issued from reputation rank '
        + Number(state.rank_required) + '. You are rank ' + Number(state.rank) + '.</p>'
        + '<a class="mission-contract-link" href="reputation.html">Your standing <b aria-hidden="true">&rarr;</b></a>';
      return;
    }
    if (state.reason === 'no_affinity') {
      contractCard.className = 'mission-contract-card is-empty';
      contractCard.innerHTML = '<span class="eyebrow">Daily Overlord contract</span>'
        + '<p class="mission-contract-copy">No Overlord has claimed your service yet. Take the affinity quiz to be issued contracts.</p>'
        + '<a class="mission-contract-link" href="quiz.html">Take the quiz <b aria-hidden="true">&rarr;</b></a>';
      return;
    }
    if (state.reason === 'none_authored' || !state.contract) {
      contractCard.className = 'mission-contract-card is-empty';
      contractCard.innerHTML = head
        + '<p class="mission-contract-copy">' + escapeHtml(overlord ? overlord.name + ' has issued no contracts yet.' : 'No contract today.') + '</p>';
      return;
    }

    var contract = state.contract;
    var claimed = !!state.claimed_today;
    /* A contract already under way. The launch is refused server-side either
       way, so the card's job is to say so rather than to offer a button that
       cannot work -- the same standing rule as every other control here. */
    var inFlight = !claimed && !!state.in_flight;
    contractCard.className = 'mission-contract-card' + (claimed ? ' is-claimed' : (inFlight ? ' is-running' : ''));
    var reward = [];
    if (Number(contract.xp_reward) > 0) reward.push('+' + Number(contract.xp_reward) + ' XP');
    if (Number(contract.reputation_reward) > 0) reward.push('+' + Number(contract.reputation_reward) + ' rep');
    if (Number(contract.credit_reward) > 0) reward.push('+' + credits(contract.credit_reward) + ' cr');
    contractCard.innerHTML = head
      + '<strong class="mission-contract-name">' + escapeHtml(contract.name) + '</strong>'
      + '<span class="mission-contract-meta">' + escapeHtml(String(contract.mission_type).toUpperCase())
      + ' · ' + escapeHtml(missionDuration(contract.duration_seconds))
      + ' · ' + Number(contract.min_crew) + (Number(contract.max_crew) !== Number(contract.min_crew) ? '–' + Number(contract.max_crew) : '') + ' crew</span>'
      + (contract.is_contested ? '<span class="mission-contract-contested">Contested · ' + escapeHtml(contract.rival_faction_name || 'Rival recovery team') + '</span>' : '')
      + (reward.length ? '<span class="mission-contract-reward">' + escapeHtml(reward.join(' · ')) + '</span>' : '')
      + (claimed
        ? '<p class="mission-contract-copy is-done">Completed today. A new contract is issued at 00:00 UTC.</p>'
        : (inFlight
          ? '<p class="mission-contract-copy is-running">A contract is already under way. Collect it before accepting another.</p>'
          : '<button type="button" class="btn btn-solid mission-contract-launch" data-contract-id="' + Number(contract.id) + '">Accept contract</button>'));
  }

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
      + overlordAffinityMarkup(player)
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
    document.querySelectorAll('.mission-rival-progress[data-race-started-at][data-race-completes-at]').forEach(function (track) {
      var progress = missionRouteProgress(track.getAttribute('data-race-started-at'), track.getAttribute('data-race-completes-at'), false);
      var fill = track.querySelector('i');
      if (fill) fill.style.width = progress.toFixed(2) + '%';
      track.setAttribute('aria-valuenow', Math.round(progress));
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

  function rivalOutcomeLabel(outcome) {
    return {
      won: 'Rival beaten',
      safe: 'Safe cut',
      narrow_loss: 'Narrow rival loss',
      decisive_loss: 'Rival secured target',
      operation_failed: 'Operation failed'
    }[outcome] || 'Contested recovery';
  }

  function rivalRaceMarkup(mission) {
    if (!mission || !mission.is_contested) return '';
    var faction = String(mission.rival_faction_name || 'Independent recovery team');
    var approach = String(mission.rival_approach || 'secure');
    if (approach === 'safe') {
      return '<div class="mission-rival-race is-safe"><div><span>Contested recovery</span><strong>Safe cut selected</strong></div><p>Command avoided the rival clock. This run pays a reduced recovery share, but the target cannot be taken by ' + escapeHtml(faction) + '.</p></div>';
    }
    var crewProgress = missionRouteProgress(mission.started_at, mission.completes_at, false);
    var rivalProgress = missionRouteProgress(mission.started_at, mission.rival_completes_at, false);
    return '<div class="mission-rival-race"><div class="mission-rival-race-head"><span>Contested recovery</span><strong>' + escapeHtml(faction) + '</strong></div>'
      + '<div class="mission-rival-race-track is-crew"><span>Your crew</span><span class="mission-rival-progress" role="progressbar" aria-label="Your crew recovery progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + Math.round(crewProgress) + '" data-race-started-at="' + escapeHtml(mission.started_at || '') + '" data-race-completes-at="' + escapeHtml(mission.completes_at || '') + '"><i style="width:' + crewProgress.toFixed(2) + '%"></i></span></div>'
      + '<div class="mission-rival-race-track is-rival"><span>' + escapeHtml(faction) + '</span><span class="mission-rival-progress" role="progressbar" aria-label="Rival recovery progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + Math.round(rivalProgress) + '" data-race-started-at="' + escapeHtml(mission.started_at || '') + '" data-race-completes-at="' + escapeHtml(mission.rival_completes_at || '') + '"><i style="width:' + rivalProgress.toFixed(2) + '%"></i></span></div>'
      + '<p>' + (approach === 'push' ? 'Push ahead in effect: shorter clock, higher fatigue.' : 'Secure route in effect: normal recovery clock.') + '</p></div>';
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
      return '<article class="mission-active-card is-' + escapeHtml(mission.status) + watermark.className + '"' + watermark.style + '>' + missionRouteMarkup(mission, isCompleted) + '<div class="mission-card-top"><span class="mission-type">' + escapeHtml(mission.mission_type) + '</span><span class="mission-world">' + escapeHtml(mission.world_key) + '</span></div><h3>' + escapeHtml(mission.name) + '</h3><p class="mission-crew-line">' + escapeHtml((mission.crew_names || []).join(' · ')) + '</p>' + rivalRaceMarkup(mission) + '<div class="mission-active-footer"><div><strong class="mission-countdown" data-completes-at="' + escapeHtml(mission.completes_at) + '">' + (isCompleted ? 'Mission complete' : 'Calculating…') + '</strong><small>' + (isCompleted ? 'Ready for reward claim' : 'Completion verified by command') + '</small></div>' + action + '</div></article>';
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
    /* A stat sort reads the same total the card prints, which already includes
       equipment -- sorting by the level-derived value would rank a crew member
       above one the roster visibly shows as stronger. Missing or pre-migration
       values read as 0 rather than NaN, which would make the comparator
       incoherent and the order arbitrary. */
    var statSort = /^(strength|cunning|science|charisma)-desc$/.exec(sort);
    return visible.slice().sort(function (left, right) {
      var comparison = 0;
      if (sort === 'name-asc') comparison = String(left.name).localeCompare(String(right.name));
      if (sort === 'level-desc') comparison = Number(right.level) - Number(left.level);
      if (sort === 'level-asc') comparison = Number(left.level) - Number(right.level);
      if (sort === 'status') comparison = statusOrder[crewAvailability(left)] - statusOrder[crewAvailability(right)];
      /* Rarest first, ordered by CREW_TIERS rather than alphabetically: the
         names sort epic before rare before uncommon, which is neither the
         ladder nor its reverse. */
      if (sort === 'rarity-desc') comparison = CREW_TIERS.indexOf(crewTier(right.tier)) - CREW_TIERS.indexOf(crewTier(left.tier));
      if (statSort) comparison = (Number(right[statSort[1]]) || 0) - (Number(left[statSort[1]]) || 0);
      /* Level breaks a tie before the name does, but only for the rarity and
         stat sorts: four crew on 0 Science sorted by name alone put the most
         developed of them last as often as first. The older sorts keep their
         own name tie-break rather than quietly changing order. */
      if (!comparison && (statSort || sort === 'rarity-desc')) {
        comparison = (Number(right.level) || 0) - (Number(left.level) || 0);
      }
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
  /* Per-level role bonuses. The rates themselves come from the server
   * (pw_missions_role_rates(), shipped as data.role_rates) rather than being
   * written here: this file deliberately re-implements the projection maths so
   * the launch screen can respond without a round trip, but duplicating the
   * numbers it multiplies by means a retune has to be applied twice, and until
   * it is, the browser quietly disagrees with the server about the reward it
   * just promised. The literals below are only the pre-first-response
   * fallbacks. */
  var ROLE_RATE_FALLBACK = {
    Engineer: { duration_percent_per_level: 0.25 },
    Pathfinder: { xp_percent_per_level: 0.25 },
    Vanguard: { reputation_per_level: 0.10 },
    Fixer: { credit_percent_per_level: 0.50 }
  };
  /* Rarity adds to the role's per-level rate, so a rare Engineer is worth more
     per level than a common one. Shipped by the server for exactly the reason
     the rates above are: a retune applied in one place only would leave the
     launch screen promising a reward the claim does not pay. */
  var TIER_BONUS_FALLBACK = { common: 0, uncommon: 0.05, rare: 0.15, epic: 0.20, legendary: 0.25 };
  function tierBonus(tier) {
    var table = (state.data && state.data.crew_tier_bonus) || TIER_BONUS_FALLBACK;
    var value = Number(table[String(tier || 'common').toLowerCase()]);
    return isFinite(value) ? value : 0;
  }
  function roleRates() {
    return (state.data && state.data.role_rates) || ROLE_RATE_FALLBACK;
  }
  function roleRate(role, key, tier) {
    var rates = roleRates()[role];
    if (!rates || !isFinite(Number(rates[key]))) return 0;
    return Number(rates[key]) + tierBonus(tier);
  }
  var ROLE_INFO = {
    Engineer: { stat: 'science', rate: 'duration_percent_per_level',
      effect: function (l, t) { return '−' + fmt(l * roleRate('Engineer', 'duration_percent_per_level', t)) + '% mission time'; },
      copy: function (t) { var r = roleRate('Engineer', 'duration_percent_per_level', t);
        return 'Engineers shorten every operation they join by ' + fmt(r) + '% per level. This stacks across the crew, so three level-2 Engineers cut ' + fmt(r * 6) + '% from the clock.'; } },
    Pathfinder: { stat: 'charisma', rate: 'xp_percent_per_level',
      effect: function (l, t) { return '+' + fmt(l * roleRate('Pathfinder', 'xp_percent_per_level', t)) + '% crew XP'; },
      copy: function (t) { return 'Pathfinders raise the experience the whole crew earns by ' + fmt(roleRate('Pathfinder', 'xp_percent_per_level', t)) + '% per level, on top of their own Charisma.'; } },
    Vanguard: { stat: 'strength', rate: 'reputation_per_level',
      effect: function (l, t) { return '+' + fmt(l * roleRate('Vanguard', 'reputation_per_level', t)) + ' reputation'; },
      copy: function (t) { return 'Vanguards add ' + fmt(roleRate('Vanguard', 'reputation_per_level', t)) + ' flat reputation per level to a successful mission, on top of the operation\u2019s own reward.'; } },
    Fixer: { stat: 'cunning', rate: 'credit_percent_per_level',
      effect: function (l, t) { return '+' + fmt(l * roleRate('Fixer', 'credit_percent_per_level', t)) + '% credits'; },
      copy: function (t) { return 'Fixers raise the credits a successful operation pays by ' + fmt(roleRate('Fixer', 'credit_percent_per_level', t)) + '% per level. The only role with no operation-type affinity, so a Fixer earns the same on every kind of work.'; } }
  };
  /* One phrasing per role effect, keyed by the rate the server names in
     role_effect. Separate from ROLE_INFO because that one multiplies a level by
     a rate; this one is handed the resolved total and only has to word it. */
  var ROLE_EFFECT_SHAPE = {
    duration_percent_per_level: function (v) { return fmt(v) + '% faster'; },
    xp_percent_per_level: function (v) { return '+' + fmt(v) + '% crew XP'; },
    reputation_per_level: function (v) { return '+' + fmt(v) + ' reputation'; },
    credit_percent_per_level: function (v) { return '+' + fmt(v) + '% credits'; }
  };
  function fmt(value) {
    var rounded = Math.round(value * 100) / 100;
    return String(rounded % 1 === 0 ? rounded : rounded.toFixed(2).replace(/0$/, ''));
  }

  /* The five rarities, resolved once. An unrecognised value reads as common,
     which is what the server does with it too. */
  var CREW_TIERS = ['common', 'uncommon', 'rare', 'epic', 'legendary'];
  function crewTier(value) {
    var tier = String(value || '').toLowerCase();
    return CREW_TIERS.indexOf(tier) === -1 ? 'common' : tier;
  }
  function crewTierLabel(value) {
    var tier = crewTier(value);
    return tier.charAt(0).toUpperCase() + tier.slice(1);
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
      /* The label and the number share a row that wraps when the cell is too
         narrow for both. They are wrapped in their own element so the bar can
         stay on the cell's last row: without it a cell whose pair wrapped put
         its bar 7px lower than its neighbour's and the row went ragged. */
      return '<div class="crew-stat' + (capped ? ' is-max' : '') + ' is-' + key + '" tabindex="0" title="' + escapeHtml(tip) + '">'
        + '<span class="crew-stat-head">'
        + '<span class="crew-stat-key">' + info.short + '</span>'
        + '<span class="crew-stat-value">' + value + '</span></span>'
        + '<span class="crew-stat-bar"><i style="width:' + pct + '%"></i></span></div>';
    }).join('');

    var role = ROLE_INFO[crew.role];
    var roleLine = role
      ? '<p class="crew-stat-role" tabindex="0" title="' + escapeHtml(role.copy(crew.tier)) + '"><span>' + escapeHtml(crew.role) + ' bonus</span><strong>' + escapeHtml(role.effect(Number(crew.level) || 0, crew.tier)) + '</strong></p>'
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
      /* Continuous motion is for epic and legendary only. Eight shimmering
         cards is noise, and a common recruit shimmering would say the rarity
         means something it does not. The class is added by the observer below
         rather than here, so a card that is off-screen animates nothing. */
      var tier = crewTier(crew.tier);
      return '<article class="mission-crew-card is-tier-' + tier + ' ' + (deployed ? 'is-deployed' : '') + (availability === 'unavailable' ? ' is-unavailable' : '') + (favorite ? ' is-favorite' : '') + '"'
        + (tier === 'epic' || tier === 'legendary' ? ' data-crew-shine="1"' : '') + '>'
        + '<span class="mission-crew-tier">' + escapeHtml(crewTierLabel(tier)) + '</span>'
        + favoriteButton
        + '<div class="mission-crew-visual"><span class="mission-crew-portrait-wrap">' + portraitMarkup + statusDot + '</span>' + crewLoadoutStrip(crew) + '</div>'
        + '<div class="mission-crew-copy"><span class="crew-role">' + escapeHtml(crew.role) + '</span><h3>' + escapeHtml(crew.name) + '</h3>' + missionCopy + '<p>' + escapeHtml(crew.description) + '</p>'
        + '<div class="crew-progression ' + profile.className + (atMaxLevel ? ' is-max-level' : '') + '"><div class="crew-rank-insignia" aria-label="' + escapeHtml(crew.role) + ' level ' + crew.level + '"><span>' + profile.code + '</span><small>L' + crew.level + '</small></div><div class="crew-progression-copy"><div><span>' + profile.rankLabel + '</span><strong>' + rankValue + '</strong></div><div class="crew-xp-track"><span style="width:' + progress + '%"></span></div></div></div>'
        + fatigueMarkup(crew)
        + crewStatCard(crew) + '</div></article>';
    }).join('');
    watchShine();
  }

  /* Continuous rarity motion runs only while the card is on screen. A roster
     paginates to several cards and a player can leave the page open, so an
     always-running gradient and halo per card is a cost paid for something
     nobody is looking at -- the same discipline the atlas and Known Figures
     already apply to their own loops.

     The observer is built once and re-pointed at each render; disconnecting
     and rebuilding one per render would leak an observer per page turn. */
  var shineObserver = null;
  function watchShine() {
    if (prefersReducedMotion()) return;
    if (!('IntersectionObserver' in window)) {
      // No observer: show the effect rather than withhold it. The gating is an
      // efficiency, not part of what the rarity means.
      Array.prototype.forEach.call(document.querySelectorAll('[data-crew-shine]'), function (card) {
        card.classList.add('is-animated');
      });
      return;
    }
    if (!shineObserver) {
      shineObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          entry.target.classList.toggle('is-animated', entry.isIntersecting);
        });
      }, { rootMargin: '120px' });
    }
    shineObserver.disconnect();
    Array.prototype.forEach.call(document.querySelectorAll('[data-crew-shine]'), function (card) {
      shineObserver.observe(card);
    });
  }
  function prefersReducedMotion() {
    return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
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
      Engineer: { className: 'is-engineer', code: 'EN', rankLabel: 'Relay rank' },
      Fixer: { className: 'is-fixer', code: 'FX', rankLabel: 'Exchange rank' }
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
      var outcome = mission.is_contested && mission.rival_outcome ? rivalOutcomeLabel(mission.rival_outcome) : (failed ? 'Failed' : 'Claimed');
      return '<article class="mission-history-row' + (failed ? ' is-failed' : '') + '"><div><span class="mission-world">' + escapeHtml(mission.world_key) + '</span><strong>' + escapeHtml(mission.name) + '</strong><p>' + escapeHtml((mission.crew_names || []).join(' · ')) + (mission.is_contested ? ' · ' + escapeHtml(mission.rival_faction_name || 'Rival recovery team') : '') + '</p></div><div><small>Completed</small><span>' + formatDate(mission.completed_at) + '</span></div><div><small>Rewards</small><span>' + escapeHtml(rewards) + '</span></div><div><span class="mission-history-status' + (failed ? ' is-failed' : '') + '">' + escapeHtml(outcome) + '</span></div></article>';
    }).join('');
  }

  function render(data) {
    state.data = data;
    var server = apiDate(data.server_time);
    state.serverOffset = server && !isNaN(server) ? server.getTime() - Date.now() : 0;
    applyWatermark(data.watermark); renderWeather(data); renderProfile(data); renderDaily(data); renderStats(data); renderCrewOffers(data); renderCommandFeed(data); renderActive(data); renderDefinitions(data); renderCrew(data); renderInventory(data); renderHistory(data); renderOverlordContract(data);
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
      /* A promotion used to report only that it happened. These two lines say
         what it did: which stats moved, and where the role bonus now stands.
         Both sides come from the server, which already holds the pre-award and
         post-award figures -- the browser never re-derives a stat. */
      var before = member.stats_before || {};
      var after = member.stats || {};
      var moved = ['strength', 'cunning', 'science', 'charisma'].map(function (key) {
        var delta = (Number(after[key]) || 0) - (Number(before[key]) || 0);
        return delta > 0 ? '+' + delta + ' ' + STAT_INFO[key].short : '';
      }).filter(Boolean);
      /* The stat row is drawn for everyone, not only the promoted: a delta with
         no absolute beside it has nothing to be a delta of, and the debrief
         never showed a crew stat at all before this. */
      var statRow = '';
      if (Object.keys(after).length) {
        statRow = '<span class="mission-result-crew-stats">' + ['strength', 'cunning', 'science', 'charisma'].map(function (key) {
          var value = Number(after[key]) || 0;
          var delta = value - (Number(before[key]) || 0);
          var info = STAT_INFO[key];
          var tip = info.label + ' ' + value + (delta > 0 ? ', up ' + delta + ' this promotion' : '') + ' — ' + info.effect(value) + '.';
          return '<b class="is-' + key + (delta > 0 ? ' is-up' : '') + (value ? '' : ' is-zero') + '" title="' + escapeHtml(tip) + '">'
            + info.short + ' <i>' + value + '</i>'
            + (delta > 0 ? '<u>+' + delta + '</u>' : '') + '</b>';
        }).join('') + '</span>';
      }
      /* Idea 2: the line a player actually feels. The stat delta is abstract --
         "mission time 18.0% -> 18.5% faster" is the thing that changes about
         the next operation. Rendered only when it moved. */
      var roleLine = '';
      var effect = member.role_effect;
      if (effect && member.levelled_up && Number(effect.after) !== Number(effect.before)) {
        var shape = ROLE_EFFECT_SHAPE[effect.key];
        if (shape) {
          roleLine = '<span class="mission-result-crew-role">' + escapeHtml(member.role) + ' bonus · '
            + escapeHtml(shape(effect.before)) + ' <b aria-hidden="true">\u2192</b> <strong>' + escapeHtml(shape(effect.after)) + '</strong></span>';
        }
      }
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
        + '<span class="mission-result-crew-meta">' + escapeHtml(xpLine) + rest + '</span>'
        + roleLine + statRow + '</li>';
    }).join('');
    return '<div class="mission-result-block is-crew"><h4>Crew</h4><ul class="mission-result-crewlist">' + rows + '</ul></div>';
  }

  function showResult(result) {
    if (!resultModal || !resultBody) return;
    state.resultRerun = result.mission_id && (result.crew_ids || []).length
      ? { missionId: Number(result.mission_id), crewIds: (result.crew_ids || []).map(Number) }
      : null;
    var failed = result.succeeded === false;
    var rival = result.rival && result.rival.contested ? result.rival : null;
    var rivalLoss = rival && (rival.outcome === 'narrow_loss' || rival.outcome === 'decisive_loss');
    resultInner.classList.toggle('is-failed', failed);
    resultInner.classList.toggle('is-success', !failed);
    resultInner.classList.toggle('is-rival-loss', !!rivalLoss);
    resultInner.classList.toggle('is-rival-win', !!(rival && rival.outcome === 'won'));
    var title = failed ? 'Mission failed' : 'Mission complete';
    var lead = failed
      ? 'Your crew is back at command with nothing recovered, and this run does not count towards a campaign unlock.'
      : 'Your crew has returned. Command has logged the following against your record.';
    var rivalMarkup = '';
    if (rival) {
      var rivalTitle = rivalOutcomeLabel(rival.outcome);
      var rivalCopy = '';
      if (rival.outcome === 'won') {
        title = 'Target secured first';
        lead = 'Your crew reached the recovery site before the opposing team and secured the full haul.';
        rivalCopy = 'Command beat ' + (rival.faction || 'the rival recovery team') + ' to the target.' + (Number(rival.bonus_credits) > 0 ? ' The contract also paid a +' + credits(rival.bonus_credits) + ' credit first-recovery bonus.' : '');
      } else if (rival.outcome === 'safe') {
        title = 'Safe extraction complete';
        lead = 'Your crew took the secured route and returned without giving the rival a chance at the target.';
        rivalCopy = 'Safe Cut avoided ' + (rival.faction || 'the rival recovery team') + ', paying the stated 60% recovery share with no headline find.';
      } else if (rival.outcome === 'narrow_loss') {
        title = 'Rival secured the target';
        lead = 'Your crew completed the operation, but the rival recovery team arrived first.';
        rivalCopy = (rival.faction || 'The rival recovery team') + ' beat the crew by a narrow margin. Command paid the 40% recovery share; no headline find was available.';
      } else if (rival.outcome === 'decisive_loss') {
        title = 'Rival ran the field';
        lead = 'Your crew reached the operation site after the target had already been cleared.';
        rivalCopy = (rival.faction || 'The rival recovery team') + ' secured the field decisively. The crew retains 25% XP for the work, but no reputation, credits, or recovery haul.';
      } else {
        rivalCopy = 'The crew did not complete the operation before the contested recovery could be judged.';
      }
      rivalMarkup = '<div class="mission-result-rival is-' + escapeHtml(rival.outcome || 'operation_failed') + '"><span>Contested recovery</span><strong>' + escapeHtml(rivalTitle) + '</strong><p>' + escapeHtml(rivalCopy) + '</p></div>';
    }

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
      /* A new crew member was a line of text in a list. It is the rarest thing
         a mission produces and the only reward that is a character rather than
         a number, so it arrives as a card with a face -- at every rarity,
         because a common recruit joining is still someone joining. What scales
         with rarity is how much arrives, which is CSS: a common card settles, an
         epic one is swept and blooms, a legendary one is struck. */
      extras += '<div class="mission-result-block is-recruit"><h4>' + (result.crew_recruited.length === 1 ? 'New crew member' : 'New crew members') + '</h4><ul class="mission-result-recruits">'
        + result.crew_recruited.map(function (member) {
          var tier = crewTier(member.tier);
          var portrait = safeImage(member.portrait_url);
          var face = portrait
            ? '<img src="' + escapeHtml(portrait) + '" alt="">'
            : '<span aria-hidden="true">' + escapeHtml(String(member.name || '?').charAt(0)) + '</span>';
          return '<li class="mission-result-recruit is-tier-' + tier + '">'
            + '<span class="mission-result-recruit-face">' + face + '</span>'
            + '<span class="mission-result-recruit-copy"><small>' + escapeHtml(crewTierLabel(tier)) + '</small>'
            + '<strong>' + escapeHtml(member.name) + '</strong>'
            + '<em>' + escapeHtml(member.role) + '</em></span></li>';
        }).join('') + '</ul></div>';
    }
    if (!failed && result.crew_duplicates && result.crew_duplicates.length) {
      /* A duplicate used to be reported as "nothing was added", which was
         true and worth nothing. It pays credits at the recruit's own rarity
         now, so the note names the rate rather than a bare sum -- otherwise a
         player cannot tell a legendary stand-in from a common one. */
      var duplicateNames = result.crew_duplicates.map(function (member) {
        var paid = Number(member.duplicate_credits) || 0;
        return escapeHtml(member.name) + (paid > 0 ? ' <b>+' + credits(paid) + ' cr</b>' : '');
      }).join(', ');
      var duplicateTotal = Number(result.crew_duplicate_credits) || 0;
      extras += '<p class="mission-result-note">' + duplicateNames + (result.crew_duplicates.length === 1 ? ' was' : ' were')
        + ' already on your roster'
        + (duplicateTotal > 0
          ? ', so the contract paid out instead — ' + escapeHtml(credits(duplicateTotal)) + ' credits.'
          : ', so nothing was added.') + '</p>';
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
    /* Rewards the operation earned but the quartermaster had no room for. Said
     * plainly rather than dropped in silence: the player watched the roll land,
     * and an unexplained shortfall reads as the game having miscounted. */
    if (result.loot_skipped && result.loot_skipped.length) {
      extras += '<div class="mission-result-block is-warning"><h4>Left behind &mdash; storage full</h4><ul class="mission-result-loot">'
        + result.loot_skipped.map(function (entry) {
          return '<li><span>' + escapeHtml(entry.name)
            + (entry.quantity > 1 ? ' <b class="mission-result-gear-count">&times;' + entry.quantity + '</b>' : '')
            + '</span><em>no room</em></li>';
        }).join('')
        + '</ul><p class="mission-result-note">Destroy something in your inventory to make room before the next operation.</p></div>';
    }
    extras += crewAftermathMarkup(result);
    if (failed) extras += failureFactorsMarkup(result);

    resultBody.innerHTML = '<span class="eyebrow">' + (failed ? 'Debrief · loss' : 'Debrief · recovery') + '</span>'
      + '<h2 id="mission-result-title">' + escapeHtml(title) + '</h2>'
      + '<p class="mission-result-mission">' + escapeHtml(result.mission_name || '') + '</p>'
      + '<p class="mission-result-lead">' + escapeHtml(lead) + '</p>'
      + rivalMarkup
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
    if (result.rival && result.rival.contested) {
      var rivalLabel = rivalOutcomeLabel(result.rival.outcome);
      parts.unshift(rivalLabel + ' against ' + (result.rival.faction || 'a rival recovery team'));
      if (Number(result.rival.bonus_credits) > 0) parts.push('+' + credits(result.rival.bonus_credits) + ' first-recovery bonus');
    }
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
    /* Nothing is painted until the session is actually known. loggedIn alone
     * cannot tell "signed out" from "not asked yet", so gating on it here is
     * what made a signed-in visitor see the Log In panel on every load. */
    if (!window.PW_AUTH || !window.PW_AUTH.resolved) return Promise.resolve();
    if (!window.PW_AUTH.loggedIn) { gate.hidden = false; content.hidden = true; return Promise.resolve(); }
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
    var durationPercent = 0, xpPercent = 0, reputationFlat = 0, roleCreditPercent = 0;

    crew.forEach(function (member) {
      var level = Math.max(0, Math.min(Number(member.max_level) || 50, Number(member.level) || 0));
      Object.keys(totals).forEach(function (stat) { totals[stat] += Math.max(0, Number(member[stat]) || 0); });
      durationPercent += level * roleRate(member.role, 'duration_percent_per_level', member.tier);
      xpPercent += level * roleRate(member.role, 'xp_percent_per_level', member.tier);
      reputationFlat += level * roleRate(member.role, 'reputation_per_level', member.tier);
      roleCreditPercent += level * roleRate(member.role, 'credit_percent_per_level', member.tier);
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
    var contestApproach = mission.is_contested ? (state.rivalApproach || 'secure') : '';
    var rewardMultiplier = contestApproach === 'safe' ? 0.60 : 1;
    if (contestApproach === 'push') seconds = Math.max(1, Math.round(seconds * 0.85));
    var projectedDuration = Math.max(30, Math.min(Math.round(baseSeconds * (1 + (penaltyDuration / 100))), seconds));
    var projectedCredits = Math.round((Number(mission.credit_reward) || 0) * (1 + ((affinity.credit_percent + roleCreditPercent + researchCredits) / 100)));
    var projectedReputation = Math.round((Number(mission.reputation_reward) || 0) * (1 + ((affinity.reputation_percent + researchReputation) / 100))) + Math.floor(reputationFlat);
    var projectedXp = Math.round((Number(mission.xp_reward) || 0) * (1 + (xpPercent / 100)));
    if (rewardMultiplier < 1) {
      projectedCredits = Math.floor(projectedCredits * rewardMultiplier);
      projectedReputation = Math.floor(projectedReputation * rewardMultiplier);
      projectedXp = Math.floor(projectedXp * rewardMultiplier);
    }

    return {
      crew: crew,
      matched: matched,
      penalty: penalty,
      affinity: affinity,
      weather: conditions,
      penalty_duration_percent: penaltyDuration,
      penalty_success_percent: penaltySuccess,
      duration_seconds: projectedDuration,
      base_duration_seconds: baseSeconds,
      success_percent: Math.max(5, Math.min(100, Math.round(baseSuccess + (totals.strength * 0.5) + affinity.success_percent - penaltySuccess))),
      base_success_percent: baseSuccess,
      credits: projectedCredits,
      base_credits: Number(mission.credit_reward) || 0,
      reputation: projectedReputation,
      base_reputation: Number(mission.reputation_reward) || 0,
      xp: projectedXp,
      base_xp: Number(mission.xp_reward) || 0,
      rival_approach: contestApproach,
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

  function renderLaunchRival() {
    var mission = state.launchMission;
    if (!launchRival) return;
    if (!mission || !mission.is_contested) { launchRival.hidden = true; launchRival.innerHTML = ''; return; }
    var faction = String(mission.rival_faction_name || 'Independent recovery team');
    var selected = state.rivalApproach || 'secure';
    var options = [
      { key: 'push', title: 'Push ahead', copy: '15% faster. Costs 25% more fatigue; best chance to beat the rival.' },
      { key: 'secure', title: 'Secure route', copy: 'Normal recovery clock and normal rewards. Race ' + faction + ' to the target.' },
      { key: 'safe', title: 'Safe cut', copy: 'No rival race. Earn 60% XP, reputation and credits; no headline recovery.' }
    ];
    launchRival.hidden = false;
    launchRival.innerHTML = '<div class="mission-launch-rival-head"><span>Contested recovery</span><strong>Rival: ' + escapeHtml(faction) + '</strong></div><p>Choose your recovery doctrine before deployment. Command locks it to this run.</p><div class="mission-launch-rival-options" role="radiogroup" aria-label="Rival recovery approach">'
      + options.map(function (option) {
        var active = option.key === selected;
        return '<button type="button" class="mission-launch-rival-option' + (active ? ' is-selected' : '') + '" data-rival-approach="' + option.key + '" role="radio" aria-checked="' + (active ? 'true' : 'false') + '"><strong>' + escapeHtml(option.title) + '</strong><span>' + escapeHtml(option.copy) + '</span></button>';
      }).join('') + '</div>';
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
    var rivalNote = projection.rival_approach === 'push'
      ? 'Push Ahead selected: command has shortened this crew’s real clock by 15% and will charge 25% more fatigue.'
      : projection.rival_approach === 'safe'
        ? 'Safe Cut selected: the rival cannot take the target, but the displayed XP, reputation and credits are reduced to 60%; no headline recovery can be claimed.'
        : projection.rival_approach === 'secure'
          ? 'Secure Route selected: normal clock and full recovery rewards. Reach the target before the rival team.'
          : '';
    launchProjection.innerHTML = '<dl class="mission-projection-grid">' + rows + '</dl>'
      + (note ? '<p class="mission-projection-note' + (projection.penalty ? ' is-warning' : ' is-good') + '">' + escapeHtml(note) + '</p>' : '')
      + (weatherNote ? '<p class="mission-projection-note is-weather">' + escapeHtml(weatherNote) + '</p>' : '')
      + (rivalNote ? '<p class="mission-projection-note is-rival">' + escapeHtml(rivalNote) + '</p>' : '')
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
    if (role && level > 0) lines.push({ tone: '', text: role.effect(level, member.tier) });
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
    /* A daily contract is deliberately absent from state.data.missions -- it is
     * withdrawn from the ordinary board server-side -- so it is looked up
     * separately here rather than being folded into that list, where it would
     * also have appeared as a card among the regular operations. */
    if (!mission) {
      var contract = state.data && state.data.overlord_contract && state.data.overlord_contract.contract;
      if (contract && Number(contract.id) === Number(missionId)) mission = contract;
    }
    if (!mission) return;
    state.launchMission = mission; state.launchPenaltyAck = false; state.rivalApproach = mission.is_contested ? 'secure' : ''; launchError.textContent = '';
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
    renderLaunchRival();
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
    state.launchMission = null; state.launchProjection = null; state.launchPenaltyAck = false; state.rivalApproach = '';
    if (launchRival) { launchRival.hidden = true; launchRival.innerHTML = ''; }
    launchConfirm.textContent = 'Launch Mission';
  }

  definitionList.addEventListener('click', function (event) { var button = event.target.closest('.mission-launch-btn'); if (button && !button.disabled) openLaunch(button.getAttribute('data-mission-id')); });
  /* The contract uses the same launch modal as every other operation: it is an
   * ordinary mission definition with an Overlord attached, so crew selection,
   * fatigue and the projection all apply to it unchanged. */
  if (contractCard) contractCard.addEventListener('click', function (event) {
    var button = event.target.closest('[data-contract-id]');
    if (button && !button.disabled) openLaunch(button.getAttribute('data-contract-id'));
  });
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
  if (launchRival) launchRival.addEventListener('click', function (event) {
    var option = event.target.closest('[data-rival-approach]');
    if (!option || !state.launchMission || !state.launchMission.is_contested) return;
    state.rivalApproach = option.getAttribute('data-rival-approach') || 'secure';
    state.launchPenaltyAck = false;
    launchError.textContent = '';
    renderLaunchRival();
    updateLaunchState(null);
  });

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
    post('/api/missions/start.php', { mission_id: state.launchMission.id, crew_ids: crewIds, rival_approach: state.launchMission.is_contested ? state.rivalApproach : '', csrf: window.PW_AUTH.csrf }).then(function () { closeLaunch(); setStatus('Mission launched. Your crew is now in the field.'); load(); }).catch(function (error) { launchError.textContent = error.message; }).finally(function () { launchConfirm.disabled = false; launchConfirm.classList.remove('is-busy'); });
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

  /* ----------------------------------------------------------------------
   * The choice dialog.
   *
   * Replaces window.prompt() and window.confirm(), which put a browser chrome
   * box over the page -- unstyled, unlocalised, and in the prompt's case asking
   * the player to type the number next to the option they wanted.
   *
   * One component rather than three, because the four callers differ only in
   * what they list: crew for a fatigue stim, stims for a quick slot, a quantity
   * for a bulk destroy, and nothing at all for a single confirm. It returns a
   * promise resolving to { value, quantity } or null on cancel, so each caller
   * reads the same as the prompt() it replaced.
   * -------------------------------------------------------------------- */
  var choiceModal = document.getElementById('mission-choice-modal');
  var choiceOptions = document.getElementById('mission-choice-options');
  var choiceQuantity = document.getElementById('mission-choice-quantity');
  var choiceQuantityInput = document.getElementById('mission-choice-quantity-input');
  var choiceError = document.getElementById('mission-choice-error');
  var choiceConfirm = document.getElementById('mission-choice-confirm');
  var choiceState = { resolve: null, options: [], selected: null, lastFocus: null };

  function closeChoice(result) {
    var resolve = choiceState.resolve;
    choiceState.resolve = null;
    if (choiceModal.open && typeof choiceModal.close === 'function') choiceModal.close();
    else choiceModal.removeAttribute('open');
    /* Focus goes back to whatever opened the dialog. Without this a keyboard
     * user is returned to the top of the document after every confirm, which is
     * the same defect the crew list re-render once had. */
    if (choiceState.lastFocus && document.contains(choiceState.lastFocus)) {
      try { choiceState.lastFocus.focus(); } catch (error) { /* removed mid-flight */ }
    }
    choiceState.lastFocus = null;
    if (resolve) resolve(result || null);
  }

  function renderChoiceOptions() {
    choiceOptions.hidden = !choiceState.options.length;
    choiceOptions.innerHTML = choiceState.options.map(function (option, index) {
      var active = String(option.value) === String(choiceState.selected);
      return '<button type="button" class="mission-choice-option' + (active ? ' is-active' : '') + '"'
        + ' role="radio" aria-checked="' + (active ? 'true' : 'false') + '"'
        + ' tabindex="' + (active || (choiceState.selected === null && index === 0) ? '0' : '-1') + '"'
        + ' data-choice-value="' + escapeHtml(String(option.value)) + '">'
        + '<span class="mission-choice-option-main">' + escapeHtml(option.label) + '</span>'
        + (option.meta ? '<span class="mission-choice-option-meta">' + escapeHtml(option.meta) + '</span>' : '')
        + '</button>';
    }).join('');
  }

  /* @param config {eyebrow, title, copy, options, confirmLabel, danger,
   *                quantity: {label, min, max, value}} */
  function openChoice(config) {
    if (!choiceModal) return Promise.resolve(null);
    if (choiceState.resolve) closeChoice(null);
    choiceState.options = config.options || [];
    choiceState.selected = choiceState.options.length ? String(choiceState.options[0].value) : null;
    choiceState.lastFocus = document.activeElement;
    document.getElementById('mission-choice-eyebrow').textContent = config.eyebrow || '';
    document.getElementById('mission-choice-title').textContent = config.title || '';
    var copy = document.getElementById('mission-choice-copy');
    copy.textContent = config.copy || '';
    copy.hidden = !config.copy;
    choiceError.textContent = '';
    choiceConfirm.textContent = config.confirmLabel || 'Confirm';
    choiceConfirm.classList.toggle('is-danger', !!config.danger);
    var quantity = config.quantity || null;
    choiceQuantity.hidden = !quantity;
    if (quantity) {
      document.getElementById('mission-choice-quantity-label').textContent = quantity.label || 'How many?';
      choiceQuantityInput.min = String(quantity.min || 1);
      choiceQuantityInput.max = String(quantity.max || 1);
      choiceQuantityInput.value = String(quantity.value || quantity.max || 1);
    }
    renderChoiceOptions();
    if (typeof choiceModal.showModal === 'function') choiceModal.showModal();
    else choiceModal.setAttribute('open', '');
    window.setTimeout(function () {
      var first = quantity ? choiceQuantityInput : choiceOptions.querySelector('[tabindex="0"]');
      (first || choiceConfirm).focus();
    }, 25);
    return new Promise(function (resolve) { choiceState.resolve = resolve; });
  }

  function submitChoice() {
    if (choiceState.options.length && choiceState.selected === null) {
      choiceError.textContent = 'Choose one of the options first.';
      return;
    }
    var quantity = null;
    if (!choiceQuantity.hidden) {
      /* Clamped rather than rejected: the field is bounded by min/max already,
       * and a typed 999 means "all of them" far more often than it means a
       * mistake worth an error message for. */
      var min = Number(choiceQuantityInput.min) || 1;
      var max = Number(choiceQuantityInput.max) || min;
      quantity = Math.max(min, Math.min(max, Math.round(Number(choiceQuantityInput.value) || 0)));
    }
    closeChoice({ value: choiceState.selected, quantity: quantity });
  }

  if (choiceModal) {
    choiceOptions.addEventListener('click', function (event) {
      var button = event.target.closest('[data-choice-value]');
      if (!button) return;
      choiceState.selected = button.getAttribute('data-choice-value');
      renderChoiceOptions();
      var active = choiceOptions.querySelector('.is-active');
      if (active) active.focus();
    });
    /* Arrow-key roving focus across the options, matching the quiz's own answer
     * list -- a radiogroup that only responds to Tab is not one. */
    choiceOptions.addEventListener('keydown', function (event) {
      var keys = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 };
      var step = keys[event.key];
      if (!step) return;
      event.preventDefault();
      var buttons = Array.prototype.slice.call(choiceOptions.querySelectorAll('[data-choice-value]'));
      if (!buttons.length) return;
      var current = buttons.indexOf(document.activeElement);
      var next = buttons[(current + step + buttons.length) % buttons.length];
      choiceState.selected = next.getAttribute('data-choice-value');
      renderChoiceOptions();
      var active = choiceOptions.querySelector('.is-active');
      if (active) active.focus();
    });
    choiceQuantityInput.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') { event.preventDefault(); submitChoice(); }
    });
    choiceConfirm.addEventListener('click', submitChoice);
    document.getElementById('mission-choice-cancel').addEventListener('click', function () { closeChoice(null); });
    document.getElementById('mission-choice-close').addEventListener('click', function () { closeChoice(null); });
    // Escape fires the dialog's own cancel event; the promise has to settle.
    choiceModal.addEventListener('cancel', function (event) { event.preventDefault(); closeChoice(null); });
    choiceModal.addEventListener('click', function (event) { if (event.target === choiceModal) closeChoice(null); });
  }

  /* One bar per ceiling. Both are always drawn, even at zero, because the panel
   * has to state the limit it is about to enforce -- a player who only finds
   * out about the cap when a reward is dropped has been told too late. */
  function inventoryMeterMarkup(usage, compact) {
    return [
      { label: 'Equipment & stims', used: Number(usage.inventory) || 0, cap: Number(usage.inventory_cap) || 0 },
      { label: 'Salvage', used: Number(usage.salvage) || 0, cap: Number(usage.salvage_cap) || 0 }
    ].map(function (meter) {
      var percent = meter.cap > 0 ? Math.min(100, Math.round((meter.used / meter.cap) * 100)) : 0;
      var tone = percent >= 100 ? ' is-full' : percent >= 85 ? ' is-tight' : '';
      return '<div class="mission-inventory-meter' + tone + (compact ? ' is-compact' : '') + '">'
        + '<span class="mission-inventory-meter-head"><span>' + escapeHtml(meter.label) + '</span>'
        + '<strong>' + meter.used + ' / ' + meter.cap + '</strong></span>'
        + '<span class="mission-inventory-meter-track"><i style="width:' + percent + '%"></i></span>'
        + '</div>';
    }).join('');
  }

  /* Time left on a running boost, in whichever unit reads clearly at that
   * distance. Recomputed from the expiry rather than counted down from a stored
   * number, so a backgrounded tab catches up rather than drifting. */
  function stimTimeLeft(value) {
    var ends = apiDate(value);
    var seconds = ends ? Math.max(0, Math.round((ends.getTime() - Date.now()) / 1000)) : 0;
    if (seconds < 1) return 'ending';
    if (seconds < 60) return seconds + 's left';
    var minutes = Math.floor(seconds / 60);
    if (minutes < 60) return minutes + 'm left';
    return Math.floor(minutes / 60) + 'h ' + (minutes % 60) + 'm left';
  }

  /* The rail card, directly under the Market. A summary and a way in -- the
   * full panel lives further down the command view, and this is what makes it
   * findable without scrolling the whole page looking for it. */
  function renderInventoryRail(data) {
    if (!inventoryRailCard) return;
    var loot = data.loot || [];
    if (!loot.length) { inventoryRailCard.hidden = true; return; }
    var usage = data.inventory || {};
    var stims = (data.inventory && data.inventory.active_stims) || [];
    var counts = { gear: 0, stim: 0, salvage: 0 };
    loot.forEach(function (item) {
      var category = item.category || (item.slot ? 'gear' : 'salvage');
      counts[category] = (counts[category] || 0) + (Number(item.quantity) || 0);
    });
    inventoryRailCard.hidden = false;
    inventoryRailCard.innerHTML = '<span class="eyebrow">Quartermaster</span>'
      + '<span class="mission-quartermaster-card-head"><span class="mission-quartermaster-card-icon" aria-hidden="true">&#9636;</span><strong>Inventory</strong></span>'
      + '<span class="mission-quartermaster-card-counts">' + counts.gear + ' equipment &middot; ' + counts.stim + ' stims &middot; ' + counts.salvage + ' salvage</span>'
      + inventoryMeterMarkup(usage, true)
      + (stims.length
        ? '<span class="mission-quartermaster-card-boost">' + escapeHtml(stims.length + ' boost' + (stims.length === 1 ? '' : 's') + ' running') + '</span>'
        : '')
      + '<a class="mission-quartermaster-card-link" href="#missions-inventory-section">Open inventory <b aria-hidden="true">&rarr;</b></a>';
  }

  function stimTypeMeta(key) {
    var types = (state.data && state.data.inventory && state.data.inventory.stim_effect_types) || {};
    return types[key] || null;
  }

  /* A stim's own line, in the unit it is actually measured in: fatigue points
   * for the instant one, a percentage and a run time for the two boosts. */
  function stimSummary(item) {
    var meta = stimTypeMeta(item.stim_effect);
    if (!meta) return '';
    var value = Number(item.stim_value) || 0;
    if (!meta.timed) return 'Restores ' + fmt(value) + ' fatigue to one resting crew member.';
    var minutes = Math.max(1, Math.round((Number(item.stim_duration_seconds) || 0) / 60));
    return '+' + fmt(value) + '% ' + meta.label.toLowerCase() + ' for ' + minutes + ' minute' + (minutes === 1 ? '' : 's') + '.';
  }

  function renderInventoryBoosts(data) {
    if (!inventoryBoosts) return;
    var stims = (data.inventory && data.inventory.active_stims) || [];
    if (!stims.length) { inventoryBoosts.innerHTML = ''; return; }
    inventoryBoosts.innerHTML = '<p class="mission-inventory-boosts-label">Running now</p>'
      + stims.map(function (stim) {
        var meta = stimTypeMeta(stim.effect_type);
        return '<span class="mission-inventory-boost" data-boost-expires="' + escapeHtml(String(stim.expires_at)) + '">'
          + '<strong>+' + fmt(Number(stim.effect_value) || 0) + '%</strong> '
          + escapeHtml(meta ? meta.label : stim.effect_type)
          + ' <em class="mission-inventory-boost-left">' + escapeHtml(stimTimeLeft(stim.expires_at)) + '</em></span>';
      }).join('');
  }

  /* Boosts expire on a clock the page already runs. Only the one text node is
   * rewritten, rather than re-rendering the panel every second -- that would
   * discard a focused control the same way the crew list once did. */
  function tickInventoryBoosts() {
    if (!inventoryBoosts) return;
    inventoryBoosts.querySelectorAll('[data-boost-expires]').forEach(function (node) {
      var left = node.querySelector('.mission-inventory-boost-left');
      if (left) left.textContent = stimTimeLeft(node.getAttribute('data-boost-expires'));
    });
  }

  /* The quick-slot belt in the right rail, under the commander card.
   *
   * Always the full grid, empty slots included: the grid is what shows the
   * player what a Quick slots protocol bought them, and a belt that drew only
   * its filled slots would make the research invisible.
   *
   * A slot whose stim has run out or been withdrawn renders as empty rather
   * than as an item that cannot be used -- the server leaves the assignment in
   * place, so reacquiring that stim puts it straight back. */
  function renderStimBelt(data) {
    if (!stimBeltCard) return;
    var belt = data.stim_slots;
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
        /* An assigned-but-unusable slot still reads as empty, and its label says
         * which of the two reasons applies -- "none left" on a stim the player
         * still holds four of would read as the belt having miscounted. */
        var reason = !item ? 'Empty quick slot'
          : !item.is_enabled ? item.name + ' — withdrawn from service'
          : item.name + ' — none left';
        var note = escapeHtml(reason);
        return '<button type="button" class="mission-stim-slot is-empty" data-stim-slot="' + Number(slot.slot_index) + '"'
          + ' title="' + note + '" aria-label="' + note + '. Choose a stim.">'
          + '<span class="mission-stim-slot-plus" aria-hidden="true">+</span></button>';
      }
      var label = item.name + ' — ' + stimSummary(item) + ' ' + item.quantity + ' held.';
      /* Icon and count only. A slot is 43px wide in the real rail, which fits
       * neither a stim's name nor its effect label -- measured, after the first
       * attempt shipped a clipped one. The full name and effect live in the
       * title and the aria-label, and the icon is tinted by effect so the three
       * kinds stay distinguishable even on the fallback glyph. */
      return '<span class="mission-stim-slot is-filled is-' + escapeHtml(item.tier)
        + ' is-effect-' + escapeHtml(item.stim_effect) + '">'
        + '<button type="button" class="mission-stim-slot-use" data-stim-use="' + Number(item.id) + '"'
        + ' title="' + escapeHtml(label) + '" aria-label="' + escapeHtml('Use ' + label) + '">'
        + '<span class="mission-stim-slot-icon">' + gearIconHtml('', item.icon_url) + '</span>'
        + '<span class="mission-stim-slot-count">' + Number(item.quantity) + '</span>'
        + '</button>'
        + '<button type="button" class="mission-stim-slot-clear" data-stim-slot-clear="' + Number(slot.slot_index) + '"'
        + ' title="Clear this quick slot" aria-label="' + escapeHtml('Clear ' + item.name + ' from quick slot ' + (Number(slot.slot_index) + 1)) + '">&times;</button>'
        + '</span>';
    }).join('');
    stimBeltCard.innerHTML = '<span class="eyebrow">Field kit</span>'
      + '<span class="mission-stim-belt-head"><strong>Stim belt</strong>'
      + '<span class="mission-stim-belt-count">' + filled + ' / ' + Number(belt.capacity) + '</span></span>'
      + '<div class="mission-stim-belt-grid" style="grid-template-columns:repeat(' + columns + ',minmax(0,1fr))">' + cells + '</div>'
      + '<p class="mission-stim-belt-note">' + (filled
        ? 'Click a stim to use it. Research widens the belt.'
        : 'Assign the stims you want one click away.') + '</p>';
  }

  /* Choosing what goes in an empty slot. The stims already on the belt are
   * excluded, since assigning one would move it rather than add it -- offering
   * that as a choice here would read as a way to hold two. */
  function assignStimSlot(button) {
    var slotIndex = Number(button.getAttribute('data-stim-slot'));
    var belt = (state.data && state.data.stim_slots) || { slots: [] };
    var slotted = {};
    (belt.slots || []).forEach(function (slot) { if (slot.item) slotted[Number(slot.item.id)] = true; });
    var candidates = ((state.data && state.data.loot) || []).filter(function (item) {
      /* is_enabled is not on the inventory payload, so a withdrawn stim is
       * filtered out by the server instead -- but an exhausted one is
       * excluded here rather than offered and refused a round trip later. */
      return item.category === 'stim' && Number(item.quantity) > 0 && !slotted[Number(item.id)];
    });
    if (!candidates.length) {
      setStatus(Object.keys(slotted).length
        ? 'Every stim you hold is already on the belt.'
        : 'You are not carrying any stims yet.', true);
      return;
    }
    openChoice({
      eyebrow: 'Field kit',
      title: 'Quick slot ' + (slotIndex + 1),
      copy: 'Pick the stim to keep one click away in this slot.',
      confirmLabel: 'Assign',
      options: candidates.map(function (item) {
        return { value: item.id, label: item.name + ' \u00d7' + item.quantity, meta: stimSummary(item) };
      })
    }).then(function (choice) {
      if (!choice) return null;
      button.disabled = true;
      return post('/api/missions/stim-slot.php', { slot_index: slotIndex, loot_definition_id: Number(choice.value), csrf: window.PW_AUTH.csrf })
        .then(function (result) { setStatus(result.message); return load(); })
        .catch(function (error) { button.disabled = false; setStatus(error.message, true); });
    });
  }

  function clearStimSlot(button) {
    var slotIndex = Number(button.getAttribute('data-stim-slot-clear'));
    button.disabled = true;
    post('/api/missions/stim-slot.php', { slot_index: slotIndex, loot_definition_id: null, csrf: window.PW_AUTH.csrf })
      .then(function (result) { setStatus(result.message); return load(); })
      .catch(function (error) { button.disabled = false; setStatus(error.message, true); });
  }

  if (stimBeltCard) {
    stimBeltCard.addEventListener('click', function (event) {
      var clear = event.target.closest('[data-stim-slot-clear]');
      if (clear && !clear.disabled) { clearStimSlot(clear); return; }
      var use = event.target.closest('[data-stim-use]');
      if (use && !use.disabled) { useStim(use); return; }
      var empty = event.target.closest('[data-stim-slot]');
      if (empty && !empty.disabled) assignStimSlot(empty);
    });
  }

  /* The mark in the corner of the Research Facility card when protocols are
   * ready to activate. A count, not a list: which nodes they are is the
   * Research Facility's own business, and naming them here would announce the
   * final branch to a player who has not opened it.
   *
   * The glyph is aria-hidden and the meaning lives in the visible tooltip and
   * an off-screen line, because a bare "!" read aloud is not a message. */
  function renderResearchAlert(data) {
    if (!researchAlert) return;
    var count = Number(data.research && data.research.unlockable_count) || 0;
    if (!data.research || !data.research.ready || count < 1) { researchAlert.hidden = true; return; }
    var text = count === 1
      ? 'One research protocol is ready to activate.'
      : count + ' research protocols are ready to activate.';
    researchAlert.hidden = false;
    researchAlert.title = text;
    researchAlert.innerHTML = '<span aria-hidden="true">!</span><span class="sr-only">' + escapeHtml(text) + '</span>';
  }

  function renderInventory(data) {
    renderStimBelt(data);
    renderResearchAlert(data);
    renderInventoryRail(data);
    if (!inventorySection || !inventoryList) return;
    var loot = data.loot || [];
    /* Hidden entirely while the player owns nothing: an empty quartermaster
     * panel says less than no panel at all, and loot only arrives from a
     * successful operation. */
    if (!loot.length) { inventorySection.hidden = true; return; }
    inventorySection.hidden = false;
    if (inventoryMeters) inventoryMeters.innerHTML = inventoryMeterMarkup(data.inventory || {}, false);
    renderInventoryBoosts(data);
    populateInventoryFilters(loot);
    var filters = inventoryFilters();
    var visible = loot.filter(function (item) {
      var category = item.category || (item.slot ? 'gear' : 'salvage');
      if (filters.type !== 'all' && filters.type !== category) return false;
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
      var category = item.category || (item.slot ? 'gear' : 'salvage');
      var isGear = category === 'gear';
      var isStim = category === 'stim';
      var bonus = gearBonusText(item.bonus);
      var spare = Number(item.quantity) - Number(item.equipped_count);
      var requires = [];
      if (Number(item.required_level) > 1) requires.push('Level ' + item.required_level);
      if (item.required_role) requires.push(item.required_role + ' only');
      var typeLabel = isGear ? slotLabel(item.slot) : isStim ? 'Stim' : 'Salvage';
      /* Only spare copies get a Destroy button. An equipped copy is not
       * deducted from the quantity ledger, so destroying one would leave a crew
       * member wearing something nobody owns -- the server refuses it too. */
      var actions = [];
      if (isStim) {
        actions.push('<button type="button" class="btn btn-solid mission-inventory-use" data-stim-use="' + Number(item.id) + '">Use</button>');
      }
      if (spare > 0) {
        actions.push('<button type="button" class="btn mission-inventory-destroy" data-destroy-item="' + Number(item.id) + '"'
          + ' data-item-name="' + escapeHtml(item.name) + '" data-spare="' + spare + '">Destroy</button>');
      }
      return '<article class="mission-inventory-card is-' + escapeHtml(item.tier) + ' is-' + escapeHtml(category) + '">'
        + '<span class="mission-inventory-icon">' + gearIconHtml(item.slot, item.icon_url) + '</span>'
        + '<div class="mission-inventory-copy"><h3>' + escapeHtml(item.name) + '</h3>'
        + '<p class="mission-inventory-meta"><span class="mission-inventory-tier">' + escapeHtml(item.tier) + '</span>'
        + ' &middot; ' + escapeHtml(typeLabel)
        + ' &middot; x' + item.quantity + '</p>'
        + (bonus ? '<p class="mission-inventory-bonus">' + escapeHtml(bonus) + '</p>' : '')
        + (isStim ? '<p class="mission-inventory-bonus">' + escapeHtml(stimSummary(item)) + '</p>' : '')
        + (item.description ? '<p class="mission-inventory-desc">' + escapeHtml(item.description) + '</p>' : '')
        + (requires.length ? '<p class="mission-inventory-requires">' + escapeHtml(requires.join(' · ')) + '</p>' : '')
        + (isGear
          ? '<p class="mission-inventory-state' + (item.equipped_count > 0 ? ' is-active' : '') + '">'
            + escapeHtml(item.equipped_count > 0
              ? item.equipped_count + ' in use' + (spare > 0 ? ', ' + spare + ' spare' : '')
              : 'Not assigned to anyone')
            + '</p>'
          : '<p class="mission-inventory-state">' + escapeHtml(isStim ? 'Ready to use' : 'Kept for research') + '</p>')
        + (actions.length ? '<div class="mission-inventory-actions">' + actions.join('') + '</div>' : '')
        + '</div></article>';
    }).join('');
  }

  /* Destroying is permanent and there is no sell path, so it asks first. The
   * quantity prompt exists because the reason a player is here at all is that
   * they are at a ceiling -- clearing space one confirm at a time would be
   * a hundred dialogs. */
  function destroyInventoryItem(button) {
    var itemId = Number(button.getAttribute('data-destroy-item'));
    var name = button.getAttribute('data-item-name') || 'this item';
    var spare = Number(button.getAttribute('data-spare')) || 1;
    openChoice({
      eyebrow: 'Quartermaster',
      title: 'Destroy ' + name + '?',
      copy: spare > 1
        ? 'You have ' + spare + ' spare. This cannot be undone.'
        : 'This cannot be undone.',
      confirmLabel: 'Destroy',
      danger: true,
      // Only asked when there is a choice to make; a single spare copy is a
      // plain confirmation rather than a form with one possible answer.
      quantity: spare > 1 ? { label: 'How many to destroy', min: 1, max: spare, value: spare } : null
    }).then(function (choice) {
      if (!choice) return null;
      button.disabled = true;
      return post('/api/missions/inventory-destroy.php', { loot_definition_id: itemId, quantity: choice.quantity || 1, csrf: window.PW_AUTH.csrf })
        .then(function (result) { setStatus(result.message); return load(); })
        .catch(function (error) { button.disabled = false; setStatus(error.message, true); });
    });
  }

  /* A fatigue stim needs a target, so the player picks one rather than the page
   * guessing. Deployed crew are left off the list because rest does not accrue
   * in the field -- the server refuses them for the same reason. */
  function useStim(button) {
    var itemId = Number(button.getAttribute('data-stim-use'));
    var item = ((state.data && state.data.loot) || []).filter(function (entry) { return Number(entry.id) === itemId; })[0];
    if (!item) return;
    var payload = { loot_definition_id: itemId, csrf: window.PW_AUTH.csrf };
    if (item.stim_effect === 'fatigue') {
      var candidates = ((state.data && state.data.crew) || []).filter(function (member) {
        return member.status === 'available' && Number(member.fatigue) < Number(member.fatigue_max);
      });
      if (!candidates.length) { setStatus('Every crew member standing by is already fully rested.', true); return; }
      openChoice({
        eyebrow: 'Field kit',
        title: 'Give ' + item.name,
        copy: 'Only crew standing by are listed — rest does not accrue in the field.',
        confirmLabel: 'Give stim',
        options: candidates.map(function (member) {
          return { value: member.id, label: member.name, meta: member.fatigue + ' / ' + member.fatigue_max + ' fatigue' };
        })
      }).then(function (choice) {
        if (!choice) return null;
        payload.crew_id = Number(choice.value);
        return sendStim(button, payload);
      });
      return;
    }
    sendStim(button, payload);
  }

  function sendStim(button, payload) {
    button.disabled = true;
    return post('/api/missions/stim-use.php', payload)
      .then(function (result) { setStatus(result.message); return load(); })
      .catch(function (error) { button.disabled = false; setStatus(error.message, true); });
  }

  if (inventoryList) {
    inventoryList.addEventListener('click', function (event) {
      var destroy = event.target.closest('[data-destroy-item]');
      if (destroy && !destroy.disabled) { destroyInventoryItem(destroy); return; }
      var use = event.target.closest('[data-stim-use]');
      if (use && !use.disabled) useStim(use);
    });
  }

  [inventoryTypeFilter, inventorySlotFilter, inventoryTierFilter, inventoryStateFilter].forEach(function (control) {
    if (control) control.addEventListener('change', function () { if (state.data) renderInventory(state.data); });
  });

  document.addEventListener('pw-auth-ready', load); window.setInterval(tickCountdowns, 1000); window.setInterval(tickCommandFeed, 1000); window.setInterval(tickInventoryBoosts, 1000); load();
}());
