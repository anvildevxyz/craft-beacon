/**
 * Beacon SEO field — char meters, SEO checklist, plus the modal-based
 * structured-data (JSON-LD) editor.
 *
 * The form persists schema add-ons as flat hidden inputs:
 *
 *   <input type="hidden" name="<field>[schemaAddons][i][type]"    value="Article">
 *   <input type="hidden" name="<field>[schemaAddons][i][mapping]" value='{"headline":"{entry.title}"}'>
 *
 * The inline UI shows a compact "card" per schema with type + validation +
 * a one-line property summary; clicking Edit opens a Garnish.Modal with the
 * full property/source picker, the Suggest button, and a JSON-LD preview.
 * Saving the modal writes back to the hidden inputs and re-renders the card.
 */
(function() {
    'use strict';

    var rootEl = document.documentElement;
    if (rootEl.dataset.beaconSeoFieldBound === '1') {
        return;
    }
    rootEl.dataset.beaconSeoFieldBound = '1';

    // Default fallback when the field config blob doesn't ship a `types` list.
    // The server-side per-field config (built by BeaconSeoField::getInputHtml)
    // is authoritative — admins can extend the registry via the schema
    // component config in Plugin.php to add NewsArticle, Event, etc.
    var DEFAULT_TYPES = ['Article', 'Product', 'Recipe', 'HowTo', 'FAQPage', 'Review'];
    var TIER_MARKER = { required: '★', recommended: '◇', optional: '' };
    var TIER_LABEL = { required: Craft.t('beacon', 'seoField.js.required'), recommended: Craft.t('beacon', 'seoField.js.recommended'), optional: Craft.t('beacon', 'seoField.js.optional') };
    var SOURCE_GROUP_LABEL = { entry: Craft.t('beacon', 'seoField.js.entry.attributes'), seo: Craft.t('beacon', 'seoField.js.seo.field'), field: Craft.t('beacon', 'seoField.js.custom.fields') };
    var CUSTOM_PROPERTY = '__custom';
    var CUSTOM_TEMPLATE = '__template';

    function cssEscape(str) {
        if (window.CSS && CSS.escape) return CSS.escape(str);
        return String(str).replace(/"/g, '\\"');
    }

    function isLiteMode(fieldEl) {
        return !!(fieldEl && fieldEl.getAttribute('data-seo-lite-mode') === '1');
    }

    function fieldPrefix(fieldEl) {
        if (!fieldEl) return '';
        var prefix = fieldEl.getAttribute('data-field-prefix') || '';
        if (prefix) return prefix;
        var checklist = fieldEl.querySelector('[data-seo-checklist]');
        return checklist ? (checklist.getAttribute('data-field-prefix') || '') : '';
    }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function(k) {
                if (k === 'class') node.className = attrs[k];
                else if (k === 'text') node.textContent = attrs[k];
                else if (k === 'html') node.innerHTML = attrs[k];
                else node.setAttribute(k, attrs[k]);
            });
        }
        if (children) children.forEach(function(c) { if (c) node.appendChild(c); });
        return node;
    }

    function readFieldConfig(fieldName) {
        var sel = '[data-beacon-seo-field-config="' + cssEscape(fieldName) + '"]';
        var node = document.querySelector(sel);
        if (!node) return { sources: [], properties: {}, entryId: null };
        try { return JSON.parse(node.textContent || '{}'); }
        catch (e) { return { sources: [], properties: {}, entryId: null }; }
    }

    function safeJsonParse(value) {
        if (!value) return {};
        try {
            var parsed = JSON.parse(value);
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
        } catch (e) { return {}; }
    }


    function updateMeter(meter) {
        var name = meter.getAttribute('data-meter-name');
        if (!name) return;
        var input = document.querySelector('[name="' + cssEscape(name) + '"]');
        if (!input) {
            var suffix = meter.getAttribute('data-meter-suffix');
            var scope = meter.closest('.beacon-seo-field');
            if (suffix && scope) {
                input = scope.querySelector('[name$="' + suffix.replace(/"/g, '\\"') + '"]');
            }
        }
        if (!input) return;
        var len = (input.value || '').length;
        var min = +meter.getAttribute('data-meter-min') || 0;
        var max = +meter.getAttribute('data-meter-max') || 0;
        var countEl = meter.querySelector('.beacon-seo-meter-count');
        if (countEl) countEl.textContent = len;
        meter.classList.remove('is-ok', 'is-over');
        if (len > max) meter.classList.add('is-over');
        else if (len >= min) meter.classList.add('is-ok');
        var fill = meter.querySelector('.beacon-seo-meter-fill');
        if (fill && max > 0) fill.style.width = Math.min(100, (len / max) * 100) + '%';
    }

    function refreshMeters(root) {
        (root || document).querySelectorAll('.beacon-seo-meter').forEach(updateMeter);
    }


    /**
     * Locate the SEO field's named input. Craft auto-namespaces field inputs
     * to `fields[<handle>][...]` at render time, so a literal selector like
     * `[name="<handle>[title]"]` won't match. We scope the search to the
     * field's container and suffix-match instead, falling back to a global
     * lookup as a last resort.
     */
    function queryByName(prefix, suffix, scope) {
        scope = scope || document;
        var selector = '[name$="' + suffix.replace(/"/g, '\\"') + '"]';
        var candidates = scope.querySelectorAll(selector);
        for (var i = 0; i < candidates.length; i++) {
            var name = candidates[i].name || '';
            if (name.endsWith(prefix + suffix) || name.endsWith(']' + suffix) || name === prefix + suffix) {
                return candidates[i];
            }
        }
        return scope.querySelector(selector) || null;
    }

    // Robots directives render a hidden companion <input value=""> before each
    // checkbox (so an unchecked box still posts), sharing the checkbox's name.
    // queryByName() would resolve to that hidden input, whose .checked is always
    // false — so read the checkbox itself.
    function robotsCheckbox(suffix, scope) {
        return (scope || document).querySelector(
            '.beacon-seo-robots input[type="checkbox"][name$="' + suffix.replace(/"/g, '\\"') + '"]'
        );
    }

    function boolCheck(listEl, key, pass) {
        var item = listEl.querySelector('[data-seo-check="' + key + '"]');
        if (!item) return 0;
        item.classList.remove('is-pass', 'is-fail');
        item.classList.add(pass ? 'is-pass' : 'is-fail');
        return pass ? 1 : 0;
    }

    function updateChecklist(root) {
        (root || document).querySelectorAll('[data-seo-checklist]').forEach(function(listEl) {
            var prefix = listEl.getAttribute('data-field-prefix') || '';
            if (!prefix) return;
            var fieldEl = listEl.closest('.beacon-seo-field');
            var titleEl = queryByName(prefix, '[title]', fieldEl);
            var descriptionEl = queryByName(prefix, '[description]', fieldEl);
            var canonicalEl = queryByName(prefix, '[canonical]', fieldEl);
            var noindexEl = robotsCheckbox('[robots][noindex]', fieldEl);
            var nosnippetEl = robotsCheckbox('[robots][nosnippet]', fieldEl);
            var schemaCount = fieldEl ? fieldEl.querySelectorAll('[data-beacon-schema-card]').length : 0;

            // The emitted SEO title falls back to the entry title when the SEO
            // Title input is blank (BeaconVariable replicates this on render),
            // so the checklist mirrors that fallback rather than reporting the
            // input as empty.
            var entryTitleEl = document.querySelector('input[type="text"][name="title"]');
            var seoTitle = titleEl ? (titleEl.value || '').trim() : '';
            var title = seoTitle !== '' ? seoTitle : (entryTitleEl ? (entryTitleEl.value || '').trim() : '');
            var description = descriptionEl ? (descriptionEl.value || '').trim() : '';
            var canonical = canonicalEl ? (canonicalEl.value || '').trim() : '';
            var hasNoindex = !!(noindexEl && noindexEl.checked);
            var hasNosnippet = !!(nosnippetEl && nosnippetEl.checked);

            var checks = {
                titleLength: title.length >= 50 && title.length <= 60,
                descriptionLength: description.length >= 150 && description.length <= 160,
                canonical: canonical === '' || /^https?:\/\/.+/i.test(canonical),
                robots: !(hasNoindex && hasNosnippet),
                schema: schemaCount > 0,
                social: title.length > 0 && description.length > 0
            };
            var passed = 0;
            passed += boolCheck(listEl, 'titleLength', checks.titleLength);
            passed += boolCheck(listEl, 'descriptionLength', checks.descriptionLength);
            passed += boolCheck(listEl, 'canonical', checks.canonical);
            passed += boolCheck(listEl, 'robots', checks.robots);
            passed += boolCheck(listEl, 'schema', checks.schema);
            passed += boolCheck(listEl, 'social', checks.social);

            var score = Math.round((passed / 6) * 100);
            var scoreEl = listEl.querySelector('[data-seo-score]');
            if (scoreEl) scoreEl.textContent = String(score);

            // Source-trace badges (where the resolved value comes from)
            if (fieldEl && !isLiteMode(fieldEl)) {
                updateSourceBadge(fieldEl.querySelector('[data-bp-source-badge="title"]'), seoTitle !== '', !!entryTitleEl && !!entryTitleEl.value);
                updateSourceBadge(fieldEl.querySelector('[data-bp-source-badge="description"]'), description !== '', false);
                // Source-of-truth lines under each input (only when blank)
                fieldEl.querySelectorAll('[data-bp-source-line]').forEach(function(line) {
                    var which = line.getAttribute('data-bp-source-line');
                    line.classList.toggle('is-visible', shouldShowSourceLine(fieldEl, which));
                });
                updatePreviewCallout(fieldEl);
            }
        });
    }


    /**
     * Map each checklist key to a "fix me" handler: scroll its target input
     * into view and focus it so the author lands exactly where the issue is.
     * Schema/social checks scroll to the relevant section instead of a single
     * input. Wired once per field (idempotent via dataset).
     */
    function bindChecklistDeepLinks(root) {
        (root || document).querySelectorAll('[data-seo-checklist]').forEach(function(listEl) {
            if (listEl.dataset.bpDeeplinksBound === '1') return;
            listEl.dataset.bpDeeplinksBound = '1';
            var prefix = listEl.getAttribute('data-field-prefix') || '';
            var fieldEl = listEl.closest('.beacon-seo-field');
            if (!fieldEl) return;

            listEl.querySelectorAll('[data-seo-check]').forEach(function(item) {
                item.setAttribute('role', 'button');
                item.setAttribute('tabindex', '0');
                item.classList.add('is-actionable');
                var fix = function() { focusChecklistTarget(item.getAttribute('data-seo-check'), prefix, fieldEl); };
                item.addEventListener('click', fix);
                item.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fix(); }
                });
            });
        });
    }

    function focusChecklistTarget(key, prefix, fieldEl) {
        var inputName = {
            titleLength: '[title]',
            descriptionLength: '[description]',
            canonical: '[canonical]',
            social: '[title]',
        }[key];
        if (inputName) {
            var input = queryByName(prefix, inputName, fieldEl);
            if (input) { scrollAndFocus(input); return; }
        }
        if (key === 'robots') {
            var robots = fieldEl.querySelector('.beacon-seo-robots');
            if (robots) {
                scrollIntoView(robots);
                var firstCheckbox = robots.querySelector('input[type="checkbox"]');
                if (firstCheckbox) firstCheckbox.focus();
            }
            return;
        }
        if (key === 'schema') {
            var addBtn = fieldEl.querySelector('[data-beacon-add-schema]');
            if (addBtn) { scrollAndFocus(addBtn); return; }
        }
    }

    function scrollIntoView(el) {
        if (!el) return;
        try { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        catch (e) { el.scrollIntoView(); }
    }
    function scrollAndFocus(el) {
        scrollIntoView(el);
        // Small delay so the smooth-scroll completes before focus, avoiding
        // browsers that re-scroll on focus and undo the smooth landing.
        setTimeout(function() { try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); } }, 250);
    }


    /**
     * Create or fetch a sibling `.beacon-seo-hint` for an input. We attach
     * messages here rather than as `title` tooltips so authors see them
     * without hovering.
     */
    function ensureHintSlot(input) {
        if (!input) return null;
        var wrapper = input.closest('.field') || input.parentElement;
        if (!wrapper) return null;
        var hint = wrapper.querySelector(':scope > .beacon-seo-hint');
        if (!hint) {
            hint = document.createElement('div');
            hint.className = 'beacon-seo-hint';
            hint.setAttribute('aria-live', 'polite');
            wrapper.appendChild(hint);
        }
        return hint;
    }

    function setHint(input, level, text) {
        var hint = ensureHintSlot(input);
        if (!hint) return;
        hint.classList.remove('is-warn', 'is-error', 'is-info');
        if (!text) { hint.textContent = ''; hint.removeAttribute('data-active'); return; }
        hint.classList.add('is-' + (level || 'warn'));
        hint.textContent = text;
        hint.setAttribute('data-active', '1');
    }

    function bindInlineValidation(root) {
        (root || document).querySelectorAll('.beacon-seo-field').forEach(function(fieldEl) {
            if (fieldEl.dataset.bpInlineBound === '1') return;
            fieldEl.dataset.bpInlineBound = '1';

            var prefix = fieldPrefix(fieldEl);
            var lite = isLiteMode(fieldEl);

            var titleEl = queryByName(prefix, '[title]', fieldEl);
            var descEl = queryByName(prefix, '[description]', fieldEl);
            var canonicalEl = queryByName(prefix, '[canonical]', fieldEl);
            var frontMatterEl = queryByName(prefix, '[aiMarkdown][customFrontMatter]', fieldEl);

            function validateTitleSoft() {
                if (!titleEl) return;
                var len = (titleEl.value || '').length;
                if (len === 0) { setHint(titleEl, null, ''); return; }
                if (len > 60) setHint(titleEl, 'warn', Craft.t('beacon', 'seoField.js.tip.titles.over.60.characters'));
                else if (len < 30) setHint(titleEl, 'info', Craft.t('beacon', 'seoField.js.short.titles.can.underperform.aim'));
                else setHint(titleEl, null, '');
            }
            function validateDescSoft() {
                if (!descEl) return;
                var len = (descEl.value || '').length;
                if (len === 0) { setHint(descEl, null, ''); return; }
                if (len > 165) setHint(descEl, 'warn', Craft.t('beacon', 'seoField.js.tip.descriptions.over.165.characters'));
                else if (len < 120) setHint(descEl, 'info', Craft.t('beacon', 'seoField.js.below.120.characters.most.serps'));
                else setHint(descEl, null, '');
            }
            function validateCanonical() {
                if (!canonicalEl) return;
                var v = (canonicalEl.value || '').trim();
                if (v === '') { setHint(canonicalEl, null, ''); canonicalEl.classList.remove('is-invalid'); return; }
                var ok = /^https?:\/\/[^\s]+$/i.test(v);
                if (!ok) { setHint(canonicalEl, 'error', Craft.t('beacon', 'seoField.js.canonical.must.absolute.url.beginning')); canonicalEl.classList.add('is-invalid'); }
                else { setHint(canonicalEl, null, ''); canonicalEl.classList.remove('is-invalid'); }
            }
            function validateFrontMatter() {
                if (!frontMatterEl) return;
                var raw = (frontMatterEl.value || '').trim();
                if (raw === '') { setHint(frontMatterEl, null, ''); return; }
                var lines = raw.split(/\r?\n/);
                var bad = [];
                for (var i = 0; i < lines.length; i++) {
                    var line = lines[i].trim();
                    if (line === '' || line.charAt(0) === '#') continue;
                    if (!/^[A-Za-z][A-Za-z0-9_-]*:\s*\S/.test(line)) bad.push(i + 1);
                }
                if (bad.length) setHint(frontMatterEl, 'warn', Craft.t('beacon', 'seoField.js.line.expected.key.value.format', { line: bad[0] }));
                else setHint(frontMatterEl, null, '');
            }
            function validateRobots() {
                var noindexEl = robotsCheckbox('[robots][noindex]', fieldEl);
                var nosnippetEl = robotsCheckbox('[robots][nosnippet]', fieldEl);
                var noarchiveEl = robotsCheckbox('[robots][noarchive]', fieldEl);
                var notice = fieldEl.querySelector('[data-bp-robots-warn]');
                if (!notice) return;
                var ni = noindexEl && noindexEl.checked;
                var ns = nosnippetEl && nosnippetEl.checked;
                var na = noarchiveEl && noarchiveEl.checked;
                var msgs = [];
                if (ni && ns) msgs.push(Craft.t('beacon', 'seoField.js.noindex.nosnippet.together.also.blocks'));
                if (ni && na) msgs.push(Craft.t('beacon', 'seoField.js.noindex.noarchive.redundant.noindex.already'));
                notice.textContent = msgs.join(' ');
                notice.classList.toggle('is-active', msgs.length > 0);
            }

            if (!lite && titleEl) {
                titleEl.addEventListener('blur', validateTitleSoft);
                titleEl.addEventListener('input', function() { if (ensureHintSlot(titleEl).getAttribute('data-active')) validateTitleSoft(); });
            }
            if (!lite && descEl) {
                descEl.addEventListener('blur', validateDescSoft);
                descEl.addEventListener('input', function() { if (ensureHintSlot(descEl).getAttribute('data-active')) validateDescSoft(); });
            }
            if (canonicalEl) {
                canonicalEl.addEventListener('blur', validateCanonical);
                canonicalEl.addEventListener('input', function() { if (canonicalEl.classList.contains('is-invalid')) validateCanonical(); });
            }
            if (frontMatterEl) {
                frontMatterEl.addEventListener('blur', validateFrontMatter);
                frontMatterEl.addEventListener('input', function() { if (ensureHintSlot(frontMatterEl).getAttribute('data-active')) validateFrontMatter(); });
            }
            fieldEl.querySelectorAll('.beacon-seo-robots input[type="checkbox"]').forEach(function(cb) {
                cb.addEventListener('change', validateRobots);
            });
            validateRobots();

            if (!lite) {
                fieldEl.querySelectorAll('[data-bp-source-line]').forEach(function(line) {
                    var which = line.getAttribute('data-bp-source-line');
                    line.classList.toggle('is-visible', shouldShowSourceLine(fieldEl, which));
                });
            }
        });
    }

    function shouldShowSourceLine(fieldEl, which) {
        if (isLiteMode(fieldEl)) return false;
        var prefix = fieldPrefix(fieldEl);
        var key = which === 'title' ? '[title]' : '[description]';
        var input = queryByName(prefix, key, fieldEl);
        return !!(input && (input.value || '') === '');
    }


    function updatePreviewCallout(fieldEl) {
        var callout = fieldEl.querySelector('[data-bp-missing-fields]');
        if (!callout) return;
        var checklist = fieldEl.querySelector('[data-seo-checklist]');
        var prefix = checklist ? (checklist.getAttribute('data-field-prefix') || '') : '';
        var titleEl = queryByName(prefix, '[title]', fieldEl);
        var descEl = queryByName(prefix, '[description]', fieldEl);
        var ogInp = fieldEl.querySelector('[name$="[ogImageId][]"]') || fieldEl.querySelector('[name$="[ogImageId]"]');
        var hasEntryImage = ogInp && ogInp.value !== '' && ogInp.value !== '0';
        var inheritedImage = fieldEl.querySelector('[data-bp-inherited-image]');
        var hasFallbackImage = inheritedImage && !inheritedImage.hasAttribute('hidden');

        var entryTitleEl = document.querySelector('input[type="text"][name="title"]');
        var rawTitle = titleEl ? (titleEl.value || '').trim() : '';
        var effectiveTitle = rawTitle || (entryTitleEl ? (entryTitleEl.value || '').trim() : '');
        var description = descEl ? (descEl.value || '').trim() : '';

        var items = [];
        if (!effectiveTitle) items.push(Craft.t('beacon', 'seoField.js.title.empty.google.social.cards'));
        if (!description) items.push(Craft.t('beacon', 'seoField.js.description.empty.slack.discord.facebook'));
        if (!hasEntryImage && !hasFallbackImage) items.push(Craft.t('beacon', 'seoField.js.no.social.image.open.graph'));

        callout.innerHTML = '';
        if (!items.length) { callout.hidden = true; return; }
        callout.hidden = false;
        var heading = document.createElement('strong');
        heading.textContent = Craft.t('beacon', 'seoField.js.preview.gaps');
        callout.appendChild(heading);
        var ul = document.createElement('ul');
        items.forEach(function(text) {
            var li = document.createElement('li');
            li.textContent = text;
            ul.appendChild(li);
        });
        callout.appendChild(ul);
    }

    /**
     * Paint the entry/section/global/fallback badge based on whether the
     * editor has overridden the value and the resolver's sourceMap. When the
     * resolver tells us where the value actually came from (passed in via
     * `source`), we trust that over the local heuristic.
     */
    function updateSourceBadge(badge, hasEntryValue, hasEntryTitleFallback, source) {
        if (!badge) return;
        var hasSection = badge.getAttribute('data-bp-has-section') === '1';
        var cls, label;
        if (hasEntryValue) {
            cls = 'is-entry'; label = Craft.t('beacon', 'seoField.js.entry.override');
        } else if (source === 'section' || (source === undefined && hasSection)) {
            cls = 'is-section'; label = Craft.t('beacon', 'seoField.js.section.default');
        } else if (source === 'entry' || (source === undefined && hasEntryTitleFallback)) {
            cls = 'is-fallback'; label = Craft.t('beacon', 'seoField.js.entry.title.fallback');
        } else {
            cls = 'is-global'; label = Craft.t('beacon', 'seoField.js.global.default');
        }
        badge.classList.remove('is-entry', 'is-section', 'is-global', 'is-fallback');
        badge.classList.add(cls);
        badge.textContent = label;
    }


    /**
     * Posts the current (unsaved) form state to the resolve-fallback
     * endpoint and pipes the response back into the field placeholders and
     * the inherited-image preview. The XHR is debounced per field to stay
     * cheap during fast typing.
     */
    function setupFallbackResolver(fieldEl) {
        if (!fieldEl || fieldEl.dataset.bpFallbackBound === '1') return;
        fieldEl.dataset.bpFallbackBound = '1';

        var url = fieldEl.getAttribute('data-bp-resolve-url') || '';
        var entryId = fieldEl.getAttribute('data-bp-entry-id') || '';
        var siteId = fieldEl.getAttribute('data-bp-site-id') || '';
        if (!url || !entryId) return;

        var titleInput = fieldEl.querySelector('[data-bp-fallback-input="title"]');
        var descInput = fieldEl.querySelector('[data-bp-fallback-input="description"]');
        var inheritedWrap = fieldEl.querySelector('[data-bp-inherited-image]');
        var inheritedImg = fieldEl.querySelector('[data-bp-inherited-image-img]');

        var lastPayload = '';
        var pending = null;
        var inFlight = null;

        function readCurrentValues() {
            var fieldValue = { robots: {} };
            // Pull only the keys the resolver actually consults. Suffix-match
            // on the input `name` attribute to stay agnostic to Craft's
            // outer namespacing (`fields[<handle>][...]`).
            ['title', 'description', 'canonical'].forEach(function(key) {
                var inp = fieldEl.querySelector('[name$="[' + key + ']"]');
                if (inp) fieldValue[key] = inp.value || '';
            });
            // Element-select emits `[ogImageId][]` for the hidden id; fall
            // back to a plain `[ogImageId]` form if a future Craft release
            // changes that.
            var ogInp = fieldEl.querySelector('[name$="[ogImageId][]"]')
                || fieldEl.querySelector('[name$="[ogImageId]"]');
            if (ogInp) fieldValue.ogImageId = ogInp.value || '';
            // Robots — both checkbox and select/text variants.
            fieldEl.querySelectorAll('[name*="[robots]["]').forEach(function(inp) {
                var m = (inp.name || '').match(/\[robots\]\[([a-zA-Z0-9_-]+)\]$/);
                if (!m) return;
                fieldValue.robots[m[1]] = inp.type === 'checkbox' ? (inp.checked ? '1' : '') : (inp.value || '');
            });
            var entryTitleEl = document.querySelector('input[type="text"][name="title"]');
            return {
                entryTitle: entryTitleEl ? (entryTitleEl.value || '') : '',
                fieldValue: fieldValue,
            };
        }

        function applyResponse(data) {
            if (!data || typeof data !== 'object') return;
            if (titleInput && (titleInput.value || '') === '') {
                titleInput.setAttribute('placeholder', data.title || '');
            }
            if (descInput && (descInput.value || '') === '') {
                descInput.setAttribute('placeholder', data.description || '');
            }
            var ogImageInput = fieldEl.querySelector('input[name$="[ogImageId][]"]')
                || fieldEl.querySelector('input[name$="[ogImageId]"]');
            var hasEntryImage = ogImageInput && ogImageInput.value !== '' && ogImageInput.value !== '0';
            var hasFallbackImage = !!(data.ogImage && data.ogImage.length);
            if (inheritedWrap && inheritedImg) {
                if (!hasEntryImage && hasFallbackImage) {
                    inheritedImg.setAttribute('src', data.ogImageThumb || data.ogImage);
                    inheritedWrap.removeAttribute('hidden');
                } else {
                    inheritedWrap.setAttribute('hidden', '');
                }
            }
            // Source badges follow the resolver's sourceMap when it tells us.
            var sourceMap = data.sourceMap || {};
            var titleBadge = fieldEl.querySelector('[data-bp-source-badge="title"]');
            var descBadge = fieldEl.querySelector('[data-bp-source-badge="description"]');
            updateSourceBadge(titleBadge, titleInput && titleInput.value !== '', false, sourceMap.title);
            updateSourceBadge(descBadge, descInput && descInput.value !== '', false, sourceMap.description);
        }

        function send() {
            var values = readCurrentValues();
            var body = new URLSearchParams();
            body.set('entryId', entryId);
            if (siteId) body.set('siteId', siteId);
            body.set('entryTitle', values.entryTitle);
            // Flatten fieldValue into [key] / [key][sub] params.
            Object.keys(values.fieldValue).forEach(function(k) {
                var v = values.fieldValue[k];
                if (v && typeof v === 'object') {
                    Object.keys(v).forEach(function(sub) {
                        body.set('fieldValue[' + k + '][' + sub + ']', v[sub]);
                    });
                } else {
                    body.set('fieldValue[' + k + ']', v == null ? '' : v);
                }
            });
            var serialized = body.toString();
            if (serialized === lastPayload) return;
            lastPayload = serialized;

            if (inFlight && inFlight.abort) inFlight.abort();
            var controller = ('AbortController' in window) ? new AbortController() : null;
            inFlight = controller ? { abort: function() { controller.abort(); } } : null;

            var csrfToken = (window.Craft && Craft.csrfTokenValue) ? Craft.csrfTokenValue : '';
            var csrfName = (window.Craft && Craft.csrfTokenName) ? Craft.csrfTokenName : '';
            if (csrfName && csrfToken) body.set(csrfName, csrfToken);

            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: body.toString(),
                signal: controller ? controller.signal : undefined,
            })
                .then(function(r) { return r.ok ? r.json() : null; })
                .then(applyResponse)
                .catch(function() { /* swallow; field still works without live resolve */ });
        }

        function schedule() {
            if (pending) clearTimeout(pending);
            pending = setTimeout(send, 400);
        }

        fieldEl.addEventListener('input', schedule);
        fieldEl.addEventListener('change', schedule);
        // Catch entry-title edits (live outside the field).
        var entryTitleEl = document.querySelector('input[type="text"][name="title"]');
        if (entryTitleEl) entryTitleEl.addEventListener('input', schedule);

        // Seed once on bind so placeholders are accurate immediately.
        send();
    }

    function bindFallbackResolvers(root) {
        (root || document).querySelectorAll('[data-beacon-seo-field]').forEach(setupFallbackResolver);
    }

    document.addEventListener('input', function() {
        refreshMeters(document);
        updateChecklist(document);
    }, true);

    bindFallbackResolvers(document);


    function summariseSchema(card, config) {
        var typeInput = card.querySelector('[data-schema-type-input]');
        var mappingInput = card.querySelector('[data-schema-mapping-input]');
        var body = card.querySelector('[data-card-body]');
        if (!typeInput || !mappingInput || !body) return;

        var type = typeInput.value || 'Article';
        var mapping = safeJsonParse(mappingInput.value);
        var props = (config.properties || {})[type] || [];
        var requiredProps = props.filter(function(p) { return p.tier === 'required'; });
        var recommendedProps = props.filter(function(p) { return p.tier === 'recommended'; });
        var requiredHit = requiredProps.filter(function(p) { return mapping[p.name]; }).length;
        var recommendedHit = recommendedProps.filter(function(p) { return mapping[p.name]; }).length;

        var statusClass = 'is-ok';
        var statusText = '✓ ' + Craft.t('beacon', 'seoField.js.required.2', { hit: requiredHit, total: requiredProps.length });
        if (requiredProps.length && requiredHit < requiredProps.length) {
            statusClass = 'is-error';
            statusText = '★ ' + Craft.t('beacon', 'seoField.js.required.2', { hit: requiredHit, total: requiredProps.length });
        } else if (recommendedProps.length && recommendedHit < recommendedProps.length) {
            statusClass = 'is-warn';
            statusText = '◇ ' + Craft.t('beacon', 'seoField.js.recommended.2', { hit: recommendedHit, total: recommendedProps.length });
        }

        var propertyNames = Object.keys(mapping);
        var summaryLine = propertyNames.length
            ? propertyNames.slice(0, 6).join(', ') + (propertyNames.length > 6 ? ' (+' + (propertyNames.length - 6) + ')' : '')
            : Craft.t('beacon', 'seoField.js.no.properties.mapped');

        body.innerHTML = '';
        var top = el('div', null, [
            el('span', { class: 'beacon-schema-card-type', text: type }),
            el('span', { class: 'beacon-schema-card-status ' + statusClass, text: statusText })
        ]);
        body.appendChild(top);
        body.appendChild(el('div', { class: 'beacon-schema-card-properties', text: summaryLine }));
    }

    /**
     * Resolve the namespaced base for posted hidden inputs. Twig already
     * computes `fields[<handle>]` (or whatever `View::namespaceInputName`
     * resolves to) and emits it in the per-field config blob — JS-built
     * inputs need to match that prefix exactly, otherwise the new schema
     * silently drops on POST.
     */
    function resolveInputBase(container) {
        var fieldName = container.getAttribute('data-field-name') || '';
        var config = readFieldConfig(fieldName);
        return config.inputName || fieldName;
    }

    function reindexCards(container) {
        if (!container) return;
        var inputBase = resolveInputBase(container);
        var cards = container.querySelectorAll('[data-beacon-schema-card]');
        cards.forEach(function(card, idx) {
            card.setAttribute('data-schema-index', String(idx));
            var typeInput = card.querySelector('[data-schema-type-input]');
            var mappingInput = card.querySelector('[data-schema-mapping-input]');
            if (typeInput) typeInput.name = inputBase + '[schemaAddons][' + idx + '][type]';
            if (mappingInput) mappingInput.name = inputBase + '[schemaAddons][' + idx + '][mapping]';
        });
        container.setAttribute('data-next-index', String(cards.length));
        var empty = container.querySelector('[data-beacon-schema-empty]');
        if (empty) empty.style.display = cards.length ? 'none' : '';
    }

    function paintCards(container) {
        if (!container) return;
        var fieldName = container.getAttribute('data-field-name') || '';
        var config = readFieldConfig(fieldName);
        container.querySelectorAll('[data-beacon-schema-card]').forEach(function(card) {
            summariseSchema(card, config);
        });
    }


    function buildPropertySelect(type, currentProperty, config) {
        var props = (config.properties || {})[type] || [];
        var select = el('select', { 'data-mapping-key-select': '', class: 'fullwidth' });
        var seenCurrent = false;
        ['required', 'recommended', 'optional'].forEach(function(tier) {
            var matching = props.filter(function(p) { return p.tier === tier; });
            if (!matching.length) return;
            var grp = el('optgroup', { label: TIER_LABEL[tier] });
            matching.forEach(function(p) {
                var opt = el('option', {
                    value: p.name,
                    text: (TIER_MARKER[p.tier] ? TIER_MARKER[p.tier] + ' ' : '') + p.name,
                    title: p.help || ''
                });
                if (p.name === currentProperty) { opt.selected = true; seenCurrent = true; }
                grp.appendChild(opt);
            });
            select.appendChild(grp);
        });
        var customOpt = el('option', { value: CUSTOM_PROPERTY, text: Craft.t('beacon', 'seoField.js.custom.property') });
        select.appendChild(customOpt);
        if (currentProperty && !seenCurrent) customOpt.selected = true;
        return select;
    }

    function buildSourceSelect(currentTemplate, config) {
        var sources = config.sources || [];
        var select = el('select', { 'data-mapping-template-select': '', class: 'fullwidth' });
        var groups = {};
        sources.forEach(function(s) {
            (groups[s.group || 'entry'] = groups[s.group || 'entry'] || []).push(s);
        });
        var matched = false;
        ['entry', 'seo', 'field'].forEach(function(g) {
            var rows = groups[g];
            if (!rows || !rows.length) return;
            var grp = el('optgroup', { label: SOURCE_GROUP_LABEL[g] || g });
            rows.forEach(function(s) {
                var opt = el('option', { value: s.token, text: s.label, title: s.hint || '' });
                if (s.token === currentTemplate) { opt.selected = true; matched = true; }
                grp.appendChild(opt);
            });
            select.appendChild(grp);
        });
        var customOpt = el('option', { value: CUSTOM_TEMPLATE, text: Craft.t('beacon', 'seoField.js.custom.template') });
        select.appendChild(customOpt);
        if (currentTemplate && !matched) customOpt.selected = true;
        return select;
    }

    /**
     * The modal owns its own in-memory state during editing — the underlying
     * hidden form inputs only update on Save. That keeps Cancel a true revert,
     * and lets us rewrite the editor controls without worrying about partial
     * intermediate states leaking into a form submit if the page reloads
     * mid-edit.
     */
    function openSchemaModal(card, options) {
        options = options || {};
        var container = card.closest('[data-beacon-schema-cards]');
        var fieldName = container ? container.getAttribute('data-field-name') : '';
        var config = readFieldConfig(fieldName);
        var typeInput = card.querySelector('[data-schema-type-input]');
        var mappingInput = card.querySelector('[data-schema-mapping-input]');

        var initialMapping = safeJsonParse(mappingInput ? mappingInput.value : '{}');
        var state = {
            type: typeInput ? typeInput.value || 'Article' : 'Article',
            // Mapping is materialised as ordered rows so the UI preserves
            // editor intent across saves; we serialise back to a property=>token
            // object only on Save.
            rows: Object.keys(initialMapping).map(function(name) {
                return { property: name, template: initialMapping[name] || '' };
            })
        };

        var modal = el('div', { class: 'beacon-schema-modal' });
        var headerTitle = options.titleText || (typeInput && typeInput.value ? Craft.t('beacon', 'schemas.edit.edit.schema.text') : Craft.t('beacon', 'seoField.add.schema.text'));
        var header = el('div', { class: 'beacon-schema-modal-header' }, [
            el('h2', { class: 'beacon-schema-modal-title', text: headerTitle }),
            el('button', { type: 'button', class: 'beacon-schema-modal-close', 'aria-label': Craft.t('beacon', 'seoField.js.close'), text: '×' })
        ]);
        var body = el('div', { class: 'beacon-schema-modal-body' });
        var footer = el('div', { class: 'beacon-schema-modal-footer' });
        modal.appendChild(header);
        modal.appendChild(body);
        modal.appendChild(footer);

        // Toolbar: type select + Suggest button. Pull types from config when
        // present so admins can extend the registry without touching JS.
        var availableTypes = (Array.isArray(config.types) && config.types.length)
            ? config.types
            : DEFAULT_TYPES;
        var typeSelect = el('select', { 'data-modal-type': '', class: 'fullwidth' });
        availableTypes.forEach(function(t) {
            var opt = el('option', { value: t, text: t });
            if (t === state.type) opt.selected = true;
            typeSelect.appendChild(opt);
        });
        var docsLink = el('a', {
            class: 'beacon-schema-modal-docs light',
            href: 'https://schema.org/' + encodeURIComponent(state.type),
            target: '_blank',
            rel: 'noopener',
            text: Craft.t('beacon', 'seoField.js.schema.org.docs'),
            title: Craft.t('beacon', 'seoField.js.open.type.s.spec.schema'),
        });
        typeSelect.addEventListener('change', function() {
            docsLink.setAttribute('href', 'https://schema.org/' + encodeURIComponent(typeSelect.value));
        });
        var typeField = el('div', { class: 'field' }, [
            el('div', { class: 'heading' }, [el('label', { text: Craft.t('beacon', 'schemas.edit.schemaType.label') })]),
            el('div', { class: 'input ltr beacon-schema-modal-type' }, [typeSelect, docsLink])
        ]);
        var suggestBtn = el('button', { type: 'button', class: 'btn', 'data-modal-suggest': '', text: Craft.t('beacon', 'seoField.js.suggest.mapping') });
        var suggestNote = el('div', { class: 'beacon-schema-modal-suggest-note light' });
        if (!config.entryId) {
            suggestBtn.disabled = true;
            suggestBtn.title = Craft.t('beacon', 'seoField.js.save.entry.once.before.requesting');
            suggestNote.textContent = Craft.t('beacon', 'seoField.js.save.entry.first.needs.entry');
        }
        body.appendChild(el('div', { class: 'beacon-schema-modal-toolbar' }, [typeField, suggestBtn, suggestNote]));

        // Grid layout (not a <table>): keeps the Property/Source columns aligned
        // even when one row reveals its "Custom" text input below the select.
        body.appendChild(el('h3', { class: 'beacon-seo-section-title', text: Craft.t('beacon', 'seoField.js.properties') }));

        var grid = el('div', { class: 'beacon-mapping-grid' });
        grid.appendChild(el('div', { class: 'beacon-mapping-grid-head' }, [
            el('span', { class: 'beacon-mapping-grid-head-label', text: Craft.t('beacon', 'seoField.js.property') }),
            el('span', { class: 'beacon-mapping-grid-head-label', text: Craft.t('beacon', 'aiCrawlers.source.text') }),
            el('span')
        ]));
        var tbody = el('div', { class: 'beacon-mapping-grid-body', 'data-modal-rows': '' });
        grid.appendChild(tbody);
        body.appendChild(grid);
        body.appendChild(el('button', { type: 'button', class: 'btn small add icon', 'data-modal-add-row': '', text: Craft.t('beacon', 'seoField.js.add.property') }));
        var validation = el('div', { class: 'beacon-mapping-validation', 'data-modal-validation': '' });
        body.appendChild(validation);

        var preview = el('details', { class: 'beacon-schema-preview' }, [
            el('summary', { class: 'light', text: Craft.t('beacon', 'seoField.js.json.ld.preview') }),
            el('pre', { 'data-modal-preview': '' })
        ]);
        body.appendChild(preview);

        // Footer
        footer.appendChild(el('div', { class: 'light', 'data-modal-status': '' }));
        var footerRight = el('div', { class: 'beacon-schema-modal-footer-right' }, [
            el('button', { type: 'button', class: 'btn', 'data-modal-cancel': '', text: Craft.t('beacon', 'seoField.js.cancel') }),
            el('button', { type: 'button', class: 'btn submit', 'data-modal-save': '', text: Craft.t('beacon', 'seoField.js.save') })
        ]);
        footer.appendChild(footerRight);

        function paintRows() {
            tbody.innerHTML = '';
            state.rows.forEach(function(row, idx) {
                tbody.appendChild(buildModalRow(state.type, row, idx, config));
            });
            renderValidation();
            renderPreview();
        }

        function buildModalRow(type, row, idx, config) {
            var props = (config.properties || {})[type] || [];
            var matchingProp = null;
            for (var i = 0; i < props.length; i++) {
                if (props[i].name === row.property) { matchingProp = props[i]; break; }
            }
            var tierEl = el('span', {
                class: 'beacon-mapping-row-tier' + (matchingProp ? ' is-' + matchingProp.tier : ' is-optional'),
                text: matchingProp ? TIER_MARKER[matchingProp.tier] : ' '
            });

            var propSelect = buildPropertySelect(type, row.property, config);
            var propCustom = el('input', {
                type: 'text', class: 'text fullwidth beacon-mapping-custom-input',
                placeholder: Craft.t('beacon', 'seoField.js.propertyName')
            });
            propCustom.value = propSelect.value === CUSTOM_PROPERTY ? row.property : '';
            propCustom.hidden = propSelect.value !== CUSTOM_PROPERTY;

            var sourceSelect = buildSourceSelect(row.template, config);
            var sourceCustom = el('input', {
                type: 'text', class: 'text fullwidth beacon-mapping-custom-input',
                placeholder: '{entry.title} or fixed text'
            });
            sourceCustom.value = sourceSelect.value === CUSTOM_TEMPLATE ? row.template : '';
            sourceCustom.hidden = sourceSelect.value !== CUSTOM_TEMPLATE;

            propSelect.addEventListener('change', function() {
                propCustom.hidden = propSelect.value !== CUSTOM_PROPERTY;
                if (propSelect.value !== CUSTOM_PROPERTY) {
                    state.rows[idx].property = propSelect.value;
                    var match = ((config.properties || {})[state.type] || []).filter(function(p) { return p.name === propSelect.value; })[0];
                    tierEl.className = 'beacon-mapping-row-tier' + (match ? ' is-' + match.tier : ' is-optional');
                    tierEl.textContent = match ? TIER_MARKER[match.tier] : ' ';
                } else {
                    propCustom.focus();
                }
                renderValidation(); renderPreview();
            });
            propCustom.addEventListener('input', function() {
                state.rows[idx].property = propCustom.value.trim();
                renderValidation(); renderPreview();
            });
            sourceSelect.addEventListener('change', function() {
                sourceCustom.hidden = sourceSelect.value !== CUSTOM_TEMPLATE;
                if (sourceSelect.value !== CUSTOM_TEMPLATE) {
                    state.rows[idx].template = sourceSelect.value;
                } else {
                    sourceCustom.focus();
                }
                renderPreview();
            });
            sourceCustom.addEventListener('input', function() {
                state.rows[idx].template = sourceCustom.value;
                renderPreview();
            });

            // Each cell is a flex column: select on top, custom input directly
            // beneath when present. The grid row keeps Property/Source columns
            // horizontally aligned no matter which cell has the custom input
            // visible — the visual row-grouping line lives on the wrapper.
            var propCell = el('div', { class: 'beacon-mapping-cell beacon-mapping-cell-prop' }, [
                el('div', { class: 'beacon-mapping-cell-row' }, [tierEl, propSelect]),
                propCustom
            ]);
            var sourceCell = el('div', { class: 'beacon-mapping-cell' }, [
                sourceSelect,
                sourceCustom
            ]);
            var removeBtn = el('button', { type: 'button', class: 'btn small beacon-mapping-row-remove', 'aria-label': Craft.t('beacon', 'redirectSources.remove.ariaLabel'), text: '×' });
            removeBtn.addEventListener('click', function() {
                state.rows.splice(idx, 1);
                paintRows();
            });

            return el('div', { class: 'beacon-mapping-row' }, [propCell, sourceCell, removeBtn]);
        }

        function collectMapping() {
            var out = {};
            state.rows.forEach(function(r) {
                var key = (r.property || '').trim();
                if (key) out[key] = r.template || '';
            });
            return out;
        }

        function renderValidation() {
            var props = (config.properties || {})[state.type] || [];
            var mapping = collectMapping();
            var missingRequired = props.filter(function(p) { return p.tier === 'required' && !mapping[p.name]; }).map(function(p) { return p.name; });
            var missingRecommended = props.filter(function(p) { return p.tier === 'recommended' && !mapping[p.name]; }).map(function(p) { return p.name; });
            validation.classList.remove('is-warn', 'is-error', 'is-ok');
            if (missingRequired.length) {
                validation.classList.add('is-error');
                validation.textContent = '★ ' + Craft.t('beacon', 'seoField.js.missing.required', { props: missingRequired.join(', ') });
            } else if (missingRecommended.length) {
                validation.classList.add('is-warn');
                validation.textContent = '◇ ' + Craft.t('beacon', 'seoField.js.missing.recommended', { props: missingRecommended.slice(0, 4).join(', ') })
                    + (missingRecommended.length > 4 ? ' (+' + (missingRecommended.length - 4) + ')' : '');
            } else {
                validation.classList.add('is-ok');
                validation.textContent = '✓ ' + Craft.t('beacon', 'seoField.js.all.required.recommended.properties.mapped');
            }
        }

        function renderPreview() {
            var pre = preview.querySelector('[data-modal-preview]');
            if (!pre) return;
            var doc = Object.assign({ '@context': 'https://schema.org', '@type': state.type }, collectMapping());
            pre.textContent = JSON.stringify(doc, null, 2);
        }

        // Wire toolbar
        typeSelect.addEventListener('change', function() {
            state.type = typeSelect.value;
            paintRows();
        });

        body.querySelector('[data-modal-add-row]').addEventListener('click', function() {
            state.rows.push({ property: '', template: '' });
            paintRows();
        });

        suggestBtn.addEventListener('click', function() {
            if (!config.entryId) return;
            var prevText = suggestBtn.textContent;
            suggestBtn.disabled = true;
            suggestBtn.textContent = Craft.t('beacon', 'seoField.js.loading');

            var done = function() { suggestBtn.disabled = false; suggestBtn.textContent = prevText; };

            // Craft.sendActionRequest handles CSRF, base URL, and the
            // pathParam/actionTrigger forms — using fetch directly was
            // tripping over redirects under DDEV-style CP configs.
            if (window.Craft && typeof Craft.sendActionRequest === 'function') {
                Craft.sendActionRequest('POST', 'beacon/seo-field/suggest-mapping', {
                    data: { entryId: config.entryId, type: state.type }
                }).then(function(resp) {
                    var mapping = (resp && resp.data && resp.data.mapping) || {};
                    mergeSuggestedMapping(mapping);
                }).catch(function(err) {
                    if (window.console && console.warn) console.warn('[Beacon] suggest-mapping failed:', err);
                    if (window.Craft && Craft.cp && typeof Craft.cp.displayError === 'function') {
                        Craft.cp.displayError(Craft.t('beacon', 'seoField.js.could.not.load.suggested.mapping'));
                    } else {
                        window.alert(Craft.t('beacon', 'seoField.js.could.not.load.suggested.mapping'));
                    }
                }).finally(done);
                return;
            }

            // Fallback path (non-CP context); CSRF won't be auto-attached.
            done();
            window.alert(Craft.t('beacon', 'seoField.js.suggest.mapping.requires.craft.cp'));
        });

        function mergeSuggestedMapping(mapping) {
            var byName = {};
            state.rows.forEach(function(r) { if (r.property) byName[r.property] = r; });
            Object.keys(mapping).forEach(function(propName) {
                var existing = byName[propName];
                if (existing) existing.template = mapping[propName];
                else state.rows.push({ property: propName, template: mapping[propName] });
            });
            paintRows();
        }

        paintRows();

        // Custom overlay instead of Garnish.Modal — Garnish rewires the DOM and
        var saved = false;
        var overlayEl = el('div', { class: 'beacon-schema-modal-overlay' });
        overlayEl.appendChild(modal);
        document.body.appendChild(overlayEl);
        document.body.classList.add('beacon-schema-modal-open');

        function close() {
            document.body.classList.remove('beacon-schema-modal-open');
            if (overlayEl.parentNode) overlayEl.parentNode.removeChild(overlayEl);
            document.removeEventListener('keydown', onKey, true);
            if (!saved && options.discardOnClose && container && card.parentNode === container) {
                container.removeChild(card);
                reindexCards(container);
                paintCards(container);
                updateChecklist(document);
            }
            if (options.onClose) options.onClose();
        }

        function save() {
            var mapping = collectMapping();
            if (typeInput) typeInput.value = state.type;
            if (mappingInput) mappingInput.value = JSON.stringify(mapping);
            saved = true;
            paintCards(container);
            updateChecklist(document);
            close();
        }

        function onKey(e) {
            if (e.key === 'Escape') close();
        }

        header.querySelector('.beacon-schema-modal-close').addEventListener('click', close);
        footer.querySelector('[data-modal-cancel]').addEventListener('click', close);
        footer.querySelector('[data-modal-save]').addEventListener('click', save);
        overlayEl.addEventListener('click', function(e) { if (e.target === overlayEl) close(); });
        document.addEventListener('keydown', onKey, true);
    }


    function buildEmptyCard(container) {
        var inputBase = resolveInputBase(container);
        var idx = parseInt(container.getAttribute('data-next-index') || '0', 10);

        var card = el('div', { class: 'beacon-schema-card', 'data-beacon-schema-card': '', 'data-schema-index': String(idx) });
        card.appendChild(el('input', {
            type: 'hidden',
            name: inputBase + '[schemaAddons][' + idx + '][type]',
            value: 'Article',
            'data-schema-type-input': ''
        }));
        card.appendChild(el('input', {
            type: 'hidden',
            name: inputBase + '[schemaAddons][' + idx + '][mapping]',
            value: '{}',
            'data-schema-mapping-input': ''
        }));
        card.appendChild(el('div', { class: 'beacon-schema-card-body', 'data-card-body': '' }));
        var actions = el('div', { class: 'beacon-schema-card-actions' }, [
            el('button', { type: 'button', class: 'btn small', 'data-card-edit': '', text: Craft.t('beacon', 'seoField.edit.text') }),
            el('button', { type: 'button', class: 'btn small', 'data-card-remove': '', text: Craft.t('beacon', 'redirectSources.remove.ariaLabel') })
        ]);
        card.appendChild(actions);
        container.appendChild(card);
        container.setAttribute('data-next-index', String(idx + 1));
        var empty = container.querySelector('[data-beacon-schema-empty]');
        if (empty) empty.style.display = 'none';
        return card;
    }

    document.addEventListener('click', function(e) {
        var t = e.target;
        if (!t) return;

        var addBtn = t.closest('[data-beacon-add-schema]');
        if (addBtn) {
            var section = addBtn.closest('.beacon-seo-section');
            if (!section) return;
            var container = section.querySelector('[data-beacon-schema-cards]');
            if (!container) return;
            var card = buildEmptyCard(container);
            paintCards(container);
            openSchemaModal(card, {
                titleText: Craft.t('beacon', 'seoField.add.schema.text'),
                discardOnClose: true,
                onClose: null
            });
            return;
        }

        var editBtn = t.closest('[data-card-edit]');
        if (editBtn) {
            var card1 = editBtn.closest('[data-beacon-schema-card]');
            if (card1) openSchemaModal(card1, { titleText: Craft.t('beacon', 'schemas.edit.edit.schema.text') });
            return;
        }

        var removeBtn = t.closest('[data-card-remove]');
        if (removeBtn) {
            var card2 = removeBtn.closest('[data-beacon-schema-card]');
            if (!card2) return;
            var container2 = card2.parentElement;
            card2.remove();
            reindexCards(container2);
            paintCards(container2);
            updateChecklist(document);
            return;
        }
    }, true);


    /**
     * Wire each [data-beacon-preview] container so its text/host/image nodes
     * mirror the editor inputs as the user types. Adds:
     *   - Tab switching across Google/Mobile/Facebook/LinkedIn/X/Slack/Pinterest
     *   - Pixel-accurate truncation per platform (canvas measureText)
     *   - Live OG image dimension badge (probes the asset's natural size)
     */
    var measureCanvas;
    function measureTextPx(text, font) {
        if (!measureCanvas) measureCanvas = document.createElement('canvas');
        var ctx = measureCanvas.getContext('2d');
        ctx.font = font || '14px arial, sans-serif';
        return ctx.measureText(text || '').width;
    }

    /**
     * Truncate `text` so its rendered width does not exceed `budgetPx`.
     * Returns { display, truncated } — display is the visible string (with
     * the last whole word dropped if needed), truncated is whether anything
     * was cut.
     */
    function truncateToPx(text, budgetPx, font) {
        text = String(text || '');
        if (!text) return { display: '', truncated: false };
        if (measureTextPx(text, font) <= budgetPx) return { display: text, truncated: false };
        var lo = 0, hi = text.length, fit = 0;
        while (lo <= hi) {
            var mid = (lo + hi) >> 1;
            if (measureTextPx(text.slice(0, mid), font) <= budgetPx) { fit = mid; lo = mid + 1; }
            else { hi = mid - 1; }
        }
        // Drop the trailing partial word for cleaner cut.
        var slice = text.slice(0, fit);
        var space = slice.lastIndexOf(' ');
        if (space > 20 && fit < text.length) slice = slice.slice(0, space);
        return { display: slice.replace(/[\s ]+$/, ''), truncated: true };
    }

    function truncateToChars(text, budget) {
        text = String(text || '');
        if (!text || text.length <= budget) return { display: text, truncated: false };
        var slice = text.slice(0, budget);
        var space = slice.lastIndexOf(' ');
        if (space > Math.floor(budget * 0.6)) slice = slice.slice(0, space);
        return { display: slice.replace(/[\s ]+$/, ''), truncated: true };
    }

    /**
     * Live-bind a single preview container to the field's inputs.
     */
    function bindPreview(root) {
        if (root.dataset.bpBound === '1') return;
        root.dataset.bpBound = '1';

        var fieldEl = root.closest('.beacon-seo-field');
        if (!fieldEl) return;

        var prefix = (fieldEl.querySelector('[data-seo-checklist]') || {}).getAttribute
            ? (fieldEl.querySelector('[data-seo-checklist]').getAttribute('data-field-prefix') || '')
            : '';

        function readInputs() {
            var titleEl = queryByName(prefix, '[title]', fieldEl);
            var descEl = queryByName(prefix, '[description]', fieldEl);
            var canonicalEl = queryByName(prefix, '[canonical]', fieldEl);
            var entryTitleEl = document.querySelector('input[type="text"][name="title"]');

            var rawTitle = (titleEl && titleEl.value || '').trim();
            var rawDesc = (descEl && descEl.value || '').trim();
            var canonical = (canonicalEl && canonicalEl.value || '').trim() || root.dataset.bpCanonical || '';

            // Mirror server-side behaviour: fall back to entry title when SEO Title is blank.
            var effectiveTitle = rawTitle || (entryTitleEl && entryTitleEl.value || '').trim();
            var hideImage = root.dataset.bpHideImage === '1';
            return {
                title: effectiveTitle,
                description: rawDesc,
                canonical: canonical,
                host: parseHost(canonical) || root.dataset.bpHost || '',
                path: parsePath(canonical),
                ogImageUrl: hideImage ? '' : (resolveOgImageUrl(fieldEl) || root.dataset.bpOgImage || ''),
                siteName: root.dataset.bpSiteName || '',
                twitterCard: root.dataset.bpTwitterCard || 'summary_large_image',
            };
        }

        function parseHost(canonical) {
            if (!canonical) return '';
            var m = canonical.match(/^https?:\/\/([^/?#]+)/i);
            return m ? m[1] : '';
        }
        function parsePath(canonical) {
            if (!canonical) return '';
            var m = canonical.match(/^https?:\/\/[^/]+(\/[^?#]*)/i);
            return m && m[1] !== '/' ? m[1].replace(/^\//, '') : '';
        }

        function resolveOgImageUrl(scope) {
            // Craft's elementSelectField renders a thumb img for the selected asset.
            var thumb = scope.querySelector('[id$="-ogImageId"] .elementthumb img, [id$="-ogImageId"] img.elementthumb, [id$="-ogImageId"] .thumb img');
            if (thumb && thumb.src) return thumb.src;
            // Fallback: any img inside the element-select container.
            var anyImg = scope.querySelector('[id$="-ogImageId"] img');
            return anyImg && anyImg.src ? anyImg.src : '';
        }

        function renderPanel(panel, state) {
            // Title + description: truncated wrappers first, then any bare
            // [data-bp-text] that wasn't inside a wrapper (Slack/etc.).
            var updated = new WeakSet();
            ['title', 'description'].forEach(function(key) {
                var trunc = panel.querySelector('[data-bp-truncate="' + key + '"]');
                if (trunc) {
                    var span = trunc.querySelector('[data-bp-text="' + key + '"]');
                    if (span) {
                        var raw = state[key];
                        var result;
                        if (trunc.dataset.bpBudgetPx) {
                            var budget = parseInt(trunc.dataset.bpBudgetPx, 10);
                            result = truncateToPx(raw, budget, trunc.dataset.bpFont || '14px arial, sans-serif');
                        } else if (trunc.dataset.bpBudgetChars) {
                            var charBudget = parseInt(trunc.dataset.bpBudgetChars, 10);
                            result = truncateToChars(raw, charBudget);
                        } else {
                            result = { display: raw, truncated: false };
                        }
                        span.textContent = result.display || ' ';
                        span.classList.toggle('is-truncated', result.truncated);
                        updated.add(span);
                    }
                }
                panel.querySelectorAll('[data-bp-text="' + key + '"]').forEach(function(s) {
                    if (updated.has(s)) return;
                    s.textContent = state[key] || ' ';
                });
            });
            // Host + path + favicon
            panel.querySelectorAll('[data-bp-host-display]').forEach(function(el) {
                if (el.classList.contains('beacon-og-card__domain')) {
                    el.textContent = (state.host || '—').toUpperCase();
                } else {
                    el.textContent = state.host || '—';
                }
            });
            panel.querySelectorAll('[data-bp-path-display]').forEach(function(el) {
                el.textContent = state.path ? state.path.replace(/\//g, ' › ') : '';
            });
            panel.querySelectorAll('[data-bp-favicon]').forEach(function(el) {
                el.textContent = state.host ? state.host.charAt(0).toUpperCase() : 'S';
                if (state.host) {
                    var faviconUrl = 'https://' + state.host + '/favicon.ico';
                    el.style.backgroundImage = 'url(' + JSON.stringify(faviconUrl) + ')';
                    el.style.color = 'transparent';
                } else {
                    el.style.backgroundImage = '';
                    el.style.color = '';
                }
            });
            panel.querySelectorAll('[data-bp-site-display]').forEach(function(el) {
                el.textContent = state.siteName || state.host || '—';
            });
            // Image
            panel.querySelectorAll('[data-bp-image]').forEach(function(el) {
                if (state.ogImageUrl) {
                    el.style.backgroundImage = 'url(' + JSON.stringify(state.ogImageUrl) + ')';
                } else {
                    el.style.backgroundImage = '';
                }
            });
        }

        function updateBudgetBadge(panel, state) {
            var budgetEl = panel.querySelector('.beacon-preview-budget');
            if (!budgetEl) return;
            var titleTrunc = panel.querySelector('[data-bp-truncate="title"]');
            var descTrunc = panel.querySelector('[data-bp-truncate="description"]');
            var titlePxBudget = titleTrunc && titleTrunc.dataset.bpBudgetPx
                ? parseInt(titleTrunc.dataset.bpBudgetPx, 10) : 0;
            var titleCharBudget = titleTrunc && titleTrunc.dataset.bpBudgetChars
                ? parseInt(titleTrunc.dataset.bpBudgetChars, 10) : 0;
            var descCharBudget = descTrunc && descTrunc.dataset.bpBudgetChars
                ? parseInt(descTrunc.dataset.bpBudgetChars, 10) : 0;

            var bits = [];
            if (titlePxBudget && titleTrunc) {
                var px = measureTextPx(state.title, titleTrunc.dataset.bpFont || '14px arial, sans-serif');
                bits.push(Craft.t('beacon', 'seoField.js.title.px.px', { px: Math.round(px), budget: titlePxBudget }));
            } else if (titleCharBudget) {
                bits.push(Craft.t('beacon', 'seoField.js.title.chars', { count: state.title.length, budget: titleCharBudget }));
            }
            if (descCharBudget) {
                bits.push(Craft.t('beacon', 'seoField.js.desc', { count: state.description.length, budget: descCharBudget }));
            }
            budgetEl.textContent = bits.join(' · ');

            // Severity from worst-case
            var sev = 'is-ok';
            if (titlePxBudget && titleTrunc) {
                var px2 = measureTextPx(state.title, titleTrunc.dataset.bpFont || '14px arial, sans-serif');
                if (px2 > titlePxBudget) sev = 'is-over';
                else if (px2 > titlePxBudget * 0.9 || !state.title) sev = 'is-warn';
            } else if (titleCharBudget && state.title.length > titleCharBudget) sev = 'is-over';
            if (descCharBudget && state.description.length > descCharBudget) sev = 'is-over';
            if (!state.title || !state.description) sev = 'is-warn';
            budgetEl.classList.remove('is-ok', 'is-warn', 'is-over');
            budgetEl.classList.add(sev);
        }

        function updateImageMeta(state) {
            var meta = root.querySelector('[data-bp-image-meta]');
            if (!meta) return;
            if (!state.ogImageUrl) {
                meta.hidden = true;
                return;
            }
            meta.hidden = false;
            var msg = meta.querySelector('[data-bp-image-msg]');
            meta.classList.remove('is-ok', 'is-warn', 'is-bad');
            if (msg) msg.textContent = Craft.t('beacon', 'seoField.js.checking.image');
            var img = new Image();
            img.onload = function() {
                var w = img.naturalWidth, h = img.naturalHeight;
                var ratio = h ? (w / h) : 0;
                var label = Craft.t('beacon', 'seoField.js.social.image.px', { w: w, h: h });
                var cls = 'is-ok';
                if (w < 600) { cls = 'is-bad'; label += Craft.t('beacon', 'seoField.js.too.small.need.600.wide'); }
                else if (Math.abs(ratio - 1.91) > 0.35) { cls = 'is-warn'; label += Craft.t('beacon', 'seoField.js.aspect.ratio.off.1.91'); }
                else if (w < 1200) { cls = 'is-warn'; label += Craft.t('beacon', 'seoField.js.below.1200.630.recommendation'); }
                else { label += Craft.t('beacon', 'seoField.js.looks.good'); }
                meta.classList.add(cls);
                if (msg) msg.textContent = label;
            };
            img.onerror = function() {
                meta.classList.add('is-bad');
                if (msg) msg.textContent = Craft.t('beacon', 'seoField.js.could.not.load.image.verify');
            };
            img.src = state.ogImageUrl;
        }

        function refresh() {
            var state = readInputs();
            // Twitter card layout switches between summary / summary_large_image
            var twitterCardEl = root.querySelector('[data-bp-twitter-layout]');
            if (twitterCardEl) twitterCardEl.setAttribute('data-bp-twitter-layout', state.twitterCard || 'summary_large_image');
            // Update each panel (even hidden ones — switching tabs is instant then)
            root.querySelectorAll('[data-bp-panel]').forEach(function(panel) {
                renderPanel(panel, state);
                updateBudgetBadge(panel, state);
            });
            updateImageMeta(state);
        }

        // Image-off toggle: simulates what previews look like when the entry
        // ships no SEO image (and no usable fallback). Keeps authors honest
        // about how Slack/Twitter/Facebook downgrade to text-only cards.
        var hideToggle = root.querySelector('[data-bp-hide-image]');
        if (hideToggle) {
            hideToggle.addEventListener('click', function() {
                var on = root.dataset.bpHideImage !== '1';
                root.dataset.bpHideImage = on ? '1' : '0';
                hideToggle.setAttribute('aria-pressed', on ? 'true' : 'false');
                hideToggle.classList.toggle('is-active', on);
                hideToggle.textContent = on ? Craft.t('beacon', 'seoField.js.show.image') : Craft.t('beacon', 'seoField.preview.hide.image.text');
                refresh();
            });
        }

        var moreBtn = root.querySelector('[data-bp-show-more-previews]');
        if (moreBtn) {
            moreBtn.addEventListener('click', function() {
                root.classList.add('is-expanded');
                root.querySelectorAll('.beacon-preview-tab--extra').forEach(function(tab) {
                    tab.hidden = false;
                });
                moreBtn.hidden = true;
            });
        }

        // Tab switching
        root.querySelectorAll('[data-bp-tab]').forEach(function(tab) {
            tab.addEventListener('click', function() {
                var key = tab.getAttribute('data-bp-tab');
                root.querySelectorAll('[data-bp-tab]').forEach(function(t) {
                    var active = t === tab;
                    t.classList.toggle('is-active', active);
                    t.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                root.querySelectorAll('[data-bp-panel]').forEach(function(panel) {
                    panel.hidden = panel.getAttribute('data-bp-panel') !== key;
                    panel.classList.toggle('is-active', panel.getAttribute('data-bp-panel') === key);
                });
            });
        });

        // Wire input/change on the whole field so any edit refreshes the preview.
        ['input', 'change'].forEach(function(evt) {
            fieldEl.addEventListener(evt, function() { refresh(); }, true);
        });

        // Watch element-select async swaps for og image (Craft re-renders the picker on save).
        var ogContainer = fieldEl.querySelector('[id$="-ogImageId"]');
        if (ogContainer && window.MutationObserver) {
            new MutationObserver(function() { refresh(); }).observe(ogContainer, { childList: true, subtree: true });
        }

        refresh();
    }

    function bindAllPreviews(root) {
        (root || document).querySelectorAll('[data-beacon-preview]').forEach(bindPreview);
    }


    function paint(root) {
        refreshMeters(root);
        (root || document).querySelectorAll('[data-beacon-schema-cards]').forEach(function(container) {
            reindexCards(container);
            paintCards(container);
        });
        updateChecklist(root || document);
        bindChecklistDeepLinks(root || document);
        bindInlineValidation(root || document);
        bindAllPreviews(root);
    }

    paint(document);

    if (window.MutationObserver) {
        new MutationObserver(function(muts) {
            muts.forEach(function(m) {
                m.addedNodes.forEach(function(n) {
                    if (n.nodeType !== 1) return;
                    paint(n);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }
})();
