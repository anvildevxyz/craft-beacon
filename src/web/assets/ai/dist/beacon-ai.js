/**
 * Beacon — AI "Generate" affordances for the SEO field.
 *
 * Loaded only when AI generation is enabled (the asset bundle is registered
 * conditionally), so the mere presence of this script means the feature is on.
 * It reads the entry/site ids from the field container's existing data
 * attributes and talks to the beacon/ai-content/* controller actions. Nothing
 * is saved automatically — generated values are written into the inputs (or a
 * copyable panel) for the editor to accept or discard.
 */
(function () {
  'use strict';

  function t(message) {
    return window.Craft && Craft.t ? Craft.t('beacon', message) : message;
  }

  function post(action, payload) {
    var url = Craft.getActionUrl(action);
    return fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-Token': Craft.csrfTokenValue,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(payload),
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok) {
          throw new Error((data && (data.error || data.message)) || 'Request failed');
        }
        return data;
      });
    });
  }

  function makeButton(label) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn small beacon-ai-btn';
    btn.textContent = '✨ ' + label;
    btn.style.marginTop = '5px';
    return btn;
  }

  function withBusy(btn, promise) {
    var original = btn.textContent;
    btn.disabled = true;
    btn.textContent = t('ai.generating');
    return promise
      .catch(function (err) {
        if (window.Craft && Craft.cp) {
          Craft.cp.displayError(err.message || String(err));
        }
      })
      .then(function () {
        btn.disabled = false;
        btn.textContent = original;
      });
  }

  function setInputValue(input, value) {
    if (!input) return;
    input.value = value;
    // Fire input + change so the char meters and Craft's dirty tracking react.
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function showPanel(field, title, text) {
    var panel = document.createElement('div');
    panel.className = 'beacon-ai-panel';
    panel.style.cssText = 'margin-top:8px;padding:8px;border:1px solid var(--hairline-color,#e3e5e8);border-radius:4px;background:var(--gray-050,#f9f9f9);';
    var heading = document.createElement('strong');
    heading.textContent = title;
    var pre = document.createElement('pre');
    pre.style.cssText = 'white-space:pre-wrap;margin:6px 0;font-size:12px;';
    pre.textContent = text;
    var copy = makeButton(t('action.copy') || 'Copy');
    copy.textContent = 'Copy';
    copy.addEventListener('click', function () {
      navigator.clipboard && navigator.clipboard.writeText(text);
    });
    panel.appendChild(heading);
    panel.appendChild(pre);
    panel.appendChild(copy);
    field.appendChild(panel);
  }

  function initField(container) {
    if (container.getAttribute('data-beacon-ai-ready') === '1') return;
    container.setAttribute('data-beacon-ai-ready', '1');

    var entryId = container.getAttribute('data-bp-entry-id');
    var siteId = container.getAttribute('data-bp-site-id');
    if (!entryId) return; // unsaved entry — nothing to ground a prompt on yet

    var base = { entryId: entryId, siteId: siteId };
    var titleInput = container.querySelector('input[name$="[title]"]');
    var descInput = container.querySelector('textarea[name$="[description]"], input[name$="[description]"]');

    if (titleInput) {
      var tBtn = makeButton(t('ai.generate'));
      tBtn.addEventListener('click', function () {
        withBusy(tBtn, post('beacon/ai-content/generate-title', base).then(function (d) {
          setInputValue(titleInput, d.value);
        }));
      });
      titleInput.insertAdjacentElement('afterend', tBtn);
    }

    if (descInput) {
      var dBtn = makeButton(t('ai.generate'));
      dBtn.addEventListener('click', function () {
        withBusy(dBtn, post('beacon/ai-content/generate-description', base).then(function (d) {
          setInputValue(descInput, d.value);
        }));
      });
      descInput.insertAdjacentElement('afterend', dBtn);

      // Summary + FAQ live next to the description as copyable output.
      var sBtn = makeButton('Summary');
      sBtn.textContent = '✨ TL;DR';
      sBtn.addEventListener('click', function () {
        withBusy(sBtn, post('beacon/ai-content/generate-summary', base).then(function (d) {
          showPanel(container, 'TL;DR', d.value);
        }));
      });
      dBtn.insertAdjacentElement('afterend', sBtn);

      var fBtn = makeButton('FAQ');
      fBtn.textContent = '✨ FAQ';
      fBtn.addEventListener('click', function () {
        withBusy(fBtn, post('beacon/ai-content/generate-faq', base).then(function (d) {
          var lines = (d.faq || []).map(function (q) { return 'Q: ' + q.question + '\nA: ' + q.answer; }).join('\n\n');
          showPanel(container, 'FAQ + FAQPage JSON-LD', lines + '\n\n' + JSON.stringify(d.schema, null, 2));
        }));
      });
      sBtn.insertAdjacentElement('afterend', fBtn);
    }
  }

  function boot() {
    document.querySelectorAll('[data-beacon-seo-field]').forEach(initField);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
