// Soundtracks — repeated Spotify embed panels, rendered from
// api/soundtracks.php (Admin Console > Lore Management > Soundtrack Control)
// instead of one hand-authored .soundtrack-panel block. Each record already
// carries its parsed spotify_embed_type/spotify_embed_id (derived once,
// server-side, from the admin-pasted share link), so this only ever builds
// a fixed embed src template -- no URL parsing happens in the browser.
document.addEventListener('DOMContentLoaded', function () {
  var listEl = document.getElementById('soundtrack-list');
  if (!listEl) return;

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char];
    });
  }

  function renderPanel(entry) {
    var descriptionHtml = entry.description ? '<p>' + escapeHtml(entry.description) + '</p>' : '';
    var embedSrc = 'https://open.spotify.com/embed/' + encodeURIComponent(entry.spotify_embed_type) +
      '/' + encodeURIComponent(entry.spotify_embed_id) + '?utm_source=generator&theme=0';

    return (
      '<div class="soundtrack-panel ornate">' +
        '<span class="orn-bl"></span><span class="orn-br"></span>' +
        '<div class="soundtrack-grid">' +
          '<div>' +
            '<span class="eyebrow">' + escapeHtml(entry.eyebrow) + '</span>' +
            '<h2>' + escapeHtml(entry.heading) + '</h2>' +
            descriptionHtml +
            '<a href="' + escapeHtml(entry.spotify_url) + '" target="_blank" rel="noopener" class="spotify-badge">' +
              '<img src="images/spotify-icon.svg" alt="">' +
              'Listen on Spotify' +
            '</a>' +
          '</div>' +
          '<div class="spotify-embed">' +
            '<iframe style="border-radius:12px" src="' + escapeHtml(embedSrc) + '" width="100%" height="352" frameborder="0" allow="encrypted-media" loading="lazy" title="' + escapeHtml(entry.heading) + ' on Spotify"></iframe>' +
          '</div>' +
        '</div>' +
      '</div>'
    );
  }

  fetch('/api/soundtracks.php', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      var soundtracks = (data.ok && data.soundtracks) || [];
      if (!soundtracks.length) {
        listEl.innerHTML = '<div class="soundtrack-panel ornate"><span class="orn-bl"></span><span class="orn-br"></span><p class="lede" style="margin:0;">No soundtracks on record yet.</p></div>';
        return;
      }
      listEl.innerHTML = soundtracks.map(renderPanel).join('');
    })
    .catch(function () {
      listEl.innerHTML = '<div class="soundtrack-panel ornate"><span class="orn-bl"></span><span class="orn-br"></span><p class="lede" style="margin:0;">Could not load soundtracks right now. Please try again later.</p></div>';
    });
});
