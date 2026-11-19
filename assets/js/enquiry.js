/**
 * Enquiry form: loads the captcha question, checks fields, sends to forms/enquiry.php
 * and shows the real result. On hosts that cannot run PHP (GitHub Pages) it shows an email link instead.
 */
(function () {
  'use strict';

  var form = document.getElementById('enquiry-form');
  if (!form) return;

  var ENDPOINT = form.getAttribute('action');
  var fallback = document.getElementById('enquiry-fallback');
  var status = document.getElementById('enq-status');
  var submit = document.getElementById('enq-submit');
  var question = document.getElementById('enq-captcha-q');
  var captchaInput = document.getElementById('enq-captcha');
  var refresh = document.getElementById('enq-captcha-new');

  function track(name, params) {
    if (typeof window.pbTrack === 'function') window.pbTrack(name, params || {});
  }

  var modalEl = document.getElementById('enquiryModal');
  var direct = modalEl ? modalEl.querySelector('.enq-direct') : null;

  function sectionOf(el) {
    var sec = el && el.closest ? el.closest('section') : null;
    if (!sec) return 'link';
    return sec.id || (sec.className || '').split(' ')[0] || 'page';
  }

  // Opens the pop-up from a link such as pratikbutani.com/#enquiry
  function openFromHash() {
    if (location.hash === '#enquiry' && modalEl && window.bootstrap) {
      // Bootstrap 5.0 on this site has getInstance but not getOrCreateInstance.
      (window.bootstrap.Modal.getInstance(modalEl) || new window.bootstrap.Modal(modalEl)).show();
    }
  }
  window.addEventListener('hashchange', openFromHash);
  if (document.readyState === 'complete') openFromHash();
  else window.addEventListener('load', openFromHash);

  if (modalEl) {
    modalEl.addEventListener('show.bs.modal', function (e) {
      track('enquiry_open', { section: sectionOf(e.relatedTarget) });
    });
    modalEl.addEventListener('hidden.bs.modal', function () {
      if (location.hash === '#enquiry') history.replaceState(null, '', location.pathname + location.search);
    });
  }

  // GitHub Pages serves PHP files as plain text, so the form cannot work there.
  if (/\.github\.io$/.test(location.hostname) || location.protocol === 'file:') {
    form.hidden = true;
    if (fallback) fallback.hidden = false;
    if (direct) direct.hidden = true;
    return;
  }

  var captchaLoaded = false;
  var captchaLoading = null;

  function setStatus(type, text) {
    status.className = 'enq-status' + (type ? ' is-' + type : '');
    status.textContent = text || '';
  }

  function loadCaptcha() {
    if (captchaLoading) return captchaLoading;
    question.textContent = 'loading…';
    captchaLoading = fetch(ENDPOINT + '?action=captcha', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok || !data.captcha) throw new Error('bad captcha response');
        question.textContent = data.captcha;
        captchaInput.value = '';
        captchaLoaded = true;
      })
      .catch(function () {
        question.textContent = 'unavailable';
        setStatus('error', 'The form could not load. Please email me at pratik13butani@gmail.com instead.');
        track('form_error', { form: 'enquiry', reason: 'captcha_load' });
      })
      .then(function () { captchaLoading = null; });
    return captchaLoading;
  }

  // Load the question when the pop-up opens, not on every page view.
  if (modalEl) {
    modalEl.addEventListener('show.bs.modal', function () {
      if (status.classList.contains('is-success')) setStatus('', '');
      if (!captchaLoaded) loadCaptcha();
    });
    modalEl.addEventListener('shown.bs.modal', function () {
      var first = form.elements.name;
      if (first && !first.value) first.focus();
    });
  } else {
    loadCaptcha();
  }

  refresh.addEventListener('click', function () {
    loadCaptcha();
    captchaInput.focus();
  });

  var started = false;
  form.addEventListener('input', function () {
    if (!started) {
      started = true;
      track('form_start', { form: 'enquiry' });
    }
  });

  function markInvalid(field) {
    form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
    var el = field && form.elements[field];
    if (el && el.classList) {
      el.classList.add('is-invalid');
      el.focus();
    }
  }

  function checkLocally() {
    var checks = [
      ['name', function (v) { return v.trim().length >= 2; }, 'Please enter your name.'],
      ['email', function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim()); }, 'Please enter a valid email address.'],
      ['help_type', function (v) { return v !== ''; }, 'Please choose what you need help with.'],
      ['message', function (v) { return v.trim().length >= 20; }, 'Please tell me a little more, at least 20 characters.'],
      ['captcha', function (v) { return /^-?\d{1,3}$/.test(v.trim()); }, 'Please answer the quick check.']
    ];
    for (var i = 0; i < checks.length; i++) {
      var el = form.elements[checks[i][0]];
      if (!checks[i][1](el.value || '')) return { field: checks[i][0], error: checks[i][2] };
    }
    return null;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    var problem = checkLocally();
    if (problem) {
      setStatus('error', problem.error);
      markInvalid(problem.field);
      return;
    }
    markInvalid(null);

    submit.disabled = true;
    submit.classList.add('is-sending');
    setStatus('', 'Sending…');

    var helpType = form.elements.help_type.value;

    fetch(ENDPOINT, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
      .then(function (r) {
        return r.json().catch(function () { return { ok: false, error: 'Unexpected reply from the server.' }; });
      })
      .then(function (data) {
        if (data && data.ok) {
          form.reset();
          setStatus('success', 'Thanks, your message is on its way. I will reply to the email you gave.');
          track('generate_lead', { form: 'enquiry', help_type: helpType });
          loadCaptcha();
          return;
        }
        data = data || {};
        setStatus('error', data.error || 'Your message could not be sent. Please email me at pratik13butani@gmail.com.');
        if (data.captcha) {
          question.textContent = data.captcha;
          captchaInput.value = '';
        }
        markInvalid(data.field || null);
        track('form_error', { form: 'enquiry', reason: data.field || 'server' });
      })
      .catch(function () {
        setStatus('error', 'Network problem. Please check your connection, or email me at pratik13butani@gmail.com.');
        track('form_error', { form: 'enquiry', reason: 'network' });
      })
      .then(function () {
        submit.disabled = false;
        submit.classList.remove('is-sending');
      });
  });
})();
