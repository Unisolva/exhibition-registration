/* Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. */
/*
 * Save & New (work order §7). The form also works without this script (plain
 * POST and redirect). Rules: values are cleared only after the server answers
 * 201 (saved) or 200 (replay of a saved entry); every retry resends the same
 * submission token, so a lost response never creates a second visitor.
 * Nothing is written to browser storage. After a save the name is shown; a
 * possible duplicate stays as a warning until the next save starts (M8 CO-4).
 */
(function () {
  'use strict';

  var form = document.querySelector('form[data-entry]');
  if (!form) { return; }

  var TIMEOUT_MS = 10000;
  var SAVED_MS = 4000;
  var saveButton = form.querySelector('[data-save]');
  var retryButton = form.querySelector('[data-retry]');
  var saveNewButton = form.querySelector('[data-save-new]');
  var statusBox = document.getElementById('entry-status');
  var tokenInput = form.querySelector('input[name=submission_token]');
  var csrfMeta = document.querySelector('meta[name=csrf-token]');
  var busy = false;
  var needsFreshCsrf = false;
  var replacementToken = null;
  var savedTimer = null;
  var savedCount = 0;
  var countLine = document.querySelector('[data-saved-count]');

  function csrfToken() { return csrfMeta ? csrfMeta.getAttribute('content') : ''; }

  function setCsrf(token) {
    if (csrfMeta) { csrfMeta.setAttribute('content', token); }
    var hidden = form.querySelector('input[name=_token]');
    if (hidden) { hidden.value = token; }
  }

  function show(kind, text, link) {
    clearTimeout(savedTimer);
    statusBox.innerHTML = '';
    if (!text) { return null; }
    var div = document.createElement('div');
    div.className = 'notice notice-' + kind;
    div.textContent = text;
    if (link) {
      var a = document.createElement('a');
      a.href = link.href;
      a.textContent = link.text;
      div.appendChild(document.createTextNode(' '));
      div.appendChild(a);
    }
    statusBox.appendChild(div);
    return div;
  }

  function setBusy(on) {
    busy = on;
    saveButton.disabled = on;
    retryButton.disabled = on;
    saveNewButton.disabled = on;
  }

  function fieldInputs() {
    return form.querySelectorAll('.entry-fields input, .entry-fields select');
  }

  function clearFieldErrors() {
    var inputs = fieldInputs();
    for (var i = 0; i < inputs.length; i++) {
      inputs[i].removeAttribute('aria-invalid');
      inputs[i].removeAttribute('aria-describedby');
      var old = document.getElementById(inputs[i].id + '-error');
      if (old) { old.parentNode.removeChild(old); }
    }
  }

  function showFieldErrors(errors) {
    clearFieldErrors();
    var first = null;
    for (var key in errors) {
      if (!Object.prototype.hasOwnProperty.call(errors, key)) { continue; }
      var input = form.querySelector('.entry-fields [name="' + key + '"]');
      if (!input) { show('err', errors[key]); continue; }
      var div = document.createElement('div');
      div.className = 'error';
      div.id = input.id + '-error';
      div.textContent = errors[key];
      input.setAttribute('aria-invalid', 'true');
      input.setAttribute('aria-describedby', div.id);
      input.parentNode.appendChild(div);
      if (!first) { first = input; }
    }
    if (first) { first.focus(); }
  }

  function clearVisitorFields() {
    var inputs = fieldInputs();
    for (var i = 0; i < inputs.length; i++) {
      if (inputs[i].tagName === 'SELECT') { inputs[i].selectedIndex = 0; } else { inputs[i].value = ''; }
    }
    clearFieldErrors();
  }

  function focusName() {
    var name = form.querySelector('.entry-fields [name=name]');
    if (name) { name.focus(); }
  }

  function payload() {
    var data = {};
    var elements = form.elements;
    for (var i = 0; i < elements.length; i++) {
      var el = elements[i];
      if (!el.name || el.disabled || el.type === 'submit' || el.type === 'button') { continue; }
      data[el.name] = el.value;
    }
    return JSON.stringify(data);
  }

  function retryState(message) {
    retryButton.hidden = false;
    saveNewButton.hidden = true;
    show('err', message);
  }

  function onSaved(json) {
    replacementToken = null;
    needsFreshCsrf = false;
    retryButton.hidden = true;
    saveNewButton.hidden = true;
    tokenInput.value = json.next_token;
    clearVisitorFields();
    var saved = 'Saved ' + (json.visitor_name || 'the entry') + '.';
    if (json.possible_duplicate) {
      // Stays until the next save starts.
      show('warn', saved + ' This phone number is already registered at this exhibition.',
        json.duplicate_url ? { href: json.duplicate_url, text: 'View earlier entry' } : null);
    } else {
      var notice = show('ok', saved);
      // Hidden, not removed, so the form below does not move.
      savedTimer = setTimeout(function () { notice.style.visibility = 'hidden'; }, SAVED_MS);
    }
    savedCount += 1;
    if (countLine) {
      countLine.textContent = savedCount + ' saved in this session';
      countLine.hidden = false;
    }
    focusName();
  }

  function send() {
    if (busy) { return; }
    setBusy(true);
    show('warn', 'Saving…');

    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    var timer = setTimeout(function () { if (controller) { controller.abort(); } }, TIMEOUT_MS);

    fetch(form.action, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken()
      },
      body: payload(),
      signal: controller ? controller.signal : undefined
    }).then(function (response) {
      clearTimeout(timer);
      var status = response.status;
      return response.json().catch(function () { return {}; }).then(function (json) {
        setBusy(false);
        if (status === 201 || (status === 200 && json.status === 'replay')) {
          onSaved(json);
        } else if (status === 422) {
          retryButton.hidden = true;
          show('err', (json.errors && json.errors._form) || 'Please check the fields marked below.');
          showFieldErrors(json.errors || {});
        } else if (status === 401 || status === 419) {
          needsFreshCsrf = true;
          retryState('You were signed out. Sign in again in a new tab, then come back and press Retry. What you typed is still here.');
        } else if (status === 409) {
          // The token was already used with other values: saving again needs a new token.
          replacementToken = json.next_token || null;
          retryButton.hidden = true;
          saveNewButton.hidden = !replacementToken;
          show('err', json.message || 'This entry was already saved with different details.');
        } else {
          retryState('Not saved (server error ' + status + '). Your values are kept; press Retry.');
        }
      });
    }).catch(function () {
      clearTimeout(timer);
      setBusy(false);
      retryState('No answer from the server (connection lost or too slow). Your values are kept; press Retry.');
    });
  }

  function retry() {
    if (busy) { return; }
    if (!needsFreshCsrf) { send(); return; }

    setBusy(true);
    fetch(form.getAttribute('data-session-url'), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (response) {
      setBusy(false);
      if (response.status !== 200) {
        retryState('Still signed out. Sign in in a new tab, then press Retry.');
        return null;
      }
      return response.json().then(function (json) {
        setCsrf(json.csrf_token);
        needsFreshCsrf = false;
        send();
      });
    }).catch(function () {
      setBusy(false);
      retryState('No answer from the server. Press Retry when the connection is back.');
    });
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    retryButton.hidden = true;
    send();
  });
  retryButton.addEventListener('click', retry);
  saveNewButton.addEventListener('click', function () {
    if (replacementToken) { tokenInput.value = replacementToken; replacementToken = null; }
    saveNewButton.hidden = true;
    send();
  });
}());
