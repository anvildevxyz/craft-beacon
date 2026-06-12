(function () {
  var b = (window.__beacon = window.__beacon || {});
  b.onReady = function (fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  };
  b.t = function (msg, params) {
    return window.Craft && window.Craft.t
      ? window.Craft.t('beacon', msg, params || {})
      : msg;
  };
})();

(function () {
  if (window.__beaconConfirmFormBound) {
    return;
  }

  window.__beaconConfirmFormBound = true;
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) {
      return;
    }
    var message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
    }
  });
})();

(function () {
  if (window.__beaconToggleBound) {
    return;
  }
  if (typeof window.jQuery === 'undefined' || typeof window.Craft === 'undefined') {
    return;
  }

  window.__beaconToggleBound = true;
  var $ = window.jQuery;

  var ACTIONS = {
    bot: { action: 'beacon/ai-crawlers/toggle-bot', idParam: 'botId' },
    rule: { action: 'beacon/ai-crawlers/toggle-rule', idParam: 'ruleId' },
    schema: { action: 'beacon/schemas/toggle', idParam: 'schemaId' },
  };

  $(document).on('change', '[data-beacon-toggle]', function () {
    var $sw = $(this);
    var kind = $sw.attr('data-beacon-toggle');
    var id = $sw.attr('data-beacon-id');
    var config = ACTIONS[kind];
    if (!config || !id) {
      return;
    }
    var enabled = $sw.hasClass('on');
    var data = { enabled: enabled ? 1 : 0 };
    data[config.idParam] = id;

    Craft.sendActionRequest('POST', config.action, { data: data })
      .then(function () {
        Craft.cp.displayNotice(
          enabled ? Craft.t('beacon', 'cp.js.enabled') : Craft.t('beacon', 'cp.js.disabled')
        );
      })
      .catch(function () {
        Craft.cp.displayError(Craft.t('beacon', 'cp.js.could.not.save.change'));
        $sw.toggleClass('on');
        $sw.attr('aria-checked', $sw.hasClass('on') ? 'true' : 'false');
        var $hidden = $sw.find('input[type="hidden"]');
        if ($hidden.length) {
          $hidden.val($sw.hasClass('on') ? '1' : '');
        }
      });
  });
})();

/**
 * Guided schema-mapping builder for the Beacon "New/Edit schema" form.
 *
 * Progressive enhancement over the raw `mapping` JSON textarea: renders one
 * labelled input per schema.org property (grouped by required/recommended/
 * optional tier from the SchemaPropertyRegistry), re-renders reactively when
 * the schema type changes, can auto-fill suggested tokens, exposes a live
 * JSON-LD preview, and keeps the canonical textarea in sync so the form posts
 * exactly as before. Disabling JS falls back to editing the textarea directly.
 */
