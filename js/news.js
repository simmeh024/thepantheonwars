// Public News feed. Post bodies are received as plain text and are only ever
// assigned through textContent, so staff-authored content cannot become HTML.
(function () {
  'use strict';

  var container = document.getElementById('news-posts');
  var filters = document.getElementById('news-tag-filters');
  if (!container) return;
  var allPosts = [];
  var activeTag = '';

  function dateLabel(value) {
    var date = new Date(String(value || '').replace(' ', 'T') + 'Z');
    if (isNaN(date.getTime())) return '';
    return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long' });
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

    if (post.tags && post.tags.length) {
      var tags = document.createElement('div');
      tags.className = 'post-tags';
      post.tags.forEach(function (tag) {
        var chip = document.createElement('span');
        chip.className = 'post-tag';
        chip.textContent = tag.label;
        tags.appendChild(chip);
      });
      article.appendChild(tags);
    }
    makeParagraphs(post.body, article);

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
      (post.tags || []).forEach(function (tag) { bySlug[tag.slug] = tag.label; });
    });
    filters.textContent = '';
    [{ slug: '', label: 'All updates' }].concat(Object.keys(bySlug).sort(function (a, b) {
      return bySlug[a].localeCompare(bySlug[b]);
    }).map(function (slug) { return { slug: slug, label: bySlug[slug] }; })).forEach(function (tag) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'news-tag-filter' + (activeTag === tag.slug ? ' is-active' : '');
      button.textContent = tag.label;
      button.setAttribute('aria-pressed', activeTag === tag.slug ? 'true' : 'false');
      button.addEventListener('click', function () {
        activeTag = tag.slug;
        renderTagFilters();
        renderPosts();
      });
      filters.appendChild(button);
    });
  }

  function renderPosts() {
    container.textContent = '';
    var posts = activeTag ? allPosts.filter(function (post) {
      return (post.tags || []).some(function (tag) { return tag.slug === activeTag; });
    }) : allPosts;
    if (!posts.length) {
      var empty = document.createElement('p');
      empty.className = 'lore-status';
      empty.textContent = activeTag ? 'No updates use this tag yet.' : 'No public updates have been transmitted yet.';
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
})();
