/* Beacon SEO field — entity (Wikidata) picker.
 *
 * Progressive enhancement over a container rendered by
 * templates/_seo-field/_entities.twig. Reads its config from
 * [data-beacon-entities] (input name + search action URL + existing rows),
 * renders a typeahead, and writes picked entities as hidden inputs named
 * `<name>[entities][<i>][<key>]` so they post with the field. No build step.
 */
(function () {
  'use strict';

  function t(key) {
    return (window.Craft && Craft.t) ? Craft.t('beacon', key) : key;
  }

  function el(tag, attrs, text) {
    var node = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) { node.setAttribute(k, attrs[k]); });
    }
    if (text != null) { node.textContent = text; }
    return node;
  }

  function hidden(name, value) {
    var i = document.createElement('input');
    i.type = 'hidden';
    i.name = name;
    i.value = value == null ? '' : String(value);
    return i;
  }

  var KEYS = ['qid', 'label', 'description', 'wikidataUrl', 'wikipediaUrl', 'officialUrl', 'role'];

  function Picker(container) {
    this.container = container;
    this.name = container.getAttribute('data-name');
    this.searchUrl = container.getAttribute('data-search-url');
    this.rows = [];
    this.searchTimer = null;

    try {
      var seed = JSON.parse(container.getAttribute('data-entities') || '[]');
      if (Array.isArray(seed)) { this.rows = seed; }
    } catch (e) { /* ignore malformed seed */ }

    this.list = el('div', { class: 'beacon-entities-list' });
    this.input = el('input', {
      type: 'text',
      class: 'text fullwidth',
      placeholder: t('entities.search.placeholder'),
      autocomplete: 'off',
    });
    this.results = el('div', { class: 'beacon-entities-results', hidden: 'hidden' });
    this.status = el('div', { class: 'beacon-entities-status light' });

    var manual = el('button', { type: 'button', class: 'btn small' }, t('entities.addManual'));
    var self = this;
    manual.addEventListener('click', function () { self.addManual(); });

    container.appendChild(this.list);
    container.appendChild(this.input);
    container.appendChild(this.results);
    container.appendChild(this.status);
    container.appendChild(manual);

    this.input.addEventListener('input', function () { self.onType(); });
    document.addEventListener('click', function (e) {
      if (!container.contains(e.target)) { self.hideResults(); }
    });

    this.render();
  }

  Picker.prototype.onType = function () {
    var self = this;
    var q = this.input.value.trim();
    window.clearTimeout(this.searchTimer);
    if (q.length < 2) { this.hideResults(); return; }
    this.status.textContent = t('entities.searching');
    this.searchTimer = window.setTimeout(function () { self.search(q); }, 250);
  };

  Picker.prototype.search = function (q) {
    var self = this;
    var url = this.searchUrl + (this.searchUrl.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(q);
    fetch(url, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then(function (data) { self.showResults((data && data.results) || []); })
      .catch(function () { self.showResults([]); });
  };

  Picker.prototype.showResults = function (results) {
    var self = this;
    this.status.textContent = '';
    this.results.innerHTML = '';
    if (!results.length) {
      this.results.appendChild(el('div', { class: 'beacon-entities-result light' }, t('entities.noResults')));
    } else {
      results.forEach(function (r) {
        var item = el('button', { type: 'button', class: 'beacon-entities-result' });
        item.appendChild(el('strong', null, r.label || r.qid));
        if (r.description) { item.appendChild(el('span', { class: 'light' }, ' — ' + r.description)); }
        item.addEventListener('click', function () {
          self.add(r);
          self.input.value = '';
          self.hideResults();
        });
        self.results.appendChild(item);
      });
    }
    this.results.removeAttribute('hidden');
  };

  Picker.prototype.hideResults = function () {
    this.results.setAttribute('hidden', 'hidden');
  };

  Picker.prototype.add = function (r) {
    if (r.qid) {
      var dupe = this.rows.some(function (row) { return row.qid && row.qid === r.qid; });
      if (dupe) { return; }
    }
    this.rows.push({
      qid: r.qid || '',
      label: r.label || '',
      description: r.description || '',
      wikidataUrl: r.wikidataUrl || '',
      wikipediaUrl: r.wikipediaUrl || '',
      officialUrl: r.officialUrl || '',
      role: 'about',
    });
    this.render();
  };

  Picker.prototype.addManual = function () {
    var url = window.prompt(t('entities.manualUrlPrompt'));
    if (!url) { return; }
    url = url.trim();
    if (!url) { return; }
    var label = window.prompt('Label');
    this.rows.push({
      qid: '', label: (label || url).trim(), description: '',
      wikidataUrl: '', wikipediaUrl: '', officialUrl: url, role: 'about',
    });
    this.render();
  };

  Picker.prototype.render = function () {
    var self = this;
    this.list.innerHTML = '';
    this.rows.forEach(function (row, i) {
      var item = el('div', { class: 'beacon-entities-item' });

      var label = el('span', { class: 'beacon-entities-label' }, row.label);
      item.appendChild(label);

      var role = el('select', { class: 'beacon-entities-role' });
      [['about', t('entities.about')], ['mentions', t('entities.mentions')]].forEach(function (opt) {
        var o = el('option', { value: opt[0] }, opt[1]);
        if (row.role === opt[0]) { o.setAttribute('selected', 'selected'); }
        role.appendChild(o);
      });
      role.addEventListener('change', function () {
        self.rows[i].role = role.value;
        self.syncHidden();
      });
      item.appendChild(role);

      var remove = el('button', { type: 'button', class: 'delete icon' }, t('entities.remove'));
      remove.addEventListener('click', function () {
        self.rows.splice(i, 1);
        self.render();
      });
      item.appendChild(remove);

      self.list.appendChild(item);
    });
    this.syncHidden();
  };

  Picker.prototype.syncHidden = function () {
    var old = this.container.querySelectorAll('input[type=hidden][data-beacon-entity-input]');
    old.forEach(function (n) { n.parentNode.removeChild(n); });

    var self = this;
    this.rows.forEach(function (row, i) {
      KEYS.forEach(function (key) {
        var input = hidden(self.name + '[entities][' + i + '][' + key + ']', row[key] || '');
        input.setAttribute('data-beacon-entity-input', '1');
        self.container.appendChild(input);
      });
    });
  };

  function init() {
    document.querySelectorAll('[data-beacon-entities]').forEach(function (c) {
      if (c.getAttribute('data-beacon-entities-ready')) { return; }
      c.setAttribute('data-beacon-entities-ready', '1');
      new Picker(c);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
