/* Hugo Inventory — Frontend JS */
(function($) {
    'use strict';

    if (typeof hugoInvFE === 'undefined') return;

    var api  = hugoInvFE.restUrl;
    var rest = hugoInvFE.nonce;
    var i18n = hugoInvFE.i18n;

    // ── Lookup shortcode ───────────────────────────────────────────

    function initLookup() {
        $('.hugo-inv-fe-lookup').each(function() {
            var $wrap   = $(this);
            var $input  = $wrap.find('.hugo-inv-fe-lookup-input');
            var $btn    = $wrap.find('.hugo-inv-fe-lookup-btn');
            var $result = $wrap.find('.hugo-inv-fe-lookup-result');

            function doLookup() {
                var val = $.trim($input.val());
                if (val.length < 2) return;

                $result.removeClass('found not-found').html('<em>' + esc(i18n.searching) + '</em>').show();

                $.ajax({
                    url: api + 'assets/lookup',
                    data: { barcode: val },
                    beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', rest); },
                    success: function(data) {
                        if (data.found) {
                            var a = data.asset;
                            $result.addClass('found').html(
                                '<div class="hugo-inv-fe-result-header">' +
                                    '<h4>' + esc(a.name) + '</h4>' +
                                    '<code>' + esc(a.asset_tag) + '</code>' +
                                    statusBadge(a.status) +
                                '</div>' +
                                '<dl class="hugo-inv-fe-lookup-detail">' +
                                    detailRow('Organization', a.organization_name) +
                                    detailRow('Location', a.location_name) +
                                    detailRow('Category', a.category_name) +
                                    detailRow('Serial', a.serial_number) +
                                    detailRow('Assigned To', a.assigned_user_display) +
                                    detailRow('Purchase Date', a.purchase_date) +
                                    detailRow('Warranty Exp.', a.warranty_expiration) +
                                '</dl>'
                            );
                        } else {
                            $result.addClass('not-found').html(
                                '<strong>' + esc(i18n.notFound) + ':</strong> ' + esc(data.scanned_value)
                            );
                        }
                    },
                    error: function() {
                        $result.addClass('not-found').html(esc(i18n.error));
                    }
                });
            }

            $btn.on('click', doLookup);
            $input.on('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); doLookup(); }
            });
        });
    }

    // ── Assets table filter + count ────────────────────────────────

    function initAssetsFilter() {
        $('.hugo-inv-fe-assets').each(function() {
            var $wrap    = $(this);
            var $search  = $wrap.find('.hugo-inv-fe-assets-search');
            var $status  = $wrap.find('.hugo-inv-fe-assets-status');
            var $countEl = $wrap.find('.hugo-inv-fe-assets-count-visible');

            function filter() {
                var q = $.trim($search.val()).toLowerCase();
                var s = $status.val();
                var visible = 0;
                $wrap.find('.hugo-inv-fe-assets-table tbody tr').each(function() {
                    var $row = $(this);
                    var matchQ = !q || ($row.data('search') || '').toString().indexOf(q) !== -1;
                    var matchS = !s || $row.data('status') === s;
                    var show   = matchQ && matchS;
                    $row.toggle(show);
                    if (show) visible++;
                });
                if ($countEl.length) $countEl.text(visible.toLocaleString());
            }

            $search.on('keyup', filter);
            $status.on('change', filter);
        });
    }

    // ── Assets table column sort ───────────────────────────────────

    function initAssetsSort() {
        $(document).on('click', '.hugo-inv-fe-assets-table .hugo-inv-fe-sortable', function() {
            var $th    = $(this);
            var $table = $th.closest('table');
            var $tbody = $table.find('tbody');
            var col    = $th.data('col');

            // Determine direction: flip if already sorted this col ASC, else default ASC.
            var currentDir = $th.data('sort-dir') || '';
            var dir = (currentDir === 'asc') ? 'desc' : 'asc';

            // Clear all other headers.
            $table.find('.hugo-inv-fe-sortable').not($th)
                .removeData('sort-dir')
                .removeClass('sorted-asc sorted-desc');

            $th.data('sort-dir', dir).removeClass('sorted-asc sorted-desc').addClass('sorted-' + dir);

            // Collect and sort visible + hidden rows separately (keep empty row in place).
            var $rows = $tbody.find('tr').not('.hugo-inv-fe-empty-row');
            var rows  = $rows.toArray();

            rows.sort(function(a, b) {
                var aVal = ($(a).data(col) || '').toString();
                var bVal = ($(b).data(col) || '').toString();
                // Numeric sort if both look like numbers.
                var aNum = parseFloat(aVal);
                var bNum = parseFloat(bVal);
                var cmp;
                if (!isNaN(aNum) && !isNaN(bNum)) {
                    cmp = aNum - bNum;
                } else {
                    cmp = aVal.localeCompare(bVal, undefined, { sensitivity: 'base' });
                }
                return dir === 'asc' ? cmp : -cmp;
            });

            $.each(rows, function(i, row) {
                $tbody.append(row);
            });
        });
    }

    // ── Checkout / Check-in tabs + forms ───────────────────────────

    function initCheckout() {
        // Tab switching.
        $('.hugo-inv-fe-checkout-tabs').on('click', '.hugo-inv-fe-tab', function() {
            var $tab = $(this);
            var target = $tab.data('tab');
            $tab.addClass('active').siblings().removeClass('active');
            $tab.closest('.hugo-inv-fe-checkout')
                .find('.hugo-inv-fe-tab-content').removeClass('active')
                .filter('#hugo-inv-fe-tab-' + target).addClass('active');
        });

        // Asset lookup on scan fields.
        $('.hugo-inv-fe-checkout').find('.hugo-inv-fe-scan-field').each(function() {
            var $input   = $(this);
            var $hidden  = $input.closest('.hugo-inv-fe-field').find('input[name="asset_id"]');
            var $preview = $input.closest('.hugo-inv-fe-field').find('.hugo-inv-fe-asset-preview');
            var timer;

            $input.on('keyup', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); return; }
                clearTimeout(timer);
                var val = $.trim($input.val());
                if (val.length < 3) { $preview.hide(); $hidden.val(''); return; }

                timer = setTimeout(function() {
                    $.ajax({
                        url: api + 'assets/lookup',
                        data: { barcode: val },
                        beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', rest); },
                        success: function(data) {
                            if (data.found) {
                                var a = data.asset;
                                $hidden.val(a.id);
                                $preview.removeClass('error').html(
                                    '<strong>' + esc(a.name) + '</strong> ' +
                                    '<code>' + esc(a.asset_tag) + '</code> — ' +
                                    statusBadge(a.status)
                                ).show();
                            } else {
                                $hidden.val('');
                                $preview.addClass('error').html(esc(i18n.notFound) + ': ' + esc(val)).show();
                            }
                        }
                    });
                }, 400);
            });
        });

        // Checkout submit.
        $('#hugo-inv-fe-checkout-form').on('submit', function(e) {
            e.preventDefault();
            var $form = $(this);
            var $msg  = $form.find('.hugo-inv-fe-message');
            var $btn  = $form.find('button[type="submit"]');

            var assetId = $form.find('input[name="asset_id"]').val();
            if (!assetId) { showMsg($msg, i18n.error, 'error'); return; }

            $btn.prop('disabled', true);

            $.post(hugoInvFE.ajaxUrl, {
                action: 'hugo_inv_fe_checkout',
                _hugo_inv_fe_nonce: $form.find('input[name="_hugo_inv_fe_nonce"]').val(),
                asset_id: assetId,
                expected_return_date: $form.find('input[name="expected_return_date"]').val(),
                checkout_notes: $form.find('textarea[name="checkout_notes"]').val()
            }, function(resp) {
                $btn.prop('disabled', false);
                if (resp.success) {
                    showMsg($msg, resp.data.message, 'success');
                    $form[0].reset();
                    $form.find('.hugo-inv-fe-asset-preview').hide();
                } else {
                    showMsg($msg, resp.data.message || i18n.error, 'error');
                }
            }).fail(function() {
                $btn.prop('disabled', false);
                showMsg($msg, i18n.error, 'error');
            });
        });

        // Check-in submit.
        $('#hugo-inv-fe-checkin-form').on('submit', function(e) {
            e.preventDefault();
            var $form = $(this);
            var $msg  = $form.find('.hugo-inv-fe-message');
            var $btn  = $form.find('button[type="submit"]');

            var assetId = $form.find('input[name="asset_id"]').val();
            if (!assetId) { showMsg($msg, i18n.error, 'error'); return; }

            $btn.prop('disabled', true);

            $.post(hugoInvFE.ajaxUrl, {
                action: 'hugo_inv_fe_checkin',
                _hugo_inv_fe_nonce2: $form.find('input[name="_hugo_inv_fe_nonce2"]').val(),
                asset_id: assetId,
                checkin_notes: $form.find('textarea[name="checkin_notes"]').val()
            }, function(resp) {
                $btn.prop('disabled', false);
                if (resp.success) {
                    showMsg($msg, resp.data.message, 'success');
                    $form[0].reset();
                    $form.find('.hugo-inv-fe-asset-preview').hide();
                } else {
                    showMsg($msg, resp.data.message || i18n.error, 'error');
                }
            }).fail(function() {
                $btn.prop('disabled', false);
                showMsg($msg, i18n.error, 'error');
            });
        });
    }

    // ── Helpers ─────────────────────────────────────────────────────

    var statusColors = {
        available: '#46b450', checked_out: '#0073aa', in_repair: '#ffb900',
        retired: '#826eb4', lost: '#dc3232'
    };

    function statusBadge(status) {
        if (!status) return '';
        var bg = statusColors[status] || '#666';
        var fg = status === 'in_repair' ? '#23282d' : '#fff';
        return '<span class="hugo-inv-fe-status" style="background:' + bg + ';color:' + fg + ';">' +
               esc(status.replace(/_/g, ' ')) + '</span>';
    }

    function detailRow(label, value) {
        if (!value) return '';
        return '<dt>' + esc(label) + '</dt><dd>' + esc(value) + '</dd>';
    }

    function esc(str) {
        if (!str) return '';
        return $('<span>').text(str).html();
    }

    function showMsg($el, text, type) {
        $el.removeClass('success error').addClass(type).text(text).show();
    }

    // ── Boot ───────────────────────────────────────────────────────

    $(document).ready(function() {
        initLookup();
        initAssetsFilter();
        initAssetsSort();
        initCheckout();
    });

})(jQuery);
