// The Pantheon Wars — member system (login/register/session/community glue)
// Injects the auth modal, keeps nav in sync with session state, and exposes
// window.PW_AUTH for other scripts (quiz.html, community.html) to read.

window.PW_AUTH = { loggedIn: false, user: null, csrf: null, permissions: [] };

// '*' means every permission (the logged-in user's role is a superuser, e.g.
// admin) -- see api/helpers.php's pw_has_permission() for the server-side
// twin of this check. Client-side checks are UI convenience only; every
// endpoint re-checks the permission itself.
window.pwHasPermission = function pwHasPermission(key) {
  var perms = window.PW_AUTH.permissions || [];
  return perms.indexOf('*') !== -1 || perms.indexOf(key) !== -1;
};

document.addEventListener('DOMContentLoaded', function () {

  var EYE_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>';
  var EYE_OFF_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 5c7 0 11 7 11 7a18.5 18.5 0 0 1-2.16 3.19"/><path d="M1 1l22 22"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';

  // Password input + show/hide toggle button, shared by login/register.
  function passwordFieldHtml(id, name, autocomplete, minlength) {
    return '<div class="auth-password-field">' +
      '<input id="' + id + '" name="' + name + '" type="password" autocomplete="' + autocomplete + '" required' + (minlength ? ' minlength="' + minlength + '"' : '') + '>' +
      '<button type="button" class="auth-password-toggle" data-target="' + id + '" aria-label="Show password">' + EYE_ICON + '</button>' +
    '</div><p class="auth-caps-warning" data-caps-for="' + id + '" hidden>Caps Lock is on.</p>';
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
          '<p class="auth-modal-intro">Return to the worlds beyond the Veil.</p>' +
          '<p class="auth-error"></p>' +
          '<div class="auth-field"><label for="login-identifier">Username or email</label><input id="login-identifier" name="identifier" type="text" autocomplete="username" required></div>' +
          '<div class="auth-field"><label for="login-password">Password</label>' + passwordFieldHtml('login-password', 'password', 'current-password') + '</div>' +
          '<div class="auth-oauth-divider"><span>or</span></div>' +
          '<button type="button" class="btn auth-google-btn" data-google-oauth="login"><span class="auth-google-mark" aria-hidden="true">G</span>Continue with Google</button>' +
          '<button type="submit" class="btn btn-solid auth-submit">Log In</button>' +
        '</form>' +
        '<form class="auth-form" data-form="register" hidden>' +
          '<h3 class="auth-modal-title">Join the Pantheon</h3>' +
          '<p class="auth-modal-intro">Create your place in the Pantheon.</p>' +
          '<p class="auth-error"></p>' +
          '<div class="auth-field"><label for="reg-username">Username</label><input id="reg-username" name="username" type="text" autocomplete="username" required minlength="3" maxlength="30"><small class="auth-field-hint">3–30 characters: letters, numbers, hyphens, or underscores.</small></div>' +
          '<div class="auth-field"><label for="reg-email">Email</label><input id="reg-email" name="email" type="email" autocomplete="email" required></div>' +
          '<div class="auth-field"><label for="reg-password">Password</label>' + passwordFieldHtml('reg-password', 'password', 'new-password', 8) + '<div class="auth-password-strength" id="reg-password-strength" aria-live="polite"><span></span><small>Use 8 or more characters.</small></div></div>' +
          '<div class="auth-field"><label for="reg-password-confirm">Confirm Password</label>' + passwordFieldHtml('reg-password-confirm', 'password-confirm', 'new-password', 8) + '</div>' +
          '<div class="auth-oauth-divider"><span>or</span></div>' +
          '<label class="auth-oauth-option"><input type="checkbox" id="reg-google-avatar" checked>Import my Google profile picture when available</label>' +
          '<button type="button" class="btn auth-google-btn" data-google-oauth="register"><span class="auth-google-mark" aria-hidden="true">G</span>Register with Google</button>' +
          '<button type="submit" class="btn btn-solid auth-submit">Create Account</button>' +
        '</form>' +
        '<div class="auth-success" hidden aria-live="polite"><span class="auth-success-mark">✓</span><h3>Welcome to the Pantheon.</h3><p>Your account is ready. Preparing your profile&hellip;</p></div>' +
        '<p class="auth-privacy-note">Your profile and notification preferences stay under your control. <a href="/privacy.html">Privacy</a></p>' +
      '</div>';
    document.body.appendChild(wrap);
    return wrap;
  }

  var modal = buildModal();
  var backdrop = modal.querySelector('.auth-modal-backdrop');
  var closeBtn = modal.querySelector('.auth-modal-close');
  var tabs = modal.querySelectorAll('.auth-tab');
  var forms = modal.querySelectorAll('.auth-form');
  var authSuccess = modal.querySelector('.auth-success');

  function openModal(tab) {
    startInitialAuthRefresh();
    modal.hidden = false;
    setTab(tab || 'login');
  }
  function closeModal() { modal.hidden = true; }

  // Exposed so other pages (e.g. Development Dispatches reactions) can open
  // the login modal from a click handler without needing a .auth-trigger element.
  window.openAuthModal = openModal;

  function setTab(name) {
    authSuccess.hidden = true;
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
        form.hidden = true;
        authSuccess.hidden = false;
        setTimeout(function () {
          closeModal();
          refreshAuthNav();
        }, 1250);
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
        window.PW_AUTH = { loggedIn: false, user: null, csrf: null, permissions: [] };
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
    var notifSlot = document.getElementById('notif-nav-item');
    var loggedIn = !!(window.PW_AUTH.loggedIn && window.PW_AUTH.user);
    if (notifSlot) notifSlot.hidden = !loggedIn;
    if (!loggedIn) {
      document.dispatchEvent(new CustomEvent('pw-notifications-hide'));
    }
    if (!slot) return;
    if (loggedIn) {
      slot.className = 'nav-item has-dropdown auth-nav-item';
      slot.innerHTML =
        '<span class="nav-parent auth-username">' + escapeHtml(window.PW_AUTH.user.display_name) + '<span class="nav-caret">⌄</span></span>' +
        '<div class="nav-dropdown auth-nav-dropdown">' +
          '<a href="member.html?id=' + encodeURIComponent(window.PW_AUTH.user.id) + '">Profile</a>' +
          '<a href="profile.html">Settings</a>' +
          (pwHasPermission('admin_console.access') ? '<a href="/admin">Admin</a>' : '') +
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
          permissions: data.permissions || [],
        };
        renderNav();
        if (window.PW_AUTH.loggedIn) loadNotifications();
        document.dispatchEvent(new CustomEvent('pw-auth-ready'));
      })
      .catch(function () { renderNav(); });
  };

  var initialAuthRefreshStarted = false;

  function startInitialAuthRefresh() {
    if (initialAuthRefreshStarted) return;
    initialAuthRefreshStarted = true;
    window.refreshAuthNav();
  }

  modal.querySelectorAll('[data-google-oauth]').forEach(function (button) {
    button.addEventListener('click', function () {
      var intent = button.getAttribute('data-google-oauth');
      var returnTo = location.pathname + location.search;
      var url = '/api/oauth/start.php?provider=google&intent=' + encodeURIComponent(intent) + '&return_to=' + encodeURIComponent(returnTo);
      if (intent === 'register') {
        var importAvatar = modal.querySelector('#reg-google-avatar');
        url += '&import_avatar=' + (importAvatar && importAvatar.checked ? '1' : '0');
      }
      window.location.assign(url);
    });
  });

  modal.querySelectorAll('input[type="password"]').forEach(function (input) {
    function updateCapsLock(event) {
      var warning = modal.querySelector('[data-caps-for="' + input.id + '"]');
      if (warning) warning.hidden = !(event.getModifierState && event.getModifierState('CapsLock'));
    }
    input.addEventListener('keydown', updateCapsLock);
    input.addEventListener('keyup', updateCapsLock);
    input.addEventListener('blur', function () {
      var warning = modal.querySelector('[data-caps-for="' + input.id + '"]');
      if (warning) warning.hidden = true;
    });
  });

  var registerPassword = modal.querySelector('#reg-password');
  var registerStrength = modal.querySelector('#reg-password-strength');
  if (registerPassword && registerStrength) {
    registerPassword.addEventListener('input', function () {
      var value = registerPassword.value;
      var score = 0;
      if (value.length >= 8) score++;
      if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++;
      if (/\d/.test(value)) score++;
      if (/[^A-Za-z0-9]/.test(value)) score++;
      registerStrength.setAttribute('data-strength', String(score));
      registerStrength.querySelector('small').textContent = value.length === 0 ? 'Use 8 or more characters.' :
        (score <= 1 ? 'Add variety for a stronger password.' : score <= 3 ? 'Good password strength.' : 'Strong password.');
    });
  }

  function handleOAuthResult() {
    var params = new URLSearchParams(location.search);
    var result = params.get('oauth');
    if (!result) return;
    var messages = {
      'google-not-configured': 'Google sign-in is not configured yet. Please use your username/email and password.',
      'google-cancelled': 'Google sign-in was cancelled. You can try again whenever you are ready.',
      'google-failed': 'Google sign-in could not be completed. Please try again or use your password.',
      'google-link-required': 'This email already has an account. Sign in with your password, then link Google from Profile Settings.',
      'google-banned': 'This account has been suspended.',
    };
    // These are Profile Settings outcomes. Leave the parameter in place for
    // profile.html's own script to render beside the Google link controls.
    if (result === 'google-linked' || result === 'google-link-conflict' || result === 'google-link-expired') {
      return;
    }
    if (messages[result]) {
      openModal('login');
      showFormError(modal.querySelector('[data-form="login"]'), messages[result]);
    }
    params.delete('oauth');
    if (window.history && window.history.replaceState) {
      window.history.replaceState({}, '', location.pathname + (params.toString() ? '?' + params.toString() : '') + location.hash);
    }
  }

  function loadNotifications() {
    if (!document.getElementById('notif-bell-btn') || document.getElementById('notifications-script')) return;
    var script = document.createElement('script');
    script.id = 'notifications-script';
    script.src = '/js/notifications.js?v=8';
    script.async = true;
    document.body.appendChild(script);
  }

  // The session check only affects account chrome, not the first visible
  // content. Start it after the load event/idle period, while opening the
  // login dialog above starts it immediately when a visitor needs it.
  function scheduleInitialAuthRefresh() {
    var queue = function () {
      if ('requestIdleCallback' in window) {
        window.requestIdleCallback(startInitialAuthRefresh, { timeout: 2000 });
      } else {
        setTimeout(startInitialAuthRefresh, 0);
      }
    };
    if (document.readyState === 'complete') queue();
    else window.addEventListener('load', queue, { once: true });
  }

  scheduleInitialAuthRefresh();
  handleOAuthResult();

  // Heartbeat: session-check.php stamps last_active_at for logged-in users,
  // which powers the "Online now" status on the member list. Do not keep a
  // hidden tab alive: it is neither an active visitor nor worth polling.
  var heartbeatTimer = null;

  function sendHeartbeat() {
    if (!document.hidden && window.PW_AUTH && window.PW_AUTH.loggedIn) refreshAuthNav();
  }

  function stopHeartbeat() {
    if (heartbeatTimer !== null) {
      clearInterval(heartbeatTimer);
      heartbeatTimer = null;
    }
  }

  function startHeartbeat() {
    if (heartbeatTimer === null) {
      heartbeatTimer = setInterval(sendHeartbeat, 2 * 60 * 1000);
    }
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      stopHeartbeat();
      return;
    }
    startHeartbeat();
    sendHeartbeat();
  });

  if (!document.hidden) startHeartbeat();
});
