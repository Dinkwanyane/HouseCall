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

  // Confirm before destructive actions
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (ev) {
      if (!window.confirm(el.getAttribute('data-confirm'))) ev.preventDefault();
    });
  });
});