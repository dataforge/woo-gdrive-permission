(function ($) {
    'use strict';

    /* =========================================================
     * Product page: Google Picker integration
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
                    .setSelectFolderEnabled(true)
                    .setParent('root')
                    .setMode(google.picker.DocsViewMode.LIST);

                var builder = new google.picker.PickerBuilder()
                    .addView(docsView)
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

        var doc = data.docs[0];
        if (!doc) {
            return;
        }

        var file = {
            id: doc.id,
            name: doc.name,
            mimeType: doc.mimeType
        };

        applySelection(file, $button);
    }

    /**
     * Show the friendly preview and hide the raw input area.
     */
    function showResourcePreview($container, id, name, type) {
        var isFolder = type === 'folder';
        var link = isFolder
            ? 'https://drive.google.com/drive/folders/' + id
            : 'https://drive.google.com/file/d/' + id + '/view';

        $container.find('.wgdp-resource-preview .wgdp-resource-info').html(
            '<strong>' + $('<span>').text(name).html() + '</strong> ' +
            '(' + (isFolder ? 'folder' : 'file') + ') &mdash; ' +
            '<a href="' + link + '" target="_blank">View in Drive</a>'
        );
        $container.find('.wgdp-resource-preview').show();
        $container.find('.wgdp-resource-input-wrap').hide();
        $container.find('.wgdp-resource-url-input').val('');
    }

    function applySelection(file, $button) {
        var $container = $button.closest('.options_group, .wgdp-variation-fields');
        var isFolder = file.mimeType === 'application/vnd.google-apps.folder';
        var type = isFolder ? 'folder' : 'file';

        $container.find('.wgdp-resource-id').val(file.id).trigger('change');
        $container.find('.wgdp-resource-type').val(type).trigger('change');
        $container.find('.wgdp-resource-name').val(file.name).trigger('change');

        showResourcePreview($container, file.id, file.name, type);
    }

    // Browse button click.
    $(document).on('click', '.wgdp-browse-drive', function () {
        openPicker($(this));
    });

    // "Change" link — show the input area so the user can browse or paste a new URL.
    $(document).on('click', '.wgdp-resource-change', function (e) {
        e.preventDefault();
        var $container = $(this).closest('.options_group, .wgdp-variation-fields');
        $container.find('.wgdp-resource-preview').hide();
        $container.find('.wgdp-resource-input-wrap').show()
            .find('.wgdp-resource-cancel').show();
    });

    // "Remove" link — clear the resource entirely.
    $(document).on('click', '.wgdp-resource-clear', function (e) {
        e.preventDefault();
        if (!confirm('Remove this Drive resource from the product?')) {
            return;
        }
        var $container = $(this).closest('.options_group, .wgdp-variation-fields');
        $container.find('.wgdp-resource-id').val('').trigger('change');
        $container.find('.wgdp-resource-type').val('').trigger('change');
        $container.find('.wgdp-resource-name').val('').trigger('change');
        $container.find('.wgdp-resource-preview').hide();
        $container.find('.wgdp-resource-input-wrap').show()
            .find('.wgdp-resource-cancel').hide();
    });

    // "Cancel" link — go back to preview without changing anything.
    $(document).on('click', '.wgdp-resource-cancel', function (e) {
        e.preventDefault();
        var $container = $(this).closest('.options_group, .wgdp-variation-fields');
        $container.find('.wgdp-resource-input-wrap').hide();
        $container.find('.wgdp-resource-preview').show();
    });

    /* =========================================================
     * Product page: URL paste detection
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
                $container.find('.wgdp-resource-id').val(file.id).trigger('change');
                $container.find('.wgdp-resource-type').val(file.resourceType).trigger('change');
                $container.find('.wgdp-resource-name').val(file.name).trigger('change');
                showResourcePreview($container, file.id, file.name, file.resourceType);
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
                var newRow = '<tr>' +
                    '<td>' + $('<span>').text(d.recipient_index).html() + '</td>' +
                    '<td>' + $('<span>').text(d.email).html() + '</td>' +
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
                        '<thead><tr><th style="width:30px;">#</th><th>Email</th><th>Verification</th><th>Grant</th><th>Actions</th></tr></thead>' +
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

        $.post(wgdp.ajax_url, {
            action: 'wgdp_revoke_entitlement',
            nonce: wgdp.nonce,
            entitlement_id: $btn.data('entitlement-id')
        }, function (response) {
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

})(jQuery);
