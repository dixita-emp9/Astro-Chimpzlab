<?php
/**
 * @var string $apiKey
 * @var string $ts
 * @var string $sig
 * @var string $endpoint
 */
?>
(function () {
  var KEY = <?= json_encode($apiKey) ?>;
  var TS = <?= json_encode($ts) ?>;
  var SIG = <?= json_encode($sig) ?>;
  var ENDPOINT = <?= json_encode($endpoint) ?>;

  var STYLE_ID = 'microcrm-widget-style';
  if (!document.getElementById(STYLE_ID)) {
    var style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent =
      '.microcrm-form{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;max-width:420px}' +
      '.microcrm-form .mc-field{margin-bottom:12px}' +
      '.microcrm-form label{display:block;font-size:13px;font-weight:600;margin-bottom:4px;color:#222}' +
      '.microcrm-form input,.microcrm-form textarea{width:100%;box-sizing:border-box;padding:8px 10px;' +
        'font-size:14px;border:1px solid #ccc;border-radius:6px}' +
      '.microcrm-form button{background:#2f6feb;color:#fff;border:0;border-radius:6px;padding:10px 18px;' +
        'font-size:14px;font-weight:600;cursor:pointer}' +
      '.microcrm-form button:disabled{opacity:.6;cursor:default}' +
      '.microcrm-form .mc-status{margin-top:10px;font-size:13px;color:#b00020}' +
      '.microcrm-form .mc-success{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1a7f37}';
    document.head.appendChild(style);
  }

  function buildForm(container) {
    container.innerHTML =
      '<form class="microcrm-form" novalidate>' +
        '<div class="mc-field"><label>Name</label><input type="text" name="name" required></div>' +
        '<div class="mc-field"><label>Email</label><input type="email" name="email" required></div>' +
        '<div class="mc-field"><label>Phone</label><input type="tel" name="phone"></div>' +
        '<div class="mc-field"><label>Message</label><textarea name="message" rows="4"></textarea></div>' +
        '<div style="position:absolute;left:-9999px;top:-9999px" aria-hidden="true">' +
          '<label>Leave this field blank</label>' +
          '<input type="text" name="_hp" tabindex="-1" autocomplete="off">' +
        '</div>' +
        '<button type="submit">Send</button>' +
        '<div class="mc-status" role="status" aria-live="polite"></div>' +
      '</form>';

    var form = container.querySelector('form');
    var status = container.querySelector('.mc-status');
    var button = form.querySelector('button');

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      status.textContent = '';
      button.disabled = true;

      var params = new URLSearchParams();
      new FormData(form).forEach(function (value, key) {
        params.append(key, value);
      });
      params.append('api_key', KEY);
      params.append('_ts', TS);
      params.append('_sig', SIG);
      params.append('_ajax', '1');

      fetch(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
      })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          if (json && json.success) {
            container.innerHTML = '<p class="mc-success">' + (json.message || 'Thanks!') + '</p>';
          } else {
            button.disabled = false;
            status.textContent = (json && json.error) || 'Something went wrong. Please try again.';
          }
        })
        .catch(function () {
          button.disabled = false;
          status.textContent = 'Network error. Please try again.';
        });
    });
  }

  function init() {
    var containers = document.querySelectorAll('[data-microcrm="' + KEY + '"]');
    for (var i = 0; i < containers.length; i++) {
      buildForm(containers[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
