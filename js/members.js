// The Pantheon Wars — member system (login/register/session/community glue)
// Injects the auth modal, keeps nav in sync with session state, and exposes
// window.PW_AUTH for other scripts (quiz.html, community.html) to read.

window.PW_AUTH = { loggedIn: false, user: null, csrf: null };

document.addEventListener('DOMContentLoaded', function () {

  function buildModal() {
    var wrap = document.createElement('div');
    wrap.className = 'auth-modal';
    wrap.id = 'auth-modal';
    wrap.hidden = true;
    wrap.innerHTML =
      '<div class="auth-modal-backdrop"></div>' +
      '<div class="auth-modal-inner">' +
        '<button type="button" class="auth-modal-close" aria-label="Close">&times;</button>' +
        '<div class="auth-tabs">' +
          '<button type="button" class="auth-tab active" data-tab="login">Log In</button>' +
          '<button type="button" class="auth-tab" data-tab="register">Create Account</button>' +
        '</div>' +
        '<form class="auth-form" data-form="login">' +
          '<h3 class="auth-modal-title">Welcome back</h3>' +
          '<p class="auth-error"></p>' +
          '<div class="auth-field"><label for="login-identifier">Username or email</label><input id="login-identifier" name="identifier" type="text" autocomplete="username" required></div>' +
          '<div class="auth-field"><label for="login-password">Password</label><input id="login-password" name="password" type="password" autocomplete="current-password" required></div>' +
          '<button type="submit" class="btn btn-solid auth-submit">Log In</button>' +
        '</form>' +
        '<form class="auth-form" data-form="register" hidden>' +
          '<h3 class="auth-modal-title">Join the Pantheon</h3>' +
          '<p class="auth-error"></p>' +
          '<div class="auth-field"><label for="reg-username">Username</label><input id="reg-username" name="username" type="text" autocomplete="username" required minlength="3" maxlength="30"></div>' +
          '<div class="auth-field"><label for="reg-email">Email</label><input id="reg-email" name="email" type="email" autocomplete="email" required></div>' +
          '<div class="auth-field"><label for="reg-password">Password</label><input id="reg-password" name="password" type="password" autocomplete="new-password" required minlength="8"></div>' +
          '<button type="submit" class="btn btn-solid auth-submit">Create Account</button>' +
        '</form>' +
      '</div>';
    document.body.appendChild(wrap);
    return wrap;
  }

  var modal = buildModal();
  var backdrop = modal.querySelector('.auth-modal-backdrop');
  var closeBtn = modal.querySelector('.auth-modal-close');
  var tabs = modal.querySelectorAll('.auth-tab');
  var forms = modal.querySelectorAll('.auth-form');

  function openModal(tab) {
    modal.hidden = false;
    setTab(tab || 'login');
  }
  function closeModal() { modal.hidden = true; }

  // Exposed so other pages (e.g. Development Dispatches reactions) can open
  // the login modal from a click handler without needing a .auth-trigger element.
  window.openAuthModal = openModal;

  function setTab(name) {
    tabs.forEach(function (t) { t.classList.toggle('active', t.getAttribute('data-tab') === name); });
    forms.forEach(function (f) {
      var match = f.getAttribute('data-form') === name;
      f.hidden = !match;
      f.querySelector('.auth-error').classList.remove('show');
    });
  }

  tabs.forEach(function (t) {
    t.addEventListener('click', function () { setTab(t.getAttribute('data-tab')); });
  });
  closeBtn.addEventListener('click', closeModal);
  backdrop.addEventListener('click', closeModal);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) closeModal();
  });

  function showFormError(form, message) {
    var err = form.querySelector('.auth-error');
    err.textContent = message;
    err.classList.add('show');
  }

  function postJson(url, body) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {}),
    }).then(function (res) {
      return res.json().then(function (data) { return { status: res.status, data: data }; });
    });
  }

  modal.querySelector('[data-form="login"]').addEventListener('submit', function (e) {
    e.preventDefault();
    var form = e.target;
    var identifier = form.querySelector('#login-identifier').value.trim();
    var password = form.querySelector('#login-password').value;
    postJson('/api/login.php', { identifier: identifier, password: password }).then(function (r) {
      if (r.data && r.data.ok) {
        closeModal();
        refreshAuthNav();
      } else {
        showFormError(form, (r.data && r.data.error) || 'Something went wrong.');
      }
    }).catch(function () { showFormError(form, 'Could not reach the server. Try again in a moment.'); });
  });

  modal.querySelector('[data-form="register"]').addEventListener('submit', function (e) {
    e.preventDefault();
    var form = e.target;
    var username = form.querySelector('#reg-username').value.trim();
    var email = form.querySelector('#reg-email').value.trim();
    var password = form.querySelector('#reg-password').value;
    postJson('/api/register.php', { username: username, email: email, password: password }).then(function (r) {
      if (r.data && r.data.ok) {
        closeModal();
        refreshAuthNav();
      } else {
        showFormError(form, (r.data && r.data.error) || 'Something went wrong.');
      }
    }).catch(function () { showFormError(form, 'Could not reach the server. Try again in a moment.'); });
  });

  // Delegated so it still works after the nav item's innerHTML is replaced.
  document.addEventListener('click', function (e) {
    var trigger = e.target.closest && e.target.closest('.auth-trigger');
    if (trigger) {
      e.preventDefault();
      openModal(trigger.getAttribute('data-tab') || 'login');
    }
    var logoutBtn = e.target.closest && e.target.closest('.auth-logout-btn');
    if (logoutBtn) {
      e.preventDefault();
      postJson('/api/logout.php', {}).then(function () {
        window.PW_AUTH = { loggedIn: false, user: null, csrf: null };
        refreshAuthNav();
        if (/profile\.html$/.test(location.pathname)) location.href = 'index.html';
      });
    }
  });

  function renderNav() {
    var slot = document.getElementById('auth-nav-item');
    if (!slot) return;
    if (window.PW_AUTH.loggedIn && window.PW_AUTH.user) {
      slot.innerHTML =
        '<span class="auth-nav-user">' +
          '<a href="profile.html" class="auth-username">' + window.PW_AUTH.user.display_name + '</a>' +
          '<button type="button" class="auth-logout-btn">Log Out</button>' +
        '</span>';
    } else {
      slot.innerHTML = '<a href="#" class="auth-trigger">Login</a>';
    }
  }

  window.refreshAuthNav = function refreshAuthNav() {
    return fetch('/api/session-check.php', { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        window.PW_AUTH = {
          loggedIn: !!data.loggedIn,
          user: data.user || null,
          csrf: data.csrf || null,
        };
        renderNav();
        document.dispatchEvent(new CustomEvent('pw-auth-ready'));
      })
      .catch(function () { renderNav(); });
  };

  refreshAuthNav();

  // Heartbeat: session-check.php stamps last_active_at for logged-in users,
  // which powers the "Online now" status on the member list. Re-ping every
  // couple of minutes so it stays accurate for people who linger on one page
  // instead of navigating (a single page-load ping wouldn't be enough).
  setInterval(function () {
    if (window.PW_AUTH.loggedIn) refreshAuthNav();
  }, 2 * 60 * 1000);
});