(function () {
  if (window.__beaconSchemaBuilderBound || typeof window.Craft === 'undefined') {
    return;
  }
  window.__beaconSchemaBuilderBound = true;

  var TIERS = [
    { key: 'required', label: 'Required' },
    { key: 'recommended', label: 'Recommended' },
    { key: 'optional', label: 'Optional' },
  ];

  var t = window.__beacon.t;

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text != null) {
      node.textContent = text;
    }
    return node;
  }

  function parseObject(str, fallback) {
    try {
      var v = JSON.parse(str);
      return v && typeof v === 'object' && !Array.isArray(v) ? v : fallback;
    } catch (e) {
      return fallback;
    }
  }

  function Builder(root) {
    this.root = root;
    this.registry = parseObject(root.getAttribute('data-registry'), {});
    this.typeSelect = document.querySelector(root.getAttribute('data-type-select'));
    this.entryTypeSelect = document.querySelector(root.getAttribute('data-entry-type-select'));
    this.textarea = document.getElementById('mapping');
    this.suggestAction = root.getAttribute('data-suggest-action');
    this.previewAction = root.getAttribute('data-preview-action');
    this.advanced = false;
    this.previewTimer = null;

    if (!this.typeSelect || !this.textarea) {
      return;
    }
    this.build();
    // Unparseable mapping on re-render: show raw JSON so input isn't silently reset.
    var initial = (this.textarea.value || '').trim();
    if (initial !== '' && initial !== '{}' && parseObject(initial, null) === null) {
      this.setAdvanced(true);
    } else {
      this.render();
    }
    this.refreshPreview();
  }

  Builder.prototype.currentType = function () {
    return this.typeSelect ? this.typeSelect.value : '';
  };

  Builder.prototype.propsForType = function () {
    var list = this.registry[this.currentType()];
    return Array.isArray(list) ? list : [];
  };

  Builder.prototype.build = function () {
    var self = this;
    this.root.innerHTML = '';

    var toolbar = el('div', 'bsb-toolbar');
    this.suggestBtn = el('button', 'btn', t('Suggest mappings'));
    this.suggestBtn.type = 'button';
    this.addBtn = el('button', 'btn', t('Add property'));
    this.addBtn.type = 'button';
    this.advancedBtn = el('button', 'btn', t('Edit raw JSON'));
    this.advancedBtn.type = 'button';
    toolbar.appendChild(this.suggestBtn);
    toolbar.appendChild(this.addBtn);
    toolbar.appendChild(this.advancedBtn);
    this.root.appendChild(toolbar);

    this.rowsEl = el('div', 'bsb-rows');
    this.root.appendChild(this.rowsEl);

    // Keep textarea inside builder so advanced toggle shows/hides it with help text.
    this.rawWrap = el('div', 'bsb-raw');
    this.rawWrap.hidden = true;
    this.textarea.parentNode.insertBefore(this.rawWrap, this.textarea);
    this.rawWrap.appendChild(this.textarea);
    var rawHelp = document.querySelector('[data-bsb-raw-help]');
    if (rawHelp) {
      this.rawWrap.appendChild(rawHelp);
    }


    var preview = el('div', 'bsb-preview');
    var head = el('div', 'bsb-preview-head');
    head.appendChild(el('h3', null, t('Preview')));
    this.refreshBtn = el('button', 'btn small', t('Refresh'));
    this.refreshBtn.type = 'button';
    head.appendChild(this.refreshBtn);
    preview.appendChild(head);
    this.previewNote = el('p', 'light bsb-preview-note');
    preview.appendChild(this.previewNote);
    this.previewPre = el('pre', 'bsb-preview-code');
    preview.appendChild(this.previewPre);
    this.root.appendChild(preview);

    this.suggestBtn.addEventListener('click', function () { self.suggest(); });
    this.addBtn.addEventListener('click', function () { self.addCustomRow('', '', true); });
    this.advancedBtn.addEventListener('click', function () { self.toggleAdvanced(); });
    this.refreshBtn.addEventListener('click', function () { self.refreshPreview(); });

    this.typeSelect.addEventListener('change', function () {
      self.render();
      self.refreshPreview();
    });
    if (this.entryTypeSelect) {
      this.entryTypeSelect.addEventListener('change', function () { self.refreshPreview(); });
    }
    this.textarea.addEventListener('input', function () {
      if (self.advanced) {
        self.schedulePreview();
      }
    });
    var form = this.root.closest('form');
    if (form) {
      form.addEventListener('submit', function () {
        if (!self.advanced) {
          self.serialize();
        }
      });
    }
  };

  /** Rebuilds the guided rows for the current type, preserving entered values. */
  Builder.prototype.render = function () {
    var mapping = parseObject(this.textarea.value, {});
    var props = this.propsForType();
    var known = {};
    var self = this;
    this.rowsEl.innerHTML = '';

    TIERS.forEach(function (tier) {
      var inTier = props.filter(function (p) { return (p.tier || 'optional') === tier.key; });
      if (!inTier.length) {
        return;
      }
      var optional = tier.key === 'optional';
      var group = el(optional ? 'details' : 'div', 'bsb-group');
      var heading = el(optional ? 'summary' : 'h4', 'bsb-group-head', t(tier.label));
      group.appendChild(heading);
      inTier.forEach(function (prop) {
        known[prop.name] = true;
        group.appendChild(self.propRow(prop, mapping[prop.name] || ''));
      });
      self.rowsEl.appendChild(group);
    });

    if (!props.length) {
      this.rowsEl.appendChild(el('p', 'light', t('No curated properties for this type — add properties manually or use raw JSON.')));
    }

    // Any mapping keys not covered by the registry become editable custom rows.
    var custom = Object.keys(mapping).filter(function (k) { return !known[k]; });
    if (custom.length) {
      var group = el('div', 'bsb-group');
      group.appendChild(el('h4', 'bsb-group-head', t('Custom properties')));
      this.rowsEl.appendChild(group);
      custom.forEach(function (k) { self.addCustomRow(k, mapping[k], false); });
    }
  };

  /** A registry-backed property row: fixed label + tier badge + help + value input. */
  Builder.prototype.propRow = function (prop, value) {
    var self = this;
    var row = el('div', 'bsb-row');
    var label = el('label', 'bsb-label');
    label.appendChild(el('span', 'bsb-name', prop.name));
    if (prop.tier) {
      label.appendChild(el('span', 'bsb-badge bsb-' + prop.tier, t(prop.tier.charAt(0).toUpperCase() + prop.tier.slice(1))));
    }
    row.appendChild(label);

    var input = el('input', 'text fullwidth');
    input.type = 'text';
    input.setAttribute('data-bsb-prop', prop.name);
    input.value = value || '';
    input.placeholder = (prop.suggest && prop.suggest[0]) ? '{' + prop.suggest[0] + '}' : '';
    input.addEventListener('input', function () { self.serialize(); self.schedulePreview(); });
    row.appendChild(input);

    if (prop.help) {
      row.appendChild(el('p', 'light bsb-help', prop.help));
    }
    return row;
  };

  /** A free-form property row with an editable key (for non-registry props). */
  Builder.prototype.addCustomRow = function (key, value, focus) {
    var self = this;
    var row = el('div', 'bsb-row bsb-row-custom');
    row.setAttribute('data-bsb-custom-row', '');

    var keyInput = el('input', 'text');
    keyInput.type = 'text';
    keyInput.setAttribute('data-bsb-key', '');
    keyInput.placeholder = t('property');
    keyInput.value = key || '';

    var valInput = el('input', 'text fullwidth');
    valInput.type = 'text';
    valInput.setAttribute('data-bsb-val', '');
    valInput.placeholder = '{title}';
    valInput.value = value || '';

    var remove = el('button', 'delete icon bsb-remove');
    remove.type = 'button';
    remove.title = t('Remove');

    [keyInput, valInput].forEach(function (inp) {
      inp.addEventListener('input', function () { self.serialize(); self.schedulePreview(); });
    });
    remove.addEventListener('click', function () {
      row.parentNode.removeChild(row);
      self.serialize();
      self.schedulePreview();
    });

    var pair = el('div', 'bsb-custom-pair');
    pair.appendChild(keyInput);
    pair.appendChild(valInput);
    pair.appendChild(remove);
    row.appendChild(pair);

    // Append into a dedicated custom group (create one if the user clicked "Add").
    var group = this.rowsEl.querySelector('.bsb-group-custom-host');
    if (!group) {
      group = el('div', 'bsb-group bsb-group-custom-host');
      group.appendChild(el('h4', 'bsb-group-head', t('Custom properties')));
      this.rowsEl.appendChild(group);
    }
    group.appendChild(row);
    if (focus) {
      keyInput.focus();
    }
  };

  /** Collapses the guided rows + custom rows into the canonical JSON textarea. */
  Builder.prototype.serialize = function () {
    var out = {};
    this.rowsEl.querySelectorAll('[data-bsb-prop]').forEach(function (inp) {
      var v = inp.value.trim();
      if (v !== '') {
        out[inp.getAttribute('data-bsb-prop')] = v;
      }
    });
    this.rowsEl.querySelectorAll('[data-bsb-custom-row]').forEach(function (row) {
      var k = row.querySelector('[data-bsb-key]').value.trim();
      var v = row.querySelector('[data-bsb-val]').value.trim();
      if (k !== '') {
        out[k] = v;
      }
    });
    this.textarea.value = JSON.stringify(out, null, 2);
  };

  Builder.prototype.toggleAdvanced = function () {
    this.setAdvanced(!this.advanced);
  };

  Builder.prototype.setAdvanced = function (on) {
    this.advanced = on;
    if (on) {
      // Only re-serialize from the rows when the current raw value still parses;
      // otherwise we'd clobber unparseable text the user needs to repair.
      if (parseObject(this.textarea.value, null) !== null) {
        this.serialize();
      }
    } else {
      this.render();
    }
    this.rawWrap.hidden = !on;
    this.rowsEl.hidden = on;
    this.suggestBtn.hidden = on;
    this.addBtn.hidden = on;
    this.advancedBtn.textContent = on ? t('Use guided editor') : t('Edit raw JSON');
  };

  Builder.prototype.suggest = function () {
    var self = this;
    var type = this.currentType();
    if (!type) {
      return;
    }
    this.suggestBtn.classList.add('loading');
    window.Craft.sendActionRequest('POST', this.suggestAction, {
      data: {
        schemaType: type,
        entryTypeHandle: this.entryTypeSelect ? this.entryTypeSelect.value : '',
      },
    }).then(function (res) {
      var suggested = (res.data && res.data.mapping) || {};
      var current = parseObject(self.textarea.value, {});
      Object.keys(suggested).forEach(function (k) { current[k] = suggested[k]; });
      self.textarea.value = JSON.stringify(current, null, 2);
      if (self.advanced) {
        self.schedulePreview();
      } else {
        self.render();
        self.refreshPreview();
      }
      window.Craft.cp.displayNotice(t('Suggested mappings applied.'));
    }).catch(function () {
      window.Craft.cp.displayError(t('Could not suggest mappings.'));
    }).then(function () {
      self.suggestBtn.classList.remove('loading');
    });
  };

  Builder.prototype.schedulePreview = function () {
    var self = this;
    if (this.previewTimer) {
      window.clearTimeout(this.previewTimer);
    }
    this.previewTimer = window.setTimeout(function () { self.refreshPreview(); }, 500);
  };

  Builder.prototype.refreshPreview = function () {
    var self = this;
    if (!this.previewAction) {
      return;
    }
    if (!this.advanced) {
      this.serialize();
    }
    this.previewPre.classList.add('loading');
    window.Craft.sendActionRequest('POST', this.previewAction, {
      data: {
        schemaType: this.currentType(),
        entryTypeHandle: this.entryTypeSelect ? this.entryTypeSelect.value : '',
        mapping: this.textarea.value,
      },
    }).then(function (res) {
      var d = res.data || {};
      if (d.error) {
        self.setPreview(d.error, '');
      } else if (!d.jsonld) {
        self.setPreview(t('This mapping produces no output for the sample entry yet.'), '');
      } else {
        var note = d.hasSample
          ? t('Rendered against sample entry:') + ' ' + (d.sampleTitle || '')
          : t('No sample entry of this type exists yet — values resolve as empty.');
        self.setPreview(note, d.jsonld);
      }
    }).catch(function () {
      self.setPreview(t('Preview unavailable.'), '');
    }).then(function () {
      self.previewPre.classList.remove('loading');
    });
  };

  Builder.prototype.setPreview = function (note, code) {
    this.previewNote.textContent = note || '';
    this.previewPre.textContent = code || '';
    this.previewPre.hidden = !code;
  };

  function boot() {
    var root = document.querySelector('[data-beacon-schema-builder]');
    if (root && !root.__bsbInit) {
      root.__bsbInit = true;
      new Builder(root);
    }
  }

  window.__beacon.onReady(boot);
})();

