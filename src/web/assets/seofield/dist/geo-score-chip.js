/**
 * Beacon GEO score chip — async refresh.
 *
 * The GEO score is recomputed on the queue after an entry save, but the chip
 * is rendered server-side once at page load. On the post-save reload the job
 * usually hasn't finished yet, so the chip shows the pending state and would
 * otherwise stay stale until the editor reloads again.
 *
 * This polls `beacon/geo-score/status` for each pending chip and swaps it for
 * the rendered score chip as soon as the job persists a row. If the score
 * never lands (e.g. the entry was never resaved since scoring was enabled),
 * polling stops after a bounded window and the chip reverts to its idle hint.
 */
(function() {
    'use strict';

    if (document.documentElement.dataset.beaconGeoChipBound === '1') {
        return;
    }
    document.documentElement.dataset.beaconGeoChipBound = '1';

    var INTERVAL_MS = 2500;
    var MAX_ATTEMPTS = 24; // ~60s, comfortably past the auto queue runner.

    function statusUrl(elementId, siteId) {
        // Craft.getActionUrl is provided by the CP asset bundle (CpAsset).
        return Craft.getActionUrl('beacon/geo-score/status', {
            elementId: elementId,
            siteId: siteId,
        });
    }

    function poll(chip, attempt) {
        var elementId = chip.getAttribute('data-element-id');
        var siteId = chip.getAttribute('data-site-id');
        if (!elementId || !siteId || elementId === '0' || siteId === '0') {
            return;
        }

        fetch(statusUrl(elementId, siteId), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
            .then(function(res) {
                return res.ok ? res.json() : null;
            })
            .then(function(data) {
                if (data && data.ready && data.html) {
                    swap(chip, data.html);
                    return;
                }
                if (attempt + 1 >= MAX_ATTEMPTS) {
                    revertToIdle(chip);
                    return;
                }
                window.setTimeout(function() {
                    // Bail if the chip left the DOM (slideout closed, nav away).
                    if (chip.isConnected !== false) {
                        poll(chip, attempt + 1);
                    }
                }, INTERVAL_MS);
            })
            .catch(function() {
                // Permission/network error: leave the idle hint, don't retry.
                revertToIdle(chip);
            });
    }

    function swap(chip, html) {
        var tmp = document.createElement('div');
        tmp.innerHTML = html.trim();
        var next = tmp.firstElementChild;
        if (next && chip.parentNode) {
            chip.parentNode.replaceChild(next, chip);
        }
    }

    function revertToIdle(chip) {
        var idle = chip.getAttribute('data-idle-label');
        var label = chip.querySelector('.beacon-geo-score-chip__pending');
        if (idle && label) {
            label.textContent = idle;
        }
        chip.removeAttribute('data-beacon-geo-chip');
    }

    function start(root) {
        var chips = (root || document).querySelectorAll(
            '.beacon-geo-score-chip--pending[data-beacon-geo-chip]'
        );
        Array.prototype.forEach.call(chips, function(chip) {
            if (chip.dataset.beaconGeoPolling === '1') {
                return;
            }
            chip.dataset.beaconGeoPolling = '1';
            poll(chip, 0);
        });
    }

    if (!window.fetch || typeof Craft === 'undefined' || !Craft.getActionUrl) {
        return;
    }

    start(document);

    // Slideouts and inline element editors inject the field after load.
    if (window.MutationObserver) {
        new MutationObserver(function(muts) {
            muts.forEach(function(m) {
                m.addedNodes.forEach(function(n) {
                    if (n.nodeType === 1) {
                        start(n);
                    }
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }
})();
