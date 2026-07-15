// Public News feed. Post bodies are received as plain text and are only ever
// assigned through textContent, so staff-authored content cannot become HTML.
(function () {
  'use strict';

  var container = document.getElementById('news-posts');
  var filters = document.getElementById('news-tag-filters');
  var monthFilter = document.getElementById('news-month-filter');
  var yearFilter = document.getElementById('news-year-filter');
  var dateReset = document.getElementById('news-date-reset');
  if (!container) return;
  var allPosts = [];
  var activeTag = '';
  var activeMonth = '';
  var activeYear = '';
  var moreTagsOpen = false;
  var MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

  function ordinal(day) {
    var remainder = day % 100;
    if (remainder >= 11 && remainder <= 13) return day + 'th';
    return day + ({ 1: 'st', 2: 'nd', 3: 'rd' }[day % 10] || 'th');
  }

  function dateLabel(value) {
    var date = new Date(String(value || '').replace(' ', 'T') + 'Z');
    if (isNaN(date.getTime())) return '';
    var weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    var hours = String(date.getUTCHours()).padStart(2, '0');
    var minutes = String(date.getUTCMinutes()).padStart(2, '0');
    return hours + ':' + minutes + ' UTC - ' + weekdays[date.getUTCDay()] + ' ' + ordinal(date.getUTCDate()) + ' - ' + MONTH_NAMES[date.getUTCMonth()] + ' ' + date.getUTCFullYear();
  }

  function publishedDate(post) {
    var date = new Date(String(post.published_at || '').replace(' ', 'T') + 'Z');
    return isNaN(date.getTime()) ? null : date;
  }

  function addOption(select, value, label) {
    var option = document.createElement('option');
    option.value = value;
    option.textContent = label;
    select.appendChild(option);
  }

  function syncDateReset() {
    if (dateReset) dateReset.disabled = !activeMonth && !activeYear;
  }

  function populateDateFilters() {
    if (!monthFilter || !yearFilter) return;
    var years = {};
    var months = {};
    allPosts.forEach(function (post) {
      var date = publishedDate(post);
      if (!date) return;
      years[date.getUTCFullYear()] = true;
      months[date.getUTCMonth() + 1] = true;
    });

    monthFilter.textContent = '';
    yearFilter.textContent = '';
    addOption(monthFilter, '', 'All months');
    addOption(yearFilter, '', 'All years');
    Object.keys(months).map(Number).sort(function (a, b) { return a - b; }).forEach(function (month) {
      addOption(monthFilter, String(month), MONTH_NAMES[month - 1]);
    });
    Object.keys(years).map(Number).sort(function (a, b) { return b - a; }).forEach(function (year) {
      addOption(yearFilter, String(year), String(year));
    });
    monthFilter.value = activeMonth;
    yearFilter.value = activeYear;
    syncDateReset();
  }

  function makeParagraphs(body, article, maximum) {
    var appended = 0;
    String(body || '').split(/\n\s*\n+/).forEach(function (paragraph) {
      if (maximum && appended >= maximum) return;
      var text = paragraph.trim();
      if (!text) return;
      var element = document.createElement('p');
      element.textContent = text;
      article.appendChild(element);
      appended++;
    });
    return appended;
  }

  function signalFor(post) {
    var labels = (post.tags || []).map(function (tag) { return String(tag.label || '').toLowerCase(); }).join(' ');
    if (labels.indexOf('soundtrack') !== -1 || labels.indexOf('music') !== -1) {
      return { type: 'soundtrack', label: 'Audio signal' };
    }
    if (labels.indexOf('lore') !== -1 || labels.indexOf('world') !== -1) {
      return { type: 'lore', label: 'Lore signal' };
    }
    return { type: 'standard', label: 'Stable signal' };
  }

  function createPost(post, isFeatured) {
    var article = document.createElement('article');
    article.className = 'post news-card ' + (post.author_type === 'bh4' ? 'is-bh4' : 'is-member') + (isFeatured ? ' is-featured' : '');
    article.id = post.slug;

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
    comments.setAttribute('aria-label', 'Read ' + commentCount + ' comment' + (commentCount === 1 ? '' : 's') + ' on ' + post.title);
    rail.appendChild(comments);
    article.appendChild(rail);

    var content = document.createElement('div');
    content.className = 'post-card-content';

    if (isFeatured) {
      var featured = document.createElement('span');
      featured.className = 'post-featured-pill';
      featured.textContent = 'Latest transmission';
      content.appendChild(featured);
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

    makeParagraphs(post.body, content, 2);

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

    var stamp = document.createElement('div');
    stamp.className = 'stamp';
    if (post.author_type === 'bh4') {
      var bh4 = document.createElement('img');
      bh4.src = 'images/bh4-dispatch.jpg';
      bh4.alt = '';
      stamp.appendChild(bh4);
      stamp.appendChild(document.createTextNode('Relayed by BH-4'));
    } else {
      stamp.textContent = 'Published by ' + (post.author_display_name || 'The Pantheon Wars Editorial');
    }
    content.appendChild(stamp);

    article.appendChild(content);
    return article;
  }

  var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var cardObserver = null;

  function prepareCardReveals(cards) {
    if (cardObserver) cardObserver.disconnect();
    if (reducedMotion || !('IntersectionObserver' in window)) return;
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-revealed');
        observer.unobserve(entry.target);
      });
    }, { threshold: 0.08 });
    cardObserver = observer;
    cards.forEach(function (card) {
      card.classList.add('is-animated');
      observer.observe(card);
    });
  }

  function renderTagFilters() {
    if (!filters) return;
    var bySlug = {};
    allPosts.forEach(function (post) {
      (post.tags || []).forEach(function (tag) {
        if (!bySlug[tag.slug]) bySlug[tag.slug] = { slug: tag.slug, label: tag.label, count: 0 };
        bySlug[tag.slug].count++;
      });
    });
    filters.textContent = '';
    var tags = Object.keys(bySlug).map(function (slug) { return bySlug[slug]; }).sort(function (a, b) {
      return b.count - a.count || a.label.localeCompare(b.label);
    });

    function selectTag(slug) {
      activeTag = slug;
      if (tags.slice(10).some(function (tag) { return tag.slug === slug; })) moreTagsOpen = true;
      renderTagFilters();
      renderPosts();
    }

    function addTagButton(tag, target) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'news-tag-filter' + (activeTag === tag.slug ? ' is-active' : '');
      button.textContent = tag.label;
      button.setAttribute('aria-pressed', activeTag === tag.slug ? 'true' : 'false');
      button.addEventListener('click', function () {
        selectTag(tag.slug);
      });
      target.appendChild(button);
    }

    addTagButton({ slug: '', label: 'All updates' }, filters);
    tags.slice(0, 10).forEach(function (tag) { addTagButton(tag, filters); });

    var extraTags = tags.slice(10);
    if (!extraTags.length) return;

    var more = document.createElement('div');
    more.className = 'news-more-tags';
    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'news-more-tags-toggle';
    toggle.setAttribute('aria-expanded', moreTagsOpen ? 'true' : 'false');
    toggle.textContent = (moreTagsOpen ? 'Hide' : 'See') + ' more tags (' + extraTags.length + ')';
    toggle.addEventListener('click', function () {
      moreTagsOpen = !moreTagsOpen;
      renderTagFilters();
    });
    more.appendChild(toggle);

    var moreList = document.createElement('div');
    moreList.className = 'news-more-tags-list';
    moreList.hidden = !moreTagsOpen;
    extraTags.forEach(function (tag) { addTagButton(tag, moreList); });
    more.appendChild(moreList);
    filters.appendChild(more);
  }

  function renderPosts() {
    container.textContent = '';
    var posts = allPosts.filter(function (post) {
      if (activeTag && !(post.tags || []).some(function (tag) { return tag.slug === activeTag; })) return false;
      if (!activeMonth && !activeYear) return true;
      var date = publishedDate(post);
      if (!date) return false;
      if (activeMonth && String(date.getUTCMonth() + 1) !== activeMonth) return false;
      return !activeYear || String(date.getUTCFullYear()) === activeYear;
    });
    if (!posts.length) {
      var empty = document.createElement('p');
      empty.className = 'lore-status';
      empty.textContent = activeTag || activeMonth || activeYear ? 'No updates match the selected filters yet.' : 'No public updates have been transmitted yet.';
      container.appendChild(empty);
      return;
    }
    var cards = posts.map(function (post, index) {
      return createPost(post, index === 0);
    });
    cards.forEach(function (card) { container.appendChild(card); });
    prepareCardReveals(cards);
  }

  fetch('/api/news/list.php', { credentials: 'same-origin' })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      if (!data.ok) throw new Error(data.error || 'News could not be loaded.');
      allPosts = data.entries || [];
      populateDateFilters();
      renderTagFilters();
      renderPosts();
    })
    .catch(function () {
      container.textContent = '';
      var error = document.createElement('p');
      error.className = 'lore-status';
      error.textContent = 'News could not be loaded right now. Please try again shortly.';
      container.appendChild(error);
    });

  if (monthFilter) {
    monthFilter.addEventListener('change', function () {
      activeMonth = monthFilter.value;
      syncDateReset();
      renderPosts();
    });
  }
  if (yearFilter) {
    yearFilter.addEventListener('change', function () {
      activeYear = yearFilter.value;
      syncDateReset();
      renderPosts();
    });
  }
  if (dateReset) {
    dateReset.addEventListener('click', function () {
      activeMonth = '';
      activeYear = '';
      if (monthFilter) monthFilter.value = '';
      if (yearFilter) yearFilter.value = '';
      syncDateReset();
      renderPosts();
    });
  }
})();
