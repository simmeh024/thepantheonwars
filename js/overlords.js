// The Overlords roster page — fetches live overlord data from the
// admin-managed Overlord Control backend (api/overlords.php) and renders the
// card grid. Markup shape mirrors what used to be hand-authored directly in
// overlords.html so no CSS changes were needed for the available-card case;
// a new locked-card variant covers overlords who don't have a bio page yet.
document.addEventListener('DOMContentLoaded', function () {
  var gridEl = document.getElementById('overlord-grid');
  var noteEl = document.getElementById('overlords-note');
  if (!gridEl) return;

  function renderCard(ov) {
    if (ov.status === 'available') {
      var pronoun = ov.pronoun_possessive === 'her' ? 'her' : (ov.pronoun_possessive === 'their' ? 'their' : 'his');
      return (
        '<div class="card overlord-card" id="' + ov.slug + '-card">' +
          '<div class="thumb-circle"><img src="' + (ov.portrait_image_url || '') + '" alt="' + ov.name + '"></div>' +
          (ov.world ? '<span class="world-tag">Overlord of ' + ov.world.name + '</span>' : '') +
          '<h3>' + ov.name + '</h3>' +
          '<span class="overlord-epithet-tag">' + (ov.epithet || '') + '</span>' +
          '<p>' + (ov.card_teaser || '') + '</p>' +
          '<a href="overlord.html?slug=' + ov.slug + '" class="learn-more">Read ' + pronoun + ' profile &rarr;</a>' +
        '</div>'
      );
    }
    return (
      '<div class="card overlord-card overlord-card--locked" id="' + ov.slug + '-card">' +
        '<div class="thumb-circle"><img src="' + (ov.portrait_image_url || '') + '" alt="' + ov.name + '"></div>' +
        (ov.world ? '<span class="world-tag">Overlord of ' + ov.world.name + '</span>' : '') +
        '<h3>' + ov.name + '</h3>' +
        '<span class="lore-status">Lore Coming Soon</span>' +
      '</div>'
    );
  }

  fetch('/api/overlords.php', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.ok || !data.overlords) return;
      var overlords = data.overlords.slice().sort(function (a, b) { return a.sort_order - b.sort_order; });
      gridEl.innerHTML = overlords.map(renderCard).join('');

      var lockedCount = overlords.filter(function (o) { return o.status !== 'available'; }).length;
      if (lockedCount > 0) {
        noteEl.textContent = lockedCount + (lockedCount === 1 ? ' more Overlord awaits' : ' more Overlords await') +
          ' beyond the Nexus Veil — their profiles unlock as new books release.';
        noteEl.hidden = false;
      }
    })
    .catch(function () {
      gridEl.innerHTML = '';
    });
});
