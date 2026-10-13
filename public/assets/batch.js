/* Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. */
/* Batch screen: poll the small status endpoint every 15 seconds while items are in flight; reload when totals change. */
(function () {
  'use strict';

  var box = document.querySelector('[data-batch-status]');
  if (!box || box.getAttribute('data-in-flight') !== '1') { return; }

  var url = box.getAttribute('data-batch-status');
  var last = box.getAttribute('data-totals');

  function poll() {
    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (json) {
        if (!json) { setTimeout(poll, 15000); return; }
        if (JSON.stringify(json.totals) !== last) { window.location.reload(); return; }
        if (json.active) { setTimeout(poll, 15000); }
      })
      .catch(function () { setTimeout(poll, 15000); });
  }

  setTimeout(poll, 15000);
}());