(function () {
  if (window.__beaconIdentityTypeBound) {
    return;
  }
  window.__beaconIdentityTypeBound = true;

  function boot() {
    var select = document.getElementById('beacon-identity-type');
    if (!select) {
      return;
    }
    function sync() {
      var isPerson = select.value === 'Person';
      document.querySelectorAll('.beacon-identity-person-only').forEach(function (el) {
        el.hidden = !isPerson;
      });
      document.querySelectorAll('.beacon-identity-org-only').forEach(function (el) {
        el.hidden = isPerson;
      });
    }
    select.addEventListener('change', sync);
    sync();
  }

  window.__beacon.onReady(boot);
})();

(function () {
  if (window.__beaconTokenPillsBound) {
    return;
  }
  window.__beaconTokenPillsBound = true;

  function boot() {
    document.querySelectorAll('.beacon-token-pill').forEach(function (btn) {
      if (btn.__beaconTokenPillBound) {
        return;
      }
      btn.__beaconTokenPillBound = true;
      btn.addEventListener('click', function () {
        var field = btn.closest('.beacon-field');
        var input = field ? field.querySelector('.beacon-token-input') : null;
        if (!input) {
          return;
        }
        var token = btn.getAttribute('data-token');
        var start = input.selectionStart != null ? input.selectionStart : input.value.length;
        var end = input.selectionEnd != null ? input.selectionEnd : input.value.length;
        input.value = input.value.slice(0, start) + token + input.value.slice(end);
        var pos = start + token.length;
        input.focus();
        input.setSelectionRange(pos, pos);
        input.dispatchEvent(new Event('input', { bubbles: true }));
      });
    });
  }

  window.__beacon.onReady(boot);
})();

