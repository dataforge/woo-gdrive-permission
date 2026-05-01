(function ($) {
    'use strict';

    /* =========================================================
     * Product page: Google Picker integration (multi-file)
     * ========================================================= */

    var gapiLoaded = false;
    var gapiLoading = false;
    var gapiCallbacks = [];
    var activeDriveButton = null;
    var driveBrowserState = {
        accountId: '',
        search: '',
        nextPageToken: '',
        folderId: 'root',
        folderStack: []
    };

    function getAccountId($button) {
        var $container = $button.closest('.options_group, .wgdp-variation-fields');
        return $container.find('.wgdp-account-select').val() || '';
    }

    function getPickerOrigin() {
        return window.location.protocol + '//' + window.location.host;
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
        script.onerror = function () {
            gapiLoading = false;
            gapiCallbacks = [];
            alert('Google Picker could not load. Check browser blockers or network access to apis.google.com.');
        };
        document.head.appendChild(script);
    }

    function getServerPickerToken(accountId, callback, errorCallback) {
        $.post(wgdp.ajax_url, {
            action: 'wgdp_get_picker_token',
            nonce: wgdp.nonce,
            account_id: accountId
        }, function (response) {
            if (!response.success) {
                errorCallback('Error getting token: ' + response.data);
                return;
            }
            callback(response.data.token);
        }).fail(function () {
            errorCallback('Request failed.');
        });
    }

    function createGooglePicker(token, $button) {
        ensureGapi(function () {
            var docsView = new google.picker.DocsView()
                .setIncludeFolders(true)
                .setSelectFolderEnabled(false)
                .setParent('root')
                .setMode(google.picker.DocsViewMode.LIST);

            var builder = new google.picker.PickerBuilder()
                .addView(docsView)
                .enableFeature(google.picker.Feature.MULTISELECT_ENABLED)
                .setTitle('Select Drive files')
                .setSize(1051, 650)
                .setOAuthToken(token)
                .setDeveloperKey(wgdp.picker_api_key)
                .setAppId(wgdp.cloud_project_number)
                .setOrigin(getPickerOrigin())
                .setCallback(function (data) {
                    pickerCallback(data, $button);
                });

            builder.build().setVisible(true);
        });
    }

    function openPicker($button) {
        var originalText = $button.text();
        var accountId = getAccountId($button);
        if (!accountId) {
            alert('Please select a Google Account first.');
            return;
        }

        if (!wgdp.picker_api_key) {
            alert('Picker API Key is not configured. Please add it in the plugin settings.');
            return;
        }

        if (!wgdp.cloud_project_number) {
            alert('Cloud Project Number is not configured. Please add it in the plugin settings.');
            return;
        }

        $button.prop('disabled', true).text('Loading...');

        getServerPickerToken(accountId, function (token) {
            $button.prop('disabled', false).text(originalText);
            createGooglePicker(token, $button);
        }, function (message) {
            $button.prop('disabled', false).text(originalText);
            alert(message);
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
            // Update name in case the file was renamed on Drive.
            var safeName = $('<span>').text(file.name).html();
            $existing.find('input[type="hidden"]').filter(function () {
                return $(this).attr('name') && $(this).attr('name').indexOf('[name]') !== -1;
            }).val(safeName);
            $existing.find('.wgdp-resource-row-info strong').text(file.name);
            notifyVariationChanged($existing);
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

    function getDriveBrowser() {
        var $modal = $('#wgdp-drive-browser');
        if ($modal.length) {
            return $modal;
        }

        $modal = $(
            '<div id="wgdp-drive-browser" class="wgdp-drive-browser" aria-hidden="true">' +
                '<div class="wgdp-drive-browser__backdrop"></div>' +
                '<div class="wgdp-drive-browser__dialog" role="dialog" aria-modal="true" aria-labelledby="wgdp-drive-browser-title">' +
                    '<div class="wgdp-drive-browser__header">' +
                        '<h2 id="wgdp-drive-browser-title">Browse GDrive</h2>' +
                        '<button type="button" class="button-link wgdp-drive-browser__close" aria-label="Close">&times;</button>' +
                    '</div>' +
                    '<form class="wgdp-drive-browser__search">' +
                        '<input type="search" class="regular-text wgdp-drive-browser__search-input" placeholder="Search this folder" />' +
                        '<button type="submit" class="button">Search</button>' +
                    '</form>' +
                    '<form class="wgdp-drive-browser__paste">' +
                        '<input type="text" class="regular-text wgdp-drive-browser__url-input" placeholder="Paste a Google Drive file URL or ID" />' +
                        '<button type="submit" class="button">Add URL</button>' +
                    '</form>' +
                    '<div class="wgdp-drive-browser__path"></div>' +
                    '<div class="wgdp-drive-browser__status"></div>' +
                    '<div class="wgdp-drive-browser__list"></div>' +
                    '<div class="wgdp-drive-browser__footer">' +
                        '<button type="button" class="button wgdp-drive-browser__load-more" style="display:none;">Load More</button>' +
                    '</div>' +
                '</div>' +
            '</div>'
        );
        $('body').append($modal);
        return $modal;
    }

    function closeDriveBrowser() {
        var $modal = getDriveBrowser();
        $modal.removeClass('is-open').attr('aria-hidden', 'true');
        activeDriveButton = null;
    }

    function getActiveResourceList() {
        if (!activeDriveButton || !activeDriveButton.length) {
            return $();
        }
        return activeDriveButton.closest('.options_group, .wgdp-variation-fields').find('.wgdp-resources-list');
    }

    function setDriveBrowserStatus(message, isError) {
        var $status = getDriveBrowser().find('.wgdp-drive-browser__status');
        $status.toggleClass('is-error', !!isError).text(message || '');
    }

    function isDriveFolder(file) {
        return file.mimeType === 'application/vnd.google-apps.folder';
    }

    function renderDriveBrowserPath() {
        var $path = getDriveBrowser().find('.wgdp-drive-browser__path');
        var html = '<button type="button" class="button-link wgdp-drive-browser__path-root">My Drive</button>';

        for (var i = 0; i < driveBrowserState.folderStack.length; i++) {
            html += '<span class="wgdp-drive-browser__path-sep">/</span>' +
                '<button type="button" class="button-link wgdp-drive-browser__path-folder" data-index="' + i + '"></button>';
        }

        $path.html(html);
        $path.find('.wgdp-drive-browser__path-folder').each(function () {
            var index = parseInt($(this).data('index'), 10);
            $(this).text(driveBrowserState.folderStack[index].name);
        });
    }

    function renderDriveBrowserFiles(files, append) {
        var $modal = getDriveBrowser();
        var $list = $modal.find('.wgdp-drive-browser__list');

        if (!append) {
            $list.empty();
        }

        if (!files.length && !append) {
            $list.html('<div class="wgdp-drive-browser__empty">No accessible files found in this folder. Paste a Drive file URL above to add it directly.</div>');
            return;
        }

        for (var i = 0; i < files.length; i++) {
            var file = files[i];
            var isFolder = isDriveFolder(file);
            var $row = $(
                '<div class="wgdp-drive-browser__file' + (isFolder ? ' wgdp-drive-browser__file--folder' : '') + '">' +
                    '<div class="wgdp-drive-browser__file-name"></div>' +
                    '<button type="button" class="button button-small wgdp-drive-browser__open-folder">Open</button>' +
                    '<button type="button" class="button button-small wgdp-drive-browser__add-file">Add</button>' +
                '</div>'
            );
            $row.find('.wgdp-drive-browser__file-name').text(file.name || file.id);
            $row.find('.wgdp-drive-browser__open-folder').data('folder', {
                id: file.id,
                name: file.name || file.id
            }).toggle(isFolder);
            $row.find('.wgdp-drive-browser__add-file').data('file', {
                id: file.id,
                name: file.name || file.id,
                type: 'file'
            }).toggle(!isFolder);
            $list.append($row);
        }
    }

    function loadDriveBrowserFiles(append) {
        var $modal = getDriveBrowser();
        setDriveBrowserStatus('Loading files...', false);
        $modal.find('.wgdp-drive-browser__load-more').hide();

        $.post(wgdp.ajax_url, {
            action: 'wgdp_browse_drive_files',
            nonce: wgdp.nonce,
            account_id: driveBrowserState.accountId,
            search: driveBrowserState.search,
            page_token: append ? driveBrowserState.nextPageToken : '',
            folder_id: driveBrowserState.folderId
        }, function (response) {
            if (!response.success) {
                setDriveBrowserStatus('Error: ' + response.data, true);
                return;
            }

            var files = response.data.files || [];
            driveBrowserState.nextPageToken = response.data.nextPageToken || '';
            renderDriveBrowserFiles(files, append);
            setDriveBrowserStatus('', false);

            if (driveBrowserState.nextPageToken) {
                $modal.find('.wgdp-drive-browser__load-more').show();
            }
        }).fail(function () {
            setDriveBrowserStatus('Request failed.', true);
        });
    }

    function openDriveBrowser($button) {
        var accountId = getAccountId($button);
        if (!accountId) {
            alert('Please select a Google Account first.');
            return;
        }

        $('.picker-dialog, .picker-dialog-bg').hide();

        activeDriveButton = $button;
        driveBrowserState.accountId = accountId;
        driveBrowserState.search = '';
        driveBrowserState.nextPageToken = '';
        driveBrowserState.folderId = 'root';
        driveBrowserState.folderStack = [];

        var $modal = getDriveBrowser();
        $modal.find('.wgdp-drive-browser__search-input, .wgdp-drive-browser__url-input').val('');
        $modal.find('.wgdp-drive-browser__list').empty();
        $modal.addClass('is-open').attr('aria-hidden', 'false');
        renderDriveBrowserPath();
        loadDriveBrowserFiles(false);
    }

    // Browse button click.
    $(document).on('click', '.wgdp-browse-drive', function (e) {
        e.preventDefault();
        openDriveBrowser($(this));
    });

    $(document).on('click', '.wgdp-google-picker-drive', function (e) {
        e.preventDefault();
        openPicker($(this));
    });

    $(document).on('click', '.wgdp-drive-browser__close, .wgdp-drive-browser__backdrop', function () {
        closeDriveBrowser();
    });

    $(document).on('submit', '.wgdp-drive-browser__search', function (e) {
        e.preventDefault();
        driveBrowserState.search = $(this).find('.wgdp-drive-browser__search-input').val().trim();
        driveBrowserState.nextPageToken = '';
        loadDriveBrowserFiles(false);
    });

    $(document).on('click', '.wgdp-drive-browser__load-more', function () {
        loadDriveBrowserFiles(true);
    });

    $(document).on('click', '.wgdp-drive-browser__open-folder', function () {
        var folder = $(this).data('folder');
        if (!folder || !folder.id) {
            return;
        }

        driveBrowserState.folderStack.push(folder);
        driveBrowserState.folderId = folder.id;
        driveBrowserState.search = '';
        driveBrowserState.nextPageToken = '';
        getDriveBrowser().find('.wgdp-drive-browser__search-input').val('');
        renderDriveBrowserPath();
        loadDriveBrowserFiles(false);
    });

    $(document).on('click', '.wgdp-drive-browser__path-root', function () {
        driveBrowserState.folderId = 'root';
        driveBrowserState.folderStack = [];
        driveBrowserState.search = '';
        driveBrowserState.nextPageToken = '';
        getDriveBrowser().find('.wgdp-drive-browser__search-input').val('');
        renderDriveBrowserPath();
        loadDriveBrowserFiles(false);
    });

    $(document).on('click', '.wgdp-drive-browser__path-folder', function () {
        var index = parseInt($(this).data('index'), 10);
        if (isNaN(index) || !driveBrowserState.folderStack[index]) {
            return;
        }

        driveBrowserState.folderStack = driveBrowserState.folderStack.slice(0, index + 1);
        driveBrowserState.folderId = driveBrowserState.folderStack[index].id;
        driveBrowserState.search = '';
        driveBrowserState.nextPageToken = '';
        getDriveBrowser().find('.wgdp-drive-browser__search-input').val('');
        renderDriveBrowserPath();
        loadDriveBrowserFiles(false);
    });

    $(document).on('click', '.wgdp-drive-browser__add-file', function () {
        var file = $(this).data('file');
        var $list = getActiveResourceList();
        if (!$list.length || !file || !file.id) {
            return;
        }
        addResourceRow(file, $list);
        notifyVariationChanged($list);
        $(this).prop('disabled', true).text('Added');
    });

    $(document).on('submit', '.wgdp-drive-browser__paste', function (e) {
        e.preventDefault();

        var $form = $(this);
        var $input = $form.find('.wgdp-drive-browser__url-input');
        var val = $input.val().trim();
        var $list = getActiveResourceList();

        if (!val || !$list.length) {
            return;
        }

        setDriveBrowserStatus('Checking file...', false);
        $form.find('button').prop('disabled', true);

        $.post(wgdp.ajax_url, {
            action: 'wgdp_get_file_info',
            nonce: wgdp.nonce,
            file_id: val,
            account_id: driveBrowserState.accountId
        }, function (response) {
            $form.find('button').prop('disabled', false);
            if (!response.success) {
                setDriveBrowserStatus('Error: ' + response.data, true);
                return;
            }

            var file = response.data;
            if (file.resourceType === 'folder') {
                setDriveBrowserStatus('Folders cannot be linked. Please use individual file URLs.', true);
                return;
            }

            addResourceRow({
                id: file.id,
                name: file.name,
                type: 'file'
            }, $list);
            notifyVariationChanged($list);
            $input.val('');
            setDriveBrowserStatus('File added.', false);
        }).fail(function () {
            $form.find('button').prop('disabled', false);
            setDriveBrowserStatus('Request failed.', true);
        });
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
     * Access Manager: Send Access Email
     * ========================================================= */

    $(document).on('click', '.wgdp-am-send-access-email-btn', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Sending...');

        $.post(wgdp.ajax_url, {
            action: 'wgdp_am_send_access_email',
            nonce: wgdp.nonce,
            entitlement_id: $btn.data('entitlement-id')
        }, function (response) {
            if (response.success) {
                $btn.text('Sent!');
                setTimeout(function () {
                    $btn.prop('disabled', false).text('Send Access Email');
                }, 2000);
            } else {
                $btn.prop('disabled', false).text('Send Access Email');
                alert('Error: ' + response.data);
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Send Access Email');
            alert('Request failed.');
        });
    });

    /* =========================================================
     * Access Manager: Resend Order Email
     * ========================================================= */

    $(document).on('click', '.wgdp-am-resend-order-email-btn', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Sending...');

        $.post(wgdp.ajax_url, {
            action: 'wgdp_am_resend_order_email',
            nonce: wgdp.nonce,
            order_id: $btn.data('order-id')
        }, function (response) {
            if (response.success) {
                $btn.text('Sent!');
                setTimeout(function () {
                    $btn.prop('disabled', false).text('Resend Order Email');
                }, 2000);
            } else {
                $btn.prop('disabled', false).text('Resend Order Email');
                alert('Error: ' + response.data);
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Resend Order Email');
            alert('Request failed.');
        });
    });

    /* =========================================================
     * Access Manager: Remove Account
     * ========================================================= */

    $(document).on('click', '.wgdp-am-request-new-email-btn', function () {
        if (!confirm('Remove this Google Drive access and make the order item Awaiting Assignment? You can then use Resend Order Email to send the Provide Google Email link.')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Removing...');

        $.post(wgdp.ajax_url, {
            action: 'wgdp_am_request_new_email',
            nonce: wgdp.nonce,
            entitlement_id: $btn.data('entitlement-id')
        }, function (response) {
            if (response.success) {
                var d = response.data || {};
                if (d.status === 'revocation_error') {
                    alert(d.message || 'Drive access removal is pending retry.');
                    window.location.reload();
                    return;
                }

                var $row = $btn.closest('tr');
                $row.find('.wgdp-status-badge').filter(function() {
                    return $(this).hasClass('wgdp-gstatus--pending') ||
                        $(this).hasClass('wgdp-gstatus--granted') ||
                        $(this).hasClass('wgdp-gstatus--error') ||
                        $(this).hasClass('wgdp-gstatus--pending_release');
                }).removeClass('wgdp-gstatus--pending wgdp-gstatus--granted wgdp-gstatus--error wgdp-gstatus--pending_release')
                    .addClass('wgdp-gstatus--revoked').text('Revoked');

                $btn.closest('td').find('.wgdp-resend-otp-btn, .wgdp-am-change-email-btn, .wgdp-am-request-new-email-btn, .wgdp-am-verify-btn, .wgdp-retry-grant-btn, .wgdp-revoke-entitlement-btn').remove();
                alert(d.message || 'Google account removed.');
                window.location.reload();
            } else {
                $btn.prop('disabled', false).text('Remove Account');
                alert('Error: ' + response.data);
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Remove Account');
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
                var d = response.data || {};
                var status = d.status || (typeof response.data === 'string' ? 'revoked' : '');
                // Update the row to show revoked status.
                var $row = $btn.closest('tr');
                if ($row.length) {
                    var $grantBadge = $row.find('.wgdp-status-badge').filter(function() {
                        return $(this).hasClass('wgdp-gstatus--pending') ||
                            $(this).hasClass('wgdp-gstatus--granted') ||
                            $(this).hasClass('wgdp-gstatus--error') ||
                            $(this).hasClass('wgdp-gstatus--pending_release') ||
                            $(this).hasClass('wgdp-gstatus--revocation_error');
                    });

                    if (status === 'revocation_error') {
                        $grantBadge
                            .removeClass('wgdp-gstatus--pending wgdp-gstatus--granted wgdp-gstatus--error wgdp-gstatus--pending_release wgdp-gstatus--revocation_error')
                            .addClass('wgdp-gstatus--revocation_error').text('Revocation Error');
                    } else {
                        $grantBadge
                            .removeClass('wgdp-gstatus--pending wgdp-gstatus--granted wgdp-gstatus--error wgdp-gstatus--pending_release wgdp-gstatus--revocation_error')
                            .addClass('wgdp-gstatus--revoked').text('Revoked');
                    }
                }

                if (status === 'revocation_error') {
                    alert(d.message || 'Could not remove Drive permission. The row is marked Revocation Error and will retry automatically.');
                    window.location.reload();
                    return;
                }

                $btn.closest('td').find('.wgdp-resend-otp-btn, .wgdp-am-change-email-btn, .wgdp-am-verify-btn, .wgdp-retry-grant-btn').remove();
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
            $.getJSON(wgdp.ajax_url, {
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

    // On form submit, sync the product text for free-text server-side search.
    $('.wgdp-product-filter').closest('form').on('submit', function () {
        var $input = $(this).find('.wgdp-product-filter');
        var $hiddenId = $(this).find('.wgdp-product-filter-id');
        var $hiddenName = $(this).find('.wgdp-product-filter-name');
        if ($hiddenId.val()) {
            // A product was selected from autocomplete — clear free-text.
            $hiddenName.val('');
        } else {
            // No autocomplete selection — pass the typed text for server-side search.
            $hiddenName.val($input.val());
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
