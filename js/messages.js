// The Pantheon Wars — private member messages. This deliberately uses small,
// visibility-aware polling instead of a persistent socket: it fits the shared
// hosting environment and the site's existing notification/presence pattern.
(function () {
  'use strict';

  var gate = document.getElementById('messages-gate');
  var app = document.getElementById('messages-app');
  var listEl = document.getElementById('messages-conversation-list');
  var emptyEl = document.getElementById('messages-thread-empty');
  var contentEl = document.getElementById('messages-thread-content');
  var threadEl = document.getElementById('messages-thread-list');
  var nameEl = document.getElementById('messages-thread-name');
  var roleEl = document.getElementById('messages-thread-role');
  var composeForm = document.getElementById('messages-compose-form');
  var composeBody = document.getElementById('messages-compose-body');
  var sendButton = document.getElementById('messages-send-btn');
  var sendDisabled = document.getElementById('messages-send-disabled');
  var blockButton = document.getElementById('messages-block-btn');
  var olderButton = document.getElementById('messages-load-older');
  var countEl = document.getElementById('messages-count');
  var writeButton = document.getElementById('messages-write-btn');
  var recipientPicker = document.getElementById('messages-recipient-picker');
  var recipientInput = document.getElementById('messages-recipient-input');
  var recipientResults = document.getElementById('messages-recipient-results');
  var activeConversation = null;
  var activeMember = null;
  var oldestMessageId = 0;
  var polling = null;
  var recipientSearchTimer = null;

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function shortText(value, limit) {
    var text = String(value || '').replace(/\s+/g, ' ').trim();
    return text.length > limit ? text.slice(0, limit - 1) + '…' : text;
  }

  function formatDate(value) {
    var date = new Date(String(value).replace(' ', 'T') + 'Z');
    if (isNaN(date.getTime())) return value;
    return date.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  function fetchJson(url, options) {
    return fetch(url, options || { credentials: 'same-origin' }).then(function (response) {
      return response.json().catch(function () { return { ok: false, error: 'Could not reach private messages.' }; });
    }).then(function (data) {
      if (!data.ok) throw new Error(data.error || 'Could not complete that request.');
      return data;
    });
  }

  function post(url, data) {
    data.csrf = window.PW_AUTH.csrf;
    return fetchJson(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
  }

  function currentParam(name) {
    return new URLSearchParams(location.search).get(name);
  }

  function setConversationUrl(id) {
    var url = new URL(location.href);
    url.searchParams.delete('member');
    if (id) url.searchParams.set('conversation', id);
    else url.searchParams.delete('conversation');
    history.replaceState({}, '', url.pathname + url.search);
  }

  function renderConversations(conversations) {
    listEl.innerHTML = '';
    if (!conversations.length) {
      listEl.innerHTML = '<p class="messages-list-empty">No conversations yet. Find a member to begin one.</p>';
      return;
    }
    conversations.forEach(function (conversation) {
      var item = document.createElement('button');
      item.type = 'button';
      item.className = 'messages-conversation' + (activeConversation && activeConversation.id === conversation.id ? ' is-active' : '');
      item.style.setProperty('--member-role-color', conversation.counterpart.role_color || '#c7ccd6');
      item.innerHTML =
        '<span class="messages-avatar">' + escapeHtml((conversation.counterpart.display_name || '?').charAt(0).toUpperCase()) + '</span>' +
        '<span class="messages-conversation-copy"><strong>' + escapeHtml(conversation.counterpart.display_name) + '</strong>' +
        '<small>' + escapeHtml(conversation.last_message ? shortText(conversation.last_message.body, 62) : 'No messages yet.') + '</small></span>' +
        (conversation.unread_count ? '<span class="messages-unread">' + Math.min(conversation.unread_count, 99) + '</span>' : '');
      item.addEventListener('click', function () { selectConversation(conversation.id); });
      listEl.appendChild(item);
    });
  }

  function loadConversations() {
    return fetchJson('/api/direct-messages/list.php?ts=' + Date.now()).then(function (data) {
      renderConversations(data.conversations || []);
      return data.conversations || [];
    }).catch(function (error) {
      listEl.innerHTML = '<p class="messages-list-empty">' + escapeHtml(error.message) + '</p>';
      return [];
    });
  }

  function renderMessage(message, prepend) {
    var own = Number(message.sender_id) === Number(window.PW_AUTH.user.id);
    var row = document.createElement('article');
    row.className = 'private-message' + (own ? ' is-own' : '');
    row.dataset.messageId = message.id;
    row.innerHTML =
      '<div class="private-message-meta"><strong>' + escapeHtml(own ? 'You' : message.sender.display_name) + '</strong>' +
      (message.sender.role === 'admin' || message.sender.role === 'moderator' ? '<span class="private-message-staff">Staff</span>' : '') +
      '<time>' + escapeHtml(formatDate(message.created_at)) + '</time></div>' +
      '<div class="private-message-body"></div>' +
      (!own ? '<button type="button" class="private-message-report">Report</button>' : '');
    row.querySelector('.private-message-body').textContent = message.body;
    var report = row.querySelector('.private-message-report');
    if (report) report.addEventListener('click', function () { showReportForm(row, message.id); });
    if (prepend) threadEl.insertBefore(row, threadEl.firstChild);
    else threadEl.appendChild(row);
  }

  function showReportForm(row, messageId) {
    if (row.querySelector('.private-message-report-form')) return;
    var form = document.createElement('form');
    form.className = 'private-message-report-form';
    form.innerHTML = '<textarea required maxlength="1000" placeholder="Why should staff review this message?"></textarea><div><select><option value="harassment">Harassment</option><option value="spam">Spam</option><option value="other">Other</option></select><button type="submit">Send report</button><button type="button">Cancel</button></div><p></p>';
    form.querySelector('button[type="button"]').addEventListener('click', function () { form.remove(); });
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var reason = form.querySelector('textarea').value.trim();
      if (!reason) return;
      post('/api/direct-messages/report.php', { message_id: messageId, reason: reason, category: form.querySelector('select').value })
        .then(function () { form.querySelector('p').textContent = 'Report sent to staff.'; form.querySelector('textarea').disabled = true; form.querySelectorAll('button,select').forEach(function (el) { el.disabled = true; }); })
        .catch(function (error) { form.querySelector('p').textContent = error.message; });
    });
    row.appendChild(form);
    form.querySelector('textarea').focus();
  }

  function renderThread(conversation, messages, replace) {
    activeConversation = conversation;
    activeMember = null;
    emptyEl.hidden = true;
    contentEl.hidden = false;
    recipientPicker.hidden = true;
    nameEl.textContent = conversation.counterpart.display_name;
    roleEl.textContent = conversation.counterpart.role === 'admin' || conversation.counterpart.role === 'moderator' ? 'Staff conversation' : 'Private member conversation';
    roleEl.style.color = conversation.counterpart.role_color || '#c7ccd6';
    if (replace) threadEl.innerHTML = '';
    messages.forEach(function (message) { renderMessage(message, false); });
    if (messages.length) oldestMessageId = Number(messages[0].id);
    composeForm.hidden = !conversation.can_send;
    sendDisabled.hidden = !!conversation.can_send;
    blockButton.hidden = Number(conversation.counterpart.id) === Number(window.PW_AUTH.user.id);
    blockButton.textContent = 'Block member';
    blockButton.dataset.targetId = conversation.counterpart.id;
    blockButton.dataset.blocked = '0';
    threadEl.scrollTop = threadEl.scrollHeight;
  }

  function markRead(id) {
    return post('/api/direct-messages/mark-read.php', { conversation_id: id }).catch(function () {});
  }

  function selectConversation(id, beforeId) {
    var url = '/api/direct-messages/conversation.php?id=' + encodeURIComponent(id);
    if (beforeId) url += '&before_id=' + encodeURIComponent(beforeId);
    return fetchJson(url).then(function (data) {
      if (beforeId) {
        var priorHeight = threadEl.scrollHeight;
        data.messages.forEach(function (message) { renderMessage(message, true); });
        if (data.messages.length) oldestMessageId = Number(data.messages[0].id);
        threadEl.scrollTop = threadEl.scrollHeight - priorHeight;
        olderButton.hidden = !data.has_older;
      } else {
        renderThread(data.conversation, data.messages || [], true);
        olderButton.hidden = !data.has_older;
        setConversationUrl(id);
        markRead(id).then(loadConversations);
      }
    }).catch(function (error) {
      emptyEl.hidden = false;
      contentEl.hidden = true;
      emptyEl.querySelector('p').textContent = error.message;
    });
  }

  function openMember(memberId) {
    fetchJson('/api/members/get-public-profile.php?id=' + encodeURIComponent(memberId)).then(function (data) {
      activeConversation = null;
      activeMember = data.member;
      emptyEl.hidden = true;
      contentEl.hidden = false;
      recipientPicker.hidden = true;
      threadEl.innerHTML = '<div class="messages-new-thread-note">This conversation will begin when you send your first message.</div>';
      nameEl.textContent = data.member.display_name;
      roleEl.textContent = 'New private conversation';
      roleEl.style.color = data.member.role_color || '#c7ccd6';
      composeForm.hidden = false;
      sendDisabled.hidden = true;
      blockButton.hidden = false;
      blockButton.textContent = 'Block member';
      blockButton.dataset.targetId = data.member.id;
      blockButton.dataset.blocked = '0';
    }).catch(function (error) {
      emptyEl.querySelector('p').textContent = error.message;
    });
  }

  function chooseRecipient(member) {
    activeConversation = null;
    activeMember = member;
    nameEl.textContent = member.display_name;
    roleEl.textContent = member.role === 'admin' || member.role === 'moderator' ? 'New staff conversation' : 'New private conversation';
    roleEl.style.color = member.role_color || '#c7ccd6';
    recipientInput.value = member.display_name;
    recipientInput.setAttribute('aria-label', 'Recipient: ' + member.display_name);
    recipientResults.hidden = true;
    recipientResults.innerHTML = '';
    composeForm.hidden = false;
    sendDisabled.hidden = true;
    composeBody.focus();
  }

  function renderRecipientResults(members) {
    recipientResults.innerHTML = '';
    if (!members.length) {
      recipientResults.innerHTML = '<p>No matching members.</p>';
      recipientResults.hidden = false;
      return;
    }
    members.forEach(function (member) {
      var result = document.createElement('button');
      result.type = 'button';
      result.setAttribute('role', 'option');
      result.style.setProperty('--member-role-color', member.role_color || '#c7ccd6');
      result.innerHTML = '<span>' + escapeHtml((member.display_name || '?').charAt(0).toUpperCase()) + '</span><strong>' + escapeHtml(member.display_name) + '</strong><small>' + escapeHtml(member.role) + '</small>';
      result.addEventListener('click', function () { chooseRecipient(member); });
      recipientResults.appendChild(result);
    });
    recipientResults.hidden = false;
  }

  function searchRecipients() {
    var query = recipientInput.value.trim();
    activeMember = null;
    if (!query) {
      recipientResults.hidden = true;
      recipientResults.innerHTML = '';
      return;
    }
    fetchJson('/api/direct-messages/member-search.php?q=' + encodeURIComponent(query)).then(function (data) {
      renderRecipientResults(data.members || []);
    }).catch(function () {
      recipientResults.innerHTML = '<p>Could not search members right now.</p>';
      recipientResults.hidden = false;
    });
  }

  function startNewMessage() {
    activeConversation = null;
    activeMember = null;
    oldestMessageId = 0;
    emptyEl.hidden = true;
    contentEl.hidden = false;
    threadEl.innerHTML = '<div class="messages-new-thread-note">Choose a recipient in the <strong>To:</strong> field, then write your message.</div>';
    nameEl.textContent = 'New message';
    roleEl.textContent = 'Search for a member to begin a private conversation';
    roleEl.style.color = '';
    recipientPicker.hidden = false;
    recipientInput.value = '';
    recipientInput.setAttribute('aria-label', 'Search message recipient');
    recipientResults.hidden = true;
    recipientResults.innerHTML = '';
    composeForm.hidden = true;
    sendDisabled.hidden = false;
    sendDisabled.textContent = 'Choose a recipient before writing your message.';
    blockButton.hidden = true;
    olderButton.hidden = true;
    setConversationUrl(null);
    recipientInput.focus();
  }

  composeBody.addEventListener('input', function () { countEl.textContent = composeBody.value.length + ' / 2000'; });
  composeForm.addEventListener('submit', function (event) {
    event.preventDefault();
    var body = composeBody.value.trim();
    var recipientId = activeConversation ? activeConversation.counterpart.id : (activeMember && activeMember.id);
    if (!body || !recipientId) return;
    sendButton.disabled = true;
    post('/api/direct-messages/send.php', { recipient_id: recipientId, body: body }).then(function (data) {
      composeBody.value = ''; countEl.textContent = '0 / 2000';
      return selectConversation(data.conversation_id).then(loadConversations);
    }).catch(function (error) {
      sendDisabled.hidden = false;
      sendDisabled.textContent = error.message;
    }).finally(function () { sendButton.disabled = false; });
  });

  blockButton.addEventListener('click', function () {
    var targetId = Number(blockButton.dataset.targetId || 0);
    if (!targetId) return;
    var blocked = blockButton.dataset.blocked === '1';
    post('/api/direct-messages/' + (blocked ? 'unblock.php' : 'block.php'), { user_id: targetId }).then(function () {
      blockButton.dataset.blocked = blocked ? '0' : '1';
      blockButton.textContent = blocked ? 'Block member' : 'Unblock member';
      if (!blocked && !window.PW_AUTH.user.is_staff_messenger) {
        composeForm.hidden = true;
        sendDisabled.hidden = false;
      } else if (blocked) {
        composeForm.hidden = false;
        sendDisabled.hidden = true;
      }
      loadConversations();
    });
  });
  writeButton.addEventListener('click', startNewMessage);
  recipientInput.addEventListener('input', function () {
    if (recipientSearchTimer) clearTimeout(recipientSearchTimer);
    recipientSearchTimer = setTimeout(searchRecipients, 180);
  });
  recipientInput.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') { recipientResults.hidden = true; recipientResults.innerHTML = ''; }
  });
  olderButton.addEventListener('click', function () { if (activeConversation && oldestMessageId) selectConversation(activeConversation.id, oldestMessageId); });
  document.getElementById('messages-refresh').addEventListener('click', function () { loadConversations(); if (activeConversation) selectConversation(activeConversation.id); });

  function startPolling() {
    if (polling || document.hidden) return;
    polling = setInterval(function () { if (!document.hidden) { loadConversations(); if (activeConversation) selectConversation(activeConversation.id); } }, 25000);
  }
  function stopPolling() { if (polling) { clearInterval(polling); polling = null; } }
  document.addEventListener('visibilitychange', function () { if (document.hidden) stopPolling(); else { loadConversations(); if (activeConversation) selectConversation(activeConversation.id); startPolling(); } });

  function boot() {
    if (!window.PW_AUTH || !window.PW_AUTH.loggedIn) { gate.hidden = false; app.hidden = true; return; }
    gate.hidden = true; app.hidden = false;
    loadConversations().then(function () {
      var conversationId = Number(currentParam('conversation') || 0);
      var memberId = Number(currentParam('member') || 0);
      if (conversationId) selectConversation(conversationId);
      else if (memberId && memberId !== Number(window.PW_AUTH.user.id)) openMember(memberId);
    });
    startPolling();
  }

  document.addEventListener('pw-auth-ready', boot);
  if (window.PW_AUTH) boot();
})();