(function () {
  if (window.__beaconShortlinkQrBound) {
    return;
  }
  window.__beaconShortlinkQrBound = true;

  var t = window.__beacon.t;

  function init() {
    var el = document.getElementById('beacon-shortlink-qr');
    if (!el || el.__beaconQrInit || typeof window.qrcode !== 'function') {
      return !!el && el.__beaconQrInit;
    }
    var url = el.getAttribute('data-url');
    if (!url) {
      return true;
    }
    el.__beaconQrInit = true;
    try {
      var qr = window.qrcode(0, 'M');
      qr.addData(url);
      qr.make();
      var img = document.createElement('div');
      img.innerHTML = qr.createSvgTag(4);
      el.appendChild(img);
    } catch (e) {
      el.appendChild(document.createTextNode(t('(QR render failed)')));
    }
    return true;
  }

  function boot(attempt) {
    if (init()) {
      return;
    }
    if (attempt >= 40) {
      return;
    }
    window.setTimeout(function () { boot(attempt + 1); }, 50);
  }

  function start() {
    boot(0);
  }

  window.__beacon.onReady(start);
  window.addEventListener('load', start);
})();

(function () {
  if (window.__beaconSchemaIndexBound || typeof window.Craft === 'undefined') {
    return;
  }
  window.__beaconSchemaIndexBound = true;

  var t = window.__beacon.t;

  function boot() {
    if (window.Sortable) {
      document.querySelectorAll('.beacon-schema-table tbody').forEach(function (tbody) {
        if (tbody.__beaconSortableInit) {
          return;
        }
        tbody.__beaconSortableInit = true;
        window.Sortable.create(tbody, {
          animation: 150,
          handle: '.beacon-drag-handle',
          dataIdAttr: 'data-id',
          onEnd: function () {
            window.Craft.sendActionRequest('POST', 'beacon/schemas/reorder', {
              data: { ids: this.toArray() },
            }).then(function () {
              window.Craft.cp.displayNotice(t('Order saved.'));
            }).catch(function () {
              window.Craft.cp.displayError(t('Reorder failed. Refresh and try again.'));
            });
          },
        });
      });
    }

    document.querySelectorAll('.beacon-schema-delete').forEach(function (btn) {
      if (btn.__beaconSchemaDeleteBound) {
        return;
      }
      btn.__beaconSchemaDeleteBound = true;
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-schema-id');
        var label = btn.getAttribute('data-schema-label') || t('this schema');
        if (!window.confirm(t('Delete the {label} schema?', { label: label }))) {
          return;
        }
        window.Craft.sendActionRequest('POST', 'beacon/schemas/delete', {
          data: { schemaId: id },
        }).then(function () {
          var row = btn.closest('tr');
          var table = btn.closest('table');
          var group = btn.closest('.beacon-schema-group');
          if (row) {
            row.parentNode.removeChild(row);
          }
          if (table && !table.querySelector('tbody tr') && group) {
            group.parentNode.removeChild(group);
          }
          window.Craft.cp.displayNotice(t('Schema deleted.'));
        }).catch(function () {
          window.Craft.cp.displayError(t('Could not delete schema.'));
        });
      });
    });
  }

  window.__beacon.onReady(boot);
})();

