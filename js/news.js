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

  function makeParagraphs(body, article) {
    String(body || '').split(/\n\s*\n+/).forEach(function (paragraph) {
      var text = paragraph.trim();
      if (!text) return;
      var element = document.createElement('p');
      element.textContent = text;
      article.appendChild(element);
    });
  }

  function createPost(post) {
    var article = document.createElement('article');
    article.className = 'post';
    article.id = post.slug;

    var date = document.createElement('span');
    date.className = 'date';
    date.textContent = dateLabel(post.published_at);
    article.appendChild(date);

    var title = document.createElement('h2');
    title.textContent = post.title;
    article.appendChild(title);

    makeParagraphs(post.body, article);

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
      article.appendChild(tags);
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
    article.appendChild(stamp);

    var share = document.createElement('div');
    share.className = 'post-share';
    var link = document.createElement('a');
    link.className = 'btn share-reddit';
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = 'Share on Reddit ↗';
    link.href = 'https://www.reddit.com/submit?url=' + encodeURIComponent(window.location.origin + window.location.pathname + '#' + post.slug) + '&title=' + encodeURIComponent(post.title);
    share.appendChild(link);
    article.appendChild(share);
    return article;
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
    posts.forEach(function (post) { container.appendChild(createPost(post)); });
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
