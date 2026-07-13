// The Books page — fetches live book data from the admin-managed Book Control
// backend and renders it into the existing static phase-block markup. If the
// fetch fails for any reason, the original static book rows (baked into
// books.html as a fallback) stay exactly as they are.
document.addEventListener('DOMContentLoaded', function () {
  var phaseLists = {
    1: document.querySelector('#phase-1 .book-list'),
    2: document.querySelector('#phase-2 .book-list'),
    3: document.querySelector('#phase-3 .book-list')
  };
  if (!phaseLists[1] && !phaseLists[2] && !phaseLists[3]) return;

  var STAGE_LABELS = [
    'Idea', 'Outline', 'First Draft', 'Second Draft', 'Developmental Revision',
    'Alpha Review', 'Third Draft', 'Beta Review', 'Fourth Draft',
    'Developmental Editing', 'Copy Editing', 'Fifth Draft', 'Proofreading',
    'Print Ready', 'Published'
  ];

  var TICK_TIPS = [
    'Idea — the spark: a scene, a character, or a single question worth chasing across 400 pages.',
    'Outline — mapping plot beats, character arcs, and world details before the real writing begins.',
    'First Draft — getting the whole story down, start to finish, messy and unfiltered.',
    'Second Draft — fixing the big structural problems the first draft was too busy to notice.',
    'Developmental Revision — a deep pass on plot logic, pacing, and character motivation with fresh eyes.',
    'Alpha Review — trusted early readers weigh in before the manuscript goes any further.',
    'Third Draft — rebuilding scenes and chapters based on what the alpha readers uncovered.',
    'Beta Review — a wider group of readers tests whether the story lands the way it’s meant to.',
    'Fourth Draft — refining voice, tension, and clarity based on beta feedback.',
    'Developmental Editing — a professional editor stress-tests the manuscript’s structure and story.',
    'Copy Editing — a line-by-line polish for grammar, consistency, and continuity.',
    'Fifth Draft — folding in the editor’s notes for a tighter, cleaner manuscript.',
    'Proofreading — the last line of defense against typos before the text is locked.',
    'Print Ready — formatting, cover, and interior layout finalized for release.',
    'Published — out in the world, in readers’ hands.'
  ];

  var TICKS_HTML = TICK_TIPS.map(function (tip) {
    return '<span class="tick" data-tip="' + tip.replace(/"/g, '&quot;') + '"></span>';
  }).join('');

  function pad2(n) {
    return n < 10 ? '0' + n : String(n);
  }

  function buildBuyLinks(book) {
    var links = [];
    if (book.buy_kobo_url) links.push({ label: 'Kobo', url: book.buy_kobo_url });
    if (book.buy_amazon_url) links.push({ label: 'Amazon', url: book.buy_amazon_url });
    if (book.buy_apple_url) links.push({ label: 'Apple Books', url: book.buy_apple_url });
    if (book.buy_bn_url) links.push({ label: 'Barnes & Noble', url: book.buy_bn_url });
    if (!links.length) return '';
    return '<div class="book-buy-links">' + links.map(function (l) {
      return '<a href="' + l.url + '" class="book-buy-link" target="_blank" rel="noopener">' + l.label + '</a>';
    }).join('') + '</div>';
  }

  function renderBookRow(book) {
    var stage = Math.min(Math.max(parseInt(book.writing_stage, 10) || 1, 1), 15);
    var pct = (stage / 15 * 100).toFixed(2);
    var imageLoading = book.book_number > 1 ? ' loading="lazy"' : '';

    var metaHtml = '';
    if (book.character_image_url) {
      metaHtml = '<div class="book-meta-row"><span class="book-figure"><img src="' + book.character_image_url +
        '" alt="' + (book.character_alt || '') + '"' + imageLoading + ' decoding="async"></span><p class="meta">' + (book.meta_text || '') + '</p></div>';
    } else if (book.meta_text) {
      metaHtml = '<p class="meta">' + book.meta_text + '</p>';
    }

    var ctaHtml = book.preview_enabled ?
      '<div class="book-cta"><a href="chapter-one.html?book=' + book.book_number + '" class="btn btn-solid">Read the First Chapter &rarr;</a></div>' : '';

    var row = document.createElement('div');
    row.className = 'book-row';
    row.id = 'book-' + book.book_number;
    row.innerHTML =
      '<div class="book-progress">' +
        '<div class="book-progress-head">' +
          '<span class="book-progress-phase">' + STAGE_LABELS[stage - 1] + '</span>' +
          '<span class="book-progress-count">Phase ' + stage + ' / 15</span>' +
        '</div>' +
        '<div class="book-progress-track">' +
          '<div class="book-progress-fill" style="width: ' + pct + '%;"></div>' +
          '<div class="book-progress-ticks">' + TICKS_HTML + '</div>' +
          '<span class="book-progress-marker" style="left: ' + pct + '%;"></span>' +
        '</div>' +
      '</div>' +
      '<div class="book-num">' + pad2(book.book_number) + '</div>' +
      '<div class="book-art"><img src="' + (book.cover_image_url || '') + '" alt="' + book.title + ' cover"' + imageLoading + ' decoding="async"></div>' +
      '<div class="book-body">' +
        '<span class="status">' + book.status_label + '</span>' +
        '<h3>' + book.title + '</h3>' +
        metaHtml +
        '<p>' + (book.description || '') + '</p>' +
        buildBuyLinks(book) +
        ctaHtml +
      '</div>';
    return row;
  }

  fetch('/api/books.php', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.ok || !data.books || !data.books.length) return;
      var books = data.books.slice().sort(function (a, b) { return a.book_number - b.book_number; });
      [1, 2, 3].forEach(function (phase) {
        if (phaseLists[phase]) phaseLists[phase].innerHTML = '';
      });
      books.forEach(function (book) {
        var list = phaseLists[book.saga_phase];
        if (list) list.appendChild(renderBookRow(book));
      });
    })
    .catch(function () {
      // Leave the static fallback markup in books.html untouched.
    });
});