(function () {
  if (window.__beaconRedirectSourcesBound) {
    return;
  }
  window.__beaconRedirectSourcesBound = true;

  var t = window.__beacon.t;

  function addRow(wrap) {
    var list = wrap.querySelector('.beacon-redirect-sources__list');
    var fieldName = wrap.getAttribute('data-field-name');
    if (!list || !fieldName) {
      return;
    }
    var row = document.createElement('div');
    row.className = 'beacon-redirect-sources__row';
    var input = document.createElement('input');
    input.type = 'text';
    input.name = fieldName + '[]';
    input.className = 'text fullwidth';
    input.placeholder = t('/old/path');
    input.autocomplete = 'off';
    input.value = '';
    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn small beacon-redirect-sources__remove';
    remove.setAttribute('aria-label', t('Remove'));
    remove.textContent = '×';
    row.appendChild(input);
    row.appendChild(remove);
    list.appendChild(row);
    input.focus();
  }

  document.addEventListener('click', function (e) {
    var target = e.target;
    if (!target || !target.classList) {
      return;
    }

    if (target.classList.contains('beacon-redirect-sources__add')) {
      var wrap = target.closest('.beacon-redirect-sources');
      if (wrap) {
        addRow(wrap);
      }
      return;
    }

    if (target.classList.contains('beacon-redirect-sources__remove')) {
      var row = target.closest('.beacon-redirect-sources__row');
      if (!row) {
        return;
      }
      var list = row.parentNode;
      var sourcesWrap = list && list.closest('.beacon-redirect-sources');
      row.parentNode.removeChild(row);
      if (list && sourcesWrap && !list.querySelector('.beacon-redirect-sources__row')) {
        var marker = document.createElement('input');
        marker.type = 'hidden';
        marker.name = sourcesWrap.getAttribute('data-field-name') + '[]';
        marker.value = '';
        list.appendChild(marker);
      }
    }
  });
})();

