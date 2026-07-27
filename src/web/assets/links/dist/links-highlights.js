(function() {
    'use strict';

    /**
     * BeaconLinksHighlights — CKEditor 5 marker management for link suggestions.
     *
     * Finds CKEditor instances on the page, searches their content for
     * suggestion title matches, adds visual markers, and handles click-to-link.
     */
    const BeaconLinksHighlights = {
        active: false,
        suggestions: [],
        markers: [],  // { editorId, markerName, suggestion }
        onLinkInserted: null, // callback(suggestion) when a highlight is clicked

        /**
         * Get all CKEditor 5 instances currently on the page.
         *
         * CKEditor 5 stores a reference as .ckeditorInstance — but the location
         * varies by version: it may be on the source textarea, the editable div,
         * or accessible via the wrapper's data attribute. We try all strategies.
         */
        getEditors: function() {
            const editors = [];
            const seen = new Set();

            // Strategy 1: source textareas get .ckeditorInstance from ClassicEditor.create()
            document.querySelectorAll('textarea').forEach(el => {
                if (el.ckeditorInstance && !seen.has(el.ckeditorInstance)) {
                    seen.add(el.ckeditorInstance);
                    editors.push(el.ckeditorInstance);
                }
            });

            // Strategy 2: .ck-editor__editable elements (newer CKEditor builds)
            if (editors.length === 0) {
                document.querySelectorAll('.ck-editor__editable').forEach(el => {
                    if (el.ckeditorInstance && !seen.has(el.ckeditorInstance)) {
                        seen.add(el.ckeditorInstance);
                        editors.push(el.ckeditorInstance);
                    }
                });
            }

            // Strategy 3: find editors via the .ck wrapper and traverse to the source element
            if (editors.length === 0) {
                document.querySelectorAll('.ck-editor').forEach(wrapper => {
                    // The wrapper's previous sibling is usually the source textarea
                    const textarea = wrapper.previousElementSibling;
                    if (textarea && textarea.tagName === 'TEXTAREA' && textarea.ckeditorInstance) {
                        if (!seen.has(textarea.ckeditorInstance)) {
                            seen.add(textarea.ckeditorInstance);
                            editors.push(textarea.ckeditorInstance);
                        }
                    }
                });
            }

            return editors;
        },

        /**
         * Enable highlights for the given suggestions.
         * @param {Array} suggestions — [{elementId, title, url, score, ...}]
         * @param {Function} onLinkInserted — callback(suggestion) when link is inserted
         * @returns {Object} matchCounts — {elementId: numberOfMatches}
         */
        enable: function(suggestions, onLinkInserted) {
            this.suggestions = suggestions;
            this.onLinkInserted = onLinkInserted;
            this.active = true;
            return this.scan();
        },

        /**
         * Highlight matches for a single suggestion only (clears any existing markers).
         *
         * @param {Object} suggestion
         * @param {Function} onLinkInserted
         * @returns {number} match count for this suggestion
         */
        enableOne: function(suggestion, onLinkInserted) {
            this.removeAllMarkers();
            this.suggestions = [suggestion];
            this.onLinkInserted = onLinkInserted;
            this.active = true;
            const matchCounts = this.scan();
            return matchCounts[suggestion.elementId] || 0;
        },

        /**
         * Disable all highlights and remove all markers.
         */
        disable: function() {
            this.active = false;
            this.removeAllMarkers();
            this.suggestions = [];
            this.markers = [];
        },

        /**
         * Register the marker-to-highlight downcast conversion on an editor.
         * Must be called BEFORE adding markers so CKEditor knows how to render them.
         */
        registerConversion: function(editor) {
            if (editor._beaconLinksConversionRegistered) return;
            editor.conversion.for('editingDowncast').markerToHighlight({
                model: 'beaconLinks',
                view: (data) => {
                    const parts = data.markerName.split(':');
                    const elementId = parts[1];
                    return {
                        classes: ['beacon-links-highlight'],
                        attributes: {
                            'data-beacon-links-target': elementId,
                            'title': 'Click to link',
                        },
                    };
                },
            });
            editor._beaconLinksConversionRegistered = true;
        },

        /**
         * Scan all editors for suggestion title matches and add markers.
         * @returns {Object} matchCounts — {elementId: numberOfMatches}
         */
        scan: function() {
            this.removeAllMarkers();
            this.markers = [];
            const matchCounts = {};
            const editors = this.getEditors();

            // Register conversion on all editors BEFORE adding markers
            editors.forEach(editor => this.registerConversion(editor));

            this.suggestions.forEach(suggestion => {
                let totalMatches = 0;
                const searchPhrases = this.generateSearchPhrases(suggestion.title);

                // Fallback: if title phrases don't match, use overlapping keywords
                const keywordPhrases = this.generateKeywordPhrases(suggestion.keywords || []);

                editors.forEach(editor => {
                    // Try title phrases first (longest first)
                    let matched = false;
                    for (const phrase of searchPhrases) {
                        const matches = this.findTextInEditor(editor, phrase);
                        if (matches.length > 0) {
                            matches.forEach((range, index) => {
                                const markerName = 'beaconLinks:' + suggestion.elementId + ':' + editor.id + ':' + index;
                                this.addMarker(editor, markerName, range, suggestion);
                                totalMatches++;
                            });
                            matched = true;
                            break;
                        }
                    }
                    // Fallback: highlight overlapping keywords (max 3 to avoid noise)
                    if (!matched && keywordPhrases.length > 0) {
                        let kwMatches = 0;
                        for (const kw of keywordPhrases) {
                            if (kwMatches >= 3) break;
                            const matches = this.findTextInEditor(editor, kw);
                            if (matches.length > 0) {
                                // Only mark the first occurrence of each keyword
                                const range = matches[0];
                                const markerName = 'beaconLinks:' + suggestion.elementId + ':' + editor.id + ':kw' + kwMatches;
                                this.addMarker(editor, markerName, range, suggestion);
                                totalMatches++;
                                kwMatches++;
                            }
                        }
                    }
                });
                matchCounts[suggestion.elementId] = totalMatches;
            });

            // Register click handlers on all editors
            this.registerClickHandlers(editors);

            return matchCounts;
        },

        /**
         * Find all occurrences of searchText in the editor's text content.
         * Returns an array of model Ranges.
         */
        findTextInEditor: function(editor, searchText) {
            const ranges = [];
            const searchLower = searchText.toLowerCase();
            const root = editor.model.document.getRoot();

            // Walk through all text nodes in the model
            const modelRange = editor.model.createRangeIn(root);

            // Build a flat text representation with position mapping
            const textParts = [];
            for (const item of modelRange.getItems()) {
                if (item.is('$text') || item.is('$textProxy')) {
                    textParts.push({
                        text: item.data,
                        startOffset: item.startOffset,
                        parent: item.parent,
                    });
                }
            }

            // For each text node's parent (paragraph, etc.), build contiguous text and search
            const parentGroups = new Map();
            textParts.forEach(part => {
                const parentId = part.parent.getPath().join(',');
                if (!parentGroups.has(parentId)) {
                    parentGroups.set(parentId, { parent: part.parent, parts: [] });
                }
                parentGroups.get(parentId).parts.push(part);
            });

            parentGroups.forEach(group => {
                // Build the full text of this parent element
                let fullText = '';
                const offsets = []; // maps char index in fullText to {part, localOffset}
                group.parts.forEach(part => {
                    for (let i = 0; i < part.text.length; i++) {
                        offsets.push({ part: part, localOffset: i });
                    }
                    fullText += part.text;
                });

                // Search for all occurrences (case-insensitive)
                const fullTextLower = fullText.toLowerCase();
                let searchFrom = 0;
                while (true) {
                    const idx = fullTextLower.indexOf(searchLower, searchFrom);
                    if (idx === -1) break;

                    const startInfo = offsets[idx];
                    const endInfo = offsets[idx + searchLower.length - 1];

                    // Create model positions and range
                    try {
                        const startPosition = editor.model.createPositionAt(
                            startInfo.part.parent,
                            startInfo.part.startOffset + startInfo.localOffset
                        );
                        const endPosition = editor.model.createPositionAt(
                            endInfo.part.parent,
                            endInfo.part.startOffset + endInfo.localOffset + 1
                        );
                        const range = editor.model.createRange(startPosition, endPosition);
                        ranges.push(range);
                    } catch (e) {
                        // Position creation can fail for edge cases — skip
                    }

                    searchFrom = idx + 1;
                }
            });

            return ranges;
        },

        /**
         * Generate highlight phrases from overlapping keywords.
         * Multi-word keywords first (more specific), then single words.
         * Only keywords with 4+ chars to avoid noise.
         */
        generateKeywordPhrases: function(keywords) {
            if (!keywords || keywords.length === 0) return [];
            // Sort: multi-word first (more specific), then by length desc
            const sorted = [...keywords].sort((a, b) => {
                const aWords = a.split(/\s+/).length;
                const bWords = b.split(/\s+/).length;
                if (bWords !== aWords) return bWords - aWords;
                return b.length - a.length;
            });
            return sorted.filter(kw => kw.length >= 4);
        },

        /**
         * Generate search phrases from a title, longest first.
         * Full title first, then significant sub-phrases (2+ word n-grams, min 10 chars).
         * Strips common filler words from the start.
         */
        generateSearchPhrases: function(title) {
            const phrases = [title]; // Always try exact title first
            const filler = ['a', 'an', 'the', 'in', 'on', 'for', 'with', 'and', 'or', 'to', 'of', 'vs', 'from', 'your'];
            const words = title.split(/\s+/);

            // Generate contiguous sub-phrases of decreasing length (min 2 words)
            for (let len = words.length - 1; len >= 2; len--) {
                for (let start = 0; start <= words.length - len; start++) {
                    const subWords = words.slice(start, start + len);
                    // Skip if first word is a filler word
                    if (filler.includes(subWords[0].toLowerCase())) continue;
                    const phrase = subWords.join(' ');
                    // Skip short phrases (too generic) and duplicates
                    if (phrase.length >= 10 && phrase !== title && !phrases.includes(phrase)) {
                        phrases.push(phrase);
                    }
                }
            }

            return phrases;
        },

        /**
         * Add a visual marker to the editor.
         */
        addMarker: function(editor, markerName, range, suggestion) {
            try {
                editor.model.change(writer => {
                    writer.addMarker(markerName, {
                        range: range,
                        usingOperation: false,
                        affectsData: false,
                    });
                });

                this.markers.push({
                    editorId: editor.id,
                    markerName: markerName,
                    suggestion: suggestion,
                    editor: editor,
                });
            } catch (e) {
                // Marker creation can fail if range is invalid — skip silently
            }
        },

        /**
         * Remove all link-suggestion markers from all editors.
         */
        removeAllMarkers: function() {
            this.markers.forEach(entry => {
                try {
                    const marker = entry.editor.model.markers.get(entry.markerName);
                    if (marker) {
                        entry.editor.model.change(writer => {
                            writer.removeMarker(entry.markerName);
                        });
                    }
                } catch (e) {
                    // Editor may have been destroyed — ignore
                }
            });
            this.markers = [];

            // Also clean up click handlers
            const editors = this.getEditors();
            editors.forEach(editor => {
                if (editor._beaconLinksClickHandler) {
                    editor.editing.view.document.off('click', editor._beaconLinksClickHandler);
                    editor._beaconLinksClickHandler = null;
                }
            });
        },

        /**
         * Register click handlers on editor editable elements to detect highlight clicks.
         */
        registerClickHandlers: function(editors) {
            editors.forEach(editor => {
                // Avoid duplicate handlers
                if (editor._beaconLinksClickHandler) return;

                const editableElement = editor.editing.view.getDomRoot();
                if (!editableElement) return;

                const handler = (e) => {
                    const highlightEl = e.target.closest('.beacon-links-highlight');
                    if (!highlightEl) return;

                    e.preventDefault();
                    e.stopPropagation();

                    const targetId = parseInt(highlightEl.dataset.beaconLinksTarget);
                    this.linkHighlight(editor, targetId);
                };

                editableElement.addEventListener('click', handler);
                editor._beaconLinksClickHandler = handler;
                editor._beaconLinksClickElement = editableElement;
            });
        },

        /**
         * Convert a highlighted phrase into an actual link.
         */
        linkHighlight: function(editor, targetElementId) {
            // Find the suggestion data
            const suggestion = this.suggestions.find(s => s.elementId === targetElementId);
            if (!suggestion) return;

            // Find the first marker for this suggestion in this editor
            const markerEntry = this.markers.find(
                m => m.suggestion.elementId === targetElementId && m.editor === editor
            );
            if (!markerEntry) return;

            const marker = editor.model.markers.get(markerEntry.markerName);
            if (!marker) return;

            const range = marker.getRange();
            const url = suggestion.url || suggestion.cpEditUrl;

            // Apply the link
            editor.model.change(writer => {
                // Remove the marker first
                writer.removeMarker(markerEntry.markerName);
                // Set linkHref attribute on the range
                writer.setAttribute('linkHref', url, range);
            });

            // Remove ALL markers for this suggestion (across all editors)
            this.removeMarkersForSuggestion(targetElementId);

            // Notify the sidebar
            if (this.onLinkInserted) {
                this.onLinkInserted(suggestion);
            }
        },

        /**
         * Remove all markers for a specific suggestion across all editors.
         */
        removeMarkersForSuggestion: function(elementId) {
            const toRemove = this.markers.filter(m => m.suggestion.elementId === elementId);
            toRemove.forEach(entry => {
                try {
                    const marker = entry.editor.model.markers.get(entry.markerName);
                    if (marker) {
                        entry.editor.model.change(writer => {
                            writer.removeMarker(entry.markerName);
                        });
                    }
                } catch (e) {
                    // Ignore
                }
            });
            this.markers = this.markers.filter(m => m.suggestion.elementId !== elementId);
        },
    };

    window.BeaconLinksHighlights = BeaconLinksHighlights;
})();
