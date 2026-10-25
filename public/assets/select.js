/* Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. */
/* Visitor selection: a visible count and "select all on this page" only (work order §7). */
(function () {
  'use strict';

  var form = document.querySelector('form[data-select]');
  if (!form) { return; }

  var all = form.querySelector('[data-select-all]');
  var count = form.querySelector('[data-select-count]');
  var submits = form.querySelectorAll('[data-select-submit]');
  var rows = form.querySelectorAll('[data-select-row]');

  function update() {
    var n = 0;
    for (var i = 0; i < rows.length; i++) { if (rows[i].checked) { n++; } }
    count.textContent = n + ' selected';
    for (var s = 0; s < submits.length; s++) { submits[s].disabled = n === 0; }
    all.checked = n > 0 && n === rows.length;
    all.indeterminate = n > 0 && n < rows.length;
  }

  all.addEventListener('change', function () {
    for (var i = 0; i < rows.length; i++) { rows[i].checked = all.checked; }
    update();
  });
  for (var i = 0; i < rows.length; i++) { rows[i].addEventListener('change', update); }

  // A page restored from the back/forward cache starts with nothing selected.
  window.addEventListener('pageshow', function () {
    for (var i = 0; i < rows.length; i++) { rows[i].checked = false; }
    update();
  });
  update();
}());