(function () {
  if (window.__beaconGeoRecomputeBound || typeof window.Craft === 'undefined') {
    return;
  }
  window.__beaconGeoRecomputeBound = true;

  var t = window.__beacon.t;

  function boot() {
    document.querySelectorAll('[data-beacon-recompute]').forEach(function (btn) {
      if (btn.__beaconRecomputeBound) {
        return;
      }
      btn.__beaconRecomputeBound = true;
      btn.addEventListener('click', function () {
        btn.disabled = true;
        var status = document.querySelector('[data-beacon-recompute-status]');
        if (status) {
          status.textContent = t('Queuing recompute…');
        }
        window.Craft.sendActionRequest('POST', 'beacon/geo-score/recompute', {
          data: {
            elementId: btn.dataset.elementId,
            siteId: btn.dataset.siteId,
          },
        }).then(function (resp) {
          if (status) {
            status.textContent = (resp.data && resp.data.message) ? resp.data.message : t('Done.');
          }
          window.setTimeout(function () { window.location.reload(); }, 3000);
        }).catch(function (err) {
          if (status) {
            status.textContent = (err.response && err.response.data && err.response.data.message)
              || err.message
              || t('Error');
          }
          btn.disabled = false;
        });
      });
    });
  }

  window.__beacon.onReady(boot);
})();

