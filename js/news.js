// Public News feed. Post bodies are received as plain text and are only ever
// assigned through textContent, so staff-authored content cannot become HTML.
(function () {
  'use strict';

  var container = document.getElementById('news-posts');
  if (!container) return;

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

  fetch('/api/news/list.php', { credentials: 'same-origin' })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      if (!data.ok) throw new Error(data.error || 'News could not be loaded.');
      container.textContent = '';
      if (!data.entries || !data.entries.length) {
        var empty = document.createElement('p');
        empty.className = 'lore-status';
        empty.textContent = 'No public updates have been transmitted yet.';
        container.appendChild(empty);
        return;
      }
      data.entries.forEach(function (post) { container.appendChild(createPost(post)); });
    })
    .catch(function () {
      container.textContent = '';
      var error = document.createElement('p');
      error.className = 'lore-status';
      error.textContent = 'News could not be loaded right now. Please try again shortly.';
      container.appendChild(error);
    });
})();
