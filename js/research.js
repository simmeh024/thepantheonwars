/* Research Facility: the browser draws the authored lattice, but every gate,
 * cost and permanent unlock is checked again by api/research/unlock.php. */
(function () {
  'use strict';

  var state = { data: null, selectedId: null, busyId: null, categoryFilter: '' };
  var gate = document.getElementById('research-gate');
  var content = document.getElementById('research-content');
  var board = document.getElementById('research-tree-board');
  var treeViewport = document.querySelector('.research-tree-scroll');
  var detail = document.getElementById('research-detail');
  var status = document.getElementById('research-status');
  var effectsList = document.getElementById('research-effects-list');
  var categoryFilter = document.getElementById('research-category-filter');
  var categoryFilterWrap = document.getElementById('research-category-filter-wrap');
  var treeKey = document.querySelector('.research-tree-key');

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
  function nodeById(id) { return (state.data && state.data.nodes || []).filter(function (node) { return Number(node.id) === Number(id); })[0] || null; }
  function effectText(node) { return node.effect_type === 'secret_mission' ? 'Unlocks ' + (node.target_mission_name || 'classified mission') : '+' + percent(node.effect_value) + '% ' + node.effect_short; }
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
    var rows = [
      ['mission_speed_percent', 'Mission speed'], ['xp_percent', 'Mission XP'], ['reputation_percent', 'Mission reputation'],
      ['luck_percent', 'Recovery luck'], ['market_discount_percent', 'Market discount'], ['market_refresh_percent', 'Market refresh']
    ].filter(function (row) { return Number(effects[row[0]]) > 0; });
    document.getElementById('research-effects-title').textContent = rows.length ? rows.length + ' online' : 'No protocols online';
    effectsList.innerHTML = rows.length ? '<ul>' + rows.map(function (row) { return '<li><b>+' + percent(effects[row[0]]) + '%</b>' + esc(row[1]) + '</li>'; }).join('') + '</ul>' : '<p>Activate your first protocol to establish a permanent expedition advantage.</p>';
  }

  function renderTree(data) {
    renderTreeKey();
    var nodes = filteredNodes(data);
    if (!nodes.length) { board.style.width = ''; board.style.minHeight = ''; board.innerHTML = '<p class="research-empty">' + (state.categoryFilter ? 'No protocols have been assigned to this category yet.' : 'Research command has not published any protocols yet.') + '</p>'; return; }
    var dimensions = data.board || {}, width = Math.max(960, Number(dimensions.width) || 1560), height = Math.max(600, Number(dimensions.height) || 900);
    board.style.width = width + 'px'; board.style.minHeight = height + 'px';
    var byId = {}; nodes.forEach(function (node) { byId[Number(node.id)] = node; });
    var lines = nodes.map(function (node) {
      return (node.prerequisites || []).map(function (prerequisite) {
        var from = byId[Number(prerequisite.id)]; if (!from) return '';
        var startX = Number(from.canvas_x) + 196, startY = Number(from.canvas_y) + 63, endX = Number(node.canvas_x), endY = Number(node.canvas_y) + 63;
        var gap = Math.max(48, (endX - startX) * .52), stateClass = ' is-' + nodeState(node);
        return '<path class="' + stateClass.trim() + '" d="M ' + startX + ' ' + startY + ' C ' + (startX + gap) + ' ' + startY + ', ' + (endX - gap) + ' ' + endY + ', ' + endX + ' ' + endY + '"></path>';
      }).join('');
    }).join('');
    var nodeMarkup = nodes.map(function (node) {
      var stateName = nodeState(node), statusText = nodeStatus(node, stateName), image = safeImage(node.image_url), selected = Number(node.id) === Number(state.selectedId);
      var nodeLabel = (node.category ? node.category.name + ' / ' : '') + node.effect_label;
      return '<button type="button" class="research-node is-' + stateName + (selected ? ' is-selected' : '') + '" data-research-node="' + Number(node.id) + '" style="left:' + Number(node.canvas_x) + 'px;top:' + Number(node.canvas_y) + 'px" aria-label="' + esc(node.name + ': ' + statusText) + '" aria-pressed="' + (selected ? 'true' : 'false') + '"><span class="research-node-top"><span class="research-node-art">' + (image ? '<img src="' + esc(image) + '" alt="">' : '⌬') + '</span><span><small>' + esc(nodeLabel) + '</small><strong>' + esc(node.name) + '</strong></span></span><p>' + esc(node.effect_short) + '</p><span class="research-node-foot"><span class="research-node-state is-' + stateName + '">' + esc(statusText) + '</span><b>' + esc(node.effect_type === 'secret_mission' ? 'CLASSIFIED' : '+' + percent(node.effect_value) + '%') + '</b></span></button>';
    }).join('');
    board.innerHTML = '<svg class="research-tree-lines" viewBox="0 0 ' + width + ' ' + height + '" preserveAspectRatio="none" aria-hidden="true">' + lines + '</svg>' + nodeMarkup;
  }

  function costRow(label, value, stateName) { return '<span class="' + (stateName || '') + '"><em>' + esc(label) + '</em><strong>' + esc(value) + '</strong></span>'; }
  function renderDetail() {
    var node = nodeById(state.selectedId);
    if (!node) { detail.innerHTML = '<span class="eyebrow">Protocol dossier</span><h2>Select a research protocol</h2><p>Choose a node in the lattice to review its effect, dependencies and field costs.</p>'; return; }
    var prerequisites = node.prerequisites || [], missing = node.missing_prerequisites || [];
    var prerequisiteCopy = prerequisites.length ? prerequisites.map(function (item) { return esc(item.name); }).join(', ') : 'No prerequisite protocols.';
    var rank = state.data.reputation || {}, rankMet = Number(rank.level_number) >= Number(node.required_reputation_level), creditMet = Number(state.data.credits) >= Number(node.credit_cost);
    var salvage = node.salvage, salvageMet = !salvage || Number(salvage.held) >= Number(salvage.quantity);
    var requirements = costRow('Reputation rank', 'Rank ' + node.required_reputation_level, rankMet ? 'is-ready' : 'is-missing')
      + costRow('Credits', number(node.credit_cost) + ' cr', creditMet ? 'is-ready' : 'is-missing');
    if (salvage) requirements += costRow(salvage.name, number(salvage.quantity) + ' required · ' + number(salvage.held) + ' held', salvageMet ? 'is-ready' : 'is-missing');
    var stateCopy = node.is_unlocked ? '<p class="research-detail-state">Protocol active — its field effect is permanently available to this command.</p>'
      : (!node.is_enabled ? '<p class="research-detail-state is-retired">This protocol has been retired from new research.</p>'
        : (missing.length ? '<p class="research-detail-state is-locked">Missing prerequisite: ' + missing.map(function (item) { return esc(item.name); }).join(', ') + '.</p>' : '<p class="research-detail-state is-locked">Meet every listed requirement to authorise this protocol.</p>'));
    var action = node.can_unlock ? '<button type="button" class="btn btn-solid research-unlock-btn" data-research-unlock="' + Number(node.id) + '"' + (state.busyId === Number(node.id) ? ' disabled' : '') + '>' + (state.busyId === Number(node.id) ? 'Authorising…' : 'Unlock protocol') + '</button>' : '';
    detail.innerHTML = '<div class="research-detail-grid"><div><span class="eyebrow">' + esc((node.category ? node.category.name + ' / ' : '') + node.effect_label) + '</span><h2>' + esc(node.name) + '</h2><p>' + esc(node.description) + '</p><p class="research-detail-effect">' + esc(effectText(node)) + '</p><p class="research-detail-prereqs"><b>Research path:</b> ' + prerequisiteCopy + '</p>' + stateCopy + action + '</div><div class="research-detail-meta">' + requirements + '</div></div>';
  }

  function render(data) {
    state.data = data;
    renderCategoryFilter(data);
    var nodes = filteredNodes(data);
    if (!nodes.some(function (node) { return Number(node.id) === Number(state.selectedId); })) {
      var available = nodes.filter(function (node) { return node.can_unlock; })[0];
      state.selectedId = available ? available.id : (nodes[0] ? nodes[0].id : null);
    }
    renderCommand(data); renderEffects(data.effects || {}); renderTree(data); renderDetail();
  }

  function load() {
    if (!window.PW_AUTH || !window.PW_AUTH.loggedIn) { gate.hidden = false; content.hidden = true; return Promise.resolve(); }
    gate.hidden = true; content.hidden = false;
    return fetch('/api/research/overview.php', { credentials: 'same-origin', cache: 'no-store' }).then(function (response) { return response.json(); }).then(function (data) { if (!data.ok) throw new Error(data.error || 'The Research Facility is unavailable.'); render(data); }).catch(function (error) { board.innerHTML = '<p class="research-empty">' + esc(error.message || 'The Research Facility is unavailable.') + '</p>'; detail.innerHTML = '<span class="eyebrow">Protocol dossier</span><h2>Facility unavailable</h2><p>Research command could not establish a secure record.</p>'; setStatus(error.message || 'The Research Facility is unavailable.', true); });
  }

  function unlock(nodeId) {
    if (state.busyId || !nodeId) return;
    state.busyId = Number(nodeId); renderDetail();
    post('/api/research/unlock.php', { research_node_id: nodeId, csrf: window.PW_AUTH && window.PW_AUTH.csrf ? window.PW_AUTH.csrf : '' }).then(function (result) { setStatus(result.message || 'Research protocol activated.'); return load(); }).catch(function (error) { setStatus(error.message || 'Could not unlock that protocol.', true); }).then(function () { state.busyId = null; renderDetail(); });
  }

  var mousePan = null;
  function startMousePan(event) {
    if (event.pointerType !== 'mouse' || event.button !== 0 || event.target.closest('[data-research-node]')) return;
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
  board.addEventListener('click', function (event) { var button = event.target.closest('[data-research-node]'); if (!button) return; state.selectedId = Number(button.getAttribute('data-research-node')); renderTree(state.data); renderDetail(); });
  categoryFilter.addEventListener('change', function () { state.categoryFilter = categoryFilter.value; var nodes = filteredNodes(state.data); state.selectedId = nodes.length ? nodes[0].id : null; renderTree(state.data); renderDetail(); });
  detail.addEventListener('click', function (event) { var button = event.target.closest('[data-research-unlock]'); if (button) unlock(Number(button.getAttribute('data-research-unlock'))); });
  document.addEventListener('pw-auth-ready', load);
  load();
}());