(function () {
  if (window.__beaconTrackingFiltersBound || typeof window.Craft === 'undefined') {
    return;
  }
  window.__beaconTrackingFiltersBound = true;

  function boot() {
    var table = document.getElementById('tracking-scripts-table');
    if (!table) {
      return;
    }
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
    var filters = document.querySelectorAll('[data-tracking-filter]');
    var countEl = document.querySelector('[data-tracking-count]');
    var emptyEl = document.querySelector('[data-tracking-empty]');
    var clearEl = document.querySelector('[data-tracking-clear]');

    function values() {
      var v = { provider: '', placement: '', search: '' };
      filters.forEach(function (el) {
        v[el.getAttribute('data-tracking-filter')] = (el.value || '').trim().toLowerCase();
      });
      return v;
    }

    function apply() {
      var v = values();
      var active = !!(v.provider || v.placement || v.search);
      var shown = 0;
      rows.forEach(function (tr) {
        var okProvider = !v.provider || tr.getAttribute('data-provider').toLowerCase() === v.provider;
        var placements = (tr.getAttribute('data-placement') || '').toLowerCase().split(' ');
        var okPlacement = !v.placement || placements.indexOf(v.placement) !== -1;
        var okSearch = !v.search || (tr.getAttribute('data-name') || '').indexOf(v.search) !== -1;
        var show = okProvider && okPlacement && okSearch;
        tr.hidden = !show;
        if (show) {
          shown++;
        }
      });
      if (countEl) {
        countEl.textContent = window.__beacon.t('cp.js.shown.of.total.shown', {
          shown: shown,
          total: rows.length,
        });
      }
      if (emptyEl) {
        emptyEl.hidden = shown !== 0;
      }
      if (clearEl) {
        clearEl.hidden = !active;
      }
      table.hidden = shown === 0;
    }

    filters.forEach(function (el) {
      el.addEventListener('change', apply);
      el.addEventListener('input', apply);
    });
    if (clearEl) {
      clearEl.addEventListener('click', function () {
        filters.forEach(function (el) { el.value = ''; });
        apply();
      });
    }
    apply();
  }

  window.__beacon.onReady(boot);
})();

(function () {
  if (window.__beaconSiteSelectorBound) {
    return;
  }
  window.__beaconSiteSelectorBound = true;

  document.addEventListener('change', function (e) {
    var target = e.target;
    if (!target || !target.matches || !target.matches('[data-beacon-site-selector]')) {
      return;
    }
    var base = target.getAttribute('data-beacon-site-selector') || '';
    if (!base) {
      return;
    }
    try {
      var url = new URL(base, window.location.origin);
      url.searchParams.set('site', target.value);
      window.location.href = url.toString();
    } catch (err) {
      var sep = base.indexOf('?') === -1 ? '?' : '&';
      window.location.href = base + sep + 'site=' + encodeURIComponent(target.value);
    }
  });
})();

(function () {
  if (window.__beacon404BulkBound) {
    return;
  }
  window.__beacon404BulkBound = true;

  var t = window.__beacon.t;

  function boot() {
    var form = document.getElementById('beacon-404-bulk');
    if (!form) {
      return;
    }
    var all = document.getElementById('beacon-404-all');
    var rows = form.querySelectorAll('.beacon-404-row');
    var apply = document.getElementById('beacon-404-mark-handled');
    var count = document.getElementById('beacon-404-count');
    if (!all || !apply || !count) {
      return;
    }
    function refresh() {
      var n = 0;
      rows.forEach(function (cb) { if (cb.checked) { n++; } });
      count.textContent = n ? (n + ' ' + t('selected')) : '';
      apply.disabled = (n === 0);
    }
    all.addEventListener('change', function () {
      rows.forEach(function (cb) { cb.checked = all.checked; });
      refresh();
    });
    rows.forEach(function (cb) { cb.addEventListener('change', refresh); });
  }

  window.__beacon.onReady(boot);
})();
