// HouseCall: small helpers. Server-side PHP validation is the real security; this just gives instant feedback.
document.addEventListener('DOMContentLoaded', function () {

  // Mobile menu
  var toggle = document.querySelector('.nav-toggle');
  var nav = document.getElementById('main-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // Name fields: strip digits as the user types
  document.querySelectorAll('input[data-type="name"]').forEach(function (el) {
    el.addEventListener('input', function () {
      el.value = el.value.replace(/[0-9]/g, '');
    });
  });

  // Number-only fields (phone, age): strip letters as the user types
  document.querySelectorAll('input[data-type="digits"]').forEach(function (el) {
    el.addEventListener('input', function () {
      el.value = el.value.replace(/\D/g, '');
    });
  });

  // Money fields (rates): digits and one decimal point only
  document.querySelectorAll('input[data-type="money"]').forEach(function (el) {
    el.addEventListener('input', function () {
      el.value = el.value.replace(/[^0-9.]/g, '').replace(/(\..*)\./g, '$1');
    });
  });

  // Live password checklist (4 letters, 3 numbers, 1 special character, 8+ characters)
  var pw = document.getElementById('password');
  var rules = document.getElementById('pw-rules');
  if (pw && rules) {
    var update = function () {
      var v = pw.value;
      var checks = {
        len: v.length >= 8,
        letters: (v.match(/[A-Za-z]/g) || []).length >= 4,
        digits: (v.match(/[0-9]/g) || []).length >= 3,
        special: (v.match(/[^A-Za-z0-9]/g) || []).length >= 1
      };
      Object.keys(checks).forEach(function (k) {
        var li = rules.querySelector('[data-rule="' + k + '"]');
        if (li) li.classList.toggle('ok', checks[k]);
      });
    };
    pw.addEventListener('input', update);
    update();
  }

  // Show tutor levels only when "Tutor" is ticked
  var tutorBox = document.getElementById('tutor-box');
  var tutorLevels = document.getElementById('tutor-levels');
  if (tutorBox && tutorLevels) {
    tutorBox.addEventListener('change', function () { tutorLevels.hidden = !tutorBox.checked; });
  }

    // Eye button: lets people see the password they are typing (every password box)
  var eyeOpen = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  var eyeOff = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.94 17.94A10.9 10.9 0 0 1 12 19c-7 0-11-7-11-7a19.8 19.8 0 0 1 5.06-5.94M9.9 4.24A10.9 10.9 0 0 1 12 5c7 0 11 7 11 7a19.7 19.7 0 0 1-3.17 4.19M14.12 14.12A3 3 0 1 1 9.88 9.88"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
  document.querySelectorAll('input[type="password"]').forEach(function (input) {
    var wrap = document.createElement('div');
    wrap.className = 'pw-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pw-toggle';
    btn.innerHTML = eyeOpen;
    btn.setAttribute('aria-label', 'Show password');
    btn.setAttribute('aria-pressed', 'false');
    btn.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.innerHTML = show ? eyeOff : eyeOpen;
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      input.focus();
    });
    wrap.appendChild(btn);
  });

  // Confirm before destructive actions
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (ev) {
      if (!window.confirm(el.getAttribute('data-confirm'))) ev.preventDefault();
    });
  });
});