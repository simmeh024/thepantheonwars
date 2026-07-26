/* Research Management keeps the tree's layout in the database. The canvas is
 * an authoring aid only: node effects, costs and prerequisites are validated
 * again by the PHP endpoints before a player can ever see or buy a protocol. */
(function () {
  'use strict';

  var nodes = [], categories = [], salvage = [], missions = [], rareLootTables = [], effectTypes = {}, boardSize = { width: 2040, height: 1080 }, missionLocksReady = false, lootTableLocksReady = false, queueTransmissionsReady = false;
  var current = null, categoryCurrent = null, dragging = null, panning = null, linkMode = false, linkSource = null, draftPosition = null, suppressClick = false;
  var canvas = document.getElementById('research-admin-canvas'), viewport = document.getElementById('research-admin-canvas-viewport');
  var count = document.getElementById('research-admin-count'), editorFields = document.getElementById('research-editor-fields');
  var imageUrl = document.getElementById('research-node-image-url'), imagePreview = document.getElementById('research-node-image-preview'), imageFile = document.getElementById('research-node-image-file');
  var effectType = document.getElementById('research-node-effect-type'), effectValue = document.getElementById('research-node-effect-value');
  var targetMissionField = document.getElementById('research-node-target-mission-field'), targetMission = document.getElementById('research-node-target-mission');
  var targetLootTableField = document.getElementById('research-node-target-loot-table-field'), targetLootTable = document.getElementById('research-node-target-loot-table');
  var prerequisites = document.getElementById('research-node-prerequisites'), salvageSelect = document.getElementById('research-node-salvage'), categorySelect = document.getElementById('research-node-category');
  var categoryList = document.getElementById('research-category-list'), categoryFields = document.getElementById('research-category-fields');

  function can(key) { return typeof window.pwHasPermission === 'function' && window.pwHasPermission(key); }
  function esc(value) { var node = document.createElement('div'); node.textContent = value == null ? '' : String(value); return node.innerHTML; }
  function safeImage(value) { return /^\/uploads\/research-images\/img_[a-f0-9]{16}\.jpg$/.test(String(value || '')) ? String(value) : ''; }
  function request(url, payload) {
    var options = { credentials: 'same-origin', cache: 'no-store' };
    if (payload) {
      payload.csrf = window.PW_AUTH && window.PW_AUTH.csrf ? window.PW_AUTH.csrf : '';
      options.method = 'POST'; options.headers = { 'Content-Type': 'application/json' }; options.body = JSON.stringify(payload);
    }
    return fetch(url, options).then(function (response) { return response.json().catch(function () { return {}; }); }).then(function (data) {
      if (!data.ok) throw new Error(data.error || 'The research request could not be completed.');
      return data;
    });
  }
  function byId(id) { return nodes.filter(function (node) { return Number(node.id) === Number(id); })[0] || null; }
  function categoryById(id) { return categories.filter(function (category) { return Number(category.id) === Number(id); })[0] || null; }
  function setError(message) { document.getElementById('research-node-error').textContent = message || ''; }
  function setNotice(message) { document.getElementById('research-node-status').textContent = message || ''; }
  function selectedIds(select) { return Array.prototype.slice.call(select.options).filter(function (option) { return option.selected; }).map(function (option) { return Number(option.value); }); }
  function slugify(value) { return String(value || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 120); }
  function valueText(node) {
    if (node.effect_type === 'secret_mission') return node.target_mission_name || 'Classified mission';
    if (node.effect_type === 'rare_loot_table') return node.target_loot_table_name || 'Rare loot table';
    if (node.effect_type === 'crew_capacity') return '+' + Math.floor(Number(node.effect_value || 0)) + ' slots';
    return '+' + Number(node.effect_value || 0) + '%';
  }
  function boardWidth() { return Math.max(960, Number(boardSize.width) || 2040); }
  function boardHeight() { return Math.max(600, Number(boardSize.height) || 1080); }
  function clampPosition(position) {
    return {
      x: Math.max(0, Math.min(boardWidth() - 196, Math.round(Number(position.x) || 0))),
      /* 126, not 116: api/admin/research/layout-save.php rejects anything past
       * boardHeight() - 126, so the extra ten pixels this used to allow were a
       * band where a drag looked accepted and then failed to save. */
      y: Math.max(0, Math.min(boardHeight() - 126, Math.round(Number(position.y) || 0)))
    };
  }
  function visibleCanvasPosition() {
    if (!viewport) return clampPosition({ x: 80 + nodes.length * 38, y: 80 + nodes.length * 34 });
    return clampPosition({
      x: viewport.scrollLeft + (viewport.clientWidth / 2) - 98,
      y: viewport.scrollTop + (viewport.clientHeight / 2) - 58
    });
  }

  function canvasControls() {
    var editable = can('research.manage');
    var hasLinks = nodes.length > 1;
    var linkLabel = linkMode ? 'Cancel link' : 'Connect';
    return '<div class="research-admin-canvas-controls" role="toolbar" aria-label="Research canvas controls">' +
      '<span class="research-admin-canvas-controls-title">Canvas</span>' +
      '<button type="button" class="research-admin-canvas-control" data-research-canvas-action="add"' + (editable ? '' : ' disabled') + '>+ Add</button>' +
      '<button type="button" class="research-admin-canvas-control' + (linkMode ? ' is-active' : '') + '" data-research-canvas-action="link" aria-pressed="' + (linkMode ? 'true' : 'false') + '"' + (editable && hasLinks ? '' : ' disabled') + '>' + linkLabel + '</button>' +
    '</div>';
  }
  function canvasLinkNote() {
    if (!linkMode) return '';
    return '<p class="research-admin-canvas-link-note" aria-live="polite">' +
      (linkSource ? 'Source selected: <strong>' + esc(linkSource.name) + '</strong>. Choose the protocol that should require it.' : 'Connect mode: choose the prerequisite protocol, then choose the protocol it unlocks.') +
    '</p>';
  }

  function renderCanvas() {
    if (!canvas) return;
    if (linkSource && !byId(linkSource.id)) linkSource = null;
    canvas.style.width = boardWidth() + 'px';
    canvas.style.minHeight = boardHeight() + 'px';
    canvas.classList.toggle('is-linking', linkMode);

    var lookup = {};
    nodes.forEach(function (node) { lookup[Number(node.id)] = node; });
    var lines = nodes.map(function (node) {
      return (node.prerequisite_ids || []).map(function (id) {
        var from = lookup[Number(id)];
        if (!from) return '';
        var startX = Number(from.canvas_x) + 196, startY = Number(from.canvas_y) + 58;
        var endX = Number(node.canvas_x), endY = Number(node.canvas_y) + 58;
        var curve = Math.max(48, (endX - startX) * .52);
        return '<path d="M ' + startX + ' ' + startY + ' C ' + (startX + curve) + ' ' + startY + ', ' + (endX - curve) + ' ' + endY + ', ' + endX + ' ' + endY + '"></path>';
      }).join('');
    }).join('');
    var cards = nodes.map(function (node) {
      var selected = current && Number(current.id) === Number(node.id);
      var source = linkSource && Number(linkSource.id) === Number(node.id);
      var image = safeImage(node.image_url), type = effectTypes[node.effect_type] || {};
      var nodeLabel = (node.category_name ? node.category_name + ' / ' : '') + (type.label || node.effect_type);
      var classes = 'research-admin-node' + (selected ? ' is-selected' : '') + (source ? ' is-link-source' : '') + (linkMode && !source ? ' is-link-target' : '') + (!node.is_enabled ? ' is-disabled' : '');
      return '<button type="button" class="' + classes + '" data-research-admin-node="' + Number(node.id) + '" style="left:' + Number(node.canvas_x) + 'px;top:' + Number(node.canvas_y) + 'px">' +
        '<span class="research-admin-node-head"><span class="research-admin-node-mark">' + (image ? '<img src="' + esc(image) + '" alt="">' : '⌬') + '</span><span><small>' + esc(nodeLabel) + '</small><strong>' + esc(node.name) + '</strong></span></span>' +
        '<p>' + esc(node.description || 'No field briefing yet.') + '</p><span class="research-admin-node-foot"><span>' + ((node.prerequisite_ids || []).length ? (node.prerequisite_ids || []).length + ' prerequisite' + ((node.prerequisite_ids || []).length === 1 ? '' : 's') : 'Root protocol') + '</span><b>' + esc(valueText(node)) + '</b></span>' +
      '</button>';
    }).join('');
    var empty = nodes.length ? '' : '<p class="admin-list-empty">No research protocols yet. Add your first permanent expedition unlock.</p>';
    canvas.innerHTML = canvasControls() + canvasLinkNote() + '<svg class="research-admin-lines" viewBox="0 0 ' + boardWidth() + ' ' + boardHeight() + '" preserveAspectRatio="none" aria-hidden="true">' + lines + '</svg>' + cards + empty;
    count.textContent = nodes.length + (nodes.length === 1 ? ' research protocol' : ' research protocols');
  }

  function previewImage() { var url = safeImage(imageUrl.value); imagePreview.hidden = !url; imagePreview.src = url || ''; }
  function fillSelect(select, items, selected, label, formatter) {
    select.innerHTML = label === null ? '' : '<option value="">' + esc(label) + '</option>';
    items.forEach(function (item) { var option = document.createElement('option'); option.value = item.id; option.textContent = formatter(item); option.selected = Number(item.id) === Number(selected); select.appendChild(option); });
  }
  function fillPrerequisites(selected, ownId) {
    prerequisites.innerHTML = '';
    nodes.filter(function (node) { return Number(node.id) !== Number(ownId); }).forEach(function (node) {
      var option = document.createElement('option'); option.value = node.id;
      option.textContent = node.name + ' · ' + ((effectTypes[node.effect_type] || {}).label || node.effect_type);
      option.selected = (selected || []).map(Number).indexOf(Number(node.id)) !== -1;
      prerequisites.appendChild(option);
    });
  }
  function toggleEffectFields() {
    var secret = effectType.value === 'secret_mission';
    var rareTable = effectType.value === 'rare_loot_table';
    targetMissionField.hidden = !secret;
    targetLootTableField.hidden = !rareTable;
    document.getElementById('research-node-effect-value-field').hidden = secret || rareTable;
    var effect = effectTypes[effectType.value] || {};
    var valueLabel = document.querySelector('label[for="research-node-effect-value"]');
    if (valueLabel) valueLabel.textContent = effect.value_label || 'Effect value (%)';
    /* Crew capacity and crew endurance are whole-number effects, not
     * percentages, and each has its own ceiling -- the same three shapes
     * api/admin/research/research-helpers.php validates. */
    var wholeNumberMax = { crew_capacity: '24', crew_fatigue: '200' }[effectType.value];
    effectValue.min = wholeNumberMax ? '1' : '0.01';
    effectValue.step = wholeNumberMax ? '1' : '0.01';
    effectValue.max = wholeNumberMax || '50';
  }
  function showEditor(node, placement) {
    current = node || null;
    if (current && current.id) draftPosition = null;
    else draftPosition = clampPosition(placement || draftPosition || visibleCanvasPosition());
    document.getElementById('research-editor-title').textContent = current && current.id ? 'Edit Research Protocol' : 'Add Research Protocol';
    document.getElementById('research-editor-copy').textContent = current && current.id ? 'Update this protocol\'s permanent field effect, costs, prerequisites or placement.' : 'Define the first permanent benefit in this new research branch.';
    editorFields.hidden = false;
    document.getElementById('research-node-name').value = current ? current.name || '' : '';
    document.getElementById('research-node-slug').value = current ? current.slug || '' : '';
    document.getElementById('research-node-slug').dataset.touched = current && current.id ? '1' : '';
    document.getElementById('research-node-description').value = current ? current.description || '' : '';
    var transmission = document.getElementById('research-node-activation-transmission');
    transmission.value = current ? current.activation_transmission || '' : '';
    transmission.disabled = !queueTransmissionsReady || !can('research.manage');
    document.getElementById('research-node-activation-transmission-hint').textContent = queueTransmissionsReady
      ? 'Optional. This is saved to the player command log when the protocol is activated.'
      : 'Run sql/migration_research_queue_transmissions.sql before authoring activation transmissions.';
    imageUrl.value = current ? current.image_url || '' : ''; previewImage();
    fillSelect(categorySelect, categories, current ? current.research_category_id : null, 'Uncategorised', function (category) { return category.name; });
    effectType.innerHTML = Object.keys(effectTypes).map(function (key) { return '<option value="' + esc(key) + '">' + esc(effectTypes[key].label) + '</option>'; }).join('');
    effectType.value = current ? current.effect_type : 'mission_speed'; effectValue.value = current ? current.effect_value : 5;
    fillSelect(targetMission, missions, current ? current.target_mission_definition_id : null, 'Choose mission', function (item) { return item.name + ' · ' + item.mission_type + (item.is_enabled ? '' : ' (disabled)'); });
    Array.prototype.slice.call(targetMission.options).forEach(function (option) {
      var mission = missions.filter(function (item) { return Number(item.id) === Number(option.value); })[0];
      if (mission) option.textContent += mission.requires_research_unlock ? ' · research locked' : ' · locks when saved';
    });
    targetMission.disabled = !missionLocksReady;
    if (!missionLocksReady) targetMission.options[0].textContent = 'Mission research locks migration required';
    fillSelect(targetLootTable, rareLootTables, current ? current.target_loot_table_id : null, 'Choose rare loot table', function (item) {
      return item.name + (item.is_enabled ? '' : ' (disabled)') + (item.requires_research_unlock ? ' · research locked' : ' · locks when saved');
    });
    targetLootTable.disabled = !lootTableLocksReady;
    if (!lootTableLocksReady) targetLootTable.options[0].textContent = 'Rare loot table migration required';
    fillPrerequisites(current ? current.prerequisite_ids : [], current ? current.id : null);
    document.getElementById('research-node-rank').value = current ? current.required_reputation_level : 1;
    document.getElementById('research-node-credit-cost').value = current ? current.credit_cost : 0;
    fillSelect(salvageSelect, salvage, current ? current.salvage_loot_definition_id : null, 'No salvage required', function (item) { return item.name + ' · ' + item.tier; });
    document.getElementById('research-node-salvage-quantity').value = current ? current.salvage_quantity : 0;
    document.getElementById('research-node-sort-order').value = current ? current.sort_order : nodes.length * 10;
    document.getElementById('research-node-enabled').checked = current ? !!current.is_enabled : true;
    document.getElementById('research-node-position').textContent = current && current.id ? 'X ' + current.canvas_x + ' · Y ' + current.canvas_y + ' — drag the node to move it.' : 'New protocol will enter at X ' + draftPosition.x + ' · Y ' + draftPosition.y + '.';
    document.getElementById('research-node-save-btn').disabled = !can('research.manage');
    toggleEffectFields(); setError(''); setNotice(''); renderCanvas();
  }
  function hideEditor() {
    current = null; draftPosition = null; editorFields.hidden = true;
    document.getElementById('research-editor-title').textContent = 'Select a protocol';
    document.getElementById('research-editor-copy').textContent = 'Choose an existing research node from the canvas, or add a new one to begin its dossier.';
    setError(''); setNotice(''); renderCanvas();
  }
  function payload() {
    var position = current && current.id ? { x: current.canvas_x, y: current.canvas_y } : (draftPosition || visibleCanvasPosition());
    return {
      id: current && current.id ? current.id : undefined,
      name: document.getElementById('research-node-name').value, slug: document.getElementById('research-node-slug').value,
      description: document.getElementById('research-node-description').value, activation_transmission: document.getElementById('research-node-activation-transmission').value, image_url: imageUrl.value,
      research_category_id: categorySelect.value,
      effect_type: effectType.value, effect_value: effectValue.value, target_mission_definition_id: targetMission.value, target_loot_table_id: targetLootTable.value,
      prerequisite_ids: selectedIds(prerequisites), required_reputation_level: document.getElementById('research-node-rank').value,
      credit_cost: document.getElementById('research-node-credit-cost').value, salvage_loot_definition_id: salvageSelect.value,
      salvage_quantity: document.getElementById('research-node-salvage-quantity').value, canvas_x: position.x, canvas_y: position.y,
      sort_order: document.getElementById('research-node-sort-order').value, is_enabled: document.getElementById('research-node-enabled').checked
    };
  }
  function save() {
    if (!can('research.manage')) return;
    var button = document.getElementById('research-node-save-btn'); button.disabled = true; button.classList.add('is-busy'); setError('');
    request('/api/admin/research/save.php', payload()).then(function (result) {
      current = { id: result.id }; draftPosition = null; setNotice('Research protocol saved.');
      return load().then(function () { current = byId(result.id); showEditor(current); setNotice('Research protocol saved.'); });
    }).catch(function (error) { setError(error.message); }).then(function () { button.disabled = !can('research.manage'); button.classList.remove('is-busy'); });
  }
  function savePosition(node) {
    if (!can('research.manage') || !node || !node.id) return;
    request('/api/admin/research/layout-save.php', { id: node.id, canvas_x: node.canvas_x, canvas_y: node.canvas_y }).catch(function (error) { setError(error.message); });
  }
  function upload() {
    if (!can('research.manage') || !imageFile.files || !imageFile.files[0]) { setError('Choose an image file first.'); return; }
    var button = document.getElementById('research-node-image-upload'), data = new FormData();
    data.append('image', imageFile.files[0]); data.append('csrf', window.PW_AUTH && window.PW_AUTH.csrf ? window.PW_AUTH.csrf : ''); button.disabled = true; setError('');
    fetch('/api/admin/research/upload-image.php', { method: 'POST', credentials: 'same-origin', body: data }).then(function (response) { return response.json(); }).then(function (result) {
      if (!result.ok) throw new Error(result.error || 'Image upload failed.');
      imageUrl.value = result.url; previewImage(); setNotice('Research image uploaded. Save the protocol to attach it.');
    }).catch(function (error) { setError(error.message); }).then(function () { button.disabled = !can('research.manage'); imageFile.value = ''; });
  }
  function connectNodes(source, target) {
    if (!source || !target || Number(source.id) === Number(target.id)) return;
    request('/api/admin/research/connect.php', { prerequisite_node_id: source.id, research_node_id: target.id }).then(function () {
      linkMode = false; linkSource = null;
      return load().then(function () { showEditor(byId(target.id)); setNotice('Connected “' + source.name + '” as a prerequisite for “' + target.name + '”.'); });
    }).catch(function (error) { setError(error.message); });
  }
  function beginAddProtocol() {
    if (!can('research.manage')) return;
    linkMode = false; linkSource = null; showEditor(null, visibleCanvasPosition());
    window.setTimeout(function () { document.getElementById('research-node-name').focus(); }, 0);
  }
  function setCategoryError(message) { document.getElementById('research-category-error').textContent = message || ''; }
  function setCategoryNotice(message) { document.getElementById('research-category-status').textContent = message || ''; }
  function renderCategories() {
    if (!categoryList) return;
    categoryList.innerHTML = categories.length ? categories.map(function (category) {
      var selected = categoryCurrent && Number(categoryCurrent.id) === Number(category.id);
      var assigned = nodes.filter(function (node) { return Number(node.research_category_id) === Number(category.id); }).length;
      var gate = category.requires_all_other_unlocked ? '<small>Final gate</small>' : '';
      return '<button type="button" class="research-category-row' + (selected ? ' is-selected' : '') + '" data-research-category="' + Number(category.id) + '"><span><strong>' + esc(category.name) + '</strong><small>' + esc(category.description || 'No branch briefing yet.') + '</small></span><span>' + gate + assigned + ' protocol' + (assigned === 1 ? '' : 's') + '</span></button>';
    }).join('') : '<p class="admin-list-empty">No research categories yet. Add one to begin organising protocol branches.</p>';
  }
  function showCategoryEditor(category) {
    categoryCurrent = category || null;
    categoryFields.hidden = false;
    document.getElementById('research-category-editor-title').textContent = categoryCurrent ? 'Edit Research Category' : 'Add Research Category';
    document.getElementById('research-category-editor-copy').textContent = categoryCurrent ? 'Refine this branch without changing the protocols already assigned to it.' : 'Create a reusable branch for the public research lattice.';
    document.getElementById('research-category-name').value = categoryCurrent ? categoryCurrent.name || '' : '';
    document.getElementById('research-category-slug').value = categoryCurrent ? categoryCurrent.slug || '' : '';
    document.getElementById('research-category-slug').dataset.touched = categoryCurrent ? '1' : '';
    document.getElementById('research-category-description').value = categoryCurrent ? categoryCurrent.description || '' : '';
    document.getElementById('research-category-sort-order').value = categoryCurrent ? categoryCurrent.sort_order : categories.length * 10;
    document.getElementById('research-category-final-gate').checked = categoryCurrent ? !!categoryCurrent.requires_all_other_unlocked : false;
    document.getElementById('research-category-final-gate').disabled = !can('research.manage');
    document.getElementById('research-category-save-btn').disabled = !can('research.manage');
    document.getElementById('research-category-delete-btn').hidden = !categoryCurrent || !can('research.manage');
    setCategoryError(''); setCategoryNotice(''); renderCategories();
  }
  function hideCategoryEditor() {
    categoryCurrent = null; categoryFields.hidden = true;
    document.getElementById('research-category-editor-title').textContent = 'Select a category';
    document.getElementById('research-category-editor-copy').textContent = 'Choose a category to refine its branch, or add a new one for future protocols.';
    setCategoryError(''); setCategoryNotice(''); renderCategories();
  }
  function categoryPayload() {
    return {
      id: categoryCurrent ? categoryCurrent.id : undefined,
      name: document.getElementById('research-category-name').value,
      slug: document.getElementById('research-category-slug').value,
      description: document.getElementById('research-category-description').value,
      sort_order: document.getElementById('research-category-sort-order').value,
      requires_all_other_unlocked: document.getElementById('research-category-final-gate').checked
    };
  }
  function saveCategory() {
    if (!can('research.manage')) return;
    var button = document.getElementById('research-category-save-btn');
    button.disabled = true; button.classList.add('is-busy'); setCategoryError('');
    request('/api/admin/research/category-save.php', categoryPayload()).then(function (result) {
      categoryCurrent = { id: result.id };
      return load().then(function () { showCategoryEditor(categoryById(result.id)); setCategoryNotice('Research category saved.'); });
    }).catch(function (error) { setCategoryError(error.message); }).then(function () { button.disabled = !can('research.manage'); button.classList.remove('is-busy'); });
  }
  function deleteCategory() {
    if (!can('research.manage') || !categoryCurrent) return;
    if (!window.confirm('Delete this research category? Assigned protocols will remain available as uncategorised.')) return;
    var button = document.getElementById('research-category-delete-btn');
    button.disabled = true; setCategoryError('');
    request('/api/admin/research/category-delete.php', { id: categoryCurrent.id }).then(function () {
      return load().then(function () { hideCategoryEditor(); setCategoryNotice('Research category deleted. Assigned protocols are now uncategorised.'); });
    }).catch(function (error) { setCategoryError(error.message); }).then(function () { button.disabled = !can('research.manage'); });
  }
  function toggleLinkMode() {
    if (!can('research.manage') || nodes.length < 2) return;
    linkMode = !linkMode; linkSource = null; setError(''); setNotice(''); renderCanvas();
  }
  function load() {
    return request('/api/admin/research/list.php?refresh=' + Date.now()).then(function (data) {
      nodes = data.nodes || []; categories = data.categories || []; salvage = data.salvage || []; missions = data.missions || []; rareLootTables = data.rare_loot_tables || []; missionLocksReady = !!data.mission_locks_ready; lootTableLocksReady = !!data.loot_table_locks_ready; queueTransmissionsReady = !!data.queue_transmissions_ready; effectTypes = data.effect_types || {}; boardSize = data.board || boardSize;
      if (categoryCurrent && categoryCurrent.id) categoryCurrent = categoryById(categoryCurrent.id);
      if (!editorFields.hidden) fillSelect(categorySelect, categories, categorySelect.value, 'Uncategorised', function (category) { return category.name; });
      renderCanvas(); renderCategories();
    }).catch(function (error) {
      canvas.innerHTML = '<p class="admin-list-empty">' + esc(error.message || 'Could not load Research Management.') + '</p>'; count.textContent = '';
    });
  }

  canvas.addEventListener('click', function (event) {
    var control = event.target.closest('[data-research-canvas-action]');
    if (control && canvas.contains(control)) {
      if (control.disabled) return;
      if (control.getAttribute('data-research-canvas-action') === 'add') beginAddProtocol();
      if (control.getAttribute('data-research-canvas-action') === 'link') toggleLinkMode();
      return;
    }
    var button = event.target.closest('[data-research-admin-node]');
    if (!button || !canvas.contains(button)) return;
    if (suppressClick) { suppressClick = false; return; }
    var node = byId(Number(button.getAttribute('data-research-admin-node')));
    if (!node) return;
    if (!linkMode) { showEditor(node); return; }
    if (!linkSource) {
      linkSource = node; showEditor(node); setNotice('Prerequisite selected. Now choose the protocol it unlocks.'); renderCanvas();
    } else if (Number(linkSource.id) !== Number(node.id)) {
      connectNodes(linkSource, node);
    }
  });
  canvas.addEventListener('pointerdown', function (event) {
    var button = event.target.closest('[data-research-admin-node]');
    if (button && canvas.contains(button)) {
      if (linkMode || !can('research.manage') || event.button !== 0) return;
      var node = byId(Number(button.getAttribute('data-research-admin-node')));
      if (!node) return;
      var rect = canvas.getBoundingClientRect();
      dragging = { node: node, button: button, offsetX: event.clientX - rect.left - Number(node.canvas_x), offsetY: event.clientY - rect.top - Number(node.canvas_y), moved: false };
      button.classList.add('is-dragging'); button.setPointerCapture(event.pointerId); return;
    }
    if (event.pointerType !== 'mouse' || event.button !== 0 || event.target.closest('[data-research-canvas-action]') || !viewport) return;
    panning = { pointerId: event.pointerId, clientX: event.clientX, clientY: event.clientY, scrollLeft: viewport.scrollLeft, scrollTop: viewport.scrollTop };
    viewport.classList.add('is-panning');
    try { canvas.setPointerCapture(event.pointerId); } catch (ignore) {}
    event.preventDefault();
  });
  canvas.addEventListener('pointermove', function (event) {
    if (panning && panning.pointerId === event.pointerId) {
      viewport.scrollLeft = panning.scrollLeft - (event.clientX - panning.clientX);
      viewport.scrollTop = panning.scrollTop - (event.clientY - panning.clientY);
      event.preventDefault(); return;
    }
    if (!dragging) return;
    var rect = canvas.getBoundingClientRect();
    var position = clampPosition({ x: event.clientX - rect.left - dragging.offsetX, y: event.clientY - rect.top - dragging.offsetY });
    dragging.node.canvas_x = position.x; dragging.node.canvas_y = position.y;
    dragging.button.style.left = position.x + 'px'; dragging.button.style.top = position.y + 'px'; dragging.moved = true;
    document.getElementById('research-node-position').textContent = 'X ' + position.x + ' · Y ' + position.y + ' — release to save placement.';
  });
  function finishPointer(event) {
    if (panning && panning.pointerId === event.pointerId) {
      try { canvas.releasePointerCapture(event.pointerId); } catch (ignore) {}
      viewport.classList.remove('is-panning'); panning = null; return;
    }
    if (!dragging) return;
    try { dragging.button.releasePointerCapture(event.pointerId); } catch (ignore) {}
    dragging.button.classList.remove('is-dragging');
    if (dragging.moved) {
      suppressClick = true;
      current = dragging.node;
      showEditor(current);
      savePosition(current);
    }
    dragging = null;
  }
  canvas.addEventListener('pointerup', finishPointer);
  canvas.addEventListener('pointercancel', finishPointer);

  document.getElementById('research-node-create-btn').addEventListener('click', beginAddProtocol);
  document.getElementById('research-category-create-btn').addEventListener('click', function () { if (can('research.manage')) showCategoryEditor(null); });
  categoryList.addEventListener('click', function (event) {
    var row = event.target.closest('[data-research-category]');
    if (!row || !categoryList.contains(row)) return;
    var category = categoryById(Number(row.getAttribute('data-research-category')));
    if (category) showCategoryEditor(category);
  });
  document.getElementById('research-category-save-btn').addEventListener('click', saveCategory);
  document.getElementById('research-category-cancel-btn').addEventListener('click', hideCategoryEditor);
  document.getElementById('research-category-delete-btn').addEventListener('click', deleteCategory);
  document.getElementById('research-category-name').addEventListener('input', function () {
    var slug = document.getElementById('research-category-slug');
    if ((!categoryCurrent || !categoryCurrent.id) && (!slug.dataset.touched || !slug.value)) slug.value = slugify(this.value).slice(0, 80);
  });
  document.getElementById('research-category-slug').addEventListener('input', function () { this.dataset.touched = '1'; });
  document.getElementById('research-node-save-btn').addEventListener('click', save);
  document.getElementById('research-node-cancel-btn').addEventListener('click', function () { if (current && current.id) showEditor(byId(current.id)); else hideEditor(); });
  effectType.addEventListener('change', toggleEffectFields);
  document.getElementById('research-node-name').addEventListener('input', function () {
    var slug = document.getElementById('research-node-slug');
    if ((!current || !current.id) && (!slug.dataset.touched || !slug.value)) slug.value = slugify(this.value);
  });
  document.getElementById('research-node-slug').addEventListener('input', function () { this.dataset.touched = '1'; });
  imageUrl.addEventListener('input', previewImage);
  document.getElementById('research-node-image-upload').addEventListener('click', function () { imageFile.click(); });
  imageFile.addEventListener('change', upload);
  document.getElementById('research-node-image-clear').addEventListener('click', function () { imageUrl.value = ''; previewImage(); });
  window.loadResearchManagement = function () { return load(); };
}());
