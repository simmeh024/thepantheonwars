/* Research Management keeps the tree's layout in the database. The canvas is
 * an authoring aid only: node effects, costs and prerequisites are validated
 * again by the PHP endpoints before a player can ever see or buy a protocol. */
(function () {
  'use strict';

  var nodes = [], categories = [], salvage = [], missions = [], rareLootTables = [], effectTypes = {}, boardSize = { width: 2040, height: 1440 }, legendaryBoardSize = { width: 1200, height: 540 }, boardMode = 'standard', missionLocksReady = false, lootTableLocksReady = false, queueTransmissionsReady = false;
  var current = null, categoryCurrent = null, dragging = null, panning = null, linkMode = false, linkSource = null, draftPosition = null, suppressClick = false;
  var zoom = 1, view = 'canvas', listQuery = '', listSort = 'name';
  var zoomStage = document.getElementById('research-admin-zoom');
  var minimap = document.getElementById('research-admin-minimap');
  var minimapView = document.getElementById('research-admin-minimap-view');
  var listView = document.getElementById('research-admin-listview');
  var listHost = document.getElementById('research-admin-list');
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
    /* An effect carrying a 'flat' unit is a count, not a percentage. Read from
     * the vocabulary the API ships rather than matched against a list of names
     * here -- that list only knew about crew capacity, so crew endurance had
     * already been rendering as a percentage in this column. */
    var flat = (effectTypes[node.effect_type] || {}).flat;
    if (flat) return '+' + Math.floor(Number(node.effect_value || 0)) + ' ' + flat;
    return '+' + Number(node.effect_value || 0) + '%';
  }
  /* Two canvases, separated by the one flag that already decided the endgame
     branch: a category carrying requires_all_other_unlocked is authored on the
     smaller legendary board. Deriving it here rather than storing a second flag
     is what keeps this surface and api/research/overview.php from ever
     disagreeing about which board a protocol lives on. */
  function categoryIsLegendary(id) {
    var category = categoryById(id);
    return !!(category && category.requires_all_other_unlocked);
  }
  function nodeIsLegendary(node) { return categoryIsLegendary(node && node.research_category_id); }
  function boardNodes() {
    return nodes.filter(function (node) { return nodeIsLegendary(node) === (boardMode === 'legendary'); });
  }
  function boardWidth() {
    if (boardMode === 'legendary') return Math.max(480, Number(legendaryBoardSize.width) || 1200);
    return Math.max(960, Number(boardSize.width) || 2040);
  }
  function boardHeight() {
    if (boardMode === 'legendary') return Math.max(300, Number(legendaryBoardSize.height) || 540);
    return Math.max(600, Number(boardSize.height) || 1440);
  }
  /* A node's own board, not the one being viewed -- used when saving, because
     moving a protocol into or out of a legendary category changes which bounds
     its stored position has to satisfy. */
  function boundsFor(legendaryCategory) {
    var size = legendaryCategory ? legendaryBoardSize : boardSize;
    return {
      width: Math.max(legendaryCategory ? 480 : 960, Number(size.width) || (legendaryCategory ? 1200 : 2040)),
      height: Math.max(legendaryCategory ? 300 : 600, Number(size.height) || (legendaryCategory ? 540 : 1440))
    };
  }
  function clampPosition(position) {
    return {
      x: Math.max(0, Math.min(boardWidth() - 196, Math.round(Number(position.x) || 0))),
      /* 126, not 116: api/admin/research/layout-save.php rejects anything past
       * boardHeight() - 126, so the extra ten pixels this used to allow were a
       * band where a drag looked accepted and then failed to save. */
      y: Math.max(0, Math.min(boardHeight() - 126, Math.round(Number(position.y) || 0)))
    };
  }
  /* The 240x180 pitch the board's own grid is drawn on. Placing to it is what
     makes a tidy tree possible without dragging anything. */
  var CELL_X = 240, CELL_Y = 180;
  function occupiedCells() {
    var taken = {};
    boardNodes().forEach(function (node) {
      taken[Math.round(Number(node.canvas_x) / CELL_X) + ':' + Math.round(Number(node.canvas_y) / CELL_Y)] = true;
    });
    return taken;
  }
  /* The first free cell at or after the viewport's top-left, scanning in
     reading order. A new node used to land at the exact centre of the view,
     which drops it on top of whatever was already there. */
  function firstFreeCell() {
    var taken = occupiedCells();
    var cols = Math.max(1, Math.floor(boardWidth() / CELL_X));
    var rows = Math.max(1, Math.floor(boardHeight() / CELL_Y));
    var startCol = viewport ? Math.max(0, Math.floor((viewport.scrollLeft / zoom) / CELL_X)) : 0;
    var startRow = viewport ? Math.max(0, Math.floor((viewport.scrollTop / zoom) / CELL_Y)) : 0;
    for (var pass = 0; pass < 2; pass++) {
      var fromRow = pass === 0 ? startRow : 0;
      for (var row = fromRow; row < rows; row++) {
        for (var col = pass === 0 && row === startRow ? startCol : 0; col < cols; col++) {
          if (!taken[col + ':' + row]) return clampPosition({ x: col * CELL_X, y: row * CELL_Y });
        }
      }
    }
    // Every cell is taken, so fall back to the view centre rather than refusing.
    return clampPosition({ x: (viewport ? viewport.scrollLeft / zoom : 0) + 40, y: (viewport ? viewport.scrollTop / zoom : 0) + 40 });
  }
  function visibleCanvasPosition() { return firstFreeCell(); }

  /* Snap every node on this board to the pitch, keeping their relative order
     and never stacking two in one cell. Positions are saved one request each,
     which is the same call a drag already makes. */
  function tidyLayout() {
    if (!can('research.manage')) return;
    var nodesToPlace = boardNodes().slice().sort(function (left, right) {
      return (Number(left.canvas_y) - Number(right.canvas_y)) || (Number(left.canvas_x) - Number(right.canvas_x));
    });
    var cols = Math.max(1, Math.floor(boardWidth() / CELL_X));
    nodesToPlace.forEach(function (node, index) {
      var position = clampPosition({ x: (index % cols) * CELL_X, y: Math.floor(index / cols) * CELL_Y });
      if (Number(node.canvas_x) === position.x && Number(node.canvas_y) === position.y) return;
      node.canvas_x = position.x; node.canvas_y = position.y;
      savePosition(node);
    });
    renderCanvas();
    setNotice('Layout tidied to the grid.');
  }

  /* The scale lives on a stage wrapper, not the canvas: a transform does not
     change layout size, so without a wrapper sized to the scaled board the
     viewport would keep scrolling the full 2040x1440 however far you zoomed
     out. The player map solves it the same way. */
  function applyZoom() {
    if (!zoomStage) return;
    zoom = Math.max(0.25, Math.min(1.5, zoom));
    canvas.style.transform = 'scale(' + zoom + ')';
    canvas.style.transformOrigin = 'top left';
    zoomStage.style.width = Math.round(boardWidth() * zoom) + 'px';
    zoomStage.style.height = Math.round(boardHeight() * zoom) + 'px';
    var level = document.getElementById('research-admin-zoom-level');
    if (level) level.textContent = Math.round(zoom * 100) + '%';
    drawMinimap();
  }
  function zoomToFit() {
    if (!viewport) return;
    // Fit the whole board, never magnify past 1: a 12-node tree blown up to
    // 150% is harder to place into, not easier.
    zoom = Math.min(1, Math.min(viewport.clientWidth / boardWidth(), viewport.clientHeight / boardHeight()));
    applyZoom();
    viewport.scrollLeft = 0; viewport.scrollTop = 0;
  }
  function toggleFullscreen() {
    var card = canvas.closest('.research-admin-canvas-card');
    if (!card) return;
    if (document.fullscreenElement) { document.exitFullscreen(); return; }
    if (card.requestFullscreen) card.requestFullscreen();
  }
  /* A whole-board overview. Nodes are rectangles, so this is one absolutely
     positioned div apiece -- cheaper than a canvas and it stays crisp. */
  function drawMinimap() {
    if (!minimap || !viewport) return;
    var scale = minimap.clientWidth / boardWidth();
    minimap.style.height = Math.round(boardHeight() * scale) + 'px';
    var marks = boardNodes().map(function (node) {
      return '<i class="research-admin-minimap-node' + (current && Number(current.id) === Number(node.id) ? ' is-current' : '')
        + (node.is_enabled ? '' : ' is-off') + '" style="left:' + (Number(node.canvas_x) * scale) + 'px;top:' + (Number(node.canvas_y) * scale)
        + 'px;width:' + Math.max(3, 196 * scale) + 'px;height:' + Math.max(2, 126 * scale) + 'px"></i>';
    }).join('');
    minimap.innerHTML = marks + '<i class="research-admin-minimap-view" id="research-admin-minimap-view"></i>';
    minimapView = document.getElementById('research-admin-minimap-view');
    syncMinimapView();
  }
  function syncMinimapView() {
    if (!minimapView || !viewport) return;
    var scale = minimap.clientWidth / boardWidth();
    minimapView.style.left = (viewport.scrollLeft / zoom) * scale + 'px';
    minimapView.style.top = (viewport.scrollTop / zoom) * scale + 'px';
    minimapView.style.width = Math.min(minimap.clientWidth, (viewport.clientWidth / zoom) * scale) + 'px';
    minimapView.style.height = Math.min(minimap.clientHeight, (viewport.clientHeight / zoom) * scale) + 'px';
  }

  function canvasControls() {
    var editable = can('research.manage');
    var hasLinks = boardNodes().length > 1;
    var linkLabel = linkMode ? 'Cancel link' : 'Connect';
    var onLegendary = boardMode === 'legendary';
    return '<div class="research-admin-canvas-controls" role="toolbar" aria-label="Research canvas controls">' +
      '<span class="research-admin-canvas-controls-title">Canvas</span>' +
      '<button type="button" class="research-admin-canvas-control' + (onLegendary ? '' : ' is-active') + '" data-research-board="standard" aria-pressed="' + (onLegendary ? 'false' : 'true') + '">Research tree</button>' +
      '<button type="button" class="research-admin-canvas-control is-legendary' + (onLegendary ? ' is-active' : '') + '" data-research-board="legendary" aria-pressed="' + (onLegendary ? 'true' : 'false') + '">Legendary</button>' +
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
    if (linkSource && nodeIsLegendary(linkSource) !== (boardMode === 'legendary')) linkSource = null;
    canvas.style.width = boardWidth() + 'px';
    canvas.style.minHeight = boardHeight() + 'px';
    canvas.classList.toggle('is-linking', linkMode);

    var visible = boardNodes();
    canvas.classList.toggle('is-legendary-board', boardMode === 'legendary');
    var lookup = {};
    visible.forEach(function (node) { lookup[Number(node.id)] = node; });
    var lines = visible.map(function (node) {
      return (node.prerequisite_ids || []).map(function (id) {
        var from = lookup[Number(id)];
        if (!from) return '';
        var startX = Number(from.canvas_x) + 196, startY = Number(from.canvas_y) + 58;
        var endX = Number(node.canvas_x), endY = Number(node.canvas_y) + 58;
        var curve = Math.max(48, (endX - startX) * .52);
        return '<path d="M ' + startX + ' ' + startY + ' C ' + (startX + curve) + ' ' + startY + ', ' + (endX - curve) + ' ' + endY + ', ' + endX + ' ' + endY + '"></path>';
      }).join('');
    }).join('');
    var cards = visible.map(function (node) {
      var selected = current && Number(current.id) === Number(node.id);
      var source = linkSource && Number(linkSource.id) === Number(node.id);
      var image = safeImage(node.image_url), type = effectTypes[node.effect_type] || {};
      var nodeLabel = (node.category_name ? node.category_name + ' / ' : '') + (type.label || node.effect_type);
      var classes = 'research-admin-node' + (selected ? ' is-selected' : '') + (source ? ' is-link-source' : '') + (linkMode && !source ? ' is-link-target' : '') + (!node.is_enabled ? ' is-disabled' : '');
      /* A hover card with the fields most often changed while laying a tree
         out. It posts the whole node with those fields overridden, because
         save.php rebuilds the row from its input -- a partial post would blank
         everything it did not mention. */
      var quick = can('research.manage')
        ? '<span class="research-admin-quick" data-research-quick="' + Number(node.id) + '"'
          /* Positioned from the node it belongs to. It is a sibling rather
             than a child because a form inside a button is invalid and its
             inputs would be unreachable -- but a sibling has no position of
             its own, so it takes the node's and sits just below the card. */
          + ' style="left:' + Number(node.canvas_x) + 'px;top:' + (Number(node.canvas_y) + 132) + 'px">'
          + '<label>Rank<input type="number" min="1" max="99" value="' + Number(node.required_reputation_level) + '" data-quick-field="required_reputation_level"></label>'
          + '<label>Credits<input type="number" min="0" max="1000000" value="' + Number(node.credit_cost) + '" data-quick-field="credit_cost"></label>'
          + '<label>Value<input type="number" min="0" step="0.01" value="' + Number(node.effect_value) + '" data-quick-field="effect_value"></label>'
          + '<label class="research-admin-quick-toggle"><input type="checkbox" data-quick-field="is_enabled"' + (node.is_enabled ? ' checked' : '') + '>Enabled</label>'
          + '<span class="research-admin-quick-actions">'
          + '<button type="button" class="btn btn-solid" data-quick-save="' + Number(node.id) + '">Apply</button>'
          + '<button type="button" class="btn" data-quick-open="' + Number(node.id) + '">Full editor</button>'
          + '</span></span>'
        : '';
      return '<button type="button" class="' + classes + '" data-research-admin-node="' + Number(node.id) + '" style="left:' + Number(node.canvas_x) + 'px;top:' + Number(node.canvas_y) + 'px">' +
        '<span class="research-admin-node-head"><span class="research-admin-node-mark">' + (image ? '<img src="' + esc(image) + '" alt="">' : '⌬') + '</span><span><small>' + esc(nodeLabel) + '</small><strong>' + esc(node.name) + '</strong></span></span>' +
        '<p>' + esc(node.description || 'No field briefing yet.') + '</p><span class="research-admin-node-foot"><span>' + ((node.prerequisite_ids || []).length ? (node.prerequisite_ids || []).length + ' prerequisite' + ((node.prerequisite_ids || []).length === 1 ? '' : 's') : 'Root protocol') + '</span><b>' + esc(valueText(node)) + '</b></span>' +
      '</button>' + quick;
    }).join('');
    var empty = visible.length ? '' : '<p class="admin-list-empty">' + (boardMode === 'legendary'
      ? 'No legendary protocols yet. Assign a protocol to a category marked as the final branch and it will appear here.'
      : 'No research protocols yet. Add your first permanent expedition unlock.') + '</p>';
    canvas.innerHTML = canvasControls() + canvasLinkNote() + '<svg class="research-admin-lines" viewBox="0 0 ' + boardWidth() + ' ' + boardHeight() + '" preserveAspectRatio="none" aria-hidden="true">' + lines + '</svg>' + cards + empty;
    applyZoom();
    count.textContent = visible.length + (visible.length === 1 ? ' research protocol' : ' research protocols')
      + (boardMode === 'legendary' ? ' on the legendary board' : '');
  }

  /* The list. A canvas is the right tool for arranging and the wrong one for
     finding, and past a dozen nodes finding is most of the work. Same
     scan-first row every other module here uses. */
  function renderList() {
    if (!listHost) return;
    var query = listQuery.trim().toLowerCase();
    var rows = nodes.filter(function (node) {
      if (!query) return true;
      return [node.name, node.slug, node.category_name, (effectTypes[node.effect_type] || {}).label]
        .some(function (value) { return String(value || '').toLowerCase().indexOf(query) !== -1; });
    });
    rows.sort(function (left, right) {
      if (listSort === 'rank') return Number(left.required_reputation_level) - Number(right.required_reputation_level)
        || String(left.name).localeCompare(String(right.name));
      if (listSort === 'cost') return Number(right.credit_cost) - Number(left.credit_cost)
        || String(left.name).localeCompare(String(right.name));
      if (listSort === 'effect') return String((effectTypes[left.effect_type] || {}).label || left.effect_type)
        .localeCompare(String((effectTypes[right.effect_type] || {}).label || right.effect_type))
        || String(left.name).localeCompare(String(right.name));
      if (listSort === 'category') return String(left.category_name || '~').localeCompare(String(right.category_name || '~'))
        || String(left.name).localeCompare(String(right.name));
      return String(left.name).localeCompare(String(right.name));
    });
    if (!rows.length) {
      listHost.innerHTML = '<p class="admin-list-empty">'
        + (query ? 'No protocol matches that search.' : 'No research protocols yet.') + '</p>';
      return;
    }
    listHost.innerHTML = rows.map(function (node) {
      return '<button type="button" class="admin-row research-admin-listrow" data-research-admin-node="' + Number(node.id) + '">'
        + '<span class="research-admin-listname"><strong>' + esc(node.name) + '</strong><small>' + esc(node.slug) + '</small></span>'
        + '<span class="research-admin-listcell">' + esc(node.category_name || 'Uncategorised') + '</span>'
        + '<span class="research-admin-listcell">' + esc((effectTypes[node.effect_type] || {}).label || node.effect_type) + '</span>'
        + '<span class="research-admin-listcell">' + esc(valueText(node)) + '</span>'
        + '<span class="research-admin-listcell">Rank ' + Number(node.required_reputation_level) + '</span>'
        + '<span class="research-admin-listcell">' + Number(node.credit_cost).toLocaleString() + ' cr</span>'
        + '<span class="research-admin-liststate">' + (node.is_enabled ? '' : '<b class="admin-pill">Disabled</b>') + '</span>'
        + '</button>';
    }).join('');
  }

  function setView(next) {
    view = next === 'list' ? 'list' : 'canvas';
    var canvasOn = view === 'canvas';
    if (listView) listView.hidden = canvasOn;
    if (viewport) viewport.hidden = !canvasOn;
    if (minimap) minimap.hidden = !canvasOn;
    var controls = document.querySelector('.research-admin-map-controls');
    if (controls) controls.hidden = !canvasOn;
    ['canvas', 'list'].forEach(function (name) {
      var tab = document.getElementById('research-admin-view-' + name);
      if (!tab) return;
      tab.classList.toggle('is-active', name === view);
      tab.setAttribute('aria-selected', name === view ? 'true' : 'false');
    });
    if (canvasOn) { renderCanvas(); applyZoom(); } else renderList();
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
    /* A count effect is validated as a whole number against its own ceiling,
     * and a percentage against 50 -- the same three shapes
     * api/admin/research/research-helpers.php branches on, read from the same
     * vocabulary rather than restated as a map that has to be extended
     * alongside it. */
    var wholeNumberMax = effect.flat ? String(effect.max || 50) : null;
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
    /* A protocol added while the legendary board is open defaults to the first
       final-gate category rather than to Uncategorised. Without this a new
       legendary node saves onto the ordinary board and disappears from the
       canvas it was drawn on, which reads as the save having failed. */
    var defaultCategory = current ? current.research_category_id : null;
    if (!current && boardMode === 'legendary') {
      var finalCategory = categories.filter(function (category) { return category.requires_all_other_unlocked; })[0];
      if (finalCategory) defaultCategory = finalCategory.id;
    }
    fillSelect(categorySelect, categories, defaultCategory, 'Uncategorised', function (category) { return category.name; });
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
    /* Moving a protocol into a legendary category shrinks the board under it,
       so a position that was valid a moment ago can now be outside it. Clamped
       here rather than rejected: the administrator changed the category, not
       the coordinates, and a save that fails on a number they did not touch is
       not a useful error. */
    var saveBounds = boundsFor(categoryIsLegendary(categorySelect.value));
    position = {
      x: Math.max(0, Math.min(saveBounds.width - 196, Math.round(Number(position.x) || 0))),
      y: Math.max(0, Math.min(saveBounds.height - 126, Math.round(Number(position.y) || 0)))
    };
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
  function save(andAnother) {
    if (!can('research.manage')) return;
    var button = document.getElementById(andAnother ? 'research-node-save-add-btn' : 'research-node-save-btn');
    button.disabled = true; button.classList.add('is-busy'); setError('');
    request('/api/admin/research/save.php', payload()).then(function (result) {
      current = { id: result.id }; draftPosition = null; setNotice('Research protocol saved.');
      return load().then(function () {
        /* Save and add another keeps the editor open on a fresh node in the
           next free cell, so authoring a run of protocols is a run of form
           fills rather than a scroll back to the canvas between each. */
        if (andAnother) {
          current = null;
          beginAddProtocol();
          setNotice('Saved. Next protocol is ready at X ' + draftPosition.x + ' \u00b7 Y ' + draftPosition.y + '.');
          return;
        }
        current = byId(result.id); showEditor(current); setNotice('Research protocol saved.');
      });
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
    if (!can('research.manage') || boardNodes().length < 2) return;
    linkMode = !linkMode; linkSource = null; setError(''); setNotice(''); renderCanvas();
  }
  function load() {
    return request('/api/admin/research/list.php?refresh=' + Date.now()).then(function (data) {
      nodes = data.nodes || []; categories = data.categories || []; salvage = data.salvage || []; missions = data.missions || []; rareLootTables = data.rare_loot_tables || []; missionLocksReady = !!data.mission_locks_ready; lootTableLocksReady = !!data.loot_table_locks_ready; queueTransmissionsReady = !!data.queue_transmissions_ready; effectTypes = data.effect_types || {}; boardSize = data.board || boardSize; legendaryBoardSize = data.legendary_board || legendaryBoardSize;
      if (categoryCurrent && categoryCurrent.id) categoryCurrent = categoryById(categoryCurrent.id);
      if (!editorFields.hidden) fillSelect(categorySelect, categories, categorySelect.value, 'Uncategorised', function (category) { return category.name; });
      renderCanvas(); renderCategories(); renderList();
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
    var boardTab = event.target.closest('[data-research-board]');
    if (boardTab && canvas.contains(boardTab)) {
      event.preventDefault();
      var target = boardTab.getAttribute('data-research-board');
      if (target !== boardMode) {
        boardMode = target; linkMode = false; linkSource = null;
        // The editor may be showing a protocol from the board just left, which
        // would leave a selected card nobody can see.
        if (current && current.id && nodeIsLegendary(current) !== (boardMode === 'legendary')) hideEditor();
        else renderCanvas();
        if (viewport) { viewport.scrollLeft = 0; viewport.scrollTop = 0; }
      }
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
      /* Board coordinates, not screen ones. getBoundingClientRect reports the
         scaled box, so every pointer delta is divided by the zoom -- without
         it a node at 50% moves half as far as the cursor. */
      dragging = { node: node, button: button,
        offsetX: (event.clientX - rect.left) / zoom - Number(node.canvas_x),
        offsetY: (event.clientY - rect.top) / zoom - Number(node.canvas_y), moved: false };
      button.classList.add('is-dragging'); button.setPointerCapture(event.pointerId); return;
    }
    if (event.pointerType !== 'mouse' || event.button !== 0 || event.target.closest('[data-research-canvas-action]') || event.target.closest('[data-research-board]') || !viewport) return;
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
    var position = clampPosition({
      x: (event.clientX - rect.left) / zoom - dragging.offsetX,
      y: (event.clientY - rect.top) / zoom - dragging.offsetY
    });
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
  /* Quick edit. It merges over the stored node rather than posting only the
     changed fields, because save.php rebuilds the row from its input and a
     partial post would blank everything it did not mention. */
  canvas.addEventListener('click', function (event) {
    var open = event.target.closest('[data-quick-open]');
    if (open) {
      event.preventDefault(); event.stopPropagation();
      var node = byId(Number(open.getAttribute('data-quick-open')));
      if (node) showEditor(node);
      return;
    }
    var apply = event.target.closest('[data-quick-save]');
    if (!apply) return;
    event.preventDefault(); event.stopPropagation();
    var target = byId(Number(apply.getAttribute('data-quick-save')));
    var host = apply.closest('[data-research-quick]');
    if (!target || !host || !can('research.manage')) return;
    var patch = {};
    Array.prototype.forEach.call(host.querySelectorAll('[data-quick-field]'), function (field) {
      patch[field.getAttribute('data-quick-field')] = field.type === 'checkbox' ? field.checked : field.value;
    });
    apply.disabled = true; apply.classList.add('is-busy');
    request('/api/admin/research/save.php', {
      id: target.id, name: target.name, slug: target.slug, description: target.description,
      activation_transmission: target.activation_transmission || '', image_url: target.image_url || '',
      research_category_id: target.research_category_id || '', effect_type: target.effect_type,
      target_mission_definition_id: target.target_mission_definition_id || '',
      target_loot_table_id: target.target_loot_table_id || '',
      prerequisite_ids: target.prerequisite_ids || [],
      salvage_loot_definition_id: target.salvage_loot_definition_id || '',
      salvage_quantity: target.salvage_quantity || 0, canvas_x: target.canvas_x, canvas_y: target.canvas_y,
      sort_order: target.sort_order || 0,
      effect_value: patch.effect_value, required_reputation_level: patch.required_reputation_level,
      credit_cost: patch.credit_cost, is_enabled: patch.is_enabled
    }).then(function () {
      return load().then(function () { setNotice('Updated ' + target.name + '.'); });
    }).catch(function (error) {
      setError(error.message);
    }).then(function () { apply.disabled = false; apply.classList.remove('is-busy'); });
  }, true);

  ['zoom-out', 'zoom-in', 'zoom-fit', 'fullscreen', 'tidy'].forEach(function (name) {
    var button = document.getElementById('research-admin-' + name);
    if (!button) return;
    button.addEventListener('click', function () {
      if (name === 'zoom-out') { zoom -= 0.15; applyZoom(); }
      if (name === 'zoom-in') { zoom += 0.15; applyZoom(); }
      if (name === 'zoom-fit') zoomToFit();
      if (name === 'fullscreen') toggleFullscreen();
      if (name === 'tidy') tidyLayout();
    });
  });
  document.addEventListener('fullscreenchange', function () {
    var button = document.getElementById('research-admin-fullscreen');
    if (!button) return;
    var on = !!document.fullscreenElement;
    button.textContent = on ? 'Exit full screen' : 'Full screen';
    button.setAttribute('aria-pressed', on ? 'true' : 'false');
    // The viewport changed size, so the fitted scale and the minimap frame
    // are both stale until they are recomputed.
    if (on) zoomToFit(); else applyZoom();
  });
  if (viewport) viewport.addEventListener('scroll', syncMinimapView);
  if (minimap) minimap.addEventListener('click', function (event) {
    if (!viewport) return;
    var rect = minimap.getBoundingClientRect();
    var scale = minimap.clientWidth / boardWidth();
    // Centre the view on the point clicked rather than putting it top-left,
    // which is what a reader means by "take me there".
    viewport.scrollLeft = ((event.clientX - rect.left) / scale) * zoom - viewport.clientWidth / 2;
    viewport.scrollTop = ((event.clientY - rect.top) / scale) * zoom - viewport.clientHeight / 2;
  });
  ['canvas', 'list'].forEach(function (name) {
    var tab = document.getElementById('research-admin-view-' + name);
    if (tab) tab.addEventListener('click', function () { setView(name); });
  });
  var search = document.getElementById('research-admin-search');
  if (search) search.addEventListener('input', function () { listQuery = this.value; renderList(); });
  var listSortControl = document.getElementById('research-admin-listsort');
  if (listSortControl) listSortControl.addEventListener('change', function () { listSort = this.value; renderList(); });
  if (listHost) listHost.addEventListener('click', function (event) {
    var row = event.target.closest('[data-research-admin-node]');
    if (!row) return;
    var node = byId(Number(row.getAttribute('data-research-admin-node')));
    if (node) showEditor(node);
  });
  var saveAdd = document.getElementById('research-node-save-add-btn');
  if (saveAdd) saveAdd.addEventListener('click', function () { save(true); });

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
