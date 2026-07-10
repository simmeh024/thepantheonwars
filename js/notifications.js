// The Pantheon Wars — nav bell notifications (bell icon + dropdown)
// Depends on js/members.js having already run refreshAuthNav() and set
// window.PW_AUTH; this file only reacts to the 'pw-auth-ready' /
// 'pw-notifications-hide' events it dispatches, so load order after
// members.js (but that's already the existing script-tag order on every
// page) isn't strictly required.

document.addEventListener('DOMContentLoaded', function () {
  var bellBtn = document.getElementById('notif-bell-btn');
  var badgeEl = document.getElementById('notif-badge');
  var dropdownEl = document.getElementById('notif-dropdown');
  var listEl = document.getElementById('notif-dropdown-list');
  var markAllBtn = document.getElementById('notif-mark-all-btn');
  if (!bellBtn) return;

  var POLL_MS = 60 * 1000;
  var pollTimer = null;
  var dropdownLoaded = false;

  var TYPE_ICONS = {
    like: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M7 22V11M2 13v7a2 2 0 0 0 2 2h13.5a2 2 0 0 0 2-1.6l1.2-6A2 2 0 0 0 18.7 12H14V6a2 2 0 0 0-2-2l-2 5.5V22"/></svg>',
    mention: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M16 12v1.5a2.5 2.5 0 0 0 5 0V12a9 9 0 1 0-4 7.5"/></svg>',
    quote: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V4s-1-1-4-1-5 2-8 2-4-1-4-1z"/><path d="M4 22V4"/></svg>',
    report_resolved: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/><path d="M9.5 12l1.8 1.8L15 10"/></svg>',
  };

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function fmtRelativeTime(iso) {
    var then = new Date(iso.replace(' ', 'T') + 'Z').getTime();
    var diffSec = Math.max(0, Math.round((Date.now() - then) / 1000));
    if (diffSec < 60) return 'just now';
    var diffMin = Math.round(diffSec / 60);
    if (diffMin < 60) return diffMin + 'm ago';
    var diffHr = Math.round(diffMin / 60);
    if (diffHr < 24) return diffHr + 'h ago';
    var diffDay = Math.round(diffHr / 24);
    if (diffDay < 30) return diffDay + 'd ago';
    return new Date(iso.replace(' ', 'T') + 'Z').toLocaleDateString();
  }

  function notificationLink(n) {
    if (!n.topic_id) return 'notifications.html';
    return 'community.html?topic=' + encodeURIComponent(n.topic_id) +
      (n.comment_id ? '&comment=' + encodeURIComponent(n.comment_id) : '');
  }

  function notificationText(n) {
    var actor = n.actor ? escapeHtml(n.actor.display_name) : null;
    var excerpt = n.excerpt ? escapeHtml(n.excerpt.slice(0, 80)) : '';
    switch (n.type) {
      case 'like':
        var likeCount = n.like_count || 1;
        var likeWho = '<strong>' + (actor || 'Someone') + '</strong>' +
          (likeCount > 1 ? ' and ' + (likeCount - 1) + ' other' + (likeCount - 1 === 1 ? '' : 's') : '');
        return likeWho + ' liked your post' + (n.topic_title ? ' in: <strong>' + escapeHtml(n.topic_title) + '</strong>' : '');
      case 'mention':
        return '<strong>' + (actor || 'Someone') + '</strong> mentioned you' + (n.topic_title ? ' in <strong>' + escapeHtml(n.topic_title) + '</strong>' : '');
      case 'quote':
        return '<strong>' + (actor || 'Someone') + '</strong> quoted you' + (n.topic_title ? ' in <strong>' + escapeHtml(n.topic_title) + '</strong>' : '');
      case 'report_resolved':
        return 'A moderator resolved your report' + (excerpt ? ': "' + excerpt + '"' : '');
      default:
        return 'You have a new notification.';
    }
  }

  function renderList(entries) {
    if (!entries.length) {
      listEl.innerHTML = '<div class="notif-empty">No notifications yet.</div>';
      return;
    }
    listEl.innerHTML = entries.map(function (n) {
      return '<a class="notif-row' + (n.is_read ? '' : ' notif-row-unread') + '" href="' + escapeHtml(notificationLink(n)) + '" data-id="' + n.id + '">' +
        '<span class="notif-row-dot"></span>' +
        '<span class="notif-row-icon">' + (TYPE_ICONS[n.type] || '') + '</span>' +
        '<span class="notif-row-body">' +
          '<span class="notif-row-text">' + notificationText(n) + '</span>' +
          '<span class="notif-row-time">' + fmtRelativeTime(n.created_at) + '</span>' +
        '</span>' +
      '</a>';
    }).join('');
  }

  function loadUnreadCount() {
    fetch('/api/notifications/unread-count.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) return;
        if (data.unread > 0) {
          badgeEl.hidden = false;
          badgeEl.textContent = data.unread > 99 ? '99+' : String(data.unread);
        } else {
          badgeEl.hidden = true;
        }
      })
      .catch(function () {});
  }

  function loadDropdown() {
    listEl.innerHTML = '<div class="notif-empty">Loading&hellip;</div>';
    fetch('/api/notifications/list.php?per_page=8', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) {
          listEl.innerHTML = '<div class="notif-empty">Could not load notifications.</div>';
          return;
        }
        renderList(data.entries || []);
      })
      .catch(function () {
        listEl.innerHTML = '<div class="notif-empty">Could not load notifications.</div>';
      });
  }

  function closeDropdown() {
    dropdownEl.hidden = true;
  }

  bellBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    var willOpen = dropdownEl.hidden;
    dropdownEl.hidden = !willOpen;
    if (willOpen) {
      loadDropdown();
      dropdownLoaded = true;
    }
  });

  document.addEventListener('click', function (e) {
    if (dropdownEl.hidden) return;
    if (!e.target.closest('.notif-nav-item')) closeDropdown();
  });

  listEl.addEventListener('click', function (e) {
    var row = e.target.closest('.notif-row');
    if (!row) return;
    var id = row.getAttribute('data-id');
    row.classList.remove('notif-row-unread');
    fetch('/api/notifications/mark-read.php', {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf: window.PW_AUTH.csrf, id: id }),
    }).then(loadUnreadCount).catch(function () {});
  });

  if (markAllBtn) {
    markAllBtn.addEventListener('click', function () {
      fetch('/api/notifications/mark-read.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf: window.PW_AUTH.csrf, all: true }),
      }).then(function () {
        loadUnreadCount();
        if (dropdownLoaded) loadDropdown();
      }).catch(function () {});
    });
  }

  function startPolling() {
    stopPolling();
    loadUnreadCount();
    pollTimer = setInterval(function () {
      if (document.hidden) return;
      loadUnreadCount();
    }, POLL_MS);
  }

  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  document.addEventListener('pw-auth-ready', function () {
    if (window.PW_AUTH.loggedIn) startPolling();
  });
  document.addEventListener('pw-notifications-hide', function () {
    stopPolling();
    closeDropdown();
    badgeEl.hidden = true;
  });

  if (window.PW_AUTH && window.PW_AUTH.loggedIn) startPolling();
});
