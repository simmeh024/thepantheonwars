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
  var threadAvatar = document.getElementById('messages-thread-avatar');
  var composeForm = document.getElementById('messages-compose-form');
  var composeBody = document.getElementById('messages-compose-body');
  var sendButton = document.getElementById('messages-send-btn');
  var sendDisabled = document.getElementById('messages-send-disabled');
  var blockButton = document.getElementById('messages-block-btn');
  var pinButton = document.getElementById('messages-pin-btn');
  var olderButton = document.getElementById('messages-load-older');
  var countEl = document.getElementById('messages-count');
  var writeButton = document.getElementById('messages-write-btn');
  var recipientPicker = document.getElementById('messages-recipient-picker');
  var recipientInput = document.getElementById('messages-recipient-input');
  var recipientResults = document.getElementById('messages-recipient-results');
  var typingEl = document.getElementById('messages-typing');
  var typingNameEl = document.getElementById('messages-typing-name');
  var threadProfileLink = document.getElementById('messages-thread-profile-link');
  var profilePopover = document.getElementById('messages-profile-popover');
  var profilePopoverName = document.getElementById('messages-profile-popover-name');
  var profilePopoverRole = document.getElementById('messages-profile-popover-role');
  var profilePopoverPresence = document.getElementById('messages-profile-popover-presence');
  var profilePopoverJoined = document.getElementById('messages-profile-popover-joined');
  var profilePopoverLink = document.getElementById('messages-profile-popover-link');
  var activeConversation = null;
  var activeMember = null;
  var oldestMessageId = 0;
  var polling = null;
  var typingPolling = null;
  var recipientSearchTimer = null;
  var lastTypingSentAt = 0;
  var readReceiptMessageId = 0;

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function shortText(value, limit) {
    var text = String(value || '').replace(/\s+/g, ' ').trim();
    return text.length > limit ? text.slice(0, limit - 1) + '…' : text;
  }

  function roleLabel(role) {
    role = String(role || 'member');
    return role.replace(/(^|_)([a-z])/g, function (match, prefix, letter) { return (prefix ? ' ' : '') + letter.toUpperCase(); });
  }

  function presenceLabel(status) {
    return { online: 'Online', away: 'Away', inactive: 'Inactive', offline: 'Offline' }[status] || 'Offline';
  }

  function profileUrl(member) { return 'member.html?id=' + encodeURIComponent(member.id); }

  function joinedLabel(value) {
    var date = new Date(String(value || '').replace(' ', 'T') + 'Z');
    return isNaN(date.getTime()) ? 'Member' : 'Member since ' + date.toLocaleDateString([], { month: 'short', year: 'numeric' });
  }

  function avatarHtml(member) {
    var initial = escapeHtml((member.display_name || '?').charAt(0).toUpperCase());
    return '<span class="messages-avatar is-' + escapeHtml(member.presence_status || 'offline') + '" style="--member-role-color:' + escapeHtml(member.role_color || '#c7ccd6') + '"><img src="/uploads/avatars/' + encodeURIComponent(member.id) + '.jpg" alt="" onerror="this.remove()"><span>' + initial + '</span></span>';
  }

  function wireAvatarFallback(container) {
    if (!container) return;
    var image = container.querySelector('img');
    if (image) image.addEventListener('error', function () { image.hidden = true; });
  }

  function setThreadIdentity(member, copy) {
    nameEl.textContent = member.display_name;
    roleEl.textContent = roleLabel(member.role) + (copy ? ' · ' + copy : '');
    roleEl.style.color = member.role_color || '#c7ccd6';
    threadAvatar.innerHTML = '<img src="/uploads/avatars/' + encodeURIComponent(member.id) + '.jpg" alt="" onerror="this.remove()"><span>' + escapeHtml((member.display_name || '?').charAt(0).toUpperCase()) + '</span>';
    threadAvatar.href = profileUrl(member);
    threadAvatar.className = 'messages-avatar is-' + (member.presence_status || 'offline');
    threadAvatar.style.setProperty('--member-role-color', member.role_color || '#c7ccd6');
    threadProfileLink.href = profileUrl(member);
    profilePopoverName.textContent = member.display_name;
    profilePopoverRole.textContent = roleLabel(member.role);
    profilePopoverRole.style.color = member.role_color || '#c7ccd6';
    profilePopoverPresence.textContent = presenceLabel(member.presence_status);
    profilePopoverJoined.textContent = joinedLabel(member.created_at);
    profilePopoverLink.href = profileUrl(member);
    profilePopover.hidden = false;
    wireAvatarFallback(threadAvatar);
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
      item.className = 'messages-conversation' + (activeConversation && activeConversation.id === conversation.id ? ' is-active' : '') +
        (conversation.unread_count ? ' is-unread' : '') + (conversation.is_pinned ? ' is-pinned' : '');
      item.innerHTML =
        avatarHtml(conversation.counterpart) +
        '<span class="messages-conversation-copy"><strong>' + escapeHtml(conversation.counterpart.display_name) + '</strong>' +
        '<small>' + escapeHtml(conversation.last_message ? shortText(conversation.last_message.body, 62) : 'No messages yet.') + '</small></span>' +
        (conversation.unread_count ? '<span class="messages-unread">' + Math.min(conversation.unread_count, 99) + '</span>' : '');
      item.addEventListener('click', function () { selectConversation(conversation.id); });
      wireAvatarFallback(item);
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
      '<span class="private-message-role" style="color:' + escapeHtml(message.sender.role_color || '#c7ccd6') + '">' + escapeHtml(roleLabel(message.sender.role)) + '</span>' +
      '<time>' + escapeHtml(formatDate(message.created_at)) + '</time></div>' +
      '<div class="private-message-body"></div>' +
      (own && Number(message.id) === Number(readReceiptMessageId) ? '<i class="private-message-read">Seen</i>' : '') +
      (!own ? '<button type="button" class="private-message-report">Report</button>' : '');
    row.querySelector('.private-message-body').textContent = message.body;
    var report = row.querySelector('.private-message-report');
    if (report) report.addEventListener('click', function () { showReportForm(row, message.id); });
    if (prepend) threadEl.insertBefore(row, threadEl.firstChild);
    else threadEl.appendChild(row);
  }

  function dateKey(value) {
    var date = new Date(String(value).replace(' ', 'T') + 'Z');
    return isNaN(date.getTime()) ? String(value).slice(0, 10) : date.toLocaleDateString([], { year: 'numeric', month: '2-digit', day: '2-digit' });
  }

  function dateLabel(value) {
    var date = new Date(String(value).replace(' ', 'T') + 'Z');
    if (isNaN(date.getTime())) return 'Earlier';
    var today = new Date();
    var yesterday = new Date(); yesterday.setDate(today.getDate() - 1);
    if (date.toDateString() === today.toDateString()) return 'Today';
    if (date.toDateString() === yesterday.toDateString()) return 'Yesterday';
    return date.toLocaleDateString([], { month: 'long', day: 'numeric', year: date.getFullYear() === today.getFullYear() ? undefined : 'numeric' });
  }

  function renderDateSeparator(value) {
    var separator = document.createElement('div');
    separator.className = 'messages-date-separator';
    separator.textContent = dateLabel(value);
    threadEl.appendChild(separator);
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
    setThreadIdentity(conversation.counterpart, 'Private conversation');
    readReceiptMessageId = 0;
    for (var i = messages.length - 1; i >= 0; i--) {
      if (Number(messages[i].sender_id) === Number(window.PW_AUTH.user.id) && Number(messages[i].id) <= Number(conversation.counterpart_last_read_message_id || 0)) {
        readReceiptMessageId = Number(messages[i].id);
        break;
      }
    }
    if (replace) threadEl.innerHTML = '';
    var previousDate = null;
    messages.forEach(function (message) {
      var currentDate = dateKey(message.created_at);
      if (currentDate !== previousDate) renderDateSeparator(message.created_at);
      renderMessage(message, false);
      previousDate = currentDate;
    });
    if (messages.length) oldestMessageId = Number(messages[0].id);
    composeForm.hidden = !conversation.can_send;
    sendDisabled.hidden = !!conversation.can_send;
    blockButton.hidden = Number(conversation.counterpart.id) === Number(window.PW_AUTH.user.id);
    blockButton.textContent = 'Block member';
    blockButton.dataset.targetId = conversation.counterpart.id;
    blockButton.dataset.blocked = '0';
    pinButton.hidden = false;
    pinButton.dataset.conversationId = conversation.id;
    pinButton.dataset.pinned = conversation.is_pinned ? '1' : '0';
    pinButton.setAttribute('aria-pressed', conversation.is_pinned ? 'true' : 'false');
    pinButton.textContent = conversation.is_pinned ? 'Pinned' : 'Pin';
    threadEl.scrollTop = threadEl.scrollHeight;
  }

  function markRead(id) {
    return post('/api/direct-messages/mark-read.php', { conversation_id: id }).catch(function () {});
  }

  function updateTypingIndicator(isTyping) {
    if (!activeConversation || !typingEl) return;
    typingEl.hidden = !isTyping;
    if (isTyping) typingNameEl.textContent = activeConversation.counterpart.display_name;
  }

  function refreshTyping() {
    if (!activeConversation || document.hidden) return;
    fetchJson('/api/direct-messages/typing.php?conversation_id=' + encodeURIComponent(activeConversation.id))
      .then(function (data) { updateTypingIndicator(!!data.is_typing); })
      .catch(function () { updateTypingIndicator(false); });
  }

  function publishTyping(isTyping, immediate) {
    if (!activeConversation) return;
    var now = Date.now();
    if (!immediate && isTyping && now - lastTypingSentAt < 3000) return;
    lastTypingSentAt = now;
    post('/api/direct-messages/typing.php', { conversation_id: activeConversation.id, is_typing: !!isTyping }).catch(function () {});
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
      refreshTyping();
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
      setThreadIdentity(data.member, 'New private conversation');
      composeForm.hidden = false;
      sendDisabled.hidden = true;
      blockButton.hidden = false;
      blockButton.textContent = 'Block member';
      blockButton.dataset.targetId = data.member.id;
      blockButton.dataset.blocked = '0';
      pinButton.hidden = true;
      profilePopover.hidden = false;
    }).catch(function (error) {
      emptyEl.querySelector('p').textContent = error.message;
    });
  }

  function chooseRecipient(member) {
    activeConversation = null;
    activeMember = member;
    setThreadIdentity(member, 'New private conversation');
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
      result.innerHTML = avatarHtml(member) + '<strong>' + escapeHtml(member.display_name) + '</strong><small style="color:' + escapeHtml(member.role_color || '#c7ccd6') + '">' + escapeHtml(roleLabel(member.role)) + '</small>';
      result.addEventListener('click', function () { chooseRecipient(member); });
      wireAvatarFallback(result);
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
    threadAvatar.innerHTML = '<span>?</span>';
    threadAvatar.removeAttribute('href');
    threadAvatar.className = 'messages-avatar';
    threadAvatar.style.removeProperty('--member-role-color');
    threadProfileLink.removeAttribute('href');
    profilePopover.hidden = true;
    recipientPicker.hidden = false;
    recipientInput.value = '';
    recipientInput.setAttribute('aria-label', 'Search message recipient');
    recipientResults.hidden = true;
    recipientResults.innerHTML = '';
    composeForm.hidden = true;
    sendDisabled.hidden = false;
    sendDisabled.textContent = 'Choose a recipient before writing your message.';
    blockButton.hidden = true;
    pinButton.hidden = true;
    olderButton.hidden = true;
    setConversationUrl(null);
    recipientInput.focus();
  }

  composeBody.addEventListener('input', function () {
    countEl.textContent = composeBody.value.length + ' / 2000';
    if (activeConversation && composeBody.value.trim()) publishTyping(true, false);
  });
  composeBody.addEventListener('blur', function () { publishTyping(false, true); });
  composeForm.addEventListener('submit', function (event) {
    event.preventDefault();
    var body = composeBody.value.trim();
    var recipientId = activeConversation ? activeConversation.counterpart.id : (activeMember && activeMember.id);
    if (!body || !recipientId) return;
    sendButton.disabled = true; sendButton.classList.add('is-busy');
    publishTyping(false, true);
    post('/api/direct-messages/send.php', { recipient_id: recipientId, body: body }).then(function (data) {
      composeBody.value = ''; countEl.textContent = '0 / 2000';
      return selectConversation(data.conversation_id).then(loadConversations);
    }).catch(function (error) {
      sendDisabled.hidden = false;
      sendDisabled.textContent = error.message;
    }).finally(function () { sendButton.disabled = false; sendButton.classList.remove('is-busy'); });
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
  pinButton.addEventListener('click', function () {
    var conversationId = Number(pinButton.dataset.conversationId || 0);
    if (!conversationId) return;
    var isPinned = pinButton.dataset.pinned !== '1';
    pinButton.disabled = true;
    post('/api/direct-messages/pin.php', { conversation_id: conversationId, is_pinned: isPinned }).then(function (data) {
      if (activeConversation) activeConversation.is_pinned = !!data.is_pinned;
      pinButton.dataset.pinned = data.is_pinned ? '1' : '0';
      pinButton.setAttribute('aria-pressed', data.is_pinned ? 'true' : 'false');
      pinButton.textContent = data.is_pinned ? 'Pinned' : 'Pin';
      loadConversations();
    }).finally(function () { pinButton.disabled = false; });
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
    typingPolling = setInterval(function () { refreshTyping(); }, 5000);
  }
  function stopPolling() {
    if (polling) { clearInterval(polling); polling = null; }
    if (typingPolling) { clearInterval(typingPolling); typingPolling = null; }
    publishTyping(false, true);
  }
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
