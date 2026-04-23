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

            // Track whether we just handled a scanner Enter on keydown,
            // so we can suppress the same Enter on keypress/keyup.
            var suppressNextEnter = false;

            $(document).on('keydown', function(e) {
                if (!self.isActive) return;

                // Don't intercept scans when a barcode/serial input is focused —
                // let the value go directly into the field.
                var focusedId = (e.target.id || '').toLowerCase();
                if (focusedId === 'barcode_value' || focusedId === 'serial_number' || focusedId === 'asset_tag') {
                    self.buffer = '';
                    return;
                }

                var now = Date.now();
                var timeDiff = now - self.lastKeyTime;

                // If too much time since last key, start fresh.
                if (timeDiff > self.threshold) {
                    self.buffer = '';
                }

                self.lastKeyTime = now;

                // Enter key — process the buffer if it looks like a scan.
                if (e.key === 'Enter') {
                    if (self.buffer.length >= self.minLength) {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        suppressNextEnter = true;

                        // If scanned into a form field, clear the injected text.
                        var tag = e.target.tagName.toLowerCase();
                        if (tag === 'input' || tag === 'textarea' || tag === 'select') {
                            var el = e.target;
                            var val = $(el).val() || '';
                            if (val.length >= self.buffer.length && val.slice(-self.buffer.length) === self.buffer) {
                                $(el).val(val.slice(0, -self.buffer.length));
                            }
                        }

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

            // Suppress Enter on keypress and keyup as well — some browsers
            // process form submission on these events, not keydown.
            $(document).on('keypress keyup', function(e) {
                if (suppressNextEnter && e.key === 'Enter') {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    if (e.type === 'keyup') {
                        suppressNextEnter = false;
                    }
                    return false;
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
                        self.beep(800, 150);

                        // If we're already editing this asset, stay on the page.
                        var params = new URLSearchParams(window.location.search);
                        var currentId = params.get('id');
                        var isEditPage = params.get('action') === 'edit' && params.get('page') === 'hugo-inventory-assets';
                        if (isEditPage && currentId && parseInt(currentId, 10) === data.asset.id) {
                            // Already viewing this asset — do nothing.
                            return;
                        }

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

        // ── Select2: Assigned User search (WP + Entra) ────────
        if ($('#assigned_user_search').length && $.fn.select2) {
            $('#assigned_user_search').select2({
                placeholder: '— Unassigned —',
                allowClear: true,
                minimumInputLength: 2,
                ajax: {
                    url: hugoInventory.restUrl + 'users/search',
                    dataType: 'json',
                    delay: 300,
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', hugoInventory.nonce);
                    },
                    data: function(params) {
                        return { q: params.term };
                    },
                    processResults: function(data) {
                        var results = [];
                        var wpGroup = [];
                        var entraGroup = [];

                        $.each(data.results || [], function(i, u) {
                            var item = {
                                id: u.source + ':' + u.id,
                                text: u.text + (u.email ? ' (' + u.email + ')' : ''),
                                source: u.source,
                                sourceId: u.id,
                                displayName: u.text
                            };
                            if (u.source === 'wp') {
                                wpGroup.push(item);
                            } else {
                                entraGroup.push(item);
                            }
                        });

                        if (wpGroup.length) {
                            results.push({ text: 'WordPress Users', children: wpGroup });
                        }
                        if (entraGroup.length) {
                            results.push({ text: 'Entra ID Directory', children: entraGroup });
                        }
                        // If no groups, return flat
                        if (!wpGroup.length && !entraGroup.length) {
                            results = [];
                        }

                        return { results: results };
                    },
                    cache: true
                },
                templateResult: function(item) {
                    if (item.loading) return item.text;
                    var $el = $('<span>');
                    $el.text(item.text);
                    if (item.source) {
                        var badge = item.source === 'entra' ? 'Entra' : 'WP';
                        var color = item.source === 'entra' ? '#0078d4' : '#0073aa';
                        $el.append(' <span style="background:' + color + ';color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;margin-left:4px;">' + badge + '</span>');
                    }
                    return $el;
                }
            }).on('select2:select', function(e) {
                var d = e.params.data;
                if (d.source === 'entra') {
                    $('#assigned_user_id').val('');
                    $('#assigned_entra_id').val(d.sourceId);
                    $('#assigned_entra_name').val(d.displayName);
                } else {
                    $('#assigned_user_id').val(d.sourceId);
                    $('#assigned_entra_id').val('');
                    $('#assigned_entra_name').val('');
                }
            }).on('select2:clear', function() {
                $('#assigned_user_id').val('');
                $('#assigned_entra_id').val('');
                $('#assigned_entra_name').val('');
            });
        }

        // ── Dashboard: Scan panel toggle ───────────────────────
        $('#hugo-inv-scan-btn').on('click', function() {
            var $panel = $('#hugo-inv-scan-panel');
            $panel.slideToggle(200, function() {
                if ($panel.is(':visible')) {
                    $('#hugo-inv-manual-scan').focus();
                }
            });
        });

        // ── Dashboard: Manual scan lookup ──────────────────────
        $('#hugo-inv-manual-scan-go').on('click', function() {
            var val = $.trim($('#hugo-inv-manual-scan').val());
            if (val.length >= Scanner.minLength) {
                Scanner.processBarcode(val);
                showScanResultInline(val);
            }
        });
        $('#hugo-inv-manual-scan').on('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $('#hugo-inv-manual-scan-go').trigger('click');
            }
        });

        // Show scan result inside the dashboard panel (in addition to the top bar).
        function showScanResultInline(barcode) {
            var $panel = $('#hugo-inv-scan-result-panel');
            $panel.html('<em>Looking up ' + $('<span>').text(barcode).html() + '…</em>').show();

            $.ajax({
                url: hugoInventory.restUrl + 'assets/lookup',
                method: 'GET',
                data: { barcode: barcode },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', hugoInventory.nonce);
                },
                success: function(data) {
                    if (data.found) {
                        var a = data.asset;
                        var editUrl = hugoInventory.adminUrl + 'admin.php?page=hugo-inventory-assets&action=edit&id=' + a.id;
                        $panel.html(
                            '<div class="hugo-inv-scan-found">' +
                            '<span class="dashicons dashicons-yes-alt" style="color:#46b450;font-size:20px;"></span>' +
                            '<div><strong>' + $('<span>').text(a.name).html() + '</strong> ' +
                            '<code>' + $('<span>').text(a.asset_tag).html() + '</code>' +
                            (a.status ? ' <span class="hugo-inv-status hugo-inv-status-' + a.status + '">' + a.status.replace('_', ' ') + '</span>' : '') +
                            '</div>' +
                            '<a href="' + editUrl + '" class="button button-small">View / Edit</a>' +
                            '</div>'
                        );
                    } else {
                        $panel.html(
                            '<div class="hugo-inv-scan-notfound">' +
                            '<span class="dashicons dashicons-dismiss" style="color:#dc3232;font-size:20px;"></span>' +
                            '<div>Not found: <strong>' + $('<span>').text(data.scanned_value).html() + '</strong></div>' +
                            '<a href="' + data.create_url + '" class="button button-small button-primary">Create Asset</a>' +
                            '</div>'
                        );
                    }
                },
                error: function() {
                    $panel.html('<div class="hugo-inv-scan-notfound"><span class="dashicons dashicons-warning"></span> Lookup failed — check connection.</div>');
                }
            });
        }

        // ── Dashboard: Table search filter ─────────────────────
        $('#hugo-inv-table-search').on('keyup', function() {
            filterDashboardTable();
        });

        // ── Dashboard: Status filter ───────────────────────────
        $('#hugo-inv-status-filter').on('change', function() {
            filterDashboardTable();
        });

        function filterDashboardTable() {
            var search = $.trim($('#hugo-inv-table-search').val()).toLowerCase();
            var status = $('#hugo-inv-status-filter').val();

            $('#hugo-inv-asset-table tbody tr').each(function() {
                var $row   = $(this);
                var rowSearch = ($row.data('search') || '').toString();
                var rowStatus = ($row.data('status') || '').toString();

                var matchSearch = !search || rowSearch.indexOf(search) !== -1;
                var matchStatus = !status || rowStatus === status;

                $row.toggle(matchSearch && matchStatus);
            });
        }

        // ── Dashboard: Select all checkbox ─────────────────────
        $('#hugo-inv-select-all').on('change', function() {
            var checked = $(this).prop('checked');
            $('#hugo-inv-asset-table tbody tr:visible .hugo-inv-row-cb').prop('checked', checked);
            updatePrintSelectedBtn();
        });

        $(document).on('change', '.hugo-inv-row-cb', function() {
            updatePrintSelectedBtn();
            // Uncheck "select all" if any row unchecked.
            if (!$(this).prop('checked')) {
                $('#hugo-inv-select-all').prop('checked', false);
            }
        });

        function updatePrintSelectedBtn() {
            var count = $('.hugo-inv-row-cb:checked').length;
            $('#hugo-inv-print-selected').prop('disabled', count === 0);
            if (count > 0) {
                $('#hugo-inv-print-selected').text('Print Selected (' + count + ')');
            } else {
                $('#hugo-inv-print-selected').html('<span class="dashicons dashicons-printer" style="vertical-align:text-top;margin-right:2px;"></span> Print Selected');
            }
        }

        // ── Dashboard: Print selected labels ───────────────────
        $('#hugo-inv-print-selected').on('click', function() {
            var ids = [];
            $('.hugo-inv-row-cb:checked').each(function() {
                ids.push($(this).val());
            });
            if (ids.length === 0) return;

            var nonce = hugoInventory.printNonce || '';
            var url = hugoInventory.ajaxUrl + '?action=hugo_inv_print_labels&ids=' + ids.join(',') + '&_wpnonce=' + nonce;
            window.open(url, '_blank');
        });
    });

})(jQuery);
