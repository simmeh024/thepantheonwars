// Chapter One preview page — supports ?book=N (defaults to Book One) and
// pulls that book's preview content from the Book Control backend. If the
// fetch fails, the requested book doesn't exist, or its preview isn't
// enabled, this falls back to the "preview not available" state rather than
// leaving Book One's static placeholder text up under the wrong heading.
document.addEventListener('DOMContentLoaded', function () {
  var heroBg = document.getElementById('preview-hero-bg');
  var portraitFrame = document.getElementById('preview-portrait-frame');
  var portraitImg = document.getElementById('preview-portrait-img');
  var eyebrowEl = document.getElementById('preview-eyebrow');
  var titleEl = document.getElementById('preview-title');
  var ledeEl = document.getElementById('preview-lede');
  var bodyContentEl = document.getElementById('preview-body-content');
  var quoteBlockEl = document.getElementById('preview-quote-block');
  var quoteCiteEl = document.getElementById('preview-quote-cite');
  var backlinkEl = document.getElementById('preview-backlink');
  var availableSection = document.getElementById('preview-available-section');
  var unavailableSection = document.getElementById('preview-unavailable-section');
  var unavailableTitleEl = document.getElementById('preview-unavailable-title');
  var ctaSection = document.getElementById('preview-cta-section');
  var ctaHeadingEl = document.getElementById('preview-cta-heading');
  var ctaLinkEl = document.getElementById('preview-cta-link');

  if (!availableSection || !unavailableSection) return;

  function showUnavailable(bookNumber) {
    availableSection.hidden = true;
    ctaSection.hidden = true;
    unavailableSection.hidden = false;
    if (unavailableTitleEl) {
      unavailableTitleEl.textContent = bookNumber ?
        'Chapter One of Book ' + bookNumber + " isn't ready yet" :
        "This chapter preview isn't ready yet";
    }
  }

  function renderPreview(book) {
    document.title = book.title + ' — First Chapter Preview — The Pantheon Wars';

    if (heroBg && book.preview_hero_image_url) {
      heroBg.style.backgroundImage = "url('" + book.preview_hero_image_url + "')";
    }

    if (book.character_image_url) {
      if (portraitFrame) portraitFrame.hidden = false;
      if (portraitImg) {
        portraitImg.src = book.character_image_url;
        portraitImg.alt = book.character_alt || '';
      }
    } else if (portraitFrame) {
      portraitFrame.hidden = true;
    }

    if (eyebrowEl) eyebrowEl.innerHTML = book.preview_eyebrow || (book.title + ' &middot; Preview');
    if (titleEl) titleEl.textContent = book.title;
    if (ledeEl) ledeEl.textContent = book.preview_lede || '';

    if (bodyContentEl) {
      var paragraphs = (book.preview_body || '').split(/\n\s*\n/).filter(function (p) { return p.trim() !== ''; });
      bodyContentEl.innerHTML = paragraphs.map(function (p) {
        return '<p>' + p.trim() + '</p>';
      }).join('\n');
    }

    if (book.preview_quote) {
      quoteBlockEl.hidden = false;
      // Replace the leading text node's content while leaving <cite> below intact.
      var firstText = Array.prototype.filter.call(quoteBlockEl.childNodes, function (n) { return n.nodeType === 3 && n.textContent.trim() !== ''; })[0];
      if (firstText) {
        firstText.textContent = '\n        ' + book.preview_quote + '\n        ';
      } else {
        quoteBlockEl.insertBefore(document.createTextNode(book.preview_quote), quoteBlockEl.firstChild);
      }
      if (quoteCiteEl) quoteCiteEl.textContent = book.preview_quote_cite ? ('— ' + book.preview_quote_cite) : '';
    } else if (quoteBlockEl) {
      quoteBlockEl.hidden = true;
    }

    if (backlinkEl) backlinkEl.href = 'books.html#book-' + book.book_number;
    if (ctaHeadingEl) ctaHeadingEl.textContent = 'Get ' + book.title;
    if (ctaLinkEl) ctaLinkEl.href = 'books.html#book-' + book.book_number;

    availableSection.hidden = false;
    unavailableSection.hidden = true;
    if (ctaSection) ctaSection.hidden = false;
  }

  var params = new URLSearchParams(window.location.search);
  var bookNumber = parseInt(params.get('book'), 10);
  if (!bookNumber || bookNumber < 1) bookNumber = 1;

  fetch('/api/books.php?book_number=' + encodeURIComponent(bookNumber), { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.ok || !data.book || !data.book.preview_enabled || !data.book.preview_body) {
        showUnavailable(bookNumber);
        return;
      }
      renderPreview(data.book);
    })
    .catch(function () {
      showUnavailable(bookNumber);
    });
});
