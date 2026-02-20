(function ($) {
    'use strict';

    /* =========================================================
     * Product page: Drive browser modal
     * ========================================================= */

    var browserState = {
        $trigger: null,
        breadcrumbs: [],
        selectedItem: null,
        accountId: '',
        mode: 'select',       // 'select' (product) or 'pick_root' (settings)
        onSelect: null,        // callback for pick_root mode
        foldersOnly: false
    };

    function getAccountId($button) {
        var $container = $button.closest('.options_group, .wgdp-variation-fields');
        return $container.find('.wgdp-account-select').val() || '';
    }

    function openBrowseModal($button) {
        var accountId = getAccountId($button);
        if (!accountId) {
            alert('Please select a Google Account first.');
            return;
        }

        browserState.$trigger = $button;
        browserState.accountId = accountId;
        browserState.breadcrumbs = [{ id: '', name: 'My Drive' }];
        browserState.selectedItem = null;
        browserState.mode = 'select';
        browserState.onSelect = null;
        browserState.foldersOnly = false;

        var $dialog = getOrCreateDialog();
        $dialog.dialog('option', 'title', 'Browse GDrive');
        $dialog.find('.wgdp-browse-hint').html(
            'Select a <strong>folder</strong> to grant access to everything inside it (including future additions), or select an individual <strong>file</strong>. Double-click a folder to open it.'
        );
        $dialog.dialog('open');
        loadFolder('');
    }

    /**
     * Open the browse modal for picking a root folder (settings page).
     * Always starts from the actual drive root, not the configured root.
     */
    window.wgdpOpenRootFolderPicker = function (accountId, nonce) {
        browserState.$trigger = null;
        browserState.accountId = accountId;
        browserState.breadcrumbs = [{ id: '', name: 'My Drive' }];
        browserState.selectedItem = null;
        browserState.mode = 'pick_root';
        browserState.foldersOnly = true;
        browserState.onSelect = function (file) {
            var folderName = file.name;
            var folderId = file.id;
            $.post(wgdp.ajax_url, {
                action: 'wgdp_update_account_root_folder',
                nonce: nonce,
                account_id: accountId,
                folder_id: folderId,
                folder_name: folderName
            }, function (r) {
                var $display = $('.wgdp-root-folder-display[data-account-id="' + accountId + '"]');
                var $result = $('.wgdp-account-result[data-account-id="' + accountId + '"]');
                if (r.success) {
                    $display.html('<strong>' + $('<span>').text(folderName).html() + '</strong>');
                    if (!$display.closest('td').find('.wgdp-reset-root-folder').length) {
                        $display.after(
                            '<br><a href="#" class="wgdp-reset-root-folder" data-account-id="' + accountId + '" style="margin-top:4px;font-size:12px;">Reset</a>'
                        );
                    }
                    $result.text('Saved').css('color', 'green');
                    setTimeout(function () { $result.text(''); }, 2000);
                } else {
                    $result.text('Error: ' + r.data).css('color', 'red');
                }
            });
        };

        var $dialog = getOrCreateDialog();
        $dialog.dialog('option', 'title', 'Choose Root Folder');
        $dialog.find('.wgdp-browse-hint').html(
            'Select a <strong>folder</strong> to limit the plugin\'s browsing scope to that folder and everything inside it. Double-click to open a folder.'
        );
        $dialog.dialog('open');
        // Always browse from actual root for root folder picking, ignoring any configured root.
        loadFolder('', true);
    };

    function getOrCreateDialog() {
        var $dialog = $('#wgdp-browse-dialog');
        if ($dialog.length) {
            return $dialog;
        }

        $dialog = $('<div id="wgdp-browse-dialog" title="Browse GDrive">' +
            '<p class="wgdp-browse-hint">Select a <strong>folder</strong> to grant access to everything inside it (including future additions), or select an individual <strong>file</strong>. Double-click a folder to open it.</p>' +
            '<div class="wgdp-breadcrumbs"></div>' +
            '<div class="wgdp-file-list"></div>' +
            '<div class="wgdp-browse-loading" style="display:none;text-align:center;padding:20px;">Loading...</div>' +
            '</div>');

        $('body').append($dialog);

        $dialog.dialog({
            autoOpen: false,
            modal: true,
            width: 600,
            height: 500,
            buttons: {
                'Select': function () {
                    var item = browserState.selectedItem;

                    // Nothing highlighted — use the current folder from breadcrumbs.
                    if (!item && browserState.breadcrumbs.length > 1) {
                        var current = browserState.breadcrumbs[browserState.breadcrumbs.length - 1];
                        item = {
                            id: current.id,
                            name: current.name,
                            mimeType: 'application/vnd.google-apps.folder'
                        };
                    }

                    if (!item) {
                        return;
                    }

                    if (browserState.mode === 'pick_root' && browserState.onSelect) {
                        browserState.onSelect(item);
                    } else {
                        applySelection(item);
                    }
                    $(this).dialog('close');
                },
                'Cancel': function () {
                    $(this).dialog('close');
                }
            }
        });

        return $dialog;
    }

    function loadFolder(folderId, skipRoot) {
        var $dialog = $('#wgdp-browse-dialog');
        var $list = $dialog.find('.wgdp-file-list');
        var $loading = $dialog.find('.wgdp-browse-loading');

        $list.empty();
        $loading.show();
        browserState.selectedItem = null;

        var postData = {
            action: 'wgdp_browse_drive',
            nonce: wgdp.nonce,
            folder_id: folderId,
            account_id: browserState.accountId
        };
        if (skipRoot) {
            postData.skip_root = '1';
        }

        $.post(wgdp.ajax_url, postData, function (response) {
            $loading.hide();
            if (!response.success) {
                $list.html('<p class="wgdp-error">Error: ' + $('<span>').text(response.data).html() + '</p>');
                return;
            }

            // Update the root breadcrumb name if the server tells us the scoped folder.
            if (response.data.root_folder_name && browserState.breadcrumbs.length >= 1) {
                browserState.breadcrumbs[0].name = response.data.root_folder_name;
                browserState.breadcrumbs[0].id = '';  // still means "start from root"
            }

            renderBreadcrumbs();
            renderFileList(response.data);
        }).fail(function () {
            $loading.hide();
            $list.html('<p class="wgdp-error">Request failed.</p>');
        });
    }

    function renderBreadcrumbs() {
        var $bc = $('#wgdp-browse-dialog .wgdp-breadcrumbs');
        $bc.empty();

        $.each(browserState.breadcrumbs, function (i, crumb) {
            if (i > 0) {
                $bc.append(' <span class="wgdp-bc-sep">/</span> ');
            }
            var $link = $('<a href="#" class="wgdp-bc-link"></a>')
                .text(crumb.name)
                .data('folder-id', crumb.id)
                .data('index', i);
            $bc.append($link);
        });

        // Scroll to the end so the current folder is visible.
        $bc[0].scrollLeft = $bc[0].scrollWidth;
    }

    function renderFileList(data) {
        var $list = $('#wgdp-browse-dialog .wgdp-file-list');
        var files = data.files || [];

        // In folders-only mode, filter out non-folders.
        if (browserState.foldersOnly) {
            files = files.filter(function (f) {
                return f.mimeType === 'application/vnd.google-apps.folder';
            });
        }

        if (!files.length) {
            var msg = browserState.foldersOnly ? 'No folders found here.' : 'This folder is empty.';
            $list.html('<p class="wgdp-empty">' + msg + '</p>');
            return;
        }

        var $ul = $('<ul class="wgdp-files"></ul>');
        $.each(files, function (i, file) {
            var isFolder = file.mimeType === 'application/vnd.google-apps.folder';
            var icon = isFolder ? '&#128193;' : '&#128196;';
            var $li = $('<li class="wgdp-file-item"></li>')
                .data('file', file)
                .addClass(isFolder ? 'wgdp-folder' : 'wgdp-file')
                .html('<span class="wgdp-file-icon">' + icon + '</span> <span class="wgdp-file-name">' + $('<span>').text(file.name).html() + '</span>');
            $ul.append($li);
        });
        $list.append($ul);
    }

    // Single click: select item.
    $(document).on('click', '.wgdp-file-item', function () {
        $('.wgdp-file-item').removeClass('wgdp-selected');
        $(this).addClass('wgdp-selected');
        browserState.selectedItem = $(this).data('file');
    });

    // Double click: navigate into folder.
    $(document).on('dblclick', '.wgdp-file-item.wgdp-folder', function () {
        var file = $(this).data('file');
        browserState.breadcrumbs.push({ id: file.id, name: file.name });
        loadFolder(file.id);
    });

    // Breadcrumb click.
    $(document).on('click', '.wgdp-bc-link', function (e) {
        e.preventDefault();
        var index = $(this).data('index');
        browserState.breadcrumbs = browserState.breadcrumbs.slice(0, index + 1);
        loadFolder($(this).data('folder-id'));
    });

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

    function applySelection(file) {
        var $container = browserState.$trigger.closest('.options_group, .wgdp-variation-fields');
        var isFolder = file.mimeType === 'application/vnd.google-apps.folder';
        var type = isFolder ? 'folder' : 'file';

        $container.find('.wgdp-resource-id').val(file.id).trigger('change');
        $container.find('.wgdp-resource-type').val(type).trigger('change');
        $container.find('.wgdp-resource-name').val(file.name).trigger('change');

        showResourcePreview($container, file.id, file.name, type);
    }

    // Browse button click.
    $(document).on('click', '.wgdp-browse-drive', function () {
        openBrowseModal($(this));
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
