// Homepage "From the Nexus Veil" dispatches teaser. Was static hand-written
// HTML that never reflected real posts; now pulls the two most recent from
// the same /api/news/list.php endpoint news.html uses, and reuses that
// page's own .post card component (meta rail, featured pill, signal
// indicator, tags, reveal-on-scroll animation) directly rather than
// inventing a second card design for this smaller strip. The section stays
// hidden until real posts render, so a fetch failure or an empty feed never
// leaves a visible, broken-looking gap on the homepage.
(function () {
  'use strict';

  var section = document.getElementById('home-dispatches-section');
  var container = document.getElementById('home-dispatches');
  if (!section || !container) return;

  var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function dateLabel(value) {
    var date = new Date(String(value || '').replace(' ', 'T') + 'Z');
    if (isNaN(date.getTime())) return '';
    var MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return MONTH_NAMES[date.getUTCMonth()] + ' ' + date.getUTCDate() + ', ' + date.getUTCFullYear();
  }

  function firstParagraph(post) {
    var body = String(post.body || '');
    if (post.body_is_rich) {
      body = body
        .replace(/<\s*br\s*\/?>/gi, '\n')
        .replace(/<\/(?:p|h2|h3|li|blockquote|figcaption|figure)>/gi, '\n\n')
        .replace(/<[^>]*>/g, '')
        .replace(/&nbsp;/gi, ' ')
        .replace(/&amp;/gi, '&');
    }
    var parts = body.split(/\n\s*\n+/).map(function (part) { return part.trim(); }).filter(Boolean);
    return parts[0] || '';
  }

  function signalFor(post) {
    var labels = (post.tags || []).map(function (tag) { return String(tag.label || '').toLowerCase(); }).join(' ');
    if (labels.indexOf('soundtrack') !== -1 || labels.indexOf('music') !== -1) return { type: 'soundtrack', label: 'Audio signal' };
    if (labels.indexOf('lore') !== -1 || labels.indexOf('world') !== -1) return { type: 'lore', label: 'Lore signal' };
    return { type: 'standard', label: 'Stable signal' };
  }

  function createCard(post, isFeatured) {
    var article = document.createElement('article');
    article.className = 'post news-card ' + (post.author_type === 'bh4' ? 'is-bh4' : 'is-member') + (isFeatured ? ' is-featured' : '');

    var rail = document.createElement('aside');
    rail.className = 'post-meta-rail';
    rail.setAttribute('aria-label', 'Article metadata');

    var date = document.createElement('span');
    date.className = 'date post-rail-time';
    date.textContent = dateLabel(post.published_at);
    rail.appendChild(date);

    var author = document.createElement('span');
    author.className = 'post-rail-author';
    author.textContent = post.author_type === 'bh4' ? 'BH-4 relay' : 'Member transmission';
    rail.appendChild(author);

    var tagCount = document.createElement('span');
    tagCount.className = 'post-rail-tags';
    var count = (post.tags || []).length;
    tagCount.textContent = count + ' tag' + (count === 1 ? '' : 's');
    rail.appendChild(tagCount);

    var commentCount = Number(post.comment_count || 0);
    var comments = document.createElement('a');
    comments.className = 'post-rail-comments';
    comments.href = 'news-post.html?slug=' + encodeURIComponent(post.slug) + '#news-comments-section';
    comments.textContent = '#' + commentCount + ' comment' + (commentCount === 1 ? '' : 's');
    rail.appendChild(comments);
    article.appendChild(rail);

    var content = document.createElement('div');
    content.className = 'post-card-content';

    if (isFeatured) {
      var pill = document.createElement('span');
      pill.className = 'post-featured-pill';
      pill.textContent = 'Latest transmission';
      content.appendChild(pill);
    }

    var signalInfo = signalFor(post);
    var signal = document.createElement('span');
    signal.className = 'post-signal is-' + signalInfo.type;
    signal.setAttribute('aria-label', signalInfo.label);
    signal.title = signalInfo.label;
    for (var barIndex = 0; barIndex < 3; barIndex++) {
      var bar = document.createElement('i');
      bar.setAttribute('aria-hidden', 'true');
      signal.appendChild(bar);
    }
    var signalLabel = document.createElement('span');
    signalLabel.className = 'post-signal-label';
    signalLabel.textContent = signalInfo.label;
    signal.appendChild(signalLabel);
    content.appendChild(signal);

    var title = document.createElement('h2');
    title.textContent = post.title;
    content.appendChild(title);

    var excerpt = firstParagraph(post);
    if (excerpt) {
      var p = document.createElement('p');
      p.textContent = excerpt;
      content.appendChild(p);
    }

    var readCue = document.createElement('a');
    readCue.className = 'post-read-cue';
    readCue.href = 'news-post.html?slug=' + encodeURIComponent(post.slug);
    readCue.textContent = 'Read transmission →';
    content.appendChild(readCue);

    if (post.tags && post.tags.length) {
      var tags = document.createElement('div');
      tags.className = 'post-tags';
      tags.setAttribute('aria-label', 'Article tags');
      post.tags.forEach(function (tag) {
        var chip = document.createElement('span');
        chip.className = 'post-tag';
        chip.textContent = tag.label;
        tags.appendChild(chip);
      });
      content.appendChild(tags);
    }

    article.appendChild(content);
    return article;
  }

  function revealCards(cards) {
    if (reducedMotion || !('IntersectionObserver' in window)) return;
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-revealed');
        observer.unobserve(entry.target);
      });
    }, { threshold: 0.08 });
    cards.forEach(function (card) {
      card.classList.add('is-animated');
      observer.observe(card);
    });
  }

  fetch('/api/news/list.php', { credentials: 'same-origin' })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      if (!data.ok || !data.entries || !data.entries.length) return;
      var latest = data.entries.slice(0, 2);
      var cards = latest.map(function (post, index) { return createCard(post, index === 0); });
      cards.forEach(function (card) { container.appendChild(card); });
      section.hidden = false;
      revealCards(cards);
    })
    .catch(function () {
      // Fail quietly -- this is a decorative homepage teaser, not the real
      // News page, so a network hiccup should just leave the section hidden
      // rather than showing an error message.
    });
})();
