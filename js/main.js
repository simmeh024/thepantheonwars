// The Pantheon Wars — site interactivity

document.addEventListener('DOMContentLoaded', function () {
  // Mobile nav toggle
  var toggle = document.querySelector('.nav-toggle');
  var nav = document.querySelector('.main-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      nav.classList.toggle('open');
    });
    nav.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () { nav.classList.remove('open'); });
    });
  }

  // Header shadow on scroll
  var header = document.querySelector('.site-header');
  if (header) {
    window.addEventListener('scroll', function () {
      if (window.scrollY > 10) header.style.boxShadow = '0 8px 24px rgba(0,0,0,0.5)';
      else header.style.boxShadow = 'none';
    });
  }

  // Newsletter forms — client-side only.
  // NOTE for Pascal: this currently just shows a confirmation message and does not
  // send the email anywhere. To actually collect subscribers, sign up for an email
  // service (e.g. Buttondown, ConvertKit, Mailchimp) and point each <form> at the
  // endpoint it gives you, or wire this fetch call to it.
  document.querySelectorAll('.newsletter-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var email = form.querySelector('input[type="email"]').value.trim();
      var confirm = form.parentElement.querySelector('.confirm');
      if (!email) return;
      if (confirm) {
        confirm.textContent = 'You are bound to the Pantheon now. Watch your inbox, ' + email + '.';
        confirm.classList.add('show');
      }
      form.reset();
    });
  });

  // City cross-section layers (Worlds > Neoh) — click a floor to expand it.
  document.querySelectorAll('.city-layer').forEach(function (layer) {
    var toggleBtn = layer.querySelector('.layer-toggle');
    if (!toggleBtn) return;
    toggleBtn.addEventListener('click', function () {
      var wasOpen = layer.classList.contains('open');
      if (layer.closest('.city-stack')) {
        layer.closest('.city-stack').querySelectorAll('.city-layer.open').forEach(function (other) {
          if (other !== layer) other.classList.remove('open');
        });
      }
      layer.classList.toggle('open', !wasOpen);
    });
  });

  // Planetary two-scene view (Worlds > High Hammer) — arrow flips between scenes.
  document.querySelectorAll('.planet-view').forEach(function (view) {
    var arrows = view.querySelectorAll('.scene-arrow');
    arrows.forEach(function (btn) {
      btn.addEventListener('click', function () {
        view.querySelectorAll('.planet-scene').forEach(function (scene) {
          scene.classList.toggle('active');
        });
      });
    });
  });
});
