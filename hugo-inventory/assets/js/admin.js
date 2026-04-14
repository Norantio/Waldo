/* Hugo Inventory Admin JS */
(function($) {
    'use strict';

    /* ──────────────────────────────────────────────────────────
     * Barcode Scanner Listener (Keyboard Wedge / USB HID)
     *
     * USB barcode scanners act as keyboards — they type characters
     * very fast and press Enter at the end. We detect this by
     * tracking the time between keystrokes:
     *
     *  - If keystrokes arrive < threshold ms apart → scanner input
     *  - If a pause > threshold ms → human typing, clear buffer
     *  - On Enter with ≥ minLength chars in buffer → trigger lookup
     *
     * Only activates on pages with .hugo-inv-scan-enabled or on
     * the assets list / scan pages.
     * ────────────────────────────────────────────────────────── */

    var Scanner = {
        buffer: '',
        lastKeyTime: 0,
        threshold: 50,  // ms — max gap between scanner keystrokes
        minLength: 4,   // minimum characters to constitute a valid scan
        isActive: false,

        init: function() {
            // Activate on any plugin admin page.
            if (!window.hugoInventory) {
                return;
            }

            // Read configurable threshold from settings.
            if (hugoInventory.scannerThreshold) {
                this.threshold = parseInt(hugoInventory.scannerThreshold, 10) || 50;
            }

            this.isActive = true;
            this.bindEvents();
            this.createScanIndicator();
        },

        bindEvents: function() {
            var self = this;

            $(document).on('keydown', function(e) {
                if (!self.isActive) return;

                // Don't intercept if user is focused on an input/textarea
                // (unless it has the .hugo-inv-scan-field class).
                var tag = e.target.tagName.toLowerCase();
                var isScanField = $(e.target).hasClass('hugo-inv-scan-field');
                if ((tag === 'input' || tag === 'textarea' || tag === 'select') && !isScanField) {
                    return;
                }

                var now = Date.now();
                var timeDiff = now - self.lastKeyTime;

                // If too much time since last key, start fresh.
                if (timeDiff > self.threshold) {
                    self.buffer = '';
                }

                self.lastKeyTime = now;

                // Enter key — process the buffer.
                if (e.key === 'Enter') {
                    if (self.buffer.length >= self.minLength) {
                        e.preventDefault();
                        e.stopPropagation();
                        self.processBarcode(self.buffer);
                    }
                    self.buffer = '';
                    return;
                }

                // Only append printable single characters.
                if (e.key.length === 1) {
                    self.buffer += e.key;
                }
            });
        },

        createScanIndicator: function() {
            // Add a visual indicator bar at the top of the page.
            if ($('#hugo-inv-scan-result').length) return;

            $('body').append(
                '<div id="hugo-inv-scan-result" style="display:none;position:fixed;top:32px;left:0;right:0;z-index:99999;padding:12px 20px;font-size:14px;text-align:center;transition:opacity 0.3s;"></div>'
            );
        },

        showResult: function(message, type) {
            var $el = $('#hugo-inv-scan-result');
            var bg = type === 'success' ? '#46b450' : type === 'error' ? '#dc3232' : '#0073aa';

            $el.css({ background: bg, color: '#fff' })
               .text(message)
               .fadeIn(200);

            // Auto-hide after 3 seconds.
            clearTimeout(this._hideTimer);
            this._hideTimer = setTimeout(function() {
                $el.fadeOut(400);
            }, 3000);
        },

        processBarcode: function(barcode) {
            var self = this;

            self.showResult('Scanning: ' + barcode + '…', 'info');

            $.ajax({
                url: hugoInventory.restUrl + 'assets/lookup',
                method: 'GET',
                data: { barcode: barcode },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', hugoInventory.nonce);
                },
                success: function(data) {
                    if (data.found) {
                        self.showResult('Found: ' + data.asset.asset_tag + ' — ' + data.asset.name, 'success');

                        // Optional: play a beep sound.
                        self.beep(800, 150);

                        // Navigate to asset edit page after a brief flash.
                        setTimeout(function() {
                            window.location.href = hugoInventory.adminUrl +
                                'admin.php?page=hugo-inventory-assets&action=edit&id=' + data.asset.id;
                        }, 600);
                    } else {
                        self.showResult('Not found: ' + data.scanned_value + ' — Create new?', 'error');
                        self.beep(300, 300);

                        // After showing the error, prompt to create.
                        setTimeout(function() {
                            if (confirm('Asset "' + data.scanned_value + '" not found.\n\nCreate a new asset with this barcode?')) {
                                window.location.href = data.create_url;
                            }
                        }, 500);
                    }
                },
                error: function() {
                    self.showResult('Scan lookup failed — check connection.', 'error');
                }
            });
        },

        /**
         * Play a short beep using the Web Audio API.
         */
        beep: function(frequency, duration) {
            try {
                var ctx = new (window.AudioContext || window.webkitAudioContext)();
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.frequency.value = frequency;
                gain.gain.value = 0.1;
                osc.start();
                setTimeout(function() { osc.stop(); ctx.close(); }, duration);
            } catch(e) {
                // Web Audio not supported — silent fallback.
            }
        }
    };

    // Boot the scanner listener when DOM is ready.
    $(document).ready(function() {
        Scanner.init();
    });

})(jQuery);
