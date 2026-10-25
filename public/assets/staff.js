/* Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. */
/*
 * Small conveniences on staff pages. Every page works without this script:
 * - [data-print]: the poster's Print button.
 * - Password fields get a "Show" button (M8 L13).
 * - The field form hides "Choices" while the type is "Short text" (M8 L3).
 * - The exhibition form shows the package notes while the package box is ticked (M9 CO-4).
 * - The visitor filters open on wide screens (M9 CO-14).
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

  // Exhibition form: the package notes show while "Offers a welcome package" is ticked (M9 CO-4).
  var offers = document.querySelector('[data-package-toggle]');
  var notes = document.querySelector('[data-package-notes]');
  if (offers && notes) {
    var syncNotes = function () { notes.hidden = !offers.checked; };
    offers.addEventListener('change', syncNotes);
    syncNotes();
  }

  // Visitor filters: open on wide screens in browsers that cannot style the fold's content (M9 CO-14).
  var filters = document.querySelector('.filter-details');
  if (filters && window.matchMedia && window.matchMedia('(min-width: 40rem)').matches) {
    filters.open = true;
  }

  // M10 CO-5: "Please specify" shows while the choice that asks for details is selected.
  function syncDetails() {
    var boxes = document.querySelectorAll('[data-details-for]');
    for (var i = 0; i < boxes.length; i++) {
      var key = boxes[i].getAttribute('data-details-for');
      var wanted = boxes[i].getAttribute('data-details-value');
      var select = document.querySelector('select[name="' + key + '"]');
      var radio = document.querySelector('input[type=radio][name="' + key + '"]:checked');
      var chosen = select ? select.value : (radio ? radio.value : '');
      boxes[i].hidden = chosen !== wanted;
    }
  }
  document.addEventListener('change', syncDetails);
  syncDetails();

  var type = document.querySelector('form select[name=type]');
  var choices = document.querySelector('[data-choices]');
  if (type && choices) {
    var sync = function () { choices.hidden = type.value !== 'select'; };
    type.addEventListener('change', sync);
    sync();
  }
}());
