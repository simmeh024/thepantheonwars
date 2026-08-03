// The Pantheon Wars — member system (login/register/session/community glue)
// Injects the auth modal, keeps nav in sync with session state, and exposes
// window.PW_AUTH for other scripts (quiz.html, community.html) to read.

/* `resolved` distinguishes "signed out" from "not asked yet". Without it every
 * gated page treats the pre-flight default as a real signed-out session and
 * paints its Log In panel before session-check.php has answered, which a signed
 * -in visitor sees as a flash on every single page load. It is set once the
 * check settles, whether it succeeded or failed. */
window.PW_AUTH = { resolved: false, loggedIn: false, user: null, csrf: null, permissions: [], oauth: { google: true, apple: false }, maintenance: { enabled: false, message: '' } };

// '*' means every permission (the logged-in user's role is a superuser, e.g.
// admin) -- see api/helpers.php's pw_has_permission() for the server-side
// twin of this check. Client-side checks are UI convenience only; every
// endpoint re-checks the permission itself.
window.pwHasPermission = function pwHasPermission(key) {
  var perms = window.PW_AUTH.permissions || [];
  return perms.indexOf('*') !== -1 || perms.indexOf(key) !== -1;
};

function initMembers() {

  var EYE_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>';
  var EYE_OFF_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 5c7 0 11 7 11 7a18.5 18.5 0 0 1-2.16 3.19"/><path d="M1 1l22 22"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
  // A generic apple-silhouette glyph, styled to match the site's own dark/gold
  // auth aesthetic rather than Apple's mandated black button treatment.
  var APPLE_ICON = '<svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M16.5 3.5c.1 1-.3 2-.9 2.8-.6.8-1.6 1.4-2.6 1.3-.1-1 .3-2.1 1-2.8.6-.8 1.7-1.3 2.5-1.3zm2.9 15.4c-.6 1.3-.9 1.9-1.7 3-1.1 1.6-2.7 3.6-4.6 3.6-1.7 0-2.2-1.1-4.1-1.1-1.9 0-2.5 1.1-4.1 1.1-1.9 0-3.4-1.9-4.5-3.4C-.9 19.6-.3 14 2.1 11.1c1.2-1.4 2.9-2.3 4.5-2.3 1.7 0 2.8 1.1 4.1 1.1 1.3 0 2.2-1.1 4.1-1.1 1.5 0 3.1.8 4.2 2.2-3.7 2.1-3.1 7 .4 8z"/></svg>';
  var PROVIDER_LABELS = { google: 'Google', apple: 'Apple' };

  // Silent Google re-login: set once a visitor actually signs in/registers/
  // links with Google on this browser, so refreshAuthNav() knows it's worth
  // trying a quiet prompt=none bounce the next time the local session is
  // gone. Cleared on an explicit Log Out (so that doesn't immediately get
  // undone by a lingering Google session) and on a failed silent attempt
  // (Google itself reporting no active session/consent -- stop asking until
  // the visitor signs in with Google again). Apple has no silent equivalent
  // and never touches this flag. GOOGLE_SILENT_TRIED_KEY is sessionStorage
  // (per-tab) so a failed attempt can't bounce through Google more than once
  // per tab session.
  var GOOGLE_LINKED_KEY = 'pw_google_linked';
  var GOOGLE_SILENT_TRIED_KEY = 'pw_google_silent_tried';

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
          '<button type="button" class="btn auth-google-btn" data-oauth-provider="google" data-oauth-intent="login"><span class="auth-google-mark" aria-hidden="true">G</span>Continue with Google</button>' +
          '<button type="button" class="btn auth-apple-btn" data-oauth-provider="apple" data-oauth-intent="login" hidden><span class="auth-apple-mark" aria-hidden="true">' + APPLE_ICON + '</span>Continue with Apple</button>' +
          '<div class="auth-oauth-divider"><span>or continue with your Pantheon Wars account</span></div>' +
          '<div class="auth-field"><label for="login-identifier">Username or email</label><input id="login-identifier" name="identifier" type="text" autocomplete="username" required>' + fieldStateHtml() + '</div>' +
          '<div class="auth-field"><label for="login-password">Password</label>' + passwordFieldHtml('login-password', 'password', 'current-password') + fieldStateHtml() + '</div>' +
          '<label class="auth-remember-option"><input type="checkbox" id="login-remember" checked>Remember me</label>' +
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
          '<label class="auth-oauth-option"><input type="checkbox" id="reg-google-avatar" checked>Import my Google profile picture when available</label>' +
          '<button type="button" class="btn auth-google-btn" data-oauth-provider="google" data-oauth-intent="register"><span class="auth-google-mark" aria-hidden="true">G</span>Register with Google</button>' +
          '<button type="button" class="btn auth-apple-btn" data-oauth-provider="apple" data-oauth-intent="register" hidden><span class="auth-apple-mark" aria-hidden="true">' + APPLE_ICON + '</span>Register with Apple</button>' +
          '<div class="auth-oauth-divider"><span>or register with your Pantheon Wars account</span></div>' +
          '<div class="auth-field"><label for="reg-username">Username</label><input id="reg-username" name="username" type="text" autocomplete="username" required minlength="3" maxlength="30" pattern="[A-Za-z0-9_-]+"><small class="auth-field-hint">3–30 characters: letters, numbers, hyphens, or underscores.</small>' + fieldStateHtml() + '</div>' +
          '<div class="auth-field"><label for="reg-email">Email</label><input id="reg-email" name="email" type="email" autocomplete="email" required>' + fieldStateHtml() + '</div>' +
          '<div class="auth-field"><label for="reg-password">Password</label>' + passwordFieldHtml('reg-password', 'password', 'new-password', 8) + '<div class="auth-password-strength" id="reg-password-strength" aria-live="polite"><i></i><i></i><i></i><i></i><small>Use 8 or more characters.</small></div>' + fieldStateHtml() + '</div>' +
          '<div class="auth-field"><label for="reg-password-confirm">Confirm Password</label>' + passwordFieldHtml('reg-password-confirm', 'password-confirm', 'new-password', 8) + fieldStateHtml() + '</div>' +
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
    var remember = form.querySelector('#login-remember').checked;
    var submitBtn = form.querySelector('.auth-submit');
    setRecoveryLink(form, false);
    submitBtn.disabled = true; submitBtn.classList.add('is-busy');
    ensureCsrfToken().then(function () {
      return postJson('/api/login.php', { identifier: identifier, password: password, remember: remember, csrf: window.PW_AUTH.csrf });
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
      .finally(function () { submitBtn.disabled = false; submitBtn.classList.remove('is-busy'); });
  });

  twoFactorForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var code = twoFactorForm.querySelector('#login-two-factor-code').value.replace(/\D/g, '');
    var submitBtn = twoFactorForm.querySelector('.auth-submit');
    submitBtn.disabled = true; submitBtn.classList.add('is-busy');
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
    }).finally(function () { submitBtn.disabled = false; submitBtn.classList.remove('is-busy'); });
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
    submitBtn.disabled = true; submitBtn.classList.add('is-busy');
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
      .finally(function () { submitBtn.disabled = false; submitBtn.classList.remove('is-busy'); });
  });

  function closeOpenAccountMenus() {
    document.querySelectorAll('.auth-nav-item.is-open').forEach(function (item) {
      item.classList.remove('is-open');
      var itemTrigger = item.querySelector('.auth-profile-chip');
      if (itemTrigger) itemTrigger.setAttribute('aria-expanded', 'false');
    });
  }

  // Delegated so it still works after the nav item's innerHTML is replaced.
  document.addEventListener('click', function (e) {
    // The account control sits outside the expandable main navigation on
    // small screens. Its menu must therefore be explicitly toggled instead
    // of inheriting the always-open mobile treatment used by main-nav menus.
    var profileChip = e.target.closest && e.target.closest('.auth-profile-chip');
    if (profileChip && window.matchMedia && window.matchMedia('(max-width: 780px)').matches) {
      e.preventDefault();
      var accountItem = profileChip.closest('.auth-nav-item');
      if (!accountItem) return;
      var isOpening = !accountItem.classList.contains('is-open');
      closeOpenAccountMenus();
      if (isOpening) {
        accountItem.classList.add('is-open');
        profileChip.setAttribute('aria-expanded', 'true');
      }
      return;
    }
    var closeAccountMenu = e.target.closest && e.target.closest('.auth-menu-close');
    if (closeAccountMenu && window.matchMedia && window.matchMedia('(max-width: 780px)').matches) {
      e.preventDefault();
      closeOpenAccountMenus();
      var closedTrigger = document.querySelector('.auth-profile-chip');
      if (closedTrigger) closedTrigger.focus();
      return;
    }
    var clickedAuthItem = e.target.closest && e.target.closest('.auth-nav-item');
    if (window.matchMedia && window.matchMedia('(max-width: 780px)').matches && !clickedAuthItem) {
      closeOpenAccountMenus();
    }
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
        window.PW_AUTH = { resolved: true, loggedIn: false, user: null, csrf: null, permissions: [], oauth: { google: true, apple: false }, maintenance: { enabled: false, message: '' } };
        // An explicit Log Out must not be immediately undone by a lingering
        // Google session on the very next page load.
        localStorage.removeItem(GOOGLE_LINKED_KEY);
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

  /* Rank celebrations are created here instead of in individual page markup,
     which makes the event available everywhere the shared member chrome runs.
     The styles travel with the component so member/profile/admin layouts that
     use different page stylesheets still receive the complete presentation. */
  function injectRankUpStyles() {
    if (document.getElementById('pw-rankup-styles')) return;
    var style = document.createElement('style');
    style.id = 'pw-rankup-styles';
    style.textContent =
      '@keyframes pw-rankup-enter{from{opacity:0;transform:translateY(20px) scale(.975)}to{opacity:1;transform:translateY(0) scale(1)}}' +
      '@keyframes pw-rankup-orbit{to{transform:rotate(360deg)}}' +
      '@keyframes pw-rankup-pulse{0%,100%{box-shadow:0 0 0 0 color-mix(in srgb,var(--pw-rank-color) 0%,transparent)}50%{box-shadow:0 0 26px 3px var(--pw-rank-color)}}' +
      '.pw-rankup-modal{position:fixed;z-index:10050;inset:0;font-family:inherit;color:#f4f1ff}' +
      '.pw-rankup-shade{position:absolute;inset:0;background:rgba(4,5,16,.82);backdrop-filter:blur(8px)}' +
      '.pw-rankup-panel{--pw-rank-color:#a279ec;position:relative;z-index:1;box-sizing:border-box;width:min(620px,calc(100% - 30px));max-height:calc(100dvh - 30px);overflow:auto;margin:15px auto;padding:31px;border:1px solid color-mix(in srgb,var(--pw-rank-color) 62%,#d8d4ed);background:linear-gradient(145deg,rgba(20,15,45,.985),rgba(10,10,30,.99));box-shadow:0 26px 70px rgba(0,0,0,.6),inset 0 1px 0 rgba(255,255,255,.08);animation:pw-rankup-enter .34s ease-out}' +
      '.pw-rankup-panel:before{content:"";position:absolute;inset:0;pointer-events:none;opacity:.28;background:linear-gradient(116deg,transparent 0 42%,var(--pw-rank-color) 50%,transparent 58%);transform:translateX(-72%);animation:pw-rankup-sweep 2.8s ease-in-out infinite}' +
      '@keyframes pw-rankup-sweep{0%,35%{transform:translateX(-72%)}70%,100%{transform:translateX(72%)}}' +
      '.pw-rankup-close{position:absolute;z-index:2;top:12px;right:14px;width:34px;height:34px;border:1px solid rgba(236,232,255,.2);background:rgba(9,8,27,.68);color:#ded9f2;font:24px/28px Arial,sans-serif;cursor:pointer}' +
      '.pw-rankup-close:hover,.pw-rankup-close:focus{border-color:var(--pw-rank-color);color:#fff}' +
      '.pw-rankup-hero{position:relative;display:grid;grid-template-columns:72px 1fr;gap:18px;align-items:center;padding:3px 34px 24px 0;border-bottom:1px solid rgba(213,205,243,.14)}' +
      '.pw-rankup-sigil{position:relative;display:grid;place-items:center;width:68px;height:68px;border:1px solid var(--pw-rank-color);background:radial-gradient(circle,rgba(255,255,255,.14),transparent 61%);color:var(--pw-rank-color);text-shadow:0 0 15px var(--pw-rank-color);animation:pw-rankup-pulse 2.3s ease-in-out infinite}' +
      '.pw-rankup-sigil:before{content:"";position:absolute;inset:6px;border:1px solid color-mix(in srgb,var(--pw-rank-color) 56%,transparent);transform:rotate(45deg)}' +
      '.pw-rankup-sigil span{position:relative;z-index:1;font-family:Georgia,serif;font-size:20px;font-weight:700}' +
      '.pw-rankup-kicker{margin:0 0 5px;color:#88dfe3;font-size:10px;font-weight:700;letter-spacing:.15em;text-transform:uppercase}' +
      '.pw-rankup-hero h2{margin:0;color:#fff;font-family:Georgia,serif;font-size:clamp(25px,5vw,34px);line-height:1.05}' +
      '.pw-rankup-hero p{margin:7px 0 0;color:#c8c4d8;font-size:14px;line-height:1.45}.pw-rankup-hero strong{color:var(--pw-rank-color)}' +
      '.pw-rankup-total{display:inline-flex;gap:7px;align-items:baseline;margin-top:6px;color:#9e98b9;font-size:11px;letter-spacing:.08em;text-transform:uppercase}.pw-rankup-total b{color:#f5d77b;font-size:15px;letter-spacing:0}' +
      '.pw-rankup-progress{margin:22px 0 0;padding:17px;border:1px solid rgba(213,205,243,.16);background:rgba(3,4,17,.33)}' +
      '.pw-rankup-progress-head{display:flex;gap:14px;justify-content:space-between;align-items:baseline;color:#d8d1eb;font-size:13px}.pw-rankup-progress-head span{color:#8f88a9;font-size:10px;letter-spacing:.1em;text-transform:uppercase}.pw-rankup-progress-head b{color:#f3efff;font-weight:600}' +
      '.pw-rankup-track{height:7px;margin:12px 0 8px;overflow:hidden;background:#050513;box-shadow:inset 0 0 0 1px rgba(220,215,245,.1)}.pw-rankup-track i{display:block;width:0;height:100%;background:linear-gradient(90deg,var(--pw-rank-color),#f2d477);box-shadow:0 0 16px var(--pw-rank-color);transition:width .8s cubic-bezier(.16,1,.3,1)}' +
      '.pw-rankup-progress p{margin:0;color:#918aa7;font-size:12px}.pw-rankup-max{margin:22px 0 0;padding:16px;border:1px solid color-mix(in srgb,var(--pw-rank-color) 42%,transparent);color:#e6dff8;font-size:13px;text-align:center}' +
      '.pw-rankup-unlocks{margin:23px 0 0}.pw-rankup-unlocks h3{margin:0 0 10px;color:#f0d47c;font-family:Georgia,serif;font-size:18px}.pw-rankup-unlocks>p{margin:0 0 13px;color:#9c95b1;font-size:12px}' +
      '.pw-rankup-unlock-list{display:grid;gap:8px;margin:0;padding:0;list-style:none}.pw-rankup-unlock{position:relative;padding:12px 12px 12px 17px;border:1px solid rgba(213,205,243,.14);background:rgba(2,3,15,.24)}.pw-rankup-unlock:before{content:"";position:absolute;left:0;top:10px;bottom:10px;width:2px;background:var(--pw-unlock-color,#a279ec)}.pw-rankup-unlock small{display:block;margin-bottom:3px;color:#8ddde1;font-size:9px;font-weight:700;letter-spacing:.13em;text-transform:uppercase}.pw-rankup-unlock b{display:block;color:#f1edfc;font-size:14px}.pw-rankup-unlock p{margin:4px 0 0;color:#aaa4bc;font-size:12px;line-height:1.45}' +
      '.pw-rankup-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:24px}.pw-rankup-actions a,.pw-rankup-actions button{box-sizing:border-box;min-height:40px;padding:0 15px;border:1px solid rgba(221,214,245,.28);background:transparent;color:#e7e1f7;font:700 11px/38px inherit;letter-spacing:.1em;text-decoration:none;text-transform:uppercase;cursor:pointer}.pw-rankup-actions a:hover,.pw-rankup-actions a:focus{border-color:var(--pw-rank-color);color:#fff}.pw-rankup-actions button{border-color:var(--pw-rank-color);background:var(--pw-rank-color);color:#080715}.pw-rankup-actions button:hover,.pw-rankup-actions button:focus{filter:brightness(1.13)}' +
      '@media (max-width:520px){.pw-rankup-panel{margin:8px auto;padding:23px 18px}.pw-rankup-hero{grid-template-columns:56px 1fr;gap:13px;padding-right:25px}.pw-rankup-sigil{width:52px;height:52px}.pw-rankup-progress-head{display:block}.pw-rankup-progress-head b{display:block;margin-top:4px}.pw-rankup-actions{justify-content:stretch}.pw-rankup-actions>*{flex:1;text-align:center}}' +
      '@media (prefers-reduced-motion:reduce){.pw-rankup-panel,.pw-rankup-sigil,.pw-rankup-panel:before{animation:none}.pw-rankup-track i{transition:none}}';
    document.head.appendChild(style);
  }

  function safeRankUpColor(value) {
    return /^#[0-9a-f]{6}$/i.test(String(value || '')) ? String(value) : '#a279ec';
  }

  function rankUpNumber(value) {
    var number = Number(value);
    return isFinite(number) ? Math.max(0, Math.round(number)) : 0;
  }

  function formatRankUpNumber(value) {
    return rankUpNumber(value).toLocaleString();
  }

  function buildRankUpModal() {
    var existing = document.getElementById('pw-rankup-modal');
    if (existing) return existing;
    injectRankUpStyles();
    var root = document.createElement('div');
    root.id = 'pw-rankup-modal';
    root.className = 'pw-rankup-modal';
    root.hidden = true;
    root.innerHTML = '<div class="pw-rankup-shade" data-rankup-close="true"></div><section class="pw-rankup-panel" role="dialog" aria-modal="true" aria-labelledby="pw-rankup-title" tabindex="-1"></section>';
    document.body.appendChild(root);
    root.addEventListener('click', function (event) {
      if (event.target.getAttribute('data-rankup-close') === 'true') closeRankUpModal();
    });
    return root;
  }

  var rankUpModal = null;
  var rankUpQueue = [];
  var rankUpIsShowing = false;

  function closeRankUpModal() {
    if (!rankUpModal || rankUpModal.hidden) return;
    rankUpModal.hidden = true;
    rankUpIsShowing = false;
    window.setTimeout(showNextRankUp, 150);
  }

  function rankUpUnlocksMarkup(unlocks) {
    if (!Array.isArray(unlocks) || !unlocks.length) {
      return '<li class="pw-rankup-unlock"><small>Standing update</small><b>New community clearance</b><p>Your new title and rank marker are now active.</p></li>';
    }
    return unlocks.slice(0, 10).map(function (unlock) {
      var accent = safeRankUpColor(unlock && unlock.accent);
      return '<li class="pw-rankup-unlock" style="--pw-unlock-color:' + accent + '"><small>' + escapeHtml(unlock && unlock.eyebrow ? unlock.eyebrow : 'Rank unlock') + '</small><b>' + escapeHtml(unlock && unlock.title ? unlock.title : 'New clearance') + '</b><p>' + escapeHtml(unlock && unlock.description ? unlock.description : 'Available now.') + '</p></li>';
    }).join('');
  }

  function renderRankUpEvent(event) {
    rankUpModal = rankUpModal || buildRankUpModal();
    var panel = rankUpModal.querySelector('.pw-rankup-panel');
    var color = safeRankUpColor(event && event.color);
    var title = escapeHtml(event && event.title ? event.title : 'New Rank');
    var rankNumber = Math.max(1, rankUpNumber(event && event.rank_number));
    var total = rankUpNumber(event && event.total_reputation);
    var nextTitle = event && event.next_title ? String(event.next_title) : '';
    var nextThreshold = rankUpNumber(event && event.next_threshold);
    var progress = Math.max(0, Math.min(100, rankUpNumber(event && event.progress_percent)));
    var nextMarkup = '';
    if (nextTitle && nextThreshold > 0) {
      var remaining = Math.max(0, nextThreshold - total);
      nextMarkup = '<div class="pw-rankup-progress"><div class="pw-rankup-progress-head"><span>Next unlock</span><b>' + escapeHtml(nextTitle) + ' &middot; ' + formatRankUpNumber(total) + ' / ' + formatRankUpNumber(nextThreshold) + ' REP</b></div><div class="pw-rankup-track"><i style="width:' + progress + '%"></i></div><p>' + (remaining > 0 ? formatRankUpNumber(remaining) + ' reputation to reach ' + escapeHtml(nextTitle) + '.' : 'The next clearance is ready.') + '</p></div>';
    } else {
      nextMarkup = '<p class="pw-rankup-max">You have reached the highest reputation rank currently available.</p>';
    }
    panel.style.setProperty('--pw-rank-color', color);
    panel.innerHTML =
      '<button type="button" class="pw-rankup-close" aria-label="Close rank-up celebration" data-rankup-close="true">&times;</button>' +
      '<div class="pw-rankup-hero"><div class="pw-rankup-sigil" aria-hidden="true"><span>' + rankNumber + '</span></div><div><p class="pw-rankup-kicker">Reputation rank achieved</p><h2 id="pw-rankup-title">Congratulations</h2><p>You are now a <strong>' + title + '</strong>.</p><p class="pw-rankup-total"><b>' + formatRankUpNumber(total) + '</b> total reputation</p></div></div>' +
      nextMarkup +
      '<div class="pw-rankup-unlocks"><h3>What you unlocked</h3><p>Your new clearance is active across the Pantheon.</p><ul class="pw-rankup-unlock-list">' + rankUpUnlocksMarkup(event && event.unlocks) + '</ul></div>' +
      '<div class="pw-rankup-actions"><a href="/reputation.html">View reputation</a><button type="button" data-rankup-close="true">Continue</button></div>';
    rankUpModal.hidden = false;
    rankUpIsShowing = true;
    window.setTimeout(function () {
      var close = panel.querySelector('[data-rankup-close]');
      if (close) close.focus();
    }, 0);
  }

  function showNextRankUp() {
    if (rankUpIsShowing || !rankUpQueue.length) return;
    renderRankUpEvent(rankUpQueue.shift());
  }

  function queueRankUpEvents(events) {
    if (!Array.isArray(events) || !events.length) return;
    events.forEach(function (event) {
      if (event && event.title) rankUpQueue.push(event);
    });
    showNextRankUp();
  }

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && rankUpIsShowing) closeRankUpModal();
  });

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
        resolved: true,
        loggedIn: !!data.loggedIn,
        user: data.user || null,
        csrf: data.csrf,
        permissions: data.permissions || [],
        oauth: data.oauth || { google: true, apple: false },
        maintenance: data.maintenance || { enabled: false, message: '' },
      };
      applyOauthButtonVisibility();
      applyMaintenanceMode();
      queueRankUpEvents(data.rank_up_events);
      // Signals that window.PW_AUTH is populated, for header chrome that loads
      // before the session resolves and needs to reconcile afterwards (the
      // weather widget's stored world). Same CustomEvent approach as
      // pw-presence-updated rather than another script polling for PW_AUTH.
      document.dispatchEvent(new CustomEvent('pw-auth-ready', {
        detail: { loggedIn: window.PW_AUTH.loggedIn }
      }));
    });
  }

  // Site Settings (Admin Console > System) can switch each OAuth provider
  // off independently of whether it's credentialed -- e.g. Apple stays
  // hidden here until an admin turns it on, matching api/oauth.php's own
  // pw_oauth_provider_config() gate so a hidden button and a working button
  // never disagree. Apple starts `hidden` in the markup above as the safe
  // default before this ever runs.
  function applyOauthButtonVisibility() {
    var settings = (window.PW_AUTH && window.PW_AUTH.oauth) || { google: true, apple: false };
    modal.querySelectorAll('form.auth-form').forEach(function (form) {
      var anyVisible = false;
      form.querySelectorAll('[data-oauth-provider]').forEach(function (button) {
        var enabled = !!settings[button.getAttribute('data-oauth-provider')];
        button.hidden = !enabled;
        if (enabled) anyVisible = true;
      });
      var divider = form.querySelector('.auth-oauth-divider');
      if (divider) divider.hidden = !anyVisible;
      var avatarOption = form.querySelector('#reg-google-avatar');
      var avatarLabel = avatarOption && avatarOption.closest('.auth-oauth-option');
      if (avatarLabel) avatarLabel.hidden = !settings.google;
    });
  }

  // Site Settings' Maintenance Mode toggle shows every non-admin visitor a
  // full-page lockout instead of the page they requested. This is a
  // visitor-facing interstitial only -- it does not block API requests --
  // so the admin console (path-checked below, as a second guard alongside
  // the permission check) and any account with admin_console.access can
  // always still reach and use the real site to fix things.
  var maintenanceOverlay = null;
  function applyMaintenanceMode() {
    var maintenance = (window.PW_AUTH && window.PW_AUTH.maintenance) || { enabled: false, message: '' };
    var onAdminConsole = /^\/admin\/?/.test(location.pathname);
    var shouldShow = !!maintenance.enabled && !onAdminConsole && !pwHasPermission('admin_console.access');

    if (!shouldShow) {
      if (maintenanceOverlay) {
        maintenanceOverlay.remove();
        maintenanceOverlay = null;
        document.documentElement.classList.remove('maintenance-lock');
      }
      return;
    }

    if (!maintenanceOverlay) {
      maintenanceOverlay = document.createElement('div');
      maintenanceOverlay.className = 'maintenance-overlay';
      maintenanceOverlay.innerHTML =
        '<div class="maintenance-overlay-card">' +
          '<span class="maintenance-overlay-kicker">The Pantheon Wars</span>' +
          '<h1>Under Maintenance</h1>' +
          '<p class="maintenance-overlay-message"></p>' +
        '</div>';
      document.body.appendChild(maintenanceOverlay);
    }
    maintenanceOverlay.querySelector('.maintenance-overlay-message').textContent = maintenance.message || 'The Pantheon Wars is undergoing scheduled maintenance. We\'ll be back shortly -- thank you for your patience.';
    document.documentElement.classList.add('maintenance-lock');
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

  function updateAuthMessagesBadge() {
    var badge = document.getElementById('auth-messages-badge');
    if (!badge || !window.PW_AUTH.loggedIn) return;
    fetch('/api/direct-messages/unread-count.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (data) {
        if (!data || !data.ok) return;
        var unread = Math.max(0, Number(data.unread) || 0);
        badge.hidden = unread === 0;
        badge.textContent = unread > 99 ? '99+' : String(unread);
        badge.setAttribute('aria-label', unread + ' unread message' + (unread === 1 ? '' : 's'));
      })
      .catch(function () {});
  }

  // Logged-in state is rendered as a .nav-item.has-dropdown. Larger screens
  // use the shared hover/focus menu behaviour; the compact account button
  // toggles the same card on touch screens.
  function renderNav() {
    var slot = document.getElementById('auth-nav-item');
    var notifSlot = document.getElementById('notif-nav-item');
    var loggedIn = !!(window.PW_AUTH.loggedIn && window.PW_AUTH.user);
    // Some acquisition prompts, such as Home's newsletter sign-up, only make
    // sense before an account exists. Keep their visibility synced with the
    // same confirmed session state that drives the account navigation, and
    // restore them immediately when the visitor signs out.
    document.querySelectorAll('[data-hide-when-logged-in]').forEach(function (el) {
      el.hidden = loggedIn;
    });
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
      var messagesIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8a2.5 2.5 0 0 1-2.5 2.5H10l-4.5 4v-4A2.5 2.5 0 0 1 3 13.5v-8Z"/><path d="M8 8h8M8 11.5h5"/></svg>';
      var reputationIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7v5c0 4.7 3.1 7.9 8 9 4.9-1.1 8-4.3 8-9V7l-8-4Z"/><path d="m8.5 12 2.1 2.1 4.9-4.9"/></svg>';
      var adminIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5 19 6v5.2c0 4.2-2.7 7.6-7 9.3-4.3-1.7-7-5.1-7-9.3V6l7-2.5Z"/><path d="m9.2 12 1.8 1.8 3.9-4"/></svg>';
      var logoutIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5H5v14h5"/><path d="m14 8 4 4-4 4M8 12h10"/></svg>';
      slot.className = 'nav-item has-dropdown auth-nav-item';
      slot.style.setProperty('--auth-role-color', roleColor);
      slot.innerHTML =
        '<button type="button" class="nav-parent auth-profile-chip" style="--auth-role-color:' + roleColor + '" aria-label="Open account menu for ' + displayName + '" aria-expanded="false"><span class="auth-profile-chip-avatar" style="display:grid;width:22px;height:22px;flex:0 0 22px;overflow:hidden;place-items:center;border-radius:50%"><img src="' + avatarUrl + '" alt="" style="display:block;width:100%;height:100%;object-fit:cover" onerror="this.hidden=true"><span class="auth-profile-chip-avatar-fallback">' + initial + '</span></span><span class="auth-profile-name">' + displayName + '</span><span class="nav-caret">⌄</span></button>' +
        '<div class="nav-dropdown auth-nav-dropdown">' +
          '<button type="button" class="auth-menu-close" aria-label="Close account menu"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 7 10 10M17 7 7 17"/></svg></button>' +
          '<a class="auth-profile-summary" href="member.html?id=' + encodeURIComponent(window.PW_AUTH.user.id) + '" aria-label="View ' + displayName + '\'s profile">' +
            '<span class="auth-profile-avatar"><img src="' + avatarUrl + '" alt="" onerror="this.hidden=true"><span class="auth-profile-avatar-fallback">' + initial + '</span></span>' +
            '<span class="auth-profile-summary-copy"><strong>' + displayName + '</strong><span><i class="auth-online-dot auth-presence-dot is-' + presenceStatus + '"></i>' + escapeHtml(roleName) + ' · ' + presenceLabel + '</span>' + reputationBarHtml(window.PW_AUTH.user.reputation, window.PW_AUTH.user.selected_icon) + '</span>' +
          '</a>' +
          presencePickerHtml(presenceStatus) +
          '<div class="auth-dropdown-actions">' +
            '<a href="member.html?id=' + encodeURIComponent(window.PW_AUTH.user.id) + '">' + profileIcon + '<span>Profile</span></a>' +
            '<a href="reputation.html">' + reputationIcon + '<span>Reputation</span></a>' +
            '<a href="messages.html">' + messagesIcon + '<span>Messages</span><span id="auth-messages-badge" class="auth-message-badge" hidden></span></a>' +
            '<a href="profile.html">' + settingsIcon + '<span>Settings</span></a>' +
            (pwHasPermission('admin_console.access') ? '<a class="auth-admin-console-link" href="/admin">' + adminIcon + '<span>Admin Console</span></a>' : '') +
          '</div>' +
          '<button type="button" class="auth-logout-btn">' + logoutIcon + '<span>Log Out</span></button>' +
        '</div>';
      updateAuthMessagesBadge();
    } else {
      slot.className = 'auth-nav-item';
      slot.style.removeProperty('--auth-role-color');
      slot.innerHTML = '<a href="#" class="auth-trigger">Login</a>';
    }
    // Every page starts with a neutral shell rather than a hard-coded Login
    // link. Reveal the resolved account state only after session-check.php
    // answers, preventing a signed-in visitor from seeing a misleading flash.
    slot.removeAttribute('aria-busy');
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
          resolved: true,
          loggedIn: !!data.loggedIn,
          user: data.user || null,
          csrf: data.csrf || null,
          permissions: data.permissions || [],
          oauth: data.oauth || { google: true, apple: false },
          maintenance: data.maintenance || { enabled: false, message: '' },
        };
        // Silent Google re-login: only for a visitor who has used Google here
        // before (GOOGLE_LINKED_KEY), only once per tab session
        // (GOOGLE_SILENT_TRIED_KEY guards against a retry loop if it fails),
        // and never during maintenance lockout. Navigates away instead of
        // painting logged-out chrome first; the same page reloads fresh after
        // the round trip either way.
        if (!window.PW_AUTH.loggedIn && window.PW_AUTH.oauth.google && !window.PW_AUTH.maintenance.enabled &&
            localStorage.getItem(GOOGLE_LINKED_KEY) === '1' && !sessionStorage.getItem(GOOGLE_SILENT_TRIED_KEY)) {
          sessionStorage.setItem(GOOGLE_SILENT_TRIED_KEY, '1');
          window.location.assign('/api/oauth/start.php?provider=google&intent=login&silent=1&return_to=' + encodeURIComponent(location.pathname + location.search));
          return;
        }
        applyOauthButtonVisibility();
        applyMaintenanceMode();
        renderNav();
        queueRankUpEvents(data.rank_up_events);
        if (window.PW_AUTH.loggedIn) loadNotifications();
        document.dispatchEvent(new CustomEvent('pw-auth-ready'));
      })
      .catch(function () {
        /* A failed check is still an answer: the page must fall back to the
         * signed-out view rather than waiting forever on a request that is not
         * coming back. */
        window.PW_AUTH.resolved = true;
        renderNav();
        document.dispatchEvent(new CustomEvent('pw-auth-ready'));
      });
  };

  var initialAuthRefreshStarted = false;

  function startInitialAuthRefresh() {
    if (initialAuthRefreshStarted) return;
    initialAuthRefreshStarted = true;
    window.refreshAuthNav();
  }

  modal.querySelectorAll('[data-oauth-provider]').forEach(function (button) {
    button.addEventListener('click', function () {
      var provider = button.getAttribute('data-oauth-provider');
      var intent = button.getAttribute('data-oauth-intent');
      var returnTo = location.pathname + location.search;
      var url = '/api/oauth/start.php?provider=' + encodeURIComponent(provider) + '&intent=' + encodeURIComponent(intent) + '&return_to=' + encodeURIComponent(returnTo);
      if (intent === 'register' && provider === 'google') {
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
    if (/^google-(signed-in|registered|linked)$/.test(result)) {
      localStorage.setItem(GOOGLE_LINKED_KEY, '1');
    } else if (result === 'google-silent-failed') {
      localStorage.removeItem(GOOGLE_LINKED_KEY);
    }
    // These are Profile Settings outcomes. Leave the parameter in place for
    // profile.html's own script to render beside the provider link controls.
    if (/-(linked|link-conflict|link-expired)$/.test(result)) {
      return;
    }
    var match = /^(google|apple)-(.+)$/.exec(result);
    if (match) {
      var label = PROVIDER_LABELS[match[1]];
      var outcomeMessages = {
        'not-configured': label + ' sign-in is not configured yet. Please use your username/email and password.',
        'cancelled': label + ' sign-in was cancelled. You can try again whenever you are ready.',
        'failed': label + ' sign-in could not be completed. Please try again or use your password.',
        'link-required': 'This email already has an account. Sign in with your password, then link ' + label + ' from Profile Settings.',
        'banned': 'This account has been suspended.',
      };
      if (outcomeMessages[match[2]]) {
        openModal('login');
        showFormError(modal.querySelector('[data-form="login"]'), outcomeMessages[match[2]]);
      }
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
    script.src = '/js/notifications.js?v=16';
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
}

// The homepage deliberately loads account chrome after its first mobile paint
// so session work cannot delay the LCP title. Other pages still initialise on
// DOMContentLoaded; a late homepage load simply calls the same setup now.
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initMembers);
else initMembers();
