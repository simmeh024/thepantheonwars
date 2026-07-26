/* Homepage Mission Console: never exposes a mission briefing to guests.
 * A signed-in player requests their already-authorized command overview only
 * after inspecting the Missions action, so the hero stays fast and sealed
 * campaign operations remain server-controlled. */
(function () {
  'use strict';
  var consoleEl = document.getElementById('hero-command-console');
  var cta = document.getElementById('hero-missions-cta');
  var preview = document.getElementById('hero-mission-preview');
  if (!consoleEl || !cta || !preview) return;

  var requested = false;
  function formatDuration(seconds) {
    var total = Math.max(0, Number(seconds) || 0);
    var hours = Math.floor(total / 3600), minutes = Math.round((total % 3600) / 60);
    return hours ? hours + 'h' + (minutes ? ' ' + minutes + 'm' : '') : Math.max(1, minutes) + 'm';
  }
  function setPreview(mission, availableCrew) {
    if (!mission) return;
    var name = preview.querySelector('.hero-mission-preview-name');
    var state = preview.querySelector('.hero-mission-preview-state');
    var details = preview.querySelector('.hero-mission-preview-details');
    var rewards = [];
    if (Number(mission.xp_reward)) rewards.push(mission.xp_reward + ' XP');
    if (Number(mission.credit_reward)) rewards.push(mission.credit_reward + ' credits');
    if (Number(mission.reputation_reward)) rewards.push('+' + mission.reputation_reward + ' rep');
    name.textContent = mission.name || 'Operation ready';
    state.textContent = 'Now available';
    details.innerHTML = '';
    [String(mission.mission_type || 'Operation').toUpperCase(), rewards.length ? rewards.join(' · ') : 'Rewards pending', formatDuration(mission.duration_seconds), (mission.min_crew || 1) + '–' + (mission.max_crew || 1) + ' crew' + (availableCrew ? ' / ' + availableCrew + ' ready' : '')].forEach(function (value) {
      var item = document.createElement('span'); item.textContent = value; details.appendChild(item);
    });
  }
  function requestBriefing() {
    if (requested) return;
    requested = true;
    fetch('/api/missions/overview.php', { credentials: 'same-origin', cache: 'no-store' }).then(function (response) {
      if (!response.ok) throw new Error('Mission briefing unavailable.');
      return response.json();
    }).then(function (data) {
      if (!data || !data.ok || !Array.isArray(data.missions)) throw new Error('Mission briefing unavailable.');
      setPreview(data.missions.filter(function (mission) { return !mission.is_offline; })[0], data.stats && data.stats.available_crew);
    }).catch(function () {
      /* Guests retain the safe clearance message authored in the HTML. */
    });
  }
  cta.addEventListener('pointerenter', function () { consoleEl.classList.add('is-mission-preview-open'); requestBriefing(); });
  cta.addEventListener('pointerleave', function () { consoleEl.classList.remove('is-mission-preview-open'); });
  cta.addEventListener('focus', function () { consoleEl.classList.add('is-mission-preview-open'); requestBriefing(); });
  cta.addEventListener('blur', function () { consoleEl.classList.remove('is-mission-preview-open'); });
}());
