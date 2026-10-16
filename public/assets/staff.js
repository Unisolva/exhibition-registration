/* Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. */
/*
 * Small conveniences on staff pages. Every page works without this script:
 * - [data-print]: the poster's Print button.
 * - Password fields get a "Show" button (M8 L13).
 * - The field form hides "Choices" while the type is "Short text" (M8 L3).
 * Nothing is written to browser storage.
 */
(function () {
  'use strict';

  var print = document.querySelector('[data-print]');
  if (print) {
    print.addEventListener('click', function () { window.print(); });
  }

  var passwords = document.querySelectorAll('input[type=password]');
  for (var i = 0; i < passwords.length; i++) {
    (function (input) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn btn-secondary password-toggle';
      button.textContent = 'Show';
      button.setAttribute('aria-controls', input.id);
      button.setAttribute('aria-pressed', 'false');
      button.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.textContent = show ? 'Hide' : 'Show';
        button.setAttribute('aria-pressed', show ? 'true' : 'false');
        input.focus();
      });
      var wrap = document.createElement('div');
      wrap.className = 'password-wrap';
      input.parentNode.insertBefore(wrap, input);
      wrap.appendChild(input);
      wrap.appendChild(button);
    }(passwords[i]));
  }

  var type = document.querySelector('form select[name=type]');
  var choices = document.querySelector('[data-choices]');
  if (type && choices) {
    var sync = function () { choices.hidden = type.value !== 'select'; };
    type.addEventListener('change', sync);
    sync();
  }
}());
