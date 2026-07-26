/* Research Facility: the browser draws the authored lattice, but every gate,
 * cost and permanent unlock is checked again by api/research/unlock.php. */
(function () {
  'use strict';

  var state = { data: null, busyId: null, queueBusyId: null, justActivatedId: null, categoryFilter: '', zoom: 1 };
  var gate = document.getElementById('research-gate');
  var content = document.getElementById('research-content');
  var board = document.getElementById('research-tree-board');
  var treeViewport = document.querySelector('.research-tree-scroll');
  var status = document.getElementById('research-status');
  var effectsList = document.getElementById('research-effects-list');
  var categoryFilter = document.getElementById('research-category-filter');
  var categoryFilterWrap = document.getElementById('research-category-filter-wrap');
  var treeKey = document.querySelector('.research-tree-key');
  var treeMap = document.getElementById('research-tree-map');
  var zoomStage = document.getElementById('research-tree-zoom');
  var zoomOut = document.getElementById('research-zoom-out');
  var zoomIn = document.getElementById('research-zoom-in');
  var zoomLevel = document.getElementById('research-zoom-level');
  var fullscreenButton = document.getElementById('research-fullscreen');
  var queueCard = document.getElementById('research-queue-card');
  var queueContent = document.getElementById('research-queue-content');
  var transmissionsCard = document.getElementById('research-transmissions-card');
  var transmissionsList = document.getElementById('research-transmissions-list');
  var transmissionModal = document.getElementById('research-transmission-modal');
  var transmissionTitle = document.getElementById('research-transmission-title');
  var transmissionCopy = document.getElementById('research-transmission-copy');
  var transmissionClose = document.getElementById('research-transmission-close');
  var transmissionAcknowledge = document.getElementById('research-transmission-acknowledge');
  var minZoom = 0.6, maxZoom = 1.6, zoomStep = 0.1;

  function esc(value) { var node = document.createElement('div'); node.textContent = value == null ? '' : String(value); return node.innerHTML; }
  function number(value) { return Math.max(0, Number(value) || 0).toLocaleString(); }
  function percent(value) { var parsed = Number(value) || 0; return String(Math.round(parsed * 100) / 100).replace(/\.0+$/, ''); }
  function safeImage(value) { return /^\/uploads\/research-images\/img_[a-f0-9]{16}\.jpg$/.test(String(value || '')) ? String(value) : ''; }
  function post(url, payload) { return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }).then(function (response) { return response.json(); }).then(function (data) { if (!data.ok) throw new Error(data.error || 'The Research Facility could not complete that request.'); return data; }); }
  function setStatus(message, error) { status.textContent = message || ''; status.classList.toggle('is-error', !!error); }
  function renderTreeKey() {
    if (!treeKey) return;
    treeKey.innerHTML = '<i class="is-online"></i> Online <i class="is-ready"></i> Ready <i class="is-funds-missing"></i> Funds missing <i class="is-rank-locked"></i> Rank locked';
  }
  function effectText(node) {
    if (node.effect_type === 'secret_mission') return 'Unlocks ' + (node.target_mission_name || 'classified mission');
    if (node.effect_type === 'rare_loot_table') return 'Unlocks ' + (node.target_loot_table_name || 'rare loot table');
    if (node.effect_type === 'crew_capacity') return '+' + Math.floor(Number(node.effect_value) || 0) + ' crew slots';
    if (node.effect_type === 'crew_fatigue') return '+' + Math.floor(Number(node.effect_value) || 0) + ' crew fatigue';
    return '+' + percent(node.effect_value) + '% ' + node.effect_short;
  }
  function nodeState(node) {
    if (node.is_unlocked) return 'online';
    if (!node.is_enabled) return 'retired';
    if (!node.rank_met) return 'rank-locked';
    if (!node.prerequisites_met) return 'path-locked';
    if (!node.funds_met) return 'funds-missing';
    return 'ready';
  }
  function nodeStatus(node, stateName) {
    if (stateName === 'online') return 'Protocol online';
    if (stateName === 'retired') return 'Protocol retired';
    if (stateName === 'rank-locked') return 'Rank ' + node.required_reputation_level + ' required';
    if (stateName === 'path-locked') {
      var remaining = (node.missing_prerequisites || []).length;
      return remaining + ' prerequisite' + (remaining === 1 ? '' : 's') + ' pending';
    }
    if (stateName === 'funds-missing') {
      var creditsMissing = Number(state.data.credits) < Number(node.credit_cost);
      var salvageMissing = node.salvage && Number(node.salvage.held) < Number(node.salvage.quantity);
      if (creditsMissing && salvageMissing) return 'Insufficient credits + salvage';
      return creditsMissing ? 'Insufficient credits' : 'Missing salvage';
    }
    return 'Ready to activate';
  }
  function nodeCosts(node) {
    var creditsMissing = Number(state.data.credits) < Number(node.credit_cost);
    var costs = [
      '<span' + (node.rank_met ? '' : ' class="is-missing"') + '>Rank ' + Number(node.required_reputation_level) + '</span>',
      '<span' + (creditsMissing ? ' class="is-missing"' : '') + '>' + number(node.credit_cost) + ' cr</span>'
    ];
    if (node.salvage) {
      var salvageMissing = Number(node.salvage.held) < Number(node.salvage.quantity);
      costs.push('<span' + (salvageMissing ? ' class="is-missing"' : '') + '>' + number(node.salvage.quantity) + ' ' + esc(node.salvage.name) + '</span>');
    }
    return costs.join('');
  }
  function nodeHoverPanel(node, stateName) {
    var canUnlock = node.can_unlock && state.busyId !== Number(node.id);
    var actionLabel = state.busyId === Number(node.id) ? 'Authorising...' : (node.is_unlocked ? 'Protocol online' : 'Unlock now');
    var reason = node.can_unlock ? 'Ready to activate this protocol.' : nodeStatus(node, stateName) + '.';
    var queueAvailable = state.data && state.data.queue_transmissions_ready && !node.is_unlocked;
    var queueBusy = state.queueBusyId === Number(node.id);
    var queueLabel = queueBusy ? 'Queueing...' : (node.is_queued ? 'Queued protocol' : 'Queue next');
    var queueAction = queueAvailable ? '<button type="button" class="research-node-queue" data-research-queue="' + Number(node.id) + '"' + (queueBusy || node.is_queued ? ' disabled' : '') + '>' + esc(queueLabel) + '</button>' : '';
    return '<div class="research-node-hover"><span class="research-node-hover-effect">' + esc(effectText(node)) + '</span><p>' + esc(node.description) + '</p><span class="research-node-costs">' + nodeCosts(node) + '</span><button type="button" class="research-node-unlock" data-research-unlock="' + Number(node.id) + '"' + (canUnlock ? '' : ' disabled') + '>' + esc(actionLabel) + '</button>' + queueAction + '<span class="research-node-hover-reason' + (canUnlock ? ' is-ready' : '') + '">' + esc(reason) + '</span></div>';
  }
  function filteredNodes(data) {
    var nodes = Array.isArray(data && data.nodes) ? data.nodes : [];
    if (!state.categoryFilter) return nodes;
    if (state.categoryFilter === 'uncategorized') return nodes.filter(function (node) { return !node.category; });
    var categoryId = Number(state.categoryFilter.replace('category-', ''));
    return nodes.filter(function (node) { return node.category && Number(node.category.id) === categoryId; });
  }
  function renderCategoryFilter(data) {
    var categories = Array.isArray(data && data.categories) ? data.categories : [];
    var hasUncategorised = (data.nodes || []).some(function (node) { return !node.category; });
    var options = '<option value="">All categories</option>' + categories.map(function (category) {
      return '<option value="category-' + Number(category.id) + '">' + esc(category.name) + '</option>';
    }).join('') + (hasUncategorised ? '<option value="uncategorized">Uncategorised</option>' : '');
    categoryFilter.innerHTML = options;
    if (state.categoryFilter && !categoryFilter.querySelector('option[value="' + state.categoryFilter + '"]')) state.categoryFilter = '';
    categoryFilter.value = state.categoryFilter;
    categoryFilterWrap.hidden = !(categories.length || hasUncategorised);
  }

  function renderCommand(data) {
    var reputation = data.reputation || {};
    document.getElementById('research-reputation').textContent = number(reputation.points);
    document.getElementById('research-credits').textContent = number(data.credits);
    document.getElementById('research-command-title').textContent = (reputation.level_name || 'Unranked') + ' command';
    document.getElementById('research-command-copy').textContent = 'Rank ' + (reputation.level_number || 0) + ' clearance · ' + number(reputation.points) + ' total reputation points.';
    document.getElementById('research-unlocked-count').textContent = number((data.summary || {}).unlocked_count);
    document.getElementById('research-available-count').textContent = number((data.summary || {}).available_count) + ' available now';
  }

  function renderEffects(effects) {
    /* The third element marks a flat effect. Crew capacity is a count of berths
     * and crew endurance a count of fatigue -- neither is a percentage, and
     * both used to render as one ("+8% Crew capacity" for eight slots). */
    var rows = [
      ['mission_speed_percent', 'Mission speed'], ['xp_percent', 'Mission XP'], ['reputation_percent', 'Mission reputation'], ['credit_percent', 'Mission credits'],
      ['crew_capacity', 'Crew capacity', 'slots'], ['crew_fatigue', 'Crew endurance', 'fatigue'],
      ['luck_percent', 'Rarity promotion'], ['market_discount_percent', 'Market discount'], ['market_refresh_percent', 'Market refresh']
    ].filter(function (row) { return Number(effects[row[0]]) > 0; });
    document.getElementById('research-effects-title').textContent = rows.length ? rows.length + ' online' : 'No protocols online';
    effectsList.innerHTML = rows.length ? '<ul>' + rows.map(function (row) {
      var value = row[2] ? '+' + Math.floor(Number(effects[row[0]]) || 0) + ' ' + row[2] : '+' + percent(effects[row[0]]) + '%';
      return '<li><b>' + esc(value) + '</b>' + esc(row[1]) + '</li>';
    }).join('') + '</ul>' : '<p>Activate your first protocol to establish a permanent expedition advantage.</p>';
  }

  function queueRequirements(node) {
    var missing = [];
    if (!node.rank_met) missing.push('Reach Rank ' + number(node.required_reputation_level));
    var creditGap = Math.max(0, Number(node.credit_cost) - Number(state.data.credits));
    if (creditGap) missing.push(number(creditGap) + ' cr needed');
    if (node.salvage) {
      var salvageGap = Math.max(0, Number(node.salvage.quantity) - Number(node.salvage.held));
      if (salvageGap) missing.push(number(salvageGap) + ' ' + node.salvage.name + ' needed');
    }
    if (!node.prerequisites_met) {
      var prerequisites = node.missing_prerequisites || [];
      missing.push(prerequisites.length === 1 ? 'Unlock ' + prerequisites[0].name : 'Unlock ' + prerequisites.length + ' prerequisite protocols');
    }
    return missing;
  }
  function renderQueue(data) {
    if (!queueCard || !queueContent) return;
    queueCard.hidden = !data.queue_transmissions_ready;
    if (!data.queue_transmissions_ready) return;
    var node = data.queue;
    if (!node) {
      queueContent.innerHTML = '<p>No protocol queued. Hover any offline protocol and choose <b>Queue next</b> to track its remaining requirements.</p>';
      return;
    }
    var remaining = queueRequirements(node);
    var stateCopy = remaining.length ? '<ul>' + remaining.map(function (item) { return '<li>' + esc(item) + '</li>'; }).join('') + '</ul>' : '<p class="research-queue-ready">All requirements met. Activate this protocol from the lattice.</p>';
    queueContent.innerHTML = '<strong>' + esc(node.name) + '</strong><span>' + esc(effectText(node)) + '</span>' + stateCopy + '<button type="button" class="research-queue-clear" data-research-queue-clear>Clear queue</button>';
  }
  function renderTransmissions(data) {
    if (!transmissionsCard || !transmissionsList) return;
    transmissionsCard.hidden = !data.queue_transmissions_ready;
    if (!data.queue_transmissions_ready) return;
    var entries = Array.isArray(data.transmissions) ? data.transmissions : [];
    transmissionsList.innerHTML = entries.length ? '<ul>' + entries.map(function (entry) {
      return '<li><strong>' + esc(entry.protocol_name) + '</strong><p>' + esc(entry.text) + '</p><small>Protocol activation recorded</small></li>';
    }).join('') + '</ul>' : '<p>No activation transmissions have been recorded for this command.</p>';
  }
  function showTransmission(transmission) {
    if (!transmissionModal || !transmission) return;
    transmissionTitle.textContent = transmission.protocol_name || 'Protocol activated';
    transmissionCopy.textContent = transmission.text || '';
    transmissionModal.hidden = false;
    window.setTimeout(function () { if (transmissionAcknowledge) transmissionAcknowledge.focus(); }, 0);
  }
  function hideTransmission() {
    if (transmissionModal) transmissionModal.hidden = true;
  }
  function markActivation(nodeId) {
    state.justActivatedId = Number(nodeId);
    window.setTimeout(function () {
      if (state.justActivatedId !== Number(nodeId)) return;
      state.justActivatedId = null;
      if (state.data) renderTree(state.data);
    }, 1900);
  }

  function clampZoom(value) { return Math.max(minZoom, Math.min(maxZoom, Math.round(value * 10) / 10)); }
  function syncMapScale() {
    if (!zoomStage || !board) return;
    var width = parseFloat(board.style.width) || board.offsetWidth || 960;
    var height = parseFloat(board.style.minHeight) || board.offsetHeight || 600;
    zoomStage.style.width = Math.ceil(width * state.zoom) + 'px';
    zoomStage.style.height = Math.ceil(height * state.zoom) + 'px';
    board.style.transform = 'scale(' + state.zoom + ')';
    if (zoomLevel) zoomLevel.textContent = Math.round(state.zoom * 100) + '%';
    if (zoomOut) zoomOut.disabled = state.zoom <= minZoom;
    if (zoomIn) zoomIn.disabled = state.zoom >= maxZoom;
  }
  function setZoom(value) {
    var nextZoom = clampZoom(value);
    if (!treeViewport || nextZoom === state.zoom) return;
    var centreX = (treeViewport.scrollLeft + treeViewport.clientWidth / 2) / state.zoom;
    var centreY = (treeViewport.scrollTop + treeViewport.clientHeight / 2) / state.zoom;
    state.zoom = nextZoom;
    syncMapScale();
    treeViewport.scrollLeft = Math.max(0, centreX * state.zoom - treeViewport.clientWidth / 2);
    treeViewport.scrollTop = Math.max(0, centreY * state.zoom - treeViewport.clientHeight / 2);
  }
  function isMapFullscreen() { return (document.fullscreenElement || document.webkitFullscreenElement) === treeMap; }
  function syncFullscreenButton() {
    if (!fullscreenButton) return;
    var active = isMapFullscreen();
    fullscreenButton.textContent = active ? 'Exit full screen' : 'Full screen';
    fullscreenButton.setAttribute('aria-pressed', active ? 'true' : 'false');
  }

  function renderTree(data) {
    renderTreeKey();
    var nodes = filteredNodes(data);
    if (!nodes.length) { board.style.width = ''; board.style.minHeight = ''; board.innerHTML = '<p class="research-empty">' + (state.categoryFilter ? 'No protocols have been assigned to this category yet.' : 'Research command has not published any protocols yet.') + '</p>'; syncMapScale(); return; }
    var dimensions = data.board || {}, width = Math.max(960, Number(dimensions.width) || 1560), height = Math.max(600, Number(dimensions.height) || 900);
    board.style.width = width + 'px'; board.style.minHeight = height + 'px';
    var byId = {}; nodes.forEach(function (node) { byId[Number(node.id)] = node; });
    var lines = nodes.map(function (node) {
      return (node.prerequisites || []).map(function (prerequisite) {
        var from = byId[Number(prerequisite.id)]; if (!from) return '';
        var startX = Number(from.canvas_x) + 196, startY = Number(from.canvas_y) + 63, endX = Number(node.canvas_x), endY = Number(node.canvas_y) + 63;
        var activatedLink = Number(node.id) === Number(state.justActivatedId) || Number(from.id) === Number(state.justActivatedId);
        var gap = Math.max(48, (endX - startX) * .52), stateClass = ' is-' + nodeState(node) + (activatedLink ? ' is-just-activated' : '');
        return '<path class="' + stateClass.trim() + '" d="M ' + startX + ' ' + startY + ' C ' + (startX + gap) + ' ' + startY + ', ' + (endX - gap) + ' ' + endY + ', ' + endX + ' ' + endY + '"></path>';
      }).join('');
    }).join('');
    var nodeMarkup = nodes.map(function (node) {
      var stateName = nodeState(node), statusText = nodeStatus(node, stateName), image = safeImage(node.image_url);
      var nodeLabel = (node.category ? node.category.name + ' / ' : '') + node.effect_label;
      var specialEffect = node.effect_type === 'secret_mission' || node.effect_type === 'rare_loot_table';
      var flatUnit = { crew_capacity: 'slots', crew_fatigue: 'fatigue' }[node.effect_type];
      var effectValue = specialEffect ? 'CLASSIFIED' : (flatUnit ? '+' + Math.floor(Number(node.effect_value) || 0) + ' ' + flatUnit : '+' + percent(node.effect_value) + '%');
      return '<article class="research-node is-' + stateName + (node.is_queued ? ' is-queued' : '') + (Number(node.id) === Number(state.justActivatedId) ? ' is-just-activated' : '') + '" style="left:' + Number(node.canvas_x) + 'px;top:' + Number(node.canvas_y) + 'px"><button type="button" class="research-node-select" aria-label="' + esc(node.name + ': ' + statusText) + '"><span class="research-node-top"><span class="research-node-art">' + (image ? '<img src="' + esc(image) + '" alt="">' : '⌬') + '</span><span><small>' + esc(nodeLabel) + '</small><strong>' + esc(node.name) + '</strong></span></span><p>' + esc(node.effect_short) + '</p><span class="research-node-foot"><span class="research-node-state is-' + stateName + '">' + esc(statusText) + '</span><b>' + esc(effectValue) + '</b></span></button>' + nodeHoverPanel(node, stateName) + '</article>';
    }).join('');
    board.innerHTML = '<svg class="research-tree-lines" viewBox="0 0 ' + width + ' ' + height + '" preserveAspectRatio="none" aria-hidden="true">' + lines + '</svg>' + nodeMarkup;
    syncMapScale();
  }

  function render(data) {
    state.data = data;
    renderCategoryFilter(data);
    renderCommand(data); renderEffects(data.effects || {}); renderQueue(data); renderTransmissions(data); renderTree(data);
  }

  function load() {
    if (!window.PW_AUTH || !window.PW_AUTH.loggedIn) { gate.hidden = false; content.hidden = true; return Promise.resolve(); }
    gate.hidden = true; content.hidden = false;
    return fetch('/api/research/overview.php', { credentials: 'same-origin', cache: 'no-store' }).then(function (response) { return response.json(); }).then(function (data) { if (!data.ok) throw new Error(data.error || 'The Research Facility is unavailable.'); render(data); }).catch(function (error) { board.innerHTML = '<p class="research-empty">' + esc(error.message || 'The Research Facility is unavailable.') + '</p>'; syncMapScale(); setStatus(error.message || 'The Research Facility is unavailable.', true); });
  }

  function unlock(nodeId) {
    if (state.busyId || !nodeId) return;
    state.busyId = Number(nodeId); renderTree(state.data);
    post('/api/research/unlock.php', { research_node_id: nodeId, csrf: window.PW_AUTH && window.PW_AUTH.csrf ? window.PW_AUTH.csrf : '' }).then(function (result) {
      markActivation(nodeId);
      if (result.transmission) showTransmission(result.transmission);
      setStatus(result.message || 'Research protocol activated.');
      return load();
    }).catch(function (error) { setStatus(error.message || 'Could not unlock that protocol.', true); }).then(function () { state.busyId = null; if (state.data) renderTree(state.data); });
  }
  function queue(nodeId) {
    if (state.queueBusyId !== null) return;
    state.queueBusyId = nodeId === null ? 'clear' : Number(nodeId);
    if (state.data) renderTree(state.data);
    post('/api/research/queue.php', { research_node_id: nodeId || '', csrf: window.PW_AUTH && window.PW_AUTH.csrf ? window.PW_AUTH.csrf : '' }).then(function (result) {
      setStatus(result.message || 'Research queue updated.');
      return load();
    }).catch(function (error) { setStatus(error.message || 'Could not update the research queue.', true); }).then(function () {
      state.queueBusyId = null;
      if (state.data) renderTree(state.data);
    });
  }

  var mousePan = null;
  function startMousePan(event) {
    if (event.pointerType !== 'mouse' || event.button !== 0 || event.target.closest('.research-node')) return;
    mousePan = { pointerId: event.pointerId, clientX: event.clientX, clientY: event.clientY, scrollLeft: treeViewport.scrollLeft, scrollTop: treeViewport.scrollTop };
    treeViewport.classList.add('is-panning');
    try { board.setPointerCapture(event.pointerId); } catch (ignore) {}
    event.preventDefault();
  }
  function moveMousePan(event) {
    if (!mousePan || mousePan.pointerId !== event.pointerId) return;
    treeViewport.scrollLeft = mousePan.scrollLeft - (event.clientX - mousePan.clientX);
    treeViewport.scrollTop = mousePan.scrollTop - (event.clientY - mousePan.clientY);
    event.preventDefault();
  }
  function finishMousePan(event) {
    if (!mousePan || mousePan.pointerId !== event.pointerId) return;
    try { if (board.hasPointerCapture && board.hasPointerCapture(event.pointerId)) board.releasePointerCapture(event.pointerId); } catch (ignore) {}
    treeViewport.classList.remove('is-panning');
    mousePan = null;
  }

  board.addEventListener('pointerdown', startMousePan);
  board.addEventListener('pointermove', moveMousePan);
  board.addEventListener('pointerup', finishMousePan);
  board.addEventListener('pointercancel', finishMousePan);
  board.addEventListener('click', function (event) {
    var unlockButton = event.target.closest('[data-research-unlock]');
    if (unlockButton && board.contains(unlockButton)) { unlock(Number(unlockButton.getAttribute('data-research-unlock'))); return; }
    var queueButton = event.target.closest('[data-research-queue]');
    if (queueButton && board.contains(queueButton)) { queue(Number(queueButton.getAttribute('data-research-queue'))); }
  });
  categoryFilter.addEventListener('change', function () { state.categoryFilter = categoryFilter.value; renderTree(state.data); });
  if (queueContent) queueContent.addEventListener('click', function (event) { if (event.target.closest('[data-research-queue-clear]')) queue(null); });
  zoomOut.addEventListener('click', function () { setZoom(state.zoom - zoomStep); });
  zoomIn.addEventListener('click', function () { setZoom(state.zoom + zoomStep); });
  fullscreenButton.addEventListener('click', function () {
    if (isMapFullscreen()) { if (document.exitFullscreen) document.exitFullscreen(); else if (document.webkitExitFullscreen) document.webkitExitFullscreen(); return; }
    if (treeMap && treeMap.requestFullscreen) treeMap.requestFullscreen();
    else if (treeMap && treeMap.webkitRequestFullscreen) treeMap.webkitRequestFullscreen();
  });
  document.addEventListener('fullscreenchange', syncFullscreenButton);
  document.addEventListener('webkitfullscreenchange', syncFullscreenButton);
  if (transmissionClose) transmissionClose.addEventListener('click', hideTransmission);
  if (transmissionAcknowledge) transmissionAcknowledge.addEventListener('click', hideTransmission);
  if (transmissionModal) transmissionModal.addEventListener('click', function (event) { if (event.target === transmissionModal) hideTransmission(); });
  document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && transmissionModal && !transmissionModal.hidden) hideTransmission(); });
  document.addEventListener('pw-auth-ready', load);
  load();
}());
