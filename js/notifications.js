// The Pantheon Wars — nav bell notifications (bell icon + dropdown)
// Depends on js/members.js having already run refreshAuthNav() and set
// window.PW_AUTH; this file only reacts to the 'pw-auth-ready' /
// 'pw-notifications-hide' events it dispatches, so load order after
// members.js (but that's already the existing script-tag order on every
// page) isn't strictly required.

function initNotifications() {
  var bellBtn = document.getElementById('notif-bell-btn');
  var badgeEl = document.getElementById('notif-badge');
  var dropdownEl = document.getElementById('notif-dropdown');
  var listEl = document.getElementById('notif-dropdown-list');
  var markAllBtn = document.getElementById('notif-mark-all-btn');
  if (!bellBtn) return;

  var POLL_MS = 60 * 1000;
  var pollTimer = null;
  var dropdownLoaded = false;
  var previousUnread = null;
  var hasRenderedEntries = false;
  var renderedEntryIds = {};
  var badgePulseTimer = null;

  bellBtn.setAttribute('aria-haspopup', 'dialog');
  bellBtn.setAttribute('aria-controls', 'notif-dropdown');
  bellBtn.setAttribute('aria-expanded', 'false');
  dropdownEl.setAttribute('role', 'dialog');
  dropdownEl.setAttribute('aria-label', 'Notifications');
  dropdownEl.setAttribute('tabindex', '-1');

  var TYPE_ICONS = {
    like: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M7 22V11M2 13v7a2 2 0 0 0 2 2h13.5a2 2 0 0 0 2-1.6l1.2-6A2 2 0 0 0 18.7 12H14V6a2 2 0 0 0-2-2l-2 5.5V22"/></svg>',
    mention: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M16 12v1.5a2.5 2.5 0 0 0 5 0V12a9 9 0 1 0-4 7.5"/></svg>',
    quote: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V4s-1-1-4-1-5 2-8 2-4-1-4-1z"/><path d="M4 22V4"/></svg>',
    report_resolved: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/><path d="M9.5 12l1.8 1.8L15 10"/></svg>',
    world_available: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18z"/></svg>',
    news_published: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h14v16H5z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>',
  };
  var EMPTY_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>';

  function loadingHtml() {
    return '<div class="notif-loading" role="status" aria-label="Loading notifications"><span></span><span></span><span></span></div>';
  }

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
    if (n.type === 'world_available' && n.world_slug) return 'worlds.html#' + encodeURIComponent(n.world_slug);
    if (n.type === 'news_published' && n.news_slug) return 'news.html#' + encodeURIComponent(n.news_slug);
    if (!n.topic_id) return 'notifications.html';
    return 'community.html?topic=' + encodeURIComponent(n.topic_id) +
      (n.comment_id ? '&comment=' + encodeURIComponent(n.comment_id) : '');
  }

  // This dropdown only ever runs .excerpt through escapeHtml (never
  // community.html's formatBody), so BBCode markup would otherwise show up
  // as literal [b]/[quote=...]/[spoiler] brackets. Strips it down to plain
  // text instead -- spoiler content is replaced with a placeholder rather
  // than un-hidden, since this is exactly the kind of preview a spoiler
  // tag exists to protect against.
  function stripBbcodePreview(raw) {
    var s = String(raw || '');
    s = s.replace(/\[spoiler\][\s\S]*?\[\/spoiler\]/gi, '(spoiler hidden)');
    s = s.replace(/\[quote=[^\]]{1,150}\]([\s\S]*?)\[\/quote\]/gi, '$1');
    s = s.replace(/\[quote\]([\s\S]*?)\[\/quote\]/gi, '$1');
    s = s.replace(/\[b\]([\s\S]*?)\[\/b\]/gi, '$1');
    s = s.replace(/\[i\]([\s\S]*?)\[\/i\]/gi, '$1');
    s = s.replace(/\[u\]([\s\S]*?)\[\/u\]/gi, '$1');
    s = s.replace(/\[c\]([\s\S]*?)\[\/c\]/gi, '$1');
    s = s.replace(/\[color=[^\]]+\]([\s\S]*?)\[\/color\]/gi, '$1');
    s = s.replace(/\[url=[^\]]+\]([\s\S]*?)\[\/url\]/gi, '$1');
    s = s.replace(/\[img\][\s\S]*?\[\/img\]/gi, '[image]');
    return s;
  }

  function notificationText(n) {
    var actor = n.actor ? escapeHtml(n.actor.display_name) : null;
    var excerpt = n.excerpt ? escapeHtml(stripBbcodePreview(n.excerpt).slice(0, 80)) : '';
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
      case 'world_available':
        var worldName = n.world_name ? escapeHtml(n.world_name) : 'A new world';
        return 'The following world is now ready to explore: <strong>' + worldName + '</strong>. Explore ' + worldName + ' &rarr;';
      case 'news_published':
        return 'A news article has been published. Check it out: <strong>' + (excerpt || 'Latest news') + '</strong> &rarr;';
      default:
        return 'You have a new notification.';
    }
  }

  function renderList(entries) {
    if (!entries.length) {
      listEl.innerHTML = '<div class="notif-empty notif-empty--icon">' + EMPTY_ICON + '<span>No notifications yet.</span></div>';
      return;
    }
    listEl.innerHTML = entries.map(function (n) {
      var id = String(n.id);
      var isNew = hasRenderedEntries && !renderedEntryIds[id];
      renderedEntryIds[id] = true;
      return '<a class="notif-row' + (n.is_read ? '' : ' notif-row-unread') + (isNew ? ' notif-row-is-new' : '') + '" href="' + escapeHtml(notificationLink(n)) + '" data-id="' + n.id + '">' +
        '<span class="notif-row-dot"></span>' +
        '<span class="notif-row-icon">' + (TYPE_ICONS[n.type] || '') + '</span>' +
        '<span class="notif-row-body">' +
          '<span class="notif-row-text">' + notificationText(n) + '</span>' +
          '<span class="notif-row-time">' + fmtRelativeTime(n.created_at) + '</span>' +
        '</span>' +
      '</a>';
    }).join('');
    hasRenderedEntries = true;
  }

  function pulseBadge() {
    badgeEl.classList.remove('notif-badge-pulse');
    void badgeEl.offsetWidth;
    badgeEl.classList.add('notif-badge-pulse');
    clearTimeout(badgePulseTimer);
    badgePulseTimer = setTimeout(function () {
      badgeEl.classList.remove('notif-badge-pulse');
    }, 750);
  }

  function loadUnreadCount() {
    fetch('/api/notifications/unread-count.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) return;
        if (data.unread > 0) {
          badgeEl.hidden = false;
          badgeEl.textContent = data.unread > 99 ? '99+' : String(data.unread);
          bellBtn.setAttribute('aria-label', 'Notifications, ' + data.unread + ' unread');
        } else {
          badgeEl.hidden = true;
          bellBtn.setAttribute('aria-label', 'Notifications');
        }
        if (previousUnread !== null && data.unread > previousUnread) pulseBadge();
        previousUnread = data.unread;
      })
      .catch(function () {});
  }

  function loadDropdown() {
    listEl.innerHTML = loadingHtml();
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
    dropdownEl.classList.remove('is-open');
    bellBtn.setAttribute('aria-expanded', 'false');
  }

  function openDropdown() {
    dropdownEl.hidden = false;
    dropdownEl.classList.remove('is-open');
    void dropdownEl.offsetWidth;
    dropdownEl.classList.add('is-open');
    bellBtn.setAttribute('aria-expanded', 'true');
    dropdownEl.focus();
    loadDropdown();
    dropdownLoaded = true;
  }

  bellBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    if (dropdownEl.hidden) openDropdown();
    else closeDropdown();
  });

  document.addEventListener('click', function (e) {
    if (dropdownEl.hidden) return;
    if (!e.target.closest('.notif-nav-item')) closeDropdown();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || dropdownEl.hidden) return;
    closeDropdown();
    bellBtn.focus();
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
    previousUnread = null;
  });

  if (window.PW_AUTH && window.PW_AUTH.loggedIn) startPolling();
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initNotifications);
else initNotifications();
