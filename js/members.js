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

  function fieldStateHtml() {
    return '<span class="auth-field-state" aria-hidden="true"></span>';
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
        '<div class="auth-modal-header">' +
          '<span class="auth-modal-kicker">The Pantheon Wars</span>' +
          '<div class="auth-tabs">' +
            '<button type="button" class="auth-tab active" data-tab="login">Log In</button>' +
            '<button type="button" class="auth-tab" data-tab="register">Create Account</button>' +
          '</div>' +
        '</div>' +
        '<div class="auth-modal-body">' +
        '<form class="auth-form" data-form="login">' +
          '<h3 class="auth-modal-title">Welcome back</h3>' +
          '<p class="auth-modal-intro">Return to the worlds beyond the Veil.</p>' +
          '<p class="auth-error"></p>' +
          '<p class="auth-recovery-link" hidden>Forgot your password? <a href="/password-reset.html">Reset it here.</a></p>' +
          '<div class="auth-field"><label for="login-identifier">Username or email</label><input id="login-identifier" name="identifier" type="text" autocomplete="username" required>' + fieldStateHtml() + '</div>' +
          '<div class="auth-field"><label for="login-password">Password</label>' + passwordFieldHtml('login-password', 'password', 'current-password') + fieldStateHtml() + '</div>' +
          '<div class="auth-oauth-divider"><span>or continue through Google</span></div>' +
          '<button type="button" class="btn auth-google-btn" data-google-oauth="login"><span class="auth-google-mark" aria-hidden="true">G</span>Continue with Google</button>' +
          '<button type="submit" class="btn btn-solid auth-submit">Log In</button>' +
        '</form>' +
        '<form class="auth-form" data-form="two-factor" hidden>' +
          '<h3 class="auth-modal-title">Verify your sign-in</h3>' +
          '<p class="auth-modal-intro">Enter the six-digit code from your authenticator app to finish signing in.</p>' +
          '<p class="auth-error"></p>' +
          '<div class="auth-field auth-two-factor-code"><label for="login-two-factor-code">Authenticator Code</label><input id="login-two-factor-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required></div>' +
          '<button type="submit" class="btn btn-solid auth-submit">Verify and Sign In</button>' +
          '<button type="button" class="btn auth-two-factor-back">Use a different account</button>' +
        '</form>' +
        '<form class="auth-form" data-form="register" hidden>' +
          '<h3 class="auth-modal-title">Join the Pantheon</h3>' +
          '<p class="auth-modal-intro">Create your place in the Pantheon.</p>' +
          '<p class="auth-error"></p>' +
          '<div class="auth-field"><label for="reg-username">Username</label><input id="reg-username" name="username" type="text" autocomplete="username" required minlength="3" maxlength="30" pattern="[A-Za-z0-9_-]+"><small class="auth-field-hint">3–30 characters: letters, numbers, hyphens, or underscores.</small>' + fieldStateHtml() + '</div>' +
          '<div class="auth-field"><label for="reg-email">Email</label><input id="reg-email" name="email" type="email" autocomplete="email" required>' + fieldStateHtml() + '</div>' +
          '<div class="auth-field"><label for="reg-password">Password</label>' + passwordFieldHtml('reg-password', 'password', 'new-password', 8) + '<div class="auth-password-strength" id="reg-password-strength" aria-live="polite"><i></i><i></i><i></i><i></i><small>Use 8 or more characters.</small></div>' + fieldStateHtml() + '</div>' +
          '<div class="auth-field"><label for="reg-password-confirm">Confirm Password</label>' + passwordFieldHtml('reg-password-confirm', 'password-confirm', 'new-password', 8) + fieldStateHtml() + '</div>' +
          '<div class="auth-oauth-divider"><span>or continue through Google</span></div>' +
          '<label class="auth-oauth-option"><input type="checkbox" id="reg-google-avatar" checked>Import my Google profile picture when available</label>' +
          '<button type="button" class="btn auth-google-btn" data-google-oauth="register"><span class="auth-google-mark" aria-hidden="true">G</span>Register with Google</button>' +
          '<button type="submit" class="btn btn-solid auth-submit">Create Account</button>' +
        '</form>' +
        '<div class="auth-success" hidden aria-live="polite"><span class="auth-success-mark">✓</span><span class="auth-success-label">Account created</span><h3>Welcome to the Pantheon.</h3><p>Your account is ready. Preparing your profile&hellip;</p></div>' +
        '<p class="auth-privacy-note">Your profile and notification preferences stay under your control. <a href="/privacy.html">Review our privacy commitment</a></p>' +
        '</div>' +
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
  var twoFactorForm = modal.querySelector('[data-form="two-factor"]');

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
      var recovery = f.querySelector('.auth-recovery-link');
      if (recovery) recovery.hidden = true;
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

  function showTwoFactorChallenge() {
    authSuccess.hidden = true;
    forms.forEach(function (form) { form.hidden = form !== twoFactorForm; });
    tabs.forEach(function (tab) { tab.classList.remove('active'); });
    twoFactorForm.querySelector('.auth-error').classList.remove('show');
    twoFactorForm.querySelector('#login-two-factor-code').value = '';
    setTimeout(function () { twoFactorForm.querySelector('#login-two-factor-code').focus(); }, 30);
  }

  function setRecoveryLink(form, visible) {
    var recovery = form.querySelector('.auth-recovery-link');
    if (recovery) recovery.hidden = !visible;
  }

  function setFieldState(input, state) {
    var field = input.closest && input.closest('.auth-field');
    if (!field) return;
    field.classList.remove('is-typing', 'is-valid', 'is-invalid');
    if (state) field.classList.add('is-' + state);
  }

  function updateFieldState(input) {
    var value = input.value.trim();
    var state = '';
    if (value) {
      if (input.id === 'reg-password-confirm' && input.value !== modal.querySelector('#reg-password').value) state = 'typing';
      else state = input.checkValidity() ? 'valid' : 'typing';
    }
    setFieldState(input, state);
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
    setRecoveryLink(form, false);
    submitBtn.disabled = true;
    ensureCsrfToken().then(function () {
      return postJson('/api/login.php', { identifier: identifier, password: password, csrf: window.PW_AUTH.csrf });
    }).then(function (r) {
      if (r.data && r.data.ok) {
        if (r.data.two_factor_required) {
          showTwoFactorChallenge();
        } else {
          closeModal();
          refreshAuthNav();
        }
      } else {
        showFormError(form, (r.data && r.data.error) || 'Something went wrong.');
        // The generic 401 intentionally covers both an unknown identifier
        // and a wrong password. Showing the recovery route in both cases
        // avoids revealing whether an account exists.
        setRecoveryLink(form, r.status === 401);
      }
    }).catch(function (error) {
      showFormError(form, (error && error.message) || 'Could not reach the server. Try again in a moment.');
    })
      .finally(function () { submitBtn.disabled = false; });
  });

  twoFactorForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var code = twoFactorForm.querySelector('#login-two-factor-code').value.replace(/\D/g, '');
    var submitBtn = twoFactorForm.querySelector('.auth-submit');
    submitBtn.disabled = true;
    twoFactorForm.querySelector('.auth-error').classList.remove('show');
    ensureCsrfToken().then(function () {
      return postJson('/api/two-factor/verify.php', { code: code, csrf: window.PW_AUTH.csrf });
    }).then(function (r) {
      if (r.data && r.data.ok) {
        closeModal();
        refreshAuthNav();
        return;
      }
      showFormError(twoFactorForm, (r.data && r.data.error) || 'Could not verify that code.');
    }).catch(function (error) {
      showFormError(twoFactorForm, (error && error.message) || 'Could not reach the server. Try again in a moment.');
    }).finally(function () { submitBtn.disabled = false; });
  });

  modal.querySelector('.auth-two-factor-back').addEventListener('click', function () {
    setTab('login');
    modal.querySelector('#login-password').value = '';
    modal.querySelector('#login-identifier').focus();
  });

  modal.querySelector('[data-form="register"]').addEventListener('submit', function (e) {
    e.preventDefault();
    var form = e.target;
    var username = form.querySelector('#reg-username').value.trim();
    var email = form.querySelector('#reg-email').value.trim();
    var password = form.querySelector('#reg-password').value;
    var confirmPassword = form.querySelector('#reg-password-confirm').value;
    if (password !== confirmPassword) {
      setFieldState(form.querySelector('#reg-password-confirm'), 'invalid');
      showFormError(form, 'Passwords don\'t match.');
      return;
    }
    form.querySelectorAll('input[required]').forEach(function (input) { updateFieldState(input); });
    var submitBtn = form.querySelector('.auth-submit');
    submitBtn.disabled = true;
    ensureCsrfToken().then(function () {
      return postJson('/api/register.php', { username: username, email: email, password: password, csrf: window.PW_AUTH.csrf });
    }).then(function (r) {
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
    }).catch(function (error) {
      showFormError(form, (error && error.message) || 'Could not reach the server. Try again in a moment.');
    })
      .finally(function () { submitBtn.disabled = false; });
  });

  // Delegated so it still works after the nav item's innerHTML is replaced.
  document.addEventListener('click', function (e) {
    var trigger = e.target.closest && e.target.closest('.auth-trigger');
    if (trigger) {
      e.preventDefault();
      openModal(trigger.getAttribute('data-tab') || 'login');
    }
    var presenceBtn = e.target.closest && e.target.closest('.auth-presence-option');
    if (presenceBtn) {
      e.preventDefault();
      var nextStatus = presenceBtn.getAttribute('data-presence-status') || '';
      if (!window.PW_AUTH.loggedIn || !window.PW_AUTH.user || !['online', 'away', 'inactive'].includes(nextStatus)) return;
      if (window.PW_AUTH.user.presence_status === nextStatus) return;
      presenceBtn.disabled = true;
      postJson('/api/presence/update.php', { status: nextStatus, csrf: window.PW_AUTH.csrf }).then(function (result) {
        if (!result.data || !result.data.ok) throw new Error('Could not update status.');
        window.PW_AUTH.user.presence_status = result.data.presence_status;
        renderNav();
        document.dispatchEvent(new CustomEvent('pw-presence-updated', { detail: { status: result.data.presence_status } }));
      }).catch(function () {
        presenceBtn.disabled = false;
      });
      return;
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

  function ensureCsrfToken() {
    if (window.PW_AUTH && window.PW_AUTH.csrf) return Promise.resolve();
    // This deliberately does not call refreshAuthNav(). That routine also
    // renders the navigation and starts notification loading; an unrelated
    // UI exception there must never prevent a login form from obtaining the
    // CSRF token supplied by the session endpoint.
    return fetch('/api/session-check.php?refresh=' + Date.now(), {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Accept': 'application/json' },
    }).then(function (res) {
      if (!res.ok) throw new Error('Could not reach the secure session service.');
      return res.json();
    }).then(function (data) {
      if (!data || !data.ok || !data.csrf) {
        throw new Error('Could not establish a secure session. Please try again.');
      }
      window.PW_AUTH = {
        loggedIn: !!data.loggedIn,
        user: data.user || null,
        csrf: data.csrf,
        permissions: data.permissions || [],
      };
    });
  }

  // Fixed 6-icon Overlord resonance catalog, index-matched to quiz.html's
  // own hardcoded overlord list. Each color reuses that Overlord's world's
  // existing atlas signal color (js/worlds.js ATLAS_TONES).
  var OVERLORD_ICONS = {
    'syn-dravus': { name: 'Syn Dravus', color: 'rgb(154, 96, 238)', svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>' },
    'malric-thorne': { name: 'Malric Thorne', color: 'rgb(204, 72, 80)', svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 18h16"/><path d="M4 18 3 8l5 4 4-7 4 7 5-4-1 10Z"/></svg>' },
    'korrus-vale': { name: 'Korrus Vale', color: 'rgb(159, 224, 65)', svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 20.5 7v10L12 22 3.5 17V7Z"/><circle cx="12" cy="12" r="3"/></svg>' },
    'lysara-venthe': { name: 'Lysara Venthe', color: 'rgb(68, 150, 237)', svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9c2-3 4-3 6 0s4 3 6 0 4-3 6 0"/><path d="M2 15c2-3 4-3 6 0s4 3 6 0 4-3 6 0"/></svg>' },
    'zura-kaleth': { name: 'Zura Kaleth', color: 'rgb(59, 148, 83)', svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v8"/><path d="M12 11 6 21"/><path d="M12 11l6 10"/><path d="M12 11 4 15"/><path d="M12 11l8 4"/></svg>' },
    'maerion-thal': { name: 'Maerion Thal', color: 'rgb(184, 111, 66)', svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 16c4-2 6-8 9-11 3 3 5 9 9 11-4 2-7 0-9-3-2 3-5 5-9 3Z"/></svg>' }
  };

  // Builds the compact reputation bar + optional resonance icon badge shown
  // in the nav profile dropdown, just above the role/presence line.
  function reputationBarHtml(rep, iconKey) {
    if (!rep) return '';
    var color = rep.level_color || '#c7ccd6';
    var square = '<span class="reputation-bar-square" style="background:' + color + '">' + (rep.level_number != null ? rep.level_number : '') + '</span>';
    var track = '<span class="reputation-bar-track"><span class="reputation-bar-fill" style="width:' + (rep.progress_percent || 0) + '%;background:' + color + '"></span></span>';
    var title = rep.level_name || 'Unranked';
    if (rep.next_level_name) {
      title += ' · ' + Math.max(0, rep.next_level_threshold - rep.points) + ' rep to ' + rep.next_level_name;
    } else if (rep.level_name) {
      title += ' · Max level';
    }
    var icon = iconKey ? OVERLORD_ICONS[iconKey] : null;
    var iconHtml = icon
      ? '<span class="resonance-icon-badge" style="color:' + icon.color + '" title="' + escapeHtml(icon.name) + ' — Pure Resonance">' + icon.svg + '</span>'
      : '';
    return '<div class="reputation-bar-row auth-reputation-row" title="' + escapeHtml(title) + '">' +
      '<span class="reputation-bar reputation-bar-compact">' + square + track + '</span>' + iconHtml +
      '</div>';
  }

  var PRESENCE_LABELS = { online: 'Online', away: 'Away', inactive: 'Inactive', offline: 'Offline' };

  function normalizedPresenceStatus(status) {
    return ['online', 'away', 'inactive'].indexOf(status) !== -1 ? status : 'online';
  }

  function presencePickerHtml(currentStatus) {
    var statuses = ['online', 'away', 'inactive'];
    return '<div class="auth-presence-picker" aria-label="Presence status">' +
      '<span class="auth-presence-picker-label">Set your status</span>' +
      '<div class="auth-presence-options" role="group" aria-label="Choose your presence status">' +
      statuses.map(function (status) {
        var active = status === currentStatus;
        return '<button type="button" class="auth-presence-option is-' + status + (active ? ' is-active' : '') + '" data-presence-status="' + status + '" aria-pressed="' + String(active) + '">' +
          '<i class="auth-presence-dot is-' + status + '" aria-hidden="true"></i><span>' + PRESENCE_LABELS[status] + '</span></button>';
      }).join('') +
      '</div></div>';
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
      var displayName = escapeHtml(window.PW_AUTH.user.display_name);
      var initial = escapeHtml(String(window.PW_AUTH.user.display_name || window.PW_AUTH.user.username || '?').charAt(0).toUpperCase());
      var roleColor = escapeHtml(window.PW_AUTH.user.role_color || '#a279ec');
      var roleName = String(window.PW_AUTH.user.role || 'member').replace(/(^|_)([a-z])/g, function (match, prefix, letter) {
        return (prefix ? ' ' : '') + letter.toUpperCase();
      });
      var presenceStatus = normalizedPresenceStatus(window.PW_AUTH.user.presence_status);
      var presenceLabel = PRESENCE_LABELS[presenceStatus];
      var avatarUrl = '/uploads/avatars/' + encodeURIComponent(window.PW_AUTH.user.id) + '.jpg';
      var profileIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.25"/><path d="M5.2 20c.9-3.3 3.15-5 6.8-5s5.9 1.7 6.8 5"/></svg>';
      var settingsIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.05.05-2.1 2.1-.05-.05a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.04 1.56v.08h-3v-.08A1.7 1.7 0 0 0 10.68 18.64a1.7 1.7 0 0 0-1.88.34l-.05.05-2.1-2.1.05-.05A1.7 1.7 0 0 0 7.04 15 1.7 1.7 0 0 0 5.48 14H5.4v-3h.08A1.7 1.7 0 0 0 7.04 9.96 1.7 1.7 0 0 0 6.7 8.08l-.05-.05 2.1-2.1.05.05a1.7 1.7 0 0 0 1.88.34A1.7 1.7 0 0 0 11.72 4.8v-.08h3v.08a1.7 1.7 0 0 0 1.04 1.56 1.7 1.7 0 0 0 1.88-.34l.05-.05 2.1 2.1-.05.05a1.7 1.7 0 0 0-.34 1.88A1.7 1.7 0 0 0 20.96 11H21v3h-.04A1.7 1.7 0 0 0 19.4 15Z"/></svg>';
      var adminIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5 19 6v5.2c0 4.2-2.7 7.6-7 9.3-4.3-1.7-7-5.1-7-9.3V6l7-2.5Z"/><path d="m9.2 12 1.8 1.8 3.9-4"/></svg>';
      var logoutIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5H5v14h5"/><path d="m14 8 4 4-4 4M8 12h10"/></svg>';
      slot.className = 'nav-item has-dropdown auth-nav-item';
      slot.style.setProperty('--auth-role-color', roleColor);
      slot.innerHTML =
        '<span class="nav-parent auth-profile-chip" style="--auth-role-color:' + roleColor + '"><span class="auth-profile-initial">' + initial + '</span><span class="auth-profile-name">' + displayName + '</span><span class="nav-caret">⌄</span></span>' +
        '<div class="nav-dropdown auth-nav-dropdown">' +
          '<a class="auth-profile-summary" href="member.html?id=' + encodeURIComponent(window.PW_AUTH.user.id) + '" aria-label="View ' + displayName + '\'s profile">' +
            '<span class="auth-profile-avatar"><img src="' + avatarUrl + '" alt="" onerror="this.hidden=true"><span class="auth-profile-avatar-fallback">' + initial + '</span></span>' +
            '<span class="auth-profile-summary-copy"><strong>' + displayName + '</strong><span><i class="auth-online-dot auth-presence-dot is-' + presenceStatus + '"></i>' + escapeHtml(roleName) + ' · ' + presenceLabel + '</span>' + reputationBarHtml(window.PW_AUTH.user.reputation, window.PW_AUTH.user.selected_icon) + '</span>' +
          '</a>' +
          presencePickerHtml(presenceStatus) +
          '<div class="auth-dropdown-actions">' +
            '<a href="member.html?id=' + encodeURIComponent(window.PW_AUTH.user.id) + '">' + profileIcon + '<span>Profile</span></a>' +
            '<a href="profile.html">' + settingsIcon + '<span>Settings</span></a>' +
            (pwHasPermission('admin_console.access') ? '<a href="/admin">' + adminIcon + '<span>Admin Console</span></a>' : '') +
          '</div>' +
          '<button type="button" class="auth-logout-btn">' + logoutIcon + '<span>Log Out</span></button>' +
        '</div>';
    } else {
      slot.className = 'auth-nav-item';
      slot.style.removeProperty('--auth-role-color');
      slot.innerHTML = '<a href="#" class="auth-trigger">Login</a>';
    }
  }

  window.refreshAuthNav = function refreshAuthNav() {
    return fetch('/api/session-check.php?refresh=' + Date.now(), {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Accept': 'application/json' },
    })
      .then(function (res) {
        if (!res.ok) throw new Error('Session check failed.');
        return res.json();
      })
      .then(function (data) {
        if (!data || !data.ok || !data.csrf) throw new Error('Invalid session response.');
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

  // Registration benefits from visible completion feedback. The compact login
  // form should stay quiet until it needs to show a real authentication error.
  modal.querySelectorAll('[data-form="register"] input[required]').forEach(function (input) {
    input.addEventListener('input', function () { updateFieldState(input); });
    input.addEventListener('blur', function () { updateFieldState(input); });
  });
  modal.querySelectorAll('[data-form="register"]').forEach(function (form) {
    form.addEventListener('invalid', function (event) {
      setFieldState(event.target, 'invalid');
    }, true);
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
      var confirmation = modal.querySelector('#reg-password-confirm');
      if (confirmation && confirmation.value) updateFieldState(confirmation);
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
    script.src = '/js/notifications.js?v=11';
    script.async = true;
    document.body.appendChild(script);
  }

  // Account chrome is part of the header visitors interact with immediately.
  // Start its session check as soon as this DOM-ready script runs; waiting for
  // load plus requestIdleCallback could leave an already signed-in visitor
  // looking logged out for several seconds on a busy refresh. Analytics remains
  // deferred independently in js/main.js.
  function scheduleInitialAuthRefresh() {
    startInitialAuthRefresh();
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
