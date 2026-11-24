// Small progressive enhancements. Every rule is also enforced on the server.
(function () {
  'use strict';

  // Ask for confirmation before destructive or irreversible actions: <button data-confirm="...">
  document.addEventListener('submit', function (event) {
    var form = event.target;
    var submitter = event.submitter || form.querySelector('[data-confirm]');
    var message = (submitter && submitter.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
      return;
    }
    // Prevent double submission of the same form.
    if (form.dataset.submitted === '1') {
      event.preventDefault();
      return;
    }
    form.dataset.submitted = '1';
    window.setTimeout(function () { form.dataset.submitted = ''; }, 4000);
  });

  // Print buttons: <button data-print>
  document.querySelectorAll('[data-print]').forEach(function (btn) {
    btn.addEventListener('click', function () { window.print(); });
  });

  // Character counters: <textarea data-maxlength-counter="id-of-counter" maxlength="...">
  document.querySelectorAll('[data-counter]').forEach(function (field) {
    var counter = document.getElementById(field.getAttribute('data-counter'));
    var max = parseInt(field.getAttribute('maxlength'), 10);
    if (!counter || !max) return;
    var update = function () { counter.textContent = field.value.length + ' / ' + max; };
    field.addEventListener('input', update);
    update();
  });

  // Verification page: poll until Gmail confirms the account, then open the dashboard.
  document.querySelectorAll('[data-verify-poll]').forEach(function (el) {
    var url = el.getAttribute('data-verify-poll');
    var badge = document.getElementById('verify-live-badge');
    var status = document.getElementById('verify-live-status');
    if (!url) return;
    var timer = window.setInterval(function () {
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (data) {
          if (!data || !data.verified || !data.redirect) return;
          window.clearInterval(timer);
          if (badge) {
            badge.className = 'status status-done';
            badge.textContent = 'Verified';
          }
          if (status) status.textContent = 'Gmail confirmed. Opening your account…';
          window.location.assign(data.redirect);
        })
        .catch(function () {});
    }, 3000);
  });

  // Status action forms: show the assignee / remarks fields relevant to the chosen action.
  document.querySelectorAll('[data-action-select]').forEach(function (select) {
    var form = select.closest('form');
    var toggle = function () {
      form.querySelectorAll('[data-show-for]').forEach(function (el) {
        var list = el.getAttribute('data-show-for').split('|');
        el.hidden = list.indexOf(select.value) === -1;
      });
    };
    select.addEventListener('change', toggle);
    toggle();
  });
})();
