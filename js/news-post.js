// Dedicated public News transmission view and its lightweight, member-only discussion.
// Article and comment text are always inserted with textContent.
(function () {
  'use strict';

  var slug = new URLSearchParams(window.location.search).get('slug') || '';
  var articleHost = document.getElementById('news-detail');
  var bannerTitle = document.getElementById('news-detail-banner-title');
  var commentsSection = document.getElementById('news-comments-section');
  var commentsList = document.getElementById('news-comments-list');
  var commentsCount = document.getElementById('news-comments-count');
  var commentLogin = document.getElementById('news-comments-login');
  var commentForm = document.getElementById('news-comment-form');
  var commentBody = document.getElementById('news-comment-body');
  var commentError = document.getElementById('news-comment-error');
  var commentSubmit = document.getElementById('news-comment-submit');
  var entry = null;

  if (!articleHost || !/^[a-z0-9-]{1,120}$/.test(slug)) {
    showArticleError('This transmission could not be identified.');
    return;
  }

  function showArticleError(message) {
    articleHost.textContent = '';
    var notice = document.createElement('p');
    notice.className = 'lore-status';
    notice.textContent = message;
    articleHost.appendChild(notice);
  }

  function dateLabel(value) {
    var date = new Date(String(value || '').replace(' ', 'T') + 'Z');
    if (isNaN(date.getTime())) return '';
    var days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    var months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var day = date.getUTCDate();
    var mod = day % 100;
    var suffix = mod >= 11 && mod <= 13 ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' }[day % 10] || 'th');
    return String(date.getUTCHours()).padStart(2, '0') + ':' + String(date.getUTCMinutes()).padStart(2, '0') +
      ' UTC — ' + days[date.getUTCDay()] + ' ' + day + suffix + ' — ' + months[date.getUTCMonth()] + ' ' + date.getUTCFullYear();
  }

  function relativeTime(value) {
    var time = new Date(String(value || '').replace(' ', 'T') + 'Z').getTime();
    if (isNaN(time)) return '';
    var seconds = Math.max(0, Math.round((Date.now() - time) / 1000));
    if (seconds < 60) return 'just now';
    if (seconds < 3600) return Math.round(seconds / 60) + 'm ago';
    if (seconds < 86400) return Math.round(seconds / 3600) + 'h ago';
    return Math.round(seconds / 86400) + 'd ago';
  }

  function appendParagraphs(body, target) {
    String(body || '').split(/\n\s*\n+/).forEach(function (raw) {
      var text = raw.trim();
      if (!text) return;
      var paragraph = document.createElement('p');
      paragraph.textContent = text;
      target.appendChild(paragraph);
    });
  }

  function renderArticle(post) {
    document.title = post.title + ' — The Pantheon Wars';
    bannerTitle.textContent = post.title;
    articleHost.textContent = '';

    var article = document.createElement('article');
    article.className = 'news-detail-article ' + (post.author_type === 'bh4' ? 'is-bh4' : 'is-member');
    var meta = document.createElement('div');
    meta.className = 'news-detail-meta';
    var date = document.createElement('span');
    date.textContent = dateLabel(post.published_at);
    meta.appendChild(date);
    var author = document.createElement('span');
    author.textContent = post.author_type === 'bh4' ? 'Relayed by BH-4' : 'Published by ' + (post.author_display_name || 'The Pantheon Wars Editorial');
    meta.appendChild(author);
    article.appendChild(meta);

    var title = document.createElement('h1');
    title.textContent = post.title;
    article.appendChild(title);

    var body = document.createElement('div');
    body.className = 'news-detail-body';
    appendParagraphs(post.body, body);
    article.appendChild(body);

    if (post.tags && post.tags.length) {
      var tags = document.createElement('div');
      tags.className = 'post-tags news-detail-tags';
      post.tags.forEach(function (tag) {
        var chip = document.createElement('span');
        chip.className = 'post-tag';
        chip.textContent = tag.label;
        tags.appendChild(chip);
      });
      article.appendChild(tags);
    }

    var actions = document.createElement('div');
    actions.className = 'news-detail-actions';
    var share = document.createElement('a');
    share.className = 'btn share-reddit';
    share.target = '_blank';
    share.rel = 'noopener';
    share.textContent = 'Share on Reddit ↗';
    share.href = 'https://www.reddit.com/submit?url=' + encodeURIComponent(window.location.href) + '&title=' + encodeURIComponent(post.title);
    actions.appendChild(share);
    article.appendChild(actions);
    articleHost.appendChild(article);
  }

  function setCommentAccess() {
    if (!entry || !entry.comments_enabled) return;
    var signedIn = !!(window.PW_AUTH && window.PW_AUTH.loggedIn && window.PW_AUTH.csrf);
    commentLogin.hidden = signedIn;
    commentForm.hidden = !signedIn;
  }

  function renderComments(comments) {
    commentsList.textContent = '';
    commentsCount.textContent = '(' + comments.length + ')';
    if (!comments.length) {
      var empty = document.createElement('p');
      empty.className = 'news-comments-empty';
      empty.textContent = 'No replies yet. Be the first traveller to respond.';
      commentsList.appendChild(empty);
      return;
    }
    comments.forEach(function (comment) {
      var item = document.createElement('article');
      item.className = 'news-comment';
      var avatar = document.createElement('span');
      avatar.className = 'news-comment-avatar';
      avatar.style.setProperty('--comment-role-color', comment.role_color || '#a279ec');
      avatar.textContent = String(comment.display_name || comment.username || '?').charAt(0).toUpperCase();
      item.appendChild(avatar);
      var copy = document.createElement('div');
      copy.className = 'news-comment-copy';
      var head = document.createElement('div');
      head.className = 'news-comment-meta';
      var name = document.createElement('strong');
      name.textContent = comment.display_name || comment.username || 'Member';
      head.appendChild(name);
      var role = document.createElement('span');
      role.textContent = String(comment.role || 'member').replace(/(^|_)([a-z])/g, function (_, gap, letter) { return gap + letter.toUpperCase(); });
      head.appendChild(role);
      var when = document.createElement('time');
      when.dateTime = comment.created_at;
      when.title = dateLabel(comment.created_at);
      when.textContent = relativeTime(comment.created_at);
      head.appendChild(when);
      copy.appendChild(head);
      var text = document.createElement('p');
      text.textContent = comment.body;
      copy.appendChild(text);
      item.appendChild(copy);
      commentsList.appendChild(item);
    });
  }

  function loadComments() {
    return fetch('/api/news/comments/list.php?slug=' + encodeURIComponent(slug), { credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data.ok) throw new Error(data.error || 'Comments could not be loaded.');
        if (!data.comments_enabled) {
          commentsSection.hidden = true;
          return;
        }
        renderComments(data.comments || []);
      })
      .catch(function () {
        commentsList.textContent = '';
        var error = document.createElement('p');
        error.className = 'news-comments-empty';
        error.textContent = 'Replies could not be loaded right now.';
        commentsList.appendChild(error);
      });
  }

  commentForm.addEventListener('submit', function (event) {
    event.preventDefault();
    var body = commentBody.value.trim();
    if (!body) {
      commentError.textContent = 'Write a reply before sending it.';
      return;
    }
    commentError.textContent = '';
    commentSubmit.disabled = true;
    commentSubmit.textContent = 'Sending…';
    fetch('/api/news/comments/post.php', {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ slug: slug, body: body, csrf: window.PW_AUTH.csrf })
    }).then(function (response) { return response.json(); }).then(function (data) {
      if (!data.ok) throw new Error(data.error || 'Your reply could not be sent.');
      commentBody.value = '';
      return loadComments();
    }).catch(function (error) {
      commentError.textContent = error.message || 'Your reply could not be sent.';
    }).finally(function () {
      commentSubmit.disabled = false;
      commentSubmit.textContent = 'Post reply';
    });
  });

  document.addEventListener('pw-auth-ready', setCommentAccess);
  fetch('/api/news/get.php?slug=' + encodeURIComponent(slug), { credentials: 'same-origin' })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      if (!data.ok || !data.entry) throw new Error(data.error || 'This transmission could not be loaded.');
      entry = data.entry;
      renderArticle(entry);
      if (!entry.comments_enabled) return;
      commentsSection.hidden = false;
      setCommentAccess();
      return loadComments();
    })
    .catch(function (error) { showArticleError(error.message || 'This transmission could not be loaded.'); });
})();
