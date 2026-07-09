// The Pantheon Wars — member system (login/register/session/community glue)
// Injects the auth modal, keeps nav in sync with session state, and exposes
// window.PW_AUTH for other scripts (quiz.html, community.html) to read.

window.PW_AUTH = { loggedIn: false, user: null, csrf: null };

document.addEventListener('DOMContentLoaded', function () {

  var EYE_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>';
  var EYE_OFF_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 5c7 0 11 7 11 7a18.5 18.5 0 0 1-2.16 3.19"/><path d="M1 1l22 22"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';

  // Password input + show/hide toggle button, shared by login/register.
  function passwordFieldHtml(id, name, autocomplete, minlength) {
    return '<div class="auth-password-field">' +
      '<input id="' + id + '" name="' + name + '" type="password" autocomplete="' + autocomplete + '" required' + (minlength ? ' minlength="' + minlength + '"' : '') + '>' +
      '<button type="button" class="auth-password-toggle" data-target="' + id + '" aria-label="Show password">' + EYE_ICON + '</button>' +
    '</div>';
  }

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
          '<div class="auth-field"><label for="login-password">Password</label>' + passwordFieldHtml('login-password', 'password', 'current-password') + '</div>' +
          '<button type="submit" class="btn btn-solid auth-submit">Log In</button>' +
        '</form>' +
        '<form class="auth-form" data-form="register" hidden>' +
          '<h3 class="auth-modal-title">Join the Pantheon</h3>' +
          '<p class="auth-error"></p>' +
          '<div class="auth-field"><label for="reg-username">Username</label><input id="reg-username" name="username" type="text" autocomplete="username" required minlength="3" maxlength="30"></div>' +
          '<div class="auth-field"><label for="reg-email">Email</label><input id="reg-email" name="email" type="email" autocomplete="email" required></div>' +
          '<div class="auth-field"><label for="reg-password">Password</label>' + passwordFieldHtml('reg-password', 'password', 'new-password', 8) + '</div>' +
          '<div class="auth-field"><label for="reg-password-confirm">Confirm Password</label>' + passwordFieldHtml('reg-password-confirm', 'password-confirm', 'new-password', 8) + '</div>' +
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

  modal.querySelectorAll('.auth-password-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-target'));
      var showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      btn.innerHTML = showing ? EYE_ICON : EYE_OFF_ICON;
      btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    });
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
    var submitBtn = form.querySelector('.auth-submit');
    submitBtn.disabled = true;
    postJson('/api/login.php', { identifier: identifier, password: password, csrf: window.PW_AUTH.csrf }).then(function (r) {
      if (r.data && r.data.ok) {
        closeModal();
        refreshAuthNav();
      } else {
        showFormError(form, (r.data && r.data.error) || 'Something went wrong.');
      }
    }).catch(function () { showFormError(form, 'Could not reach the server. Try again in a moment.'); })
      .finally(function () { submitBtn.disabled = false; });
  });

  modal.querySelector('[data-form="register"]').addEventListener('submit', function (e) {
    e.preventDefault();
    var form = e.target;
    var username = form.querySelector('#reg-username').value.trim();
    var email = form.querySelector('#reg-email').value.trim();
    var password = form.querySelector('#reg-password').value;
    var confirmPassword = form.querySelector('#reg-password-confirm').value;
    if (password !== confirmPassword) {
      showFormError(form, 'Passwords don\'t match.');
      return;
    }
    var submitBtn = form.querySelector('.auth-submit');
    submitBtn.disabled = true;
    postJson('/api/register.php', { username: username, email: email, password: password, csrf: window.PW_AUTH.csrf }).then(function (r) {
      if (r.data && r.data.ok) {
        closeModal();
        refreshAuthNav();
      } else {
        showFormError(form, (r.data && r.data.error) || 'Something went wrong.');
      }
    }).catch(function () { showFormError(form, 'Could not reach the server. Try again in a moment.'); })
      .finally(function () { submitBtn.disabled = false; });
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
      postJson('/api/logout.php', { csrf: window.PW_AUTH.csrf }).then(function () {
        window.PW_AUTH = { loggedIn: false, user: null, csrf: null };
        refreshAuthNav();
        if (/\/admin\/?$/.test(location.pathname)) location.href = '../index.html';
        else if (/profile\.html$/.test(location.pathname)) location.href = 'index.html';
      });
    }
  });

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // Logged-in state is rendered as a .nav-item.has-dropdown, reusing the
  // exact same hover/focus-within dropdown CSS that already drives the
  // "The Universe" / "News" / "Community" nav menus (see css/style.css) --
  // no extra JS toggle logic or new CSS needed, and it already behaves
  // correctly in the mobile expanded-menu layout too.
  function renderNav() {
    var slot = document.getElementById('auth-nav-item');
    if (!slot) return;
    if (window.PW_AUTH.loggedIn && window.PW_AUTH.user) {
      slot.className = 'nav-item has-dropdown auth-nav-item';
      slot.innerHTML =
        '<span class="nav-parent auth-username">' + escapeHtml(window.PW_AUTH.user.display_name) + '<span class="nav-caret">⌄</span></span>' +
        '<div class="nav-dropdown auth-nav-dropdown">' +
          '<a href="member.html?id=' + encodeURIComponent(window.PW_AUTH.user.id) + '">Profile</a>' +
          '<a href="profile.html">Settings</a>' +
          ((window.PW_AUTH.user.role === 'admin' || window.PW_AUTH.user.role === 'moderator') ? '<a href="/admin">Admin</a>' : '') +
          '<button type="button" class="auth-logout-btn">Log Out</button>' +
        '</div>';
    } else {
      slot.className = 'auth-nav-item';
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
