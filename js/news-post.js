// Dedicated public News transmission view and its lightweight, member-only discussion.
// Comment text goes through formatBody() (escape first, then whitelist a
// closed BBCode tag set back in) -- same approach as community.html's own
// formatBody(), hand-duplicated here rather than shared since this codebase
// has no shared JS module. Keep the tag set in lockstep with community.html
// if either one changes. Article bodies use only a small server-sanitised
// editorial HTML subset returned by api/news/get.php (unrelated to comments).
(function () {
  'use strict';

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char];
    });
  }

  // Same tag set as community.html's formatBody(), minus nothing -- a bare
  // [quote]...[/quote] (no attribution) still renders fine even without a
  // Quote button here, since news comments have no reply-to-a-specific-
  // comment relationship to attach a quote target to.
  function formatBody(raw) {
    var s = escapeHtml(raw);
    s = s.replace(/\[quote=([^\]]{1,150})\]([\s\S]*?)\[\/quote\]/gi, function (m, attr, inner) {
      return '<blockquote class="comment-quote"><div class="comment-quote-attr">' + attr + '</div>' + inner + '</blockquote>';
    });
    s = s.replace(/\[quote\]([\s\S]*?)\[\/quote\]/gi, '<blockquote class="comment-quote">$1</blockquote>');
    s = s.replace(/\[spoiler\]([\s\S]*?)\[\/spoiler\]/gi, function (m, inner) {
      return '<div class="comment-spoiler"><button type="button" class="comment-spoiler-toggle" data-spoiler-toggle>' +
        '<span class="comment-spoiler-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg></span>' +
        '<span class="comment-spoiler-label-text">Reveal suppressed text</span></button><div class="comment-spoiler-content">' + inner + '</div></div>';
    });
    s = s.replace(/\[b\]([\s\S]*?)\[\/b\]/gi, '<strong>$1</strong>');
    s = s.replace(/\[i\]([\s\S]*?)\[\/i\]/gi, '<em>$1</em>');
    s = s.replace(/\[u\]([\s\S]*?)\[\/u\]/gi, '<u>$1</u>');
    s = s.replace(/\[c\]([\s\S]*?)\[\/c\]/gi, '<code>$1</code>');
    s = s.replace(/\[color=(#[0-9a-fA-F]{3,8})\]([\s\S]*?)\[\/color\]/gi, function (m, hex, inner) {
      return '<span style="color:' + hex + '">' + inner + '</span>';
    });
    s = s.replace(/\[url=(https?:\/\/[^\s\[\]"]+)\]([\s\S]*?)\[\/url\]/gi, function (m, url, label) {
      return '<a href="' + url + '" target="_blank" rel="noopener noreferrer nofollow">' + label + '</a>';
    });
    s = s.replace(/\[url\](https?:\/\/[^\s\[\]"]+)\[\/url\]/gi, function (m, url) {
      return '<a href="' + url + '" target="_blank" rel="noopener noreferrer nofollow">' + url + '</a>';
    });
    s = s.replace(/\[img\](https?:\/\/[^\s\[\]"]+)\[\/img\]/gi, function (m, url) {
      return '<img src="' + url + '" alt="" loading="lazy" class="comment-img">';
    });
    return s;
  }

  // Delegated so spoiler controls created from safe BBCode markup do not
  // need inline event handlers (CSP-compatible) -- same pattern as
  // community.html's own document-level spoiler listener.
  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-spoiler-toggle]');
    if (!button) return;
    var box = button.closest('.comment-spoiler');
    if (!box) return;
    box.classList.toggle('is-revealed');
    var label = button.querySelector('.comment-spoiler-label-text');
    if (label) label.textContent = box.classList.contains('is-revealed') ? 'Suppress again' : 'Reveal suppressed text';
  });

  // ---------- Formatting toolbar (trimmed copy of community.html's) ----------
  // No @mention autocomplete and no Quote button here -- news comments are
  // flat (no reply-to-a-specific-comment relationship to attach a mention
  // notification or quote target to).
  function attachEditorToolbar(textarea) {
    var bar = document.createElement('div');
    bar.className = 'editor-toolbar';

    function wrapSelection(before, after, placeholder) {
      var start = textarea.selectionStart;
      var end = textarea.selectionEnd;
      var val = textarea.value;
      var selected = val.slice(start, end) || placeholder;
      textarea.value = val.slice(0, start) + before + selected + after + val.slice(end);
      var curStart = start + before.length;
      var curEnd = curStart + selected.length;
      textarea.focus();
      textarea.setSelectionRange(curStart, curEnd);
    }

    function insertAtCursor(text) {
      var start = textarea.selectionStart;
      var end = textarea.selectionEnd;
      var val = textarea.value;
      textarea.value = val.slice(0, start) + text + val.slice(end);
      var pos = start + text.length;
      textarea.focus();
      textarea.setSelectionRange(pos, pos);
    }

    function promptUrl(label) {
      var url = window.prompt(label + ' (must start with http:// or https://):', 'https://');
      if (!url) return null;
      url = url.trim();
      if (!/^https?:\/\//i.test(url)) {
        window.alert('That link needs to start with http:// or https://');
        return null;
      }
      return url;
    }

    function makeBtn(label, title, handler) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'editor-btn';
      btn.title = title;
      btn.textContent = label;
      btn.addEventListener('click', handler);
      bar.appendChild(btn);
      return btn;
    }

    function addDivider() {
      var divider = document.createElement('span');
      divider.className = 'editor-toolbar-divider';
      bar.appendChild(divider);
    }

    makeBtn('B', 'Bold', function () { wrapSelection('[b]', '[/b]', 'bold text'); });
    makeBtn('I', 'Italic', function () { wrapSelection('[i]', '[/i]', 'italic text'); });
    makeBtn('U', 'Underline', function () { wrapSelection('[u]', '[/u]', 'underlined text'); });
    makeBtn('C', 'Code', function () { wrapSelection('[c]', '[/c]', 'code'); });

    addDivider();

    makeBtn('Link', 'Insert link (URL only)', function () {
      var start = textarea.selectionStart;
      var end = textarea.selectionEnd;
      var selected = textarea.value.slice(start, end);
      var url = promptUrl('Link URL');
      if (!url) return;
      var tag = selected ? '[url=' + url + ']' + selected + '[/url]' : '[url]' + url + '[/url]';
      var val = textarea.value;
      textarea.value = val.slice(0, start) + tag + val.slice(end);
      var pos = start + tag.length;
      textarea.focus();
      textarea.setSelectionRange(pos, pos);
    });

    makeBtn('Img', 'Insert image (URL only, no upload)', function () {
      var url = promptUrl('Image URL');
      if (!url) return;
      insertAtCursor('[img]' + url + '[/img]');
    });

    makeBtn('Spoiler', 'Spoiler tag', function () { wrapSelection('[spoiler]', '[/spoiler]', 'spoiler text'); });

    addDivider();

    var colorWrap = document.createElement('label');
    colorWrap.className = 'editor-color-btn';
    colorWrap.title = 'Text color';
    colorWrap.textContent = 'A';
    var colorInput = document.createElement('input');
    colorInput.type = 'color';
    colorInput.value = '#c9a86a';
    colorInput.addEventListener('change', function () {
      wrapSelection('[color=' + colorInput.value + ']', '[/color]', 'colored text');
    });
    colorWrap.appendChild(colorInput);
    bar.appendChild(colorWrap);

    addDivider();

    var previewBtn = makeBtn('Preview', 'Preview how this will render', function () { togglePreview(); });
    var toolbarButtons = Array.prototype.slice.call(bar.querySelectorAll('button')).filter(function (b) { return b !== previewBtn; });

    var counter = document.createElement('span');
    counter.className = 'editor-char-counter';
    bar.appendChild(counter);

    function updateCounter() {
      if (!textarea.maxLength || textarea.maxLength < 0) return;
      var len = textarea.value.length;
      counter.textContent = len + ' / ' + textarea.maxLength;
      counter.classList.toggle('is-near-limit', len > textarea.maxLength * 0.9);
    }
    textarea.addEventListener('input', updateCounter);
    updateCounter();

    textarea.parentNode.insertBefore(bar, textarea);

    var previewBox = document.createElement('div');
    previewBox.className = 'editor-preview-box comment-body';
    previewBox.hidden = true;
    textarea.parentNode.insertBefore(previewBox, textarea.nextSibling);

    var inPreview = false;
    function togglePreview() {
      inPreview = !inPreview;
      if (inPreview) {
        previewBox.innerHTML = textarea.value.trim()
          ? formatBody(textarea.value)
          : '<span class="editor-preview-empty">Nothing to preview yet.</span>';
        previewBox.hidden = false;
        textarea.hidden = true;
        previewBtn.textContent = 'Edit';
        toolbarButtons.forEach(function (b) { b.disabled = true; });
        colorInput.disabled = true;
      } else {
        previewBox.hidden = true;
        textarea.hidden = false;
        previewBtn.textContent = 'Preview';
        toolbarButtons.forEach(function (b) { b.disabled = false; });
        colorInput.disabled = false;
        textarea.focus();
      }
    }

    textarea.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && textarea.form) {
        e.preventDefault();
        textarea.form.requestSubmit();
      }
    });

    return bar;
  }

  var slug = new URLSearchParams(window.location.search).get('slug') || '';
  // Admin-only Dispatch Composer preview: renders a draft's current saved
  // state through this exact same article renderer (api/admin/dispatch-
  // composer/preview.php returns the identical shape api/news/get.php does)
  // so preview and publication can never visually drift apart. Requires the
  // viewer to hold dispatch_composer.view -- the endpoint enforces that, not
  // this page.
  var composerPreviewId = new URLSearchParams(window.location.search).get('composer_preview') || '';
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
  var reportModal = document.getElementById('news-report-modal');
  var reportReason = document.getElementById('news-report-reason');
  var reportError = document.getElementById('news-report-error');
  var reportStatus = document.getElementById('news-report-status');
  var reportSubmit = document.getElementById('news-report-submit');
  var reportCancel = document.getElementById('news-report-cancel');
  var reportClose = document.getElementById('news-report-modal-close');
  var reportTargetId = null;
  var entry = null;

  if (!articleHost || (!composerPreviewId && !/^[a-z0-9-]{1,120}$/.test(slug))) {
    showArticleError('This transmission could not be identified.');
    return;
  }

  attachEditorToolbar(commentBody);

  // ---------- Draft auto-save (client-only, localStorage) ----------
  // One draft per article slug, same reasoning as community.html's composer
  // drafts: a long reply lost to an accidental refresh/navigation is real
  // lost work with no server-side counterpart.
  var DRAFT_STORAGE_KEY = 'pw_news_comment_drafts';

  function loadDraftStore() {
    try { return JSON.parse(window.localStorage.getItem(DRAFT_STORAGE_KEY)) || {}; } catch (e) { return {}; }
  }
  function saveDraft(text) {
    var store = loadDraftStore();
    if (!text) { delete store[slug]; } else { store[slug] = text; }
    try { window.localStorage.setItem(DRAFT_STORAGE_KEY, JSON.stringify(store)); } catch (e) { /* localStorage unavailable -- fine, just skip */ }
  }
  function clearDraft() { saveDraft(''); }

  commentBody.addEventListener('input', function () { saveDraft(commentBody.value.trim()); });

  function restoreDraftIfAny() {
    var draft = loadDraftStore()[slug];
    if (draft && !commentBody.value) {
      commentBody.value = draft;
      commentBody.dispatchEvent(new Event('input'));
    }
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

  function renderArticleBody(post, target) {
    if (post.body_is_rich) {
      // The server strips every unsupported tag/attribute and limits images to
      // re-encoded files in /uploads/news-images before this assignment.
      target.innerHTML = post.body;
      return;
    }
    appendParagraphs(post.body, target);
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
    renderArticleBody(post, body);
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

    if (!post.is_preview) {
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
    }
    articleHost.appendChild(article);

    if (post.is_preview) {
      var previewNotice = document.createElement('p');
      previewNotice.className = 'lore-status';
      previewNotice.textContent = 'Composer preview — not yet published. Comments and sharing are disabled here.';
      articleHost.insertBefore(previewNotice, article);
    }
  }

  function setCommentAccess() {
    if (!entry || !entry.comments_enabled) return;
    var signedIn = !!(window.PW_AUTH && window.PW_AUTH.loggedIn && window.PW_AUTH.csrf);
    commentLogin.hidden = signedIn;
    commentForm.hidden = !signedIn;
    if (signedIn) restoreDraftIfAny();
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
      item.id = 'news-comment-' + comment.id;
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
      var text = document.createElement('div');
      text.className = 'comment-body';
      text.innerHTML = formatBody(comment.body);
      copy.appendChild(text);
      var actions = document.createElement('div');
      actions.className = 'news-comment-actions';
      var report = document.createElement('button');
      report.type = 'button';
      report.className = 'news-comment-report';
      report.textContent = 'Report reply';
      report.addEventListener('click', function () {
        if (!window.PW_AUTH || !window.PW_AUTH.loggedIn) {
          var login = document.querySelector('.auth-trigger');
          if (login) login.click();
          return;
        }
        openReportModal(comment.id);
      });
      actions.appendChild(report);
      copy.appendChild(actions);
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
        if (window.location.hash) {
          var target = document.getElementById(window.location.hash.slice(1));
          if (target) target.scrollIntoView({
            behavior: window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
            block: 'center'
          });
        }
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
      clearDraft();
      commentBody.value = '';
      return loadComments();
    }).catch(function (error) {
      commentError.textContent = error.message || 'Your reply could not be sent.';
    }).finally(function () {
      commentSubmit.disabled = false;
      commentSubmit.textContent = 'Post reply';
    });
  });

  function openReportModal(commentId) {
    reportTargetId = commentId;
    reportReason.value = '';
    reportError.classList.remove('show');
    reportStatus.classList.remove('show');
    reportSubmit.hidden = false;
    reportSubmit.disabled = false;
    reportModal.hidden = false;
    setTimeout(function () { reportReason.focus(); }, 30);
  }

  function closeReportModal() {
    reportModal.hidden = true;
    reportTargetId = null;
  }

  reportClose.addEventListener('click', closeReportModal);
  reportCancel.addEventListener('click', closeReportModal);
  reportModal.querySelector('.auth-modal-backdrop').addEventListener('click', closeReportModal);
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !reportModal.hidden) closeReportModal();
  });
  reportSubmit.addEventListener('click', function () {
    var reason = reportReason.value.trim();
    reportError.classList.remove('show');
    if (!reason) {
      reportError.textContent = "Tell us why you're reporting this reply.";
      reportError.classList.add('show');
      return;
    }
    if (!reportTargetId || !window.PW_AUTH || !window.PW_AUTH.csrf) return;
    reportSubmit.disabled = true;
    fetch('/api/reports/create.php', {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ target_type: 'news_comment', target_id: reportTargetId, reason: reason, csrf: window.PW_AUTH.csrf })
    }).then(function (response) { return response.json(); }).then(function (data) {
      if (!data.ok) throw new Error(data.error || 'Could not submit that report.');
      reportStatus.textContent = 'Thanks — this has been sent to the moderators.';
      reportStatus.classList.add('show');
      reportSubmit.hidden = true;
      setTimeout(closeReportModal, 1500);
    }).catch(function (error) {
      reportSubmit.disabled = false;
      reportError.textContent = error.message || 'Could not submit that report.';
      reportError.classList.add('show');
    });
  });

  document.addEventListener('pw-auth-ready', setCommentAccess);
  var detailUrl = composerPreviewId
    ? '/api/admin/dispatch-composer/preview.php?id=' + encodeURIComponent(composerPreviewId)
    : '/api/news/get.php?slug=' + encodeURIComponent(slug);
  fetch(detailUrl, { credentials: 'same-origin' })
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
