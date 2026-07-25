/* The Market is a readout of server-created rotations. Purchases, reputation
 * gates, credits and mission state remain server-authoritative throughout. */
(function () {
  'use strict';

  var state = { data: null, serverOffset: 0, busyOffer: null };
  var gate = document.getElementById('market-gate');
  var content = document.getElementById('market-content');
  var gearList = document.getElementById('market-gear-list');
  var characterList = document.getElementById('market-character-list');
  var status = document.getElementById('market-status');
  var creditsEl = document.getElementById('market-credits');
  var commandCopy = document.getElementById('market-command-copy');
  var activeMissions = document.getElementById('market-active-missions');

  function esc(value) { var el = document.createElement('div'); el.textContent = value == null ? '' : String(value); return el.innerHTML; }
  function apiDate(value) { return value ? new Date(String(value).replace(' ', 'T') + 'Z') : null; }
  function credits(value) { return Math.max(0, Number(value) || 0).toLocaleString(); }
  function timeLeft(value) { var date = apiDate(value), seconds = date ? Math.max(0, Math.ceil((date.getTime() - (Date.now() + state.serverOffset)) / 1000)) : 0, h = Math.floor(seconds / 3600), m = Math.floor((seconds % 3600) / 60), s = seconds % 60; return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0'); }
  function formatUtc(value) { var date = apiDate(value); return date && !isNaN(date) ? date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', timeZoneName: 'short' }) : 'next UTC window'; }
  function safeImage(url) { return /^(?:images\/[a-zA-Z0-9._-]+|\/uploads\/mission-crew-images\/img_[a-f0-9]{16}\.(?:jpg|png|webp))$/.test(String(url || '')) ? String(url) : ''; }
  function gearGlyph() { return '<svg viewBox="0 0 32 32" aria-hidden="true"><path d="m18.5 4 3.3 3.3-4.6 4.6 3 3 4.6-4.6 3.3 3.3-8.2 8.2-3.7-3.7L8 25.7 6.3 24l7.2-7.2-3.7-3.7L18.5 4Z"/><path d="m5 27 3-3"/></svg>'; }
  function characterGlyph() { return '<svg viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="10" r="5"/><path d="M6.5 28c.8-6 4.3-9 9.5-9s8.7 3 9.5 9"/></svg>'; }
  function gearStats(offer) { var stats = [['STR', offer.bonus_strength], ['CUN', offer.bonus_cunning], ['SCI', offer.bonus_science], ['CHA', offer.bonus_charisma]].filter(function (stat) { return Number(stat[1]) !== 0; }); return stats.length ? stats.map(function (stat) { return '<span><b>' + esc(stat[0]) + '</b> ' + (Number(stat[1]) > 0 ? '+' : '') + Number(stat[1]) + '</span>'; }).join('') : '<span>Field equipment</span>'; }

  function offerCard(offer, type) {
    var isGear = type === 'gear';
    var soldOut = Number(offer.stock_remaining) < 1;
    var cannotAfford = Number((state.data || {}).credits || 0) < Number(offer.credit_price);
    var disabled = soldOut || cannotAfford || state.busyOffer === Number(offer.id);
    var tier = isGear ? String(offer.tier || 'common').toLowerCase() : 'character';
    var image = safeImage(isGear ? offer.icon_url : offer.portrait_url);
    var title = isGear ? (offer.tier || 'Common') + ' ' + (offer.slot ? String(offer.slot).replace(/-/g, ' ') : 'equipment') : (offer.role || 'Specialist') + ' recruit';
    var description = offer.description || (isGear ? 'A recovered piece of equipment prepared for your expedition force.' : 'A proven specialist ready to join the Neoh expedition.');
    var stockText = soldOut ? 'Sold out' : offer.stock_remaining + ' of ' + offer.stock_initial + ' available';
    var details = isGear ? gearStats(offer) : '<span><b>' + esc(offer.role || 'Crew') + '</b> role</span><span>Starts level <b>' + Number(offer.starting_level || 1) + '</b></span><span>Neoh affinity</span>';
    var buttonText = soldOut ? 'Sold out' : (state.busyOffer === Number(offer.id) ? 'Securing\u2026' : 'Acquire');
    return '<article class="market-offer is-' + esc(tier) + (isGear ? '' : ' is-character') + (soldOut ? ' is-sold-out' : '') + '"><div class="market-offer-top"><span class="market-offer-art">' + (image ? '<img src="' + esc(image) + '" alt="">' : (isGear ? gearGlyph() : characterGlyph())) + '</span><div><span class="market-offer-kicker">' + esc(title) + '</span><h3>' + esc(offer.name) + '</h3><p class="market-offer-sub">Rank ' + Number(offer.required_reputation_level) + ' eligible</p></div></div><p class="market-offer-desc">' + esc(description) + '</p><div class="market-offer-stats">' + details + '</div><div class="market-offer-buy"><span class="market-offer-price">' + credits(offer.credit_price) + ' cr<small>' + esc(stockText) + '</small></span><button type="button" class="btn btn-solid" data-market-buy="' + Number(offer.id) + '"' + (disabled ? ' disabled' : '') + '>' + buttonText + '</button></div></article>';
  }

  function renderOffers(type) {
    var rotation = state.data && state.data.rotations ? state.data.rotations[type] : null;
    var target = type === 'gear' ? gearList : characterList;
    var offers = rotation && Array.isArray(rotation.offers) ? rotation.offers : [];
    if (!offers.length) { target.innerHTML = '<p class="market-empty">No eligible ' + (type === 'gear' ? 'equipment' : 'character') + ' offers are available in this rotation. Raise your reputation to open the next catalogue tier.</p>'; return; }
    target.innerHTML = offers.map(function (offer) { return offerCard(offer, type); }).join('');
    target.querySelectorAll('[data-market-buy]').forEach(function (button) { button.addEventListener('click', function () { purchase(Number(button.getAttribute('data-market-buy'))); }); });
  }

  function renderOngoingMissions(missions) {
    if (!activeMissions) return;
    if (!missions.length) {
      activeMissions.innerHTML = '<div class="market-no-missions"><span aria-hidden="true">◇</span><strong>No ongoing missions</strong><p>Your crew are clear for the next Neoh operation.</p></div>';
      return;
    }
    activeMissions.innerHTML = '<ul>' + missions.slice(0, 4).map(function (mission) {
      var ready = mission.status === 'completed';
      return '<li class="' + (ready ? 'is-ready' : '') + '"><span class="market-active-mission-mark" aria-hidden="true">' + (ready ? '!' : '•') + '</span><div><strong>' + esc(mission.name) + '</strong><small>' + (ready ? 'Reward ready to claim' : esc(mission.mission_type || 'Expedition')) + '</small></div><em data-market-mission-countdown="' + esc(mission.completes_at) + '" data-market-mission-ready="' + (ready ? '1' : '0') + '">' + (ready ? 'Ready' : 'Calculating\u2026') + '</em></li>';
    }).join('') + (missions.length > 4 ? '<a class="market-active-more" href="missions.html">View all missions</a>' : '');
  }

  function tick() {
    if (!state.data || !state.data.rotations) return;
    ['gear', 'character'].forEach(function (type) {
      var rotation = state.data.rotations[type];
      var clock = document.querySelector('[data-market-countdown="' + type + '"]');
      var note = document.getElementById('market-' + type + '-refresh');
      if (clock) clock.textContent = timeLeft(rotation.ends_at);
      if (note) note.textContent = 'Refreshes ' + formatUtc(rotation.ends_at);
    });
    document.querySelectorAll('[data-market-mission-countdown]').forEach(function (element) {
      if (element.getAttribute('data-market-mission-ready') === '1') { element.textContent = 'Ready'; return; }
      var endsAt = apiDate(element.getAttribute('data-market-mission-countdown'));
      var remaining = endsAt ? Math.max(0, Math.ceil((endsAt.getTime() - (Date.now() + state.serverOffset)) / 1000)) : 0;
      element.textContent = remaining > 0 ? timeLeft(element.getAttribute('data-market-mission-countdown')) : 'Ready';
      if (remaining === 0) element.classList.add('is-ready');
    });
  }

  function render(data) {
    state.data = data;
    var server = apiDate(data.server_now);
    state.serverOffset = server ? server.getTime() - Date.now() : 0;
    creditsEl.textContent = credits(data.credits);
    var rep = data.reputation || {};
    commandCopy.textContent = (rep.level_name || 'Unranked') + ' command clearance \u00b7 rank ' + (rep.level_number || 0) + ' \u00b7 higher rank offers remain signal-shielded.';
    document.getElementById('market-reputation-title').textContent = rep.next_level_name ? 'Reach ' + rep.next_level_name + ' to expose the next market tier.' : 'Maximum reputation clearance reached.';
    document.getElementById('market-reputation-copy').textContent = rep.next_level_name ? 'The reputation page shows gear and recruits that become eligible at your next rank. Locked offers never appear in the Market itself.' : 'Every currently configured reputation-gated market offer is eligible for your rotations.';
    renderOngoingMissions(data.ongoing_missions || []);
    renderOffers('gear');
    renderOffers('character');
    tick();
  }

  function setStatus(message, error) { status.textContent = message || ''; status.classList.toggle('is-error', !!error); }
  function load() {
    if (!window.PW_AUTH || !window.PW_AUTH.loggedIn) { gate.hidden = false; content.hidden = true; return Promise.resolve(); }
    gate.hidden = true;
    content.hidden = false;
    return fetch('/api/market/overview.php', { credentials: 'same-origin', cache: 'no-store' }).then(function (response) { return response.json(); }).then(function (data) { if (!data.ok) throw new Error(data.error || 'The Market is unavailable.'); render(data); }).catch(function (error) { gearList.innerHTML = '<p class="market-empty">' + esc(error.message || 'The Market is unavailable.') + '</p>'; characterList.innerHTML = ''; if (activeMissions) activeMissions.innerHTML = ''; setStatus('', true); });
  }
  function purchase(id) {
    if (!id || state.busyOffer) return;
    state.busyOffer = id;
    setStatus('Securing the offer through the Neoh exchange\u2026');
    renderOffers('gear'); renderOffers('character');
    fetch('/api/market/purchase.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ rotation_item_id: id, csrf: window.PW_AUTH && window.PW_AUTH.csrf ? window.PW_AUTH.csrf : '' }) }).then(function (response) { return response.json(); }).then(function (data) { if (!data.ok) throw new Error(data.error || 'The purchase could not be completed.'); setStatus(data.message || 'Offer secured.'); return load(); }).catch(function (error) { setStatus(error.message || 'The purchase could not be completed.', true); }).then(function () { state.busyOffer = null; if (state.data) { renderOffers('gear'); renderOffers('character'); } });
  }

  document.addEventListener('pw-auth-ready', load);
  load();
  window.setInterval(tick, 1000);
}());
