/* The Market is a readout of server-created rotations. Purchases, reputation
 * gates, credits and mission state remain server-authoritative throughout. */
(function () {
  'use strict';

  var WINDOW_SECONDS = 21600;
  var state = { data: null, serverOffset: 0, busyOffer: null };
  var gate = document.getElementById('market-gate');
  var content = document.getElementById('market-content');
  var gearList = document.getElementById('market-gear-list');
  var characterList = document.getElementById('market-character-list');
  var status = document.getElementById('market-status');
  var creditsEl = document.getElementById('market-credits');
  var commandCopy = document.getElementById('market-command-copy');
  var activeMissions = document.getElementById('market-active-missions');
  var featured = document.getElementById('market-featured');
  var globalActivity = document.getElementById('market-global-activity');
  var loadoutAdvice = document.getElementById('market-loadout-advice');
  var unlockPreview = document.getElementById('market-unlock-preview');

  function esc(value) { var el = document.createElement('div'); el.textContent = value == null ? '' : String(value); return el.innerHTML; }
  function apiDate(value) { return value ? new Date(String(value).replace(' ', 'T') + 'Z') : null; }
  function credits(value) { return Math.max(0, Number(value) || 0).toLocaleString(); }
  function timeLeft(value) { var date = apiDate(value), seconds = date ? Math.max(0, Math.ceil((date.getTime() - (Date.now() + state.serverOffset)) / 1000)) : 0, h = Math.floor(seconds / 3600), m = Math.floor((seconds % 3600) / 60), s = seconds % 60; return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0'); }
  function formatUtc(value) { var date = apiDate(value); return date && !isNaN(date) ? date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', timeZoneName: 'short' }) : 'next UTC window'; }
  function safeImage(url) { return /^(?:images\/[a-zA-Z0-9._-]+|\/uploads\/mission-crew-images\/img_[a-f0-9]{16}\.(?:jpg|png|webp))$/.test(String(url || '')) ? String(url) : ''; }
  function tierName(value) { value = String(value || 'common'); return value.charAt(0).toUpperCase() + value.slice(1).toLowerCase(); }
  function slotName(value) { return String(value || 'equipment').replace(/_/g, ' ').replace(/-/g, ' '); }
  function gearGlyph() { return '<svg viewBox="0 0 32 32" aria-hidden="true"><path d="m18.5 4 3.3 3.3-4.6 4.6 3 3 4.6-4.6 3.3 3.3-8.2 8.2-3.7-3.7L8 25.7 6.3 24l7.2-7.2-3.7-3.7L18.5 4Z"/><path d="m5 27 3-3"/></svg>'; }
  function characterGlyph() { return '<svg viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="10" r="5"/><path d="M6.5 28c.8-6 4.3-9 9.5-9s8.7 3 9.5 9"/></svg>'; }
  function gearStats(offer) { var stats = [['STR', offer.bonus_strength], ['CUN', offer.bonus_cunning], ['SCI', offer.bonus_science], ['CHA', offer.bonus_charisma]].filter(function (stat) { return Number(stat[1]) !== 0; }); return stats.length ? stats.map(function (stat) { return '<span><b>' + esc(stat[0]) + '</b> ' + (Number(stat[1]) > 0 ? '+' : '') + Number(stat[1]) + '</span>'; }).join('') : '<span>Field equipment</span>'; }
  function offerDetails(offer, type) { return type === 'gear' ? gearStats(offer) : '<span><b>' + esc(offer.role || 'Crew') + '</b> role</span><span>Starts level <b>' + Number(offer.starting_level || 1) + '</b></span><span>Neoh affinity</span>'; }
  function offerArt(offer, type) { var image = safeImage(type === 'gear' ? offer.icon_url : offer.portrait_url); return image ? '<img src="' + esc(image) + '" alt="">' : (type === 'gear' ? gearGlyph() : characterGlyph()); }
  function scarcity(offer) { var stock = Number(offer.stock_remaining), initial = Math.max(1, Number(offer.stock_initial)); if (stock < 1) return { key: 'sold-out', text: 'Signal exhausted' }; if (stock === 1) return { key: 'last', text: 'Last unit' }; if (stock <= Math.ceil(initial * .34)) return { key: 'demand', text: 'High demand' }; return null; }
  function offerBuyButton(offer) { var soldOut = Number(offer.stock_remaining) < 1; var cannotAfford = Number((state.data || {}).credits || 0) < Number(offer.credit_price); var disabled = soldOut || cannotAfford || state.busyOffer === Number(offer.id); var text = soldOut ? 'Sold out' : (state.busyOffer === Number(offer.id) ? 'Securing\u2026' : 'Acquire'); return '<button type="button" class="btn btn-solid" data-market-buy="' + Number(offer.id) + '"' + (disabled ? ' disabled' : '') + '>' + text + '</button>'; }

  function gearComparison(offer) {
    if (!offer.slot) return '';
    var equipped = state.data && state.data.equipped_gear ? state.data.equipped_gear[offer.slot] : null;
    if (!equipped) return '<div class="market-compare"><button type="button" class="market-compare-toggle" data-market-compare aria-expanded="false">Compare loadout</button><div class="market-compare-panel"><span class="market-compare-kicker">Open coverage</span><strong>No ' + esc(slotName(offer.slot)) + ' gear is currently equipped.</strong><p>This item opens a new loadout option for your crew.</p></div></div>';
    var deltas = [['STR', 'bonus_strength'], ['CUN', 'bonus_cunning'], ['SCI', 'bonus_science'], ['CHA', 'bonus_charisma']].map(function (stat) { var delta = Number(offer[stat[1]]) - Number(equipped[stat[1]]); return '<span class="' + (delta > 0 ? 'is-better' : (delta < 0 ? 'is-worse' : 'is-even')) + '">' + stat[0] + ' ' + (delta > 0 ? '+' : '') + delta + '</span>'; }).join('');
    return '<div class="market-compare"><button type="button" class="market-compare-toggle" data-market-compare aria-expanded="false">Compare loadout</button><div class="market-compare-panel"><span class="market-compare-kicker">Versus equipped</span><strong>' + esc(equipped.name) + '</strong><p>Best ' + esc(slotName(offer.slot)) + ' kit currently worn by ' + esc(equipped.crew_name || 'your crew') + '.</p><div class="market-compare-deltas">' + deltas + '</div></div></div>';
  }

  function hologram(offer, type) {
    var readout = type === 'gear' ? gearStats(offer) : '<span>' + esc(offer.role || 'Specialist') + '</span><span>Level ' + Number(offer.starting_level || 1) + '</span>';
    return '<div class="market-offer-hologram" aria-hidden="true"><span class="market-hologram-scan"></span><small>Live appraisal</small><strong>' + esc(offer.name) + '</strong><div>' + readout + '</div></div>';
  }

  function offerCard(offer, type) {
    var isGear = type === 'gear';
    var soldOut = Number(offer.stock_remaining) < 1;
    var tier = isGear ? String(offer.tier || 'common').toLowerCase() : 'character';
    var title = isGear ? tierName(offer.tier) + ' ' + slotName(offer.slot) : (offer.role || 'Specialist') + ' recruit';
    var description = offer.description || (isGear ? 'A recovered piece of equipment prepared for your expedition force.' : 'A proven specialist ready to join the Neoh expedition.');
    var stockText = soldOut ? 'Sold out' : offer.stock_remaining + ' of ' + offer.stock_initial + ' available';
    var scarcityNotice = scarcity(offer);
    var discounted = Number(offer.base_credit_price) > Number(offer.credit_price);
    var price = credits(offer.credit_price) + ' cr' + (discounted ? '<small><s>' + credits(offer.base_credit_price) + ' cr</s> research price</small>' : '<small>' + esc(stockText) + '</small>');
    return '<article class="market-offer is-' + esc(tier) + (isGear ? '' : ' is-character') + (soldOut ? ' is-sold-out' : '') + '">' + hologram(offer, type) + '<div class="market-offer-top"><span class="market-offer-art">' + offerArt(offer, type) + '</span><div><span class="market-offer-kicker">' + esc(title) + '</span><h3>' + esc(offer.name) + '</h3><p class="market-offer-sub">Rank ' + Number(offer.required_reputation_level) + ' eligible</p></div></div>' + (scarcityNotice ? '<span class="market-scarcity is-' + scarcityNotice.key + '">' + esc(scarcityNotice.text) + '</span>' : '') + '<p class="market-offer-desc">' + esc(description) + '</p><div class="market-offer-stats">' + offerDetails(offer, type) + '</div>' + (isGear ? gearComparison(offer) : '') + '<div class="market-offer-buy"><span class="market-offer-price">' + price + '</span>' + offerBuyButton(offer) + '</div></article>';
  }

  function bindOfferInteractions(target) {
    target.querySelectorAll('[data-market-buy]').forEach(function (button) { button.addEventListener('click', function () { purchase(Number(button.getAttribute('data-market-buy'))); }); });
    target.querySelectorAll('[data-market-compare]').forEach(function (button) { button.addEventListener('click', function () { var card = button.closest('.market-offer'); if (!card) return; var open = card.classList.toggle('is-comparing'); button.setAttribute('aria-expanded', open ? 'true' : 'false'); }); });
  }

  function renderOffers(type) {
    var rotation = state.data && state.data.rotations ? state.data.rotations[type] : null;
    var target = type === 'gear' ? gearList : characterList;
    var offers = rotation && Array.isArray(rotation.offers) ? rotation.offers : [];
    if (!offers.length) { target.innerHTML = '<p class="market-empty">No eligible ' + (type === 'gear' ? 'equipment' : 'character') + ' offers are available in this rotation. Raise your reputation to open the next catalogue tier.</p>'; return; }
    target.innerHTML = offers.map(function (offer) { return offerCard(offer, type); }).join('');
    bindOfferInteractions(target);
  }

  function renderFeatured(feature) {
    if (!featured) return;
    if (!feature || feature.type !== 'gear' || !feature.offer) { featured.hidden = true; featured.innerHTML = ''; return; }
    var offer = feature.offer, tier = String(offer.tier || 'common').toLowerCase(), notice = scarcity(offer), soldOut = Number(offer.stock_remaining) < 1;
    featured.hidden = false;
    featured.innerHTML = '<article class="market-featured-card is-' + esc(tier) + (soldOut ? ' is-sold-out' : '') + '"><div class="market-featured-iris" aria-hidden="true"><i></i><i></i><i></i></div><div class="market-featured-art">' + offerArt(offer, 'gear') + '</div><div class="market-featured-copy"><span class="eyebrow">Featured contraband</span><span class="market-featured-rarity">' + esc(tierName(offer.tier)) + ' signal</span><h2 id="market-featured-title">' + esc(offer.name) + '</h2><p>' + esc(offer.description || 'A recovered equipment lead from the live Neoh exchange.') + '</p><div class="market-featured-stats">' + gearStats(offer) + '</div></div><div class="market-featured-action">' + (notice ? '<span class="market-scarcity is-' + notice.key + '">' + esc(notice.text) + '</span>' : '') + '<strong>' + credits(offer.credit_price) + ' <small>credits</small></strong><span>' + (soldOut ? 'Signal exhausted' : offer.stock_remaining + ' remaining') + '</span>' + offerBuyButton(offer) + '</div></article>';
    bindOfferInteractions(featured);
  }

  function renderOngoingMissions(missions) {
    if (!activeMissions) return;
    if (!missions.length) { activeMissions.innerHTML = '<div class="market-no-missions"><span aria-hidden="true">&#9671;</span><strong>No ongoing missions</strong><p>Your crew are clear for the next Neoh operation.</p></div>'; return; }
    activeMissions.innerHTML = '<ul>' + missions.slice(0, 4).map(function (mission) { var ready = mission.status === 'completed'; return '<li class="' + (ready ? 'is-ready' : '') + '"><span class="market-active-mission-mark" aria-hidden="true">' + (ready ? '!' : '&#8226;') + '</span><div><strong>' + esc(mission.name) + '</strong><small>' + (ready ? 'Reward ready to claim' : esc(mission.mission_type || 'Expedition')) + '</small></div><em data-market-mission-countdown="' + esc(mission.completes_at) + '" data-market-mission-ready="' + (ready ? '1' : '0') + '">' + (ready ? 'Ready' : 'Calculating\u2026') + '</em></li>'; }).join('') + (missions.length > 4 ? '<a class="market-active-more" href="missions.html">View all missions</a>' : '');
  }

  function relativeTime(value) { var date = apiDate(value), seconds = date ? Math.max(0, Math.floor(((Date.now() + state.serverOffset) - date.getTime()) / 1000)) : 0; if (seconds < 50) return 'just now'; if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago'; if (seconds < 86400) return Math.floor(seconds / 3600) + 'h ago'; return Math.floor(seconds / 86400) + 'd ago'; }
  function renderGlobalActivity(records) { if (!globalActivity) return; if (!records.length) { globalActivity.innerHTML = '<p class="market-activity-empty">No completed acquisitions have crossed the Neoh exchange yet.</p>'; return; } globalActivity.innerHTML = '<ul>' + records.map(function (record) { return '<li><span class="market-activity-mark" aria-hidden="true">' + (record.offer_type === 'character' ? '+' : '&#9670;') + '</span><div><strong>' + esc(record.member_name) + '</strong><small>acquired ' + esc(record.item_name) + ' &#183; ' + credits(record.credit_price) + ' cr</small></div><time data-market-activity-time="' + esc(record.purchased_at) + '">' + relativeTime(record.purchased_at) + '</time></li>'; }).join('') + '</ul>'; }

  function renderLoadoutAdvice(data) {
    if (!loadoutAdvice) return;
    var slots = Array.isArray(data.gear_slots) ? data.gear_slots : [], equipped = data.equipped_gear || {}, missing = slots.filter(function (slot) { return !equipped[slot.key]; }), missions = Array.isArray(data.ongoing_missions) ? data.ongoing_missions : [], active = missions.filter(function (mission) { return mission.status === 'active'; })[0], ready = missions.filter(function (mission) { return mission.status === 'completed'; })[0];
    var title, copy, slotCopy = missing.slice(0, 2).map(function (slot) { return slot.label; }).join(' and ');
    if (ready) { title = 'Claim and refit'; copy = ready.name + ' is ready to claim. Check the recovered kit before your next launch.'; }
    else if (active && missing.length) { title = 'Prepare ' + slotCopy; copy = active.name + ' is underway. Fill this coverage for the next Neoh operation.'; }
    else if (missing.length) { title = 'Open ' + slotCopy + ' coverage'; copy = 'Your crew have open loadout categories. Prioritize these slots before the next deployment.'; }
    else { title = 'Loadout coverage online'; copy = active ? active.name + ' is active and every equipment category has at least one kit in the field.' : 'Every equipment category has at least one kit in the crew loadout.'; }
    loadoutAdvice.innerHTML = '<span class="eyebrow">Crew recommendation</span><h2>' + esc(title) + '</h2><p>' + esc(copy) + '</p><a href="missions.html#missions-inventory-section">Review loadouts <b aria-hidden="true">&#8599;</b></a>';
  }

  function renderUnlockPreview(reputation, categories) {
    if (!unlockPreview) return;
    if (!reputation.next_level_name) { unlockPreview.innerHTML = '<span class="eyebrow">Signal-shielded catalogue</span><h2>Maximum clearance</h2><p>Your reputation is high enough for every currently configured Market tier.</p><a href="reputation.html">View reputation <b aria-hidden="true">&#8599;</b></a>'; return; }
    var cards = categories.length ? '<div class="market-unlock-categories">' + categories.map(function (category) { return '<span class="is-' + esc(category.type) + '"><b>' + esc(category.label) + '</b><small>' + esc(category.copy) + '</small></span>'; }).join('') + '</div>' : '<p class="market-unlock-quiet">No new Market catalogue class is scheduled for this rank.</p>';
    unlockPreview.innerHTML = '<span class="eyebrow">Signal-shielded catalogue</span><h2>' + esc(reputation.next_level_name) + ' clearance</h2><p>Raise your reputation to unlock the next Market access class. Exact offers stay protected until then.</p>' + cards + '<a href="reputation.html">Preview next rank <b aria-hidden="true">&#8599;</b></a>';
  }

  function ensureRotationRing(clock) { var countdown = clock.querySelector('[data-market-countdown]'); if (!countdown || countdown.parentElement.classList.contains('market-rotation-progress')) return; var ring = document.createElement('i'); ring.className = 'market-rotation-progress'; ring.setAttribute('aria-hidden', 'true'); countdown.parentNode.insertBefore(ring, countdown); ring.appendChild(countdown); }
  function tick() {
    if (!state.data || !state.data.rotations) return;
    ['gear', 'character'].forEach(function (type) { var rotation = state.data.rotations[type], clock = document.querySelector('[data-market-countdown="' + type + '"]'), note = document.getElementById('market-' + type + '-refresh'), date = apiDate(rotation.ends_at), remaining = date ? Math.max(0, Math.ceil((date.getTime() - (Date.now() + state.serverOffset)) / 1000)) : 0, windowSeconds = Math.max(1, Number(rotation.window_seconds) || WINDOW_SECONDS); if (clock) { ensureRotationRing(clock.closest('.market-rotation-clock')); clock.textContent = timeLeft(rotation.ends_at); var host = clock.closest('.market-rotation-clock'); if (host) host.style.setProperty('--market-progress', String(Math.max(0, Math.min(1, 1 - (remaining / windowSeconds))))); } if (note) note.textContent = 'Refreshes ' + formatUtc(rotation.ends_at); });
    document.querySelectorAll('[data-market-mission-countdown]').forEach(function (element) { if (element.getAttribute('data-market-mission-ready') === '1') { element.textContent = 'Ready'; return; } var endsAt = apiDate(element.getAttribute('data-market-mission-countdown')), remaining = endsAt ? Math.max(0, Math.ceil((endsAt.getTime() - (Date.now() + state.serverOffset)) / 1000)) : 0; element.textContent = remaining > 0 ? timeLeft(element.getAttribute('data-market-mission-countdown')) : 'Ready'; if (remaining === 0) { element.classList.add('is-ready'); var row = element.closest('li'); if (row) row.classList.add('is-ready'); } });
    document.querySelectorAll('[data-market-activity-time]').forEach(function (element) { element.textContent = relativeTime(element.getAttribute('data-market-activity-time')); });
  }

  function render(data) {
    state.data = data;
    var server = apiDate(data.server_now), rep = data.reputation || {};
    state.serverOffset = server ? server.getTime() - Date.now() : 0;
    creditsEl.textContent = credits(data.credits);
    var research = data.research_effects || {}, marketNotes = [];
    if (Number(research.market_discount_percent) > 0) marketNotes.push('+' + Number(research.market_discount_percent) + '% research pricing');
    if (Number(research.market_refresh_percent) > 0) marketNotes.push('+' + Number(research.market_refresh_percent) + '% signal cadence');
    commandCopy.textContent = (rep.level_name || 'Unranked') + ' command clearance \u00b7 rank ' + (rep.level_number || 0) + (marketNotes.length ? ' \u00b7 ' + marketNotes.join(' · ') : ' \u00b7 higher rank offers remain signal-shielded.');
    document.getElementById('market-reputation-title').textContent = rep.next_level_name ? 'Reach ' + rep.next_level_name + ' to expose the next market tier.' : 'Maximum reputation clearance reached.';
    document.getElementById('market-reputation-copy').textContent = rep.next_level_name ? 'The reputation page shows gear and recruits that become eligible at your next rank. Locked offers never appear in the Market itself.' : 'Every currently configured reputation-gated market offer is eligible for your rotations.';
    renderFeatured(data.featured_offer);
    renderLoadoutAdvice(data);
    renderUnlockPreview(rep, Array.isArray(data.next_market_categories) ? data.next_market_categories : []);
    renderOngoingMissions(Array.isArray(data.ongoing_missions) ? data.ongoing_missions : []);
    renderGlobalActivity(Array.isArray(data.global_activity) ? data.global_activity : []);
    renderOffers('gear');
    renderOffers('character');
    tick();
  }

  function setStatus(message, error) { status.textContent = message || ''; status.classList.toggle('is-error', !!error); }
  function load() {
    /* Nothing is painted until the session is actually known. loggedIn alone
     * cannot tell "signed out" from "not asked yet", so gating on it here is
     * what made a signed-in visitor see the Log In panel on every load. */
    if (!window.PW_AUTH || !window.PW_AUTH.resolved) return Promise.resolve();
    if (!window.PW_AUTH.loggedIn) { gate.hidden = false; content.hidden = true; return Promise.resolve(); }
    gate.hidden = true; content.hidden = false;
    return fetch('/api/market/overview.php', { credentials: 'same-origin', cache: 'no-store' }).then(function (response) { return response.json(); }).then(function (data) { if (!data.ok) throw new Error(data.error || 'The Market is unavailable.'); render(data); }).catch(function (error) { gearList.innerHTML = '<p class="market-empty">' + esc(error.message || 'The Market is unavailable.') + '</p>'; characterList.innerHTML = ''; if (featured) featured.hidden = true; if (activeMissions) activeMissions.innerHTML = ''; if (globalActivity) globalActivity.innerHTML = ''; setStatus('', true); });
  }
  function purchase(id) {
    if (!id || state.busyOffer) return;
    state.busyOffer = id;
    setStatus('Securing the offer through the Neoh exchange\u2026');
    renderFeatured(state.data && state.data.featured_offer); renderOffers('gear'); renderOffers('character');
    fetch('/api/market/purchase.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ rotation_item_id: id, csrf: window.PW_AUTH && window.PW_AUTH.csrf ? window.PW_AUTH.csrf : '' }) }).then(function (response) { return response.json(); }).then(function (data) { if (!data.ok) throw new Error(data.error || 'The purchase could not be completed.'); setStatus(data.message || 'Offer secured.'); return load(); }).catch(function (error) { setStatus(error.message || 'The purchase could not be completed.', true); }).then(function () { state.busyOffer = null; if (state.data) { renderFeatured(state.data.featured_offer); renderOffers('gear'); renderOffers('character'); } });
  }

  document.addEventListener('pw-auth-ready', load);
  load();
  window.setInterval(tick, 1000);
}());
