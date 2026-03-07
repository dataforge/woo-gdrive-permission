(function ($) {
    'use strict';

    /* =========================================================
     * Product page: Google Picker integration (multi-file)
     * ========================================================= */

    var gapiLoaded = false;
    var gapiLoading = false;
    var gapiCallbacks = [];

    function getAccountId($button) {
        var $container = $button.closest('.options_group, .wgdp-variation-fields');
        return $container.find('.wgdp-account-select').val() || '';
    }

    function ensureGapi(callback) {
        if (gapiLoaded) {
            callback();
            return;
        }
        gapiCallbacks.push(callback);
        if (gapiLoading) {
            return;
        }
        gapiLoading = true;
        var script = document.createElement('script');
        script.src = 'https://apis.google.com/js/api.js';
        script.onload = function () {
            gapi.load('picker', function () {
                gapiLoaded = true;
                gapiLoading = false;
                var cbs = gapiCallbacks.splice(0);
                for (var i = 0; i < cbs.length; i++) {
                    cbs[i]();
                }
            });
        };
        document.head.appendChild(script);
    }

    function openPicker($button) {
        var accountId = getAccountId($button);
        if (!accountId) {
            alert('Please select a Google Account first.');
            return;
        }

        if (!wgdp.picker_api_key) {
            alert('Picker API Key is not configured. Please add it in the plugin settings.');
            return;
        }

        $button.prop('disabled', true).text('Loading...');

        // Get the access token for this account.
        $.post(wgdp.ajax_url, {
            action: 'wgdp_get_picker_token',
            nonce: wgdp.nonce,
            account_id: accountId
        }, function (response) {
            if (!response.success) {
                $button.prop('disabled', false).text('Browse GDrive');
                alert('Error getting token: ' + response.data);
                return;
            }

            var token = response.data.token;

            ensureGapi(function () {
                $button.prop('disabled', false).text('Browse GDrive');

                var docsView = new google.picker.DocsView()
                    .setIncludeFolders(true)
                    .setSelectFolderEnabled(false)
                    .setParent('root')
                    .setMode(google.picker.DocsViewMode.LIST);

                var builder = new google.picker.PickerBuilder()
                    .addView(docsView)
                    .enableFeature(google.picker.Feature.MULTISELECT_ENABLED)
                    .setOAuthToken(token)
                    .setDeveloperKey(wgdp.picker_api_key)
                    .setCallback(function (data) {
                        pickerCallback(data, $button);
                    });

                if (wgdp.cloud_project_number) {
                    builder.setAppId(wgdp.cloud_project_number);
                }

                builder.build().setVisible(true);
            });
        }).fail(function () {
            $button.prop('disabled', false).text('Browse GDrive');
            alert('Request failed.');
        });
    }

    function pickerCallback(data, $button) {
        if (data.action !== google.picker.Action.PICKED) {
            return;
        }

        var $container = $button.closest('.options_group, .wgdp-variation-fields');
        var $list = $container.find('.wgdp-resources-list');

        for (var i = 0; i < data.docs.length; i++) {
            var doc = data.docs[i];
            if (doc.mimeType === 'application/vnd.google-apps.folder') {
                alert('Folders cannot be linked — please select individual files.');
                continue;
            }
            addResourceRow({
                id: doc.id,
                name: doc.name,
                type: 'file'
            }, $list);
        }

        notifyVariationChanged($list);
    }

    /**
     * Add a resource row to the list. Dedup by file ID.
     * If file matches an existing retired row, restore it instead.
     */
    /**
     * Notify WooCommerce that a variation's fields changed,
     * so the "Save changes" button becomes active.
     */
    function notifyVariationChanged($el) {
        var $variation = $el.closest('.woocommerce_variation');
        if ($variation.length) {
            $variation.addClass('variation-needs-update');
            $('button.cancel-variation-changes, button.save-variation-changes').prop('disabled', false);
            $('#woocommerce-product-data').triggerHandler('wc_variations_input_changed');
        }
    }

    function addResourceRow(file, $list) {
        // Dedup check — look for existing row with same ID.
        var $existing = null;
        $list.find('input[type="hidden"]').each(function () {
            if ($(this).attr('name') && $(this).attr('name').indexOf('[id]') !== -1 && $(this).val() === file.id) {
                $existing = $(this).closest('.wgdp-resource-row');
                return false;
            }
        });

        if ($existing) {
            // If retired, restore it.
            if ($existing.hasClass('wgdp-resource-row--retired')) {
                $existing.removeClass('wgdp-resource-row--retired');
                $existing.find('.wgdp-resource-status').val('active');
                $existing.find('.wgdp-badge--retired').remove();
                $existing.find('.wgdp-retired-note').remove();
                $existing.find('.wgdp-resource-row-restore').remove();
            }
            return;
        }

        var prefix = $list.data('name-prefix');
        var index = $list.find('.wgdp-resource-row').length;
        var viewUrl = (file.type === 'folder')
            ? 'https://drive.google.com/drive/folders/' + file.id
            : 'https://drive.google.com/file/d/' + file.id + '/view';

        var $row = $('<span class="wgdp-resource-row">' +
            '<input type="hidden" name="' + prefix + '[' + index + '][id]" value="' + $('<span>').text(file.id).html() + '" />' +
            '<input type="hidden" name="' + prefix + '[' + index + '][type]" value="' + $('<span>').text(file.type || 'file').html() + '" />' +
            '<input type="hidden" name="' + prefix + '[' + index + '][name]" value="' + $('<span>').text(file.name).html() + '" />' +
            '<input type="hidden" name="' + prefix + '[' + index + '][status]" value="active" class="wgdp-resource-status" />' +
            '<span class="wgdp-resource-row-info">' +
                '<strong>' + $('<span>').text(file.name).html() + '</strong> ' +
                '<a href="' + viewUrl + '" target="_blank">View</a>' +
            '</span>' +
            '<a href="#" class="wgdp-resource-row-remove" title="Remove">&times;</a>' +
        '</span>');

        $list.append($row);
    }

    /**
     * Re-index resource row name attributes after removal.
     */
    function reindexResourceRows($list) {
        var prefix = $list.data('name-prefix');
        $list.find('.wgdp-resource-row').each(function (i) {
            $(this).find('input[type="hidden"]').each(function () {
                // Extract the field name (id, type, name) from the last bracket pair.
                var field = $(this).attr('name').match(/\[([^\]]+)\]$/);
                if (field) {
                    $(this).attr('name', prefix + '[' + i + '][' + field[1] + ']');
                }
            });
        });
    }

    // Browse button click.
    $(document).on('click', '.wgdp-browse-drive', function () {
        openPicker($(this));
    });

    // Remove resource row: active → retire (keep in DOM), already retired → remove from DOM.
    $(document).on('click', '.wgdp-resource-row-remove', function (e) {
        e.preventDefault();
        var $row = $(this).closest('.wgdp-resource-row');
        var $list = $row.closest('.wgdp-resources-list');

        if ($row.hasClass('wgdp-resource-row--retired')) {
            // Already retired — remove from DOM.
            $row.remove();
            reindexResourceRows($list);
        } else {
            // Active — retire it.
            $row.addClass('wgdp-resource-row--retired');
            $row.find('.wgdp-resource-status').val('retired_manual');
            // Add badge and restore button.
            $row.find('.wgdp-resource-row-info').append(' <span class="wgdp-badge--retired">Retired</span>');
            $(this).before('<a href="#" class="wgdp-resource-row-restore" title="Restore">Restore</a> ');
        }

        notifyVariationChanged($list);
    });

    // Restore a retired resource row.
    $(document).on('click', '.wgdp-resource-row-restore', function (e) {
        e.preventDefault();
        var $row = $(this).closest('.wgdp-resource-row');
        $row.removeClass('wgdp-resource-row--retired');
        $row.find('.wgdp-resource-status').val('active');
        $row.find('.wgdp-badge--retired').remove();
        $row.find('.wgdp-retired-note').remove();
        $(this).remove();
        notifyVariationChanged($row);
    });

    /* =========================================================
     * Product page: URL paste detection (multi-file)
     * ========================================================= */

    $(document).on('blur', '.wgdp-resource-url-input', function () {
        var $input = $(this);
        var val = $input.val().trim();

        if (!val || val.indexOf('drive.google.com') === -1) {
            return;
        }

        var $container = $input.closest('.options_group, .wgdp-variation-fields');
        var accountId = $container.find('.wgdp-account-select').val() || '';

        if (!accountId) {
            return;
        }

        $input.prop('disabled', true);

        $.post(wgdp.ajax_url, {
            action: 'wgdp_get_file_info',
            nonce: wgdp.nonce,
            file_id: val,
            account_id: accountId
        }, function (response) {
            $input.prop('disabled', false);
            if (response.success) {
                var file = response.data;
                if (file.resourceType === 'folder') {
                    alert('Folders cannot be linked — please use individual file URLs.');
                    return;
                }
                var $list = $container.find('.wgdp-resources-list');
                addResourceRow({
                    id: file.id,
                    name: file.name,
                    type: 'file'
                }, $list);
                $input.val('');
                notifyVariationChanged($list);
            }
        }).fail(function () {
            $input.prop('disabled', false);
        });
    });

    /* =========================================================
     * Order meta box & entitlements list: Resend OTP button
     * ========================================================= */

    $(document).on('click', '.wgdp-resend-otp-btn', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Sending...');

        $.post(wgdp.ajax_url, {
            action: 'wgdp_resend_otp',
            nonce: wgdp.nonce,
            entitlement_id: $btn.data('entitlement-id')
        }, function (response) {
            if (response.success) {
                $btn.text('Sent!');
                setTimeout(function () {
                    $btn.prop('disabled', false).text('Resend OTP');
                }, 2000);
            } else {
                $btn.prop('disabled', false).text('Resend OTP');
                alert('Error: ' + response.data);
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Resend OTP');
            alert('Request failed.');
        });
    });

    /* =========================================================
     * Order meta box: Add entitlement
     * ========================================================= */

    $(document).on('click', '.wgdp-add-entitlement-btn', function () {
        var $btn = $(this);
        var $input = $btn.siblings('.wgdp-add-email-input');
        var email = $.trim($input.val());

        if (!email) {
            alert('Please enter an email address.');
            return;
        }

        $btn.prop('disabled', true).text('Adding...');

        $.post(wgdp.ajax_url, {
            action: 'wgdp_add_entitlement',
            nonce: wgdp.nonce,
            order_id: $btn.data('order-id'),
            order_item_id: $btn.data('order-item-id'),
            email: email
        }, function (response) {
            if (response.success) {
                var d = response.data;
                var orderItemId = $btn.data('order-item-id');
                var $table = $btn.closest('.wgdp-add-entitlement-form').prev('table.wgdp-recipients-table[data-order-item-id="' + orderItemId + '"]');

                // Build the new row.
                var fileCount = d.file_count || 1;
                var newRow = '<tr>' +
                    '<td>' + $('<span>').text(d.recipient_index).html() + '</td>' +
                    '<td>' + $('<span>').text(d.email).html() + '</td>' +
                    '<td>' + fileCount + ' file' + (fileCount > 1 ? 's' : '') + '</td>' +
                    '<td><span class="wgdp-status-badge wgdp-vstatus--pending">Pending</span></td>' +
                    '<td><span class="wgdp-status-badge wgdp-gstatus--pending">Pending</span></td>' +
                    '<td>' +
                        '<button type="button" class="button button-small wgdp-resend-otp-btn" data-entitlement-id="' + d.id + '">Resend OTP</button> ' +
                        '<button type="button" class="button button-small wgdp-revoke-entitlement-btn" data-entitlement-id="' + d.id + '" style="color:#b32d2e;">Revoke</button>' +
                    '</td>' +
                    '</tr>';

                if (!$table.length) {
                    // No table yet — create one before the form.
                    var tableHtml = '<table class="widefat fixed striped wgdp-recipients-table" data-order-item-id="' + orderItemId + '" style="margin-bottom:12px;">' +
                        '<thead><tr><th style="width:30px;">#</th><th>Email</th><th>Files</th><th>Verification</th><th>Grant</th><th>Actions</th></tr></thead>' +
                        '<tbody>' + newRow + '</tbody></table>';
                    $btn.closest('.wgdp-add-entitlement-form').before(tableHtml);
                } else {
                    $table.find('tbody').append(newRow);
                }

                $input.val('');
                $btn.prop('disabled', false).text('Add Recipient');
            } else {
                alert('Error: ' + response.data);
                $btn.prop('disabled', false).text('Add Recipient');
            }
        }).fail(function () {
            alert('Request failed.');
            $btn.prop('disabled', false).text('Add Recipient');
        });
    });

    /* =========================================================
     * Order meta box & entitlements list: Revoke entitlement button
     * ========================================================= */

    $(document).on('click', '.wgdp-revoke-entitlement-btn', function () {
        if (!confirm('Revoke this entitlement? If access was granted, it will be removed from Google Drive.')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Revoking...');

        var postData = {
            action: 'wgdp_revoke_entitlement',
            nonce: wgdp.nonce,
            entitlement_id: $btn.data('entitlement-id')
        };
        if ($btn.data('scope')) {
            postData.scope = $btn.data('scope');
        }

        $.post(wgdp.ajax_url, postData, function (response) {
            if (response.success) {
                // Update the row to show revoked status.
                var $row = $btn.closest('tr');
                if ($row.length) {
                    $row.find('.wgdp-status-badge').filter(function() {
                        return $(this).hasClass('wgdp-gstatus--pending') ||
                               $(this).hasClass('wgdp-gstatus--granted') ||
                               $(this).hasClass('wgdp-gstatus--error');
                    }).removeClass('wgdp-gstatus--pending wgdp-gstatus--granted wgdp-gstatus--error')
                      .addClass('wgdp-gstatus--revoked').text('Revoked');
                }
                $btn.closest('td').find('.wgdp-resend-otp-btn').remove();
                $btn.remove();
            } else {
                $btn.prop('disabled', false).text('Revoke');
                alert('Error: ' + response.data);
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Revoke');
            alert('Request failed.');
        });
    });

    /* =========================================================
     * Order meta box & Access Manager: Retry Grant button
     * ========================================================= */

    $(document).on('click', '.wgdp-retry-grant-btn', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Retrying...');

        $.post(wgdp.ajax_url, {
            action: 'wgdp_retry_grant',
            nonce: wgdp.nonce,
            entitlement_id: $btn.data('entitlement-id')
        }, function (response) {
            if (response.success) {
                var d = response.data;
                var $row = $btn.closest('tr');
                if ($row.length) {
                    // Update grant status badge.
                    var statusClass = d.status === 'granted' ? 'wgdp-gstatus--granted' : 'wgdp-gstatus--error';
                    var statusLabel = d.status === 'granted' ? 'Granted' : 'Error';
                    $row.find('[class*="wgdp-gstatus--"]')
                        .removeClass('wgdp-gstatus--error wgdp-gstatus--pending wgdp-gstatus--granted wgdp-gstatus--pending_release')
                        .addClass(statusClass).text(statusLabel);

                    // Remove error message text.
                    if (d.status === 'granted') {
                        $row.find('small[style*="color:#d63638"]').remove();
                        $btn.replaceWith('<span style="color:#00a32a;font-weight:600;">' + d.message + '</span>');
                    } else {
                        $btn.prop('disabled', false).text('Retry Grant');
                        alert(d.message);
                    }
                } else {
                    $btn.prop('disabled', false).text('Retry Grant');
                    alert(d.message);
                }
            } else {
                $btn.prop('disabled', false).text('Retry Grant');
                alert('Error: ' + response.data);
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Retry Grant');
            alert('Request failed.');
        });
    });

    /* =========================================================
     * Access Manager: Change Email (inline edit)
     * ========================================================= */

    // Show inline edit form.
    $(document).on('click', '.wgdp-am-change-email-btn', function () {
        var $td = $(this).closest('tr').find('.wgdp-am-email-display').closest('td');
        $td.find('.wgdp-am-email-display').hide();
        $td.find('.wgdp-am-email-edit').show();
    });

    // Cancel inline edit.
    $(document).on('click', '.wgdp-am-email-cancel', function () {
        var $td = $(this).closest('td');
        $td.find('.wgdp-am-email-edit').hide();
        $td.find('.wgdp-am-email-display').show();
    });

    // Save new email.
    $(document).on('click', '.wgdp-am-email-save', function () {
        var $btn = $(this);
        var $td = $btn.closest('td');
        var newEmail = $td.find('.wgdp-am-email-input').val().trim();
        var entitlementId = $btn.data('entitlement-id');

        if (!newEmail) {
            alert('Please enter an email address.');
            return;
        }

        $btn.prop('disabled', true).text('Saving...');

        $.post(wgdp.ajax_url, {
            action: 'wgdp_am_change_email',
            nonce: wgdp.nonce,
            entitlement_id: entitlementId,
            new_email: newEmail
        }, function (response) {
            if (response.success) {
                var d = response.data;
                $td.find('.wgdp-am-email-display').text(d.new_email).show();
                $td.find('.wgdp-am-email-edit').hide();

                // Update status badges in the same row.
                var $row = $btn.closest('tr');
                $row.find('.wgdp-vstatus--pending, .wgdp-vstatus--verified, .wgdp-vstatus--expired')
                    .removeClass('wgdp-vstatus--pending wgdp-vstatus--verified wgdp-vstatus--expired')
                    .addClass('wgdp-vstatus--pending').text('Pending');
                $row.find('.wgdp-gstatus--pending, .wgdp-gstatus--granted, .wgdp-gstatus--error, .wgdp-gstatus--pending_release')
                    .removeClass('wgdp-gstatus--pending wgdp-gstatus--granted wgdp-gstatus--error wgdp-gstatus--pending_release')
                    .addClass('wgdp-gstatus--pending').text('Pending');

                if (d.warning) {
                    alert(d.warning);
                }
                $btn.prop('disabled', false).text('Save');
            } else {
                $btn.prop('disabled', false).text('Save');
                alert('Error: ' + response.data);
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Save');
            alert('Request failed.');
        });
    });

    /* =========================================================
     * Access Manager: Verify on Drive
     * ========================================================= */

    $(document).on('click', '.wgdp-am-verify-btn', function () {
        var $btn = $(this);
        var $result = $btn.closest('td').find('.wgdp-am-verify-result');
        var entitlementId = $btn.data('entitlement-id');

        $btn.prop('disabled', true).text('Verifying...');
        $result.text('').removeClass('wgdp-verify-confirmed wgdp-verify-missing wgdp-verify-error');

        $.post(wgdp.ajax_url, {
            action: 'wgdp_am_verify_permission',
            nonce: wgdp.nonce,
            entitlement_id: entitlementId
        }, function (response) {
            $btn.prop('disabled', false).text('Verify on Drive');
            if (response.success) {
                var d = response.data;
                var cssClass = 'wgdp-verify-' + d.status;
                $result.text(d.message).addClass(cssClass);
            } else {
                $result.text('Error: ' + response.data).addClass('wgdp-verify-error');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Verify on Drive');
            $result.text('Request failed.').addClass('wgdp-verify-error');
        });
    });

    /* =========================================================
     * Access Manager: Assign Email (missing email mode)
     * ========================================================= */

    $(document).on('click', '.wgdp-am-assign-btn', function () {
        var $btn = $(this);
        var $input = $btn.siblings('.wgdp-am-assign-email-input');
        var email = $.trim($input.val());

        if (!email) {
            alert('Please enter an email address.');
            return;
        }

        $btn.prop('disabled', true).text('Assigning...');

        $.post(wgdp.ajax_url, {
            action: 'wgdp_am_assign_email',
            nonce: wgdp.nonce,
            order_id: $btn.data('order-id'),
            order_item_id: $btn.data('order-item-id'),
            email: email
        }, function (response) {
            if (response.success) {
                // Update the row counts.
                var $row = $btn.closest('tr');
                var $assigned = $row.find('td:eq(3)');
                var $unassigned = $row.find('td:eq(4)');
                var assignedCount = parseInt($assigned.text(), 10) + 1;
                var unassignedCount = parseInt($unassigned.text(), 10) - 1;
                $assigned.text(assignedCount);
                if (unassignedCount > 0) {
                    $unassigned.html('<strong style="color:#d63638;">' + unassignedCount + '</strong>');
                } else {
                    $unassigned.text('0');
                    $row.css('opacity', '0.5');
                }

                $input.val('');
                $btn.prop('disabled', false).text('Assign');
            } else {
                $btn.prop('disabled', false).text('Assign');
                alert('Error: ' + response.data);
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Assign');
            alert('Request failed.');
        });
    });

    /* =========================================================
     * Access Manager: Product filter autocomplete
     * ========================================================= */

    $('.wgdp-product-filter').autocomplete({
        source: function (request, response) {
            $.post(wgdp.ajax_url, {
                term: request.term,
                action: 'woocommerce_json_search_products',
                security: wgdp.product_search_nonce
            }, function (data) {
                var results = [];
                if (data) {
                    $.each(data, function (id, text) {
                        results.push({ label: text, value: id });
                    });
                }
                response(results);
            });
        },
        minLength: 3,
        select: function (event, ui) {
            $(this).val(ui.item.label);
            $(this).siblings('.wgdp-product-filter-id').val(ui.item.value);
            return false;
        },
        change: function () {
            // Clear the hidden ID if the text field was emptied.
            if (!$(this).val()) {
                $(this).siblings('.wgdp-product-filter-id').val('');
            }
        }
    });

    /* =========================================================
     * Product page: Release mode conditional field display
     * ========================================================= */

    $(document).on('change', '#_wgdp_release_mode', function () {
        var mode = $(this).val();
        if (mode === 'min_sales_qty') {
            $('.wgdp-show-if-min-sales').show();
        } else {
            $('.wgdp-show-if-min-sales').hide();
        }
    });

    /* =========================================================
     * Variation: Release mode override conditional display
     * ========================================================= */

    $(document).on('change', '.wgdp-var-release-mode', function () {
        var mode = $(this).val();
        var $fields = $(this).closest('.wgdp-variation-fields');
        var isMinSales = mode === 'min_sales_qty';

        $fields.find('.wgdp-var-show-if-min-sales').toggle(isMinSales);

        // Show variation counter only for min_sales_qty + this_variation_only.
        var scope = $fields.find('[name$="[_wgdp_threshold_scope]"]').val();
        $fields.find('.wgdp-var-show-if-var-counter').toggle(isMinSales && scope === 'this_variation_only');

        notifyVariationChanged($(this));
    });

    $(document).on('change', '.wgdp-variation-fields [name$="[_wgdp_threshold_scope]"]', function () {
        var $fields = $(this).closest('.wgdp-variation-fields');
        var mode = $fields.find('.wgdp-var-release-mode').val();
        var scope = $(this).val();
        $fields.find('.wgdp-var-show-if-var-counter').toggle(mode === 'min_sales_qty' && scope === 'this_variation_only');

        notifyVariationChanged($(this));
    });

    /* =========================================================
     * Product page: "Release Digital Now" AJAX button
     * ========================================================= */

    $(document).on('click', '.wgdp-release-now-btn', function () {
        if (!confirm('Release digital content now? All verified pending entitlements will be granted.')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Releasing...');

        $.post(wgdp.ajax_url, {
            action: 'wgdp_release_digital_now',
            nonce: wgdp.nonce,
            product_id: $btn.data('product-id')
        }, function (response) {
            if (response.success) {
                $btn.closest('.form-field').html(
                    '<label>Release Status</label>' +
                    '<span class="wgdp-release-gate-status wgdp-release-gate-status--released">Released</span> ' +
                    '<span class="description">Just now</span>'
                );
            } else {
                $btn.prop('disabled', false).text('Release Digital Now');
                alert('Error: ' + response.data);
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Release Digital Now');
            alert('Request failed.');
        });
    });

    /* =========================================================
     * Variation: "Release Now" AJAX button
     * ========================================================= */

    $(document).on('click', '.wgdp-release-var-now-btn', function () {
        if (!confirm('Release this variation now? Verified pending entitlements for this variation will be granted.')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Releasing...');

        $.post(wgdp.ajax_url, {
            action: 'wgdp_release_variation_now',
            nonce: wgdp.nonce,
            variation_id: $btn.data('variation-id')
        }, function (response) {
            if (response.success) {
                $btn.closest('.wgdp-var-release-status').html(
                    '<label>Release Status</label>' +
                    '<span class="wgdp-release-gate-status wgdp-release-gate-status--released">Released</span> ' +
                    '<span class="description">Just now</span>'
                );
            } else {
                $btn.prop('disabled', false).text('Release Now');
                alert('Error: ' + response.data);
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Release Now');
            alert('Request failed.');
        });
    });

    /* =========================================================
     * Product page: "Recalculate Sales" AJAX button
     * ========================================================= */

    $(document).on('click', '.wgdp-recalculate-sales-btn', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Recalculating...');

        $.post(wgdp.ajax_url, {
            action: 'wgdp_recalculate_sales',
            nonce: wgdp.nonce,
            product_id: $btn.data('product-id')
        }, function (response) {
            if (response.success) {
                $btn.closest('.form-field').find('.wgdp-sales-count-value').text(response.data.total);
                $btn.prop('disabled', false).text('Recalculate');
                if (response.data.is_released) {
                    location.reload();
                }
            } else {
                $btn.prop('disabled', false).text('Recalculate');
                alert('Error: ' + response.data);
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Recalculate');
            alert('Request failed.');
        });
    });

    /* =========================================================
     * Variation: "Recalculate Sales" AJAX button
     * ========================================================= */

    $(document).on('click', '.wgdp-recalculate-var-sales-btn', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Recalculating...');

        $.post(wgdp.ajax_url, {
            action: 'wgdp_recalculate_variation_sales',
            nonce: wgdp.nonce,
            variation_id: $btn.data('variation-id')
        }, function (response) {
            if (response.success) {
                $btn.siblings('.wgdp-var-sales-count-value').text(response.data.total);
                $btn.prop('disabled', false).text('Recalculate');
                if (response.data.is_released) {
                    location.reload();
                }
            } else {
                $btn.prop('disabled', false).text('Recalculate');
                alert('Error: ' + response.data);
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Recalculate');
            alert('Request failed.');
        });
    });

})(jQuery);
