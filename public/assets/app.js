/* Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. */
/* Forms work without JavaScript; this only stops a second press while a form is sending. */
(function () {
  'use strict';

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form.hasAttribute('data-once')) { return; }
    var buttons = form.querySelectorAll('button[type=submit]');
    for (var i = 0; i < buttons.length; i++) { buttons[i].disabled = true; }
  });

  // Coming back with the browser's Back button must not leave the button disabled.
  window.addEventListener('pageshow', function () {
    var buttons = document.querySelectorAll('form[data-once] button[type=submit]');
    for (var i = 0; i < buttons.length; i++) { buttons[i].disabled = false; }
  });
}());
