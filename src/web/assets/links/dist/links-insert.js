(function() {
    'use strict';

    window.BeaconLinksInsert = {

        /**
         * Find the best matching phrase in the source entry's CKEditor content and insert a link to the target.
         *
         * @param {number} sourceId
         * @param {number} targetId
         * @param {number} siteId
         * @returns {Promise<boolean>}
         */
        async findAndInsert(sourceId, targetId, siteId) {
            const url = Craft.getActionUrl('beacon/link-suggestions/find-phrase', {
                sourceId: sourceId,
                targetId: targetId,
                siteId: siteId,
            });

            let data;
            try {
                const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                data = await response.json();
            } catch (e) {
                return false;
            }

            if (!data.success || !data.phrase) {
                return false;
            }

            // Skip auto-insert for very short phrases — too risky to link the wrong occurrence
            if (data.phrase.length < 4) {
                return false;
            }

            const editor = this.findCkEditor(data.fieldHandle);
            if (!editor) {
                return false;
            }

            const inserted = this.insertLink(editor, data.phrase, data.targetUrl, data.targetTitle);
            return inserted;
        },

        /**
         * Find the CKEditor instance associated with a field handle.
         *
         * @param {string} fieldHandle
         * @returns {object|null}
         */
        findCkEditor(fieldHandle) {
            if (typeof CKEDITOR !== 'undefined') {
                // CKEditor 4 style
                for (const name in CKEDITOR.instances) {
                    const inst = CKEDITOR.instances[name];
                    if (fieldHandle && name.toLowerCase().includes(fieldHandle.toLowerCase())) {
                        return inst;
                    }
                }
            }

            // CKEditor 5: look for elements with data-field-handle attribute
            if (fieldHandle) {
                const fieldContainer = document.querySelector('[data-field-handle="' + fieldHandle + '"]');
                if (fieldContainer) {
                    const editorEl = fieldContainer.querySelector('.ck-editor__editable');
                    if (editorEl && editorEl.ckeditorInstance) {
                        return editorEl.ckeditorInstance;
                    }
                }
            }

            // Fallback: find any CKEditor 5 instance on the page
            const editorElements = document.querySelectorAll('.ck-editor__editable');
            for (const el of editorElements) {
                if (el.ckeditorInstance) {
                    return el.ckeditorInstance;
                }
            }

            return null;
        },

        /**
         * Walk the editor model tree and wrap the first occurrence of the phrase text with a link.
         *
         * @param {object} editor - CKEditor 5 instance
         * @param {string} phrase
         * @param {string} url
         * @param {string} title
         * @returns {boolean}
         */
        insertLink(editor, phrase, url, title) {
            let inserted = false;

            try {
                editor.model.change(writer => {
                    const root = editor.model.document.getRoot();
                    const range = editor.model.createRangeIn(root);

                    // Walk all items in the document range
                    for (const item of range.getItems()) {
                        if (!item.is('$text') && !item.is('$textProxy')) {
                            continue;
                        }

                        const nodeText = item.data;
                        if (!nodeText) {
                            continue;
                        }

                        // Case-insensitive search for the phrase
                        const lowerNode = nodeText.toLowerCase();
                        const lowerPhrase = phrase.toLowerCase();
                        const idx = lowerNode.indexOf(lowerPhrase);

                        if (idx === -1) {
                            continue;
                        }

                        // Find the position of the phrase in this text node
                        const startPos = writer.createPositionAt(item.parent, item.startOffset + idx);
                        const endPos = writer.createPositionAt(item.parent, item.startOffset + idx + phrase.length);
                        const phraseRange = writer.createRange(startPos, endPos);

                        // Apply the linkHref attribute to the range
                        writer.setAttribute('linkHref', url, phraseRange);

                        inserted = true;
                        break;
                    }
                });
            } catch (e) {
                return false;
            }

            return inserted;
        },
    };
})();
