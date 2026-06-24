(function() {
    'use strict';

    const BeaconLinksSidebar = {
        suggestions: [],
        highlightsActive: false,

        init: function(entryId, siteId) {
            this.entryId = entryId;
            this.siteId = siteId;
            this.panel = document.getElementById('beacon-links-panel');
            this.container = document.getElementById('beacon-links-suggestions');
            if (!this.panel || !this.container) return;
            this.loadSuggestions();
            this.bindEvents();
        },

        bindEvents: function() {
            this.panel.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-beacon-links-action]');
                if (!btn) return;
                e.preventDefault();
                const action = btn.dataset.beaconLinksAction;
                const targetId = parseInt(btn.dataset.targetId);
                const score = parseFloat(btn.dataset.score);

                if (action === 'use-link') {
                    this.copyAndAccept(btn.dataset.url, btn.dataset.title, targetId, score, btn);
                } else if (action === 'insert-link') {
                    this.insertLink(targetId, score, btn);
                } else if (action === 'dismiss') {
                    this.recordInteraction(targetId, 'dismissed', score, btn);
                } else if (action === 'refresh') {
                    this.loadSuggestions();
                } else if (action === 'toggle-highlights') {
                    this.toggleHighlights(btn);
                }
            });
        },

        toggleHighlights: function(btn) {
            if (this.highlightsActive) {
                // Turn off
                BeaconLinksHighlights.disable();
                this.highlightsActive = false;
                btn.textContent = 'Show in Content';
                btn.classList.remove('active');
                // Remove match badges
                this.container.querySelectorAll('.beacon-links-match-count').forEach(el => el.remove());
            } else {
                // Turn on
                if (this.suggestions.length === 0) {
                    Craft.cp.displayNotice('No suggestions to highlight.');
                    return;
                }
                const matchCounts = BeaconLinksHighlights.enable(
                    this.suggestions,
                    (suggestion) => this.onHighlightLinked(suggestion)
                );
                this.highlightsActive = true;
                btn.textContent = 'Hide Highlights';
                btn.classList.add('active');
                // Show match count badges on sidebar items
                this.updateMatchBadges(matchCounts);

                const totalMatches = Object.values(matchCounts).reduce((a, b) => a + b, 0);
                if (totalMatches === 0) {
                    Craft.cp.displayNotice('No matching phrases found in content. Add links manually where the topics overlap.');
                }
            }
        },

        /**
         * Called when a highlight is clicked in a CKEditor and a link is inserted.
         */
        onHighlightLinked: function(suggestion) {
            Craft.cp.displayNotice('Linked to "' + suggestion.title + '"');

            // Record as accepted
            this.recordInteraction(suggestion.elementId, 'accepted', suggestion.score, null);

            // Remove from sidebar
            const row = this.container.querySelector('[data-element-id="' + suggestion.elementId + '"]');
            if (row) row.remove();

            // Remove from local suggestions
            this.suggestions = this.suggestions.filter(s => s.elementId !== suggestion.elementId);

            // Check if list is empty
            const remaining = this.container.querySelectorAll('.beacon-links-suggestion');
            if (remaining.length === 0) {
                const empty = this.container.querySelector('.beacon-links-empty');
                if (empty) {
                    empty.textContent = 'All suggestions reviewed.';
                    empty.style.display = 'block';
                }
                const list = this.container.querySelector('.beacon-links-list');
                if (list) list.style.display = 'none';
            }
        },

        updateMatchBadges: function(matchCounts) {
            // Remove existing badges
            this.container.querySelectorAll('.beacon-links-match-count').forEach(el => el.remove());

            // Add badges
            Object.keys(matchCounts).forEach(elementId => {
                const count = matchCounts[elementId];
                if (count === 0) return;
                const row = this.container.querySelector('[data-element-id="' + elementId + '"]');
                if (!row) return;
                const header = row.querySelector('.beacon-links-suggestion-header');
                if (!header) return;
                const badge = document.createElement('span');
                badge.className = 'beacon-links-match-count';
                badge.textContent = count + (count === 1 ? ' match' : ' matches');
                header.appendChild(badge);
            });
        },

        loadSuggestions: function() {
            const list = this.container.querySelector('.beacon-links-list');
            const loading = this.container.querySelector('.beacon-links-loading');
            const empty = this.container.querySelector('.beacon-links-empty');
            if (loading) loading.style.display = 'block';
            if (list) list.style.display = 'none';
            if (empty) empty.style.display = 'none';

            // If highlights are active, turn them off during reload
            if (this.highlightsActive) {
                BeaconLinksHighlights.disable();
                this.highlightsActive = false;
                const toggleBtn = this.panel.querySelector('[data-beacon-links-action="toggle-highlights"]');
                if (toggleBtn) {
                    toggleBtn.textContent = 'Show in Content';
                    toggleBtn.classList.remove('active');
                }
            }

            fetch(Craft.getActionUrl('beacon/link-suggestions/get', {
                entryId: this.entryId,
                siteId: this.siteId,
            }), {
                headers: { 'Accept': 'application/json' },
            })
            .then(r => r.json())
            .then(data => {
                if (loading) loading.style.display = 'none';
                if (!data.success || !data.suggestions.length) {
                    this.suggestions = [];
                    if (empty) {
                        empty.textContent = 'No link suggestions available.';
                        empty.style.display = 'block';
                    }
                    return;
                }
                this.suggestions = data.suggestions;
                if (list) {
                    list.innerHTML = data.suggestions.map(s => this.renderSuggestion(s)).join('');
                    list.style.display = 'block';
                }
            })
            .catch(() => {
                if (loading) loading.style.display = 'none';
                if (empty) {
                    empty.textContent = 'Failed to load suggestions.';
                    empty.style.display = 'block';
                }
            });
        },

        renderSuggestion: function(s) {
            const scorePercent = Math.round(s.score * 100);
            const url = s.url || s.cpEditUrl;

            return '<div class="beacon-links-suggestion" data-element-id="' + s.elementId + '">' +
                '<div class="beacon-links-suggestion-header">' +
                    '<a href="' + s.cpEditUrl + '" class="beacon-links-title" target="_blank">' + this.escapeHtml(s.title) + '</a>' +
                    '<span class="beacon-links-section">' + this.escapeHtml(s.sectionName) + ' &middot; ' + scorePercent + '%</span>' +
                '</div>' +
                '<div class="beacon-links-score-bar">' +
                    '<div class="beacon-links-score-fill" style="width: ' + scorePercent + '%"></div>' +
                '</div>' +
                '<div class="beacon-links-actions">' +
                    '<button type="button" class="btn small submit" ' +
                        'data-beacon-links-action="use-link" ' +
                        'data-url="' + this.escapeAttr(url) + '" ' +
                        'data-title="' + this.escapeAttr(s.title) + '" ' +
                        'data-target-id="' + s.elementId + '" ' +
                        'data-score="' + s.score + '"' +
                    '>Copy Link</button>' +
                    '<button type="button" class="btn small" ' +
                        'data-beacon-links-action="insert-link" ' +
                        'data-target-id="' + s.elementId + '" ' +
                        'data-score="' + s.score + '"' +
                    '>Insert Link</button>' +
                    '<button type="button" class="btn small" ' +
                        'data-beacon-links-action="dismiss" ' +
                        'data-target-id="' + s.elementId + '" ' +
                        'data-score="' + s.score + '"' +
                    '>Dismiss</button>' +
                '</div>' +
            '</div>';
        },

        insertLink: function(targetId, score, btn) {
            btn.disabled = true;
            btn.textContent = 'Inserting…';

            // Find the suggestion data for fallback copy
            const suggestion = this.suggestions.find(s => s.elementId === targetId);

            BeaconLinksInsert.findAndInsert(this.entryId, targetId, this.siteId)
                .then(success => {
                    if (success) {
                        Craft.cp.displayNotice('Link inserted into content.');
                        this.recordInteraction(targetId, 'accepted', score, btn);
                    } else if (suggestion) {
                        // Auto-insert failed — copy the link but keep the suggestion visible
                        const url = suggestion.url;
                        const title = suggestion.title;
                        const linkHtml = '<a href="' + url + '">' + title + '</a>';

                        if (navigator.clipboard && navigator.clipboard.write) {
                            const htmlBlob = new Blob([linkHtml], { type: 'text/html' });
                            const textBlob = new Blob([url], { type: 'text/plain' });
                            navigator.clipboard.write([
                                new ClipboardItem({ 'text/html': htmlBlob, 'text/plain': textBlob })
                            ]).catch(() => {
                                navigator.clipboard.writeText(url);
                            });
                        } else {
                            navigator.clipboard.writeText(url);
                        }

                        btn.disabled = false;
                        btn.textContent = 'Insert Link';
                        Craft.cp.displayNotice('Link copied to clipboard — paste it where it fits best. Suggestion kept for reference.');
                    } else {
                        Craft.cp.displayError('Could not find a matching phrase to link.');
                        btn.disabled = false;
                        btn.textContent = 'Insert Link';
                    }
                })
                .catch(() => {
                    Craft.cp.displayError('Failed to insert link.');
                    btn.disabled = false;
                    btn.textContent = 'Insert Link';
                });
        },

        copyAndAccept: function(url, title, targetId, score, btn) {
            const linkHtml = '<a href="' + url + '">' + title + '</a>';

            if (navigator.clipboard && navigator.clipboard.write) {
                const htmlBlob = new Blob([linkHtml], { type: 'text/html' });
                const textBlob = new Blob([url], { type: 'text/plain' });
                navigator.clipboard.write([
                    new ClipboardItem({ 'text/html': htmlBlob, 'text/plain': textBlob })
                ]).then(() => {
                    Craft.cp.displayNotice('Link copied — paste it into your content.');
                }).catch(() => {
                    navigator.clipboard.writeText(url).then(() => {
                        Craft.cp.displayNotice('URL copied to clipboard.');
                    });
                });
            } else {
                navigator.clipboard.writeText(url).then(() => {
                    Craft.cp.displayNotice('URL copied to clipboard.');
                });
            }

            this.recordInteraction(targetId, 'accepted', score, btn);
        },

        recordInteraction: function(targetId, status, score, btn) {
            const row = btn ? btn.closest('.beacon-links-suggestion') : null;
            const data = new FormData();
            data.append('sourceElementId', this.entryId);
            data.append('targetElementId', targetId);
            data.append('siteId', this.siteId);
            data.append('status', status);
            data.append('score', score);
            data.append(Craft.csrfTokenName, Craft.csrfTokenValue);

            fetch(Craft.getActionUrl('beacon/link-suggestions/record-interaction'), {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: data,
            })
            .then(r => r.json())
            .then(result => {
                if (result.success && row) {
                    row.remove();
                    if (status === 'dismissed') {
                        Craft.cp.displayNotice('Suggestion dismissed.');
                    }
                    const remaining = this.container.querySelectorAll('.beacon-links-suggestion');
                    if (remaining.length === 0) {
                        const empty = this.container.querySelector('.beacon-links-empty');
                        if (empty) {
                            empty.textContent = 'All suggestions reviewed.';
                            empty.style.display = 'block';
                        }
                        const list = this.container.querySelector('.beacon-links-list');
                        if (list) list.style.display = 'none';
                    }
                }
            })
            .catch(() => {
                Craft.cp.displayError('Failed to record interaction.');
            });
        },

        escapeHtml: function(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        },

        escapeAttr: function(str) {
            return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        },
    };

    window.BeaconLinksSidebar = BeaconLinksSidebar;
})();
