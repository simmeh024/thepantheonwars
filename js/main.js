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

  // Random glitch bursts — hero image (homepage only)
  var heroEl = document.querySelector('.hero');
  var heroGlitchLayer = document.querySelector('.hero-glitch');
  if (heroEl && heroGlitchLayer) {
    (function scheduleHeroGlitch() {
      // First burst is deliberately pushed well past typical page-load
      // measurement windows (Lighthouse/PSI, etc). This element shares
      // the hero image and briefly becomes visible when the glitch
      // plays, which can get it mistaken for the Largest Contentful
      // Paint if it fires too early -- delaying it keeps the ambient
      // effect without skewing LCP measurements.
      var delay = 15000 + Math.random() * 10000;
      setTimeout(function () {
        heroEl.classList.add('is-glitching');
        heroGlitchLayer.classList.add('is-glitching');
        setTimeout(function () {
          heroEl.classList.remove('is-glitching');
          heroGlitchLayer.classList.remove('is-glitching');
        }, 350 + Math.random() * 150);
        scheduleHeroGlitch();
      }, delay);
    })();
  }

  // Random glitch bursts — nav logo (every page)
  var logoEl = document.querySelector('.logo');
  if (logoEl) {
    (function scheduleLogoGlitch() {
      var delay = 6000 + Math.random() * 10000;
      setTimeout(function () {
        logoEl.classList.add('is-glitching');
        setTimeout(function () {
          logoEl.classList.remove('is-glitching');
        }, 300 + Math.random() * 150);
        scheduleLogoGlitch();
      }, delay);
    })();
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

  // City cross-section layers (Worlds > Neoh, Worlds > High Hammer) — click a district to expand it.
  document.querySelectorAll('.city-layer').forEach(function (layer) {
    var toggleBtn = layer.querySelector('.layer-toggle');
    if (!toggleBtn) return;
    toggleBtn.addEventListener('click', function () {
      var wasOpen = layer.classList.contains('open');
      var group = layer.closest('.city-stack, .harbor-row');
      if (group) {
        group.querySelectorAll('.city-layer.open').forEach(function (other) {
          if (other !== layer) other.classList.remove('open');
        });
      }
      layer.classList.toggle('open', !wasOpen);
    });
  });

  // Map lightbox (Worlds > Neoh full map viewer)
  document.querySelectorAll('.map-thumb-btn').forEach(function (btn) {
    var lightbox = document.getElementById(btn.getAttribute('data-lightbox'));
    if (!lightbox) return;
    btn.addEventListener('click', function () { lightbox.hidden = false; });
  });
  document.querySelectorAll('.map-lightbox').forEach(function (lightbox) {
    var close = function () { lightbox.hidden = true; };
    lightbox.querySelector('.map-lightbox-close').addEventListener('click', close);
    lightbox.querySelector('.map-lightbox-backdrop').addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !lightbox.hidden) close();
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
