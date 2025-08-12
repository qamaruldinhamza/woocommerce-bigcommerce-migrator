(function($) {
    'use strict';

    var WCBCMigrator = {
        isRunning: false,
        shouldStop: false,
        currentStep: 'idle',

        init: function() {
            this.bindEvents();
            this.hideLoadingOverlay();
            this.loadStats();
            this.checkMigrationStatus();
        },

        bindEvents: function() {
            // Tab switching
            $('.tab').on('click', function() {
                var tabId = $(this).data('tab');
                $('.tab').removeClass('active');
                $(this).addClass('active');
                $('.tab-content').removeClass('active');
                $('#' + tabId + '-tab').addClass('active');
            });

            // Migration actions
            $('#prepare-migration').on('click', this.prepareMigration.bind(this));
            $('#start-batch').on('click', this.startMigration.bind(this));
            $('#stop-batch').on('click', this.stopBatchMigration.bind(this));
            $('#retry-errors').on('click', this.retryErrors.bind(this));

            // New migration features
            $('#migrate-categories').on('click', this.migrateCategories.bind(this));
            $('#migrate-attributes').on('click', this.migrateAttributes.bind(this));
            $('#setup-b2b').on('click', this.setupB2B.bind(this));

            // Settings
            $('#settings-form').on('submit', this.saveSettings.bind(this));
            $('#test-connection').on('click', this.testConnection.bind(this));

            // Reset
            $('#reset-migration').on('click', this.resetMigration.bind(this));

            // Export functions
            $('#export-errors').on('click', this.exportErrors.bind(this));
            $('#export-logs').on('click', this.exportLogs.bind(this));
        },

        checkMigrationStatus: function() {
            // Check if categories, attributes, and B2B are set up
            this.checkPrerequisites();
        },

        checkPrerequisites: function() {
            var categoriesMigrated = localStorage.getItem('wc_bc_categories_migrated') === 'true';
            var b2bSetup = localStorage.getItem('wc_bc_b2b_setup') === 'true';

            if (!categoriesMigrated || !b2bSetup) {
                this.showPrerequisiteNotice();
            }
        },

        showPrerequisiteNotice: function() {
            var notice = $('<div class="notice notice-warning"><p><strong>Setup Required:</strong> Please migrate categories and set up B2B features before migrating products.</p></div>');
            notice.append('<button class="button" id="migrate-categories">Migrate Categories</button> ');
            notice.append('<button class="button" id="setup-b2b">Setup B2B Features</button>');

            $('.wc-bc-container').prepend(notice);

            // Bind events to new buttons
            notice.find('#migrate-categories').on('click', this.migrateCategories.bind(this));
            notice.find('#setup-b2b').on('click', this.setupB2B.bind(this));
        },

        loadStats: function() {
            $.ajax({
                url: wcBcMigrator.apiUrl + 'migrate/stats',
                method: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
                },
                success: function(response) {
                    $('#stat-total').text(response.total);
                    $('#stat-pending').text(response.pending);
                    $('#stat-success').text(response.success);
                    $('#stat-error').text(response.error);

                    // Hide/show the Errors card depending on count
                    var $errorCard = $('.stat-card.error');
                    if (Number(response.error) > 0) {
                        $errorCard.show();
                    } else {
                        $errorCard.hide();
                    }

                    // Show reset button if there's data
                    if (response.total > 0) {
                        $('#reset-migration').show();
                    }
                }
            });
        },

        migrateCategories: function(e) {
            e.preventDefault();
            var button = $(e.target);
            button.prop('disabled', true).text('Migrating Categories...');

            this.showLoadingOverlay('Migrating categories...');

            $.ajax({
                url: wcBcMigrator.apiUrl + 'migrate/categories',
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
                },
                success: function(response) {
                    if (response.success > 0) {
                        WCBCMigrator.addLog('success', 'Migrated ' + response.success + ' categories successfully');
                        localStorage.setItem('wc_bc_categories_migrated', 'true');
                    }

                    if (response.error > 0) {
                        WCBCMigrator.addLog('error', response.error + ' categories failed to migrate');
                    }

                    // Show detailed messages
                    if (response.messages) {
                        response.messages.forEach(function(msg) {
                            WCBCMigrator.addLog('info', msg);
                        });
                    }
                },
                error: function(xhr) {
                    WCBCMigrator.addLog('error', 'Failed to migrate categories: ' + xhr.responseText);
                },
                complete: function() {
                    button.prop('disabled', false).text('Migrate Categories');
                    WCBCMigrator.hideLoadingOverlay();
                    WCBCMigrator.checkPrerequisites();
                }
            });
        },

        migrateAttributes: function(e) {
            e.preventDefault();
            var button = $(e.target);
            button.prop('disabled', true).text('Migrating Attributes...');

            this.showLoadingOverlay('Migrating attributes...');

            $.ajax({
                url: wcBcMigrator.apiUrl + 'migrate/attributes',
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
                },
                success: function(response) {
                    if (response.options && response.options.success > 0) {
                        WCBCMigrator.addLog('success', 'Migrated ' + response.options.success + ' attributes successfully');
                    }

                    /*if (response.brands && response.brands.success > 0) {
                        WCBCMigrator.addLog('success', 'Migrated ' + response.brands.success + ' brands successfully');
                    }*/

                    localStorage.setItem('wc_bc_attributes_migrated', 'true');

                    // Show detailed messages
                    if (response.messages) {
                        response.messages.forEach(function(msg) {
                            WCBCMigrator.addLog('info', msg);
                        });
                    }
                },
                error: function(xhr) {
                    WCBCMigrator.addLog('error', 'Failed to migrate attributes: ' + xhr.responseText);
                },
                complete: function() {
                    button.prop('disabled', false).text('Migrate Attributes');
                    WCBCMigrator.hideLoadingOverlay();
                    WCBCMigrator.checkPrerequisites();
                }
            });
        },

        setupB2B: function(e) {
            e.preventDefault();
            var button = $(e.target);
            button.prop('disabled', true).text('Setting up B2B features...');

            this.showLoadingOverlay('Setting up B2B features...');

            $.ajax({
                url: wcBcMigrator.apiUrl + 'migrate/b2b-setup',
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
                },
                success: function(response) {
                    if (response.customer_groups && response.customer_groups.success > 0) {
                        WCBCMigrator.addLog('success', 'Created ' + response.customer_groups.success + ' customer groups');
                    }

                    if (response.price_lists && response.price_lists.success > 0) {
                        WCBCMigrator.addLog('success', 'Created ' + response.price_lists.success + ' price lists');
                    }

                    localStorage.setItem('wc_bc_b2b_setup', 'true');

                    WCBCMigrator.addLog('info', 'B2B features setup completed');
                },
                error: function(xhr) {
                    WCBCMigrator.addLog('error', 'Failed to setup B2B features: ' + xhr.responseText);
                },
                complete: function() {
                    button.prop('disabled', false).text('Setup B2B Features');
                    WCBCMigrator.hideLoadingOverlay();
                    WCBCMigrator.checkPrerequisites();
                }
            });
        },

        prepareMigration: function(e) {
            e.preventDefault();
            var button = $(e.target);
            button.prop('disabled', true).text('Preparing...');

            $.ajax({
                url: wcBcMigrator.apiUrl + 'migrate/prepare',
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
                },
                success: function(response) {
                    WCBCMigrator.addLog('info', 'Prepared ' + response.inserted + ' products for migration');
                    if (response.skipped > 0) {
                        WCBCMigrator.addLog('warning', 'Skipped ' + response.skipped + ' products (already prepared)');
                    }
                    WCBCMigrator.loadStats();
                },
                error: function() {
                    WCBCMigrator.addLog('error', 'Failed to prepare products');
                },
                complete: function() {
                    button.prop('disabled', false).text('Prepare Products for Migration');
                }
            });
        },

        startMigration: function(e) {
            e.preventDefault();

            // Check prerequisites
            var categoriesMigrated = localStorage.getItem('wc_bc_categories_migrated') === 'true';
            var attributesMigrated = localStorage.getItem('wc_bc_attributes_migrated') === 'true';
            var b2bSetup = localStorage.getItem('wc_bc_b2b_setup') === 'true';

            if (!categoriesMigrated || !attributesMigrated || !b2bSetup) {
                alert('Please complete categories, attributes, and B2B setup before migrating products.');
                return;
            }

            if (this.isRunning) return;

            this.isRunning = true;
            this.shouldStop = false;

            $('#start-batch').prop('disabled', true);
            $('#stop-batch').prop('disabled', false);
            $('#progress-bar').show();
            $('#live-log').show();

            this.addLog('info', 'Starting product migration...');
            this.processBatch();
        },

        stopBatchMigration: function(e) {
            e.preventDefault();
            this.shouldStop = true;
            $('#stop-batch').prop('disabled', true).text('Stopping...');
        },

        processBatch: function() {
            if (this.shouldStop) {
                this.finishMigration();
                return;
            }

            var batchSize = $('#batch-size').val();

            $.ajax({
                url: wcBcMigrator.apiUrl + 'migrate/batch',
                method: 'POST',
                data: { batch_size: batchSize },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
                },
                success: function(response) {
                    if (response.processed > 0) {
                        WCBCMigrator.addLog('success', 'Processed ' + response.processed + ' products');
                    }

                    if (response.errors > 0) {
                        WCBCMigrator.addLog('error', response.errors + ' products failed');
                    }

                    WCBCMigrator.loadStats();
                    WCBCMigrator.updateProgress();

                    if (response.remaining > 0 && !WCBCMigrator.shouldStop) {
                        setTimeout(function() {
                            WCBCMigrator.processBatch();
                        }, 1000);
                    } else {
                        WCBCMigrator.finishMigration();
                    }
                },
                error: function() {
                    WCBCMigrator.addLog('error', 'Batch processing failed');
                    WCBCMigrator.finishMigration();
                }
            });
        },

        retryErrors: function(e) {
            e.preventDefault();
            var button = $(e.target);
            button.prop('disabled', true).text('Retrying...');

            var batchSize = $('#batch-size').val();

            $.ajax({
                url: wcBcMigrator.apiUrl + 'migrate/retry-errors',
                method: 'POST',
                data: { batch_size: batchSize },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
                },
                success: function(response) {
                    WCBCMigrator.addLog('info', 'Retried error products');
                    WCBCMigrator.loadStats();
                },
                error: function() {
                    WCBCMigrator.addLog('error', 'Failed to retry errors');
                },
                complete: function() {
                    button.prop('disabled', false).text('Retry Failed Products');
                }
            });
        },

        finishMigration: function() {
            this.isRunning = false;
            this.shouldStop = false;

            $('#start-batch').prop('disabled', false);
            $('#stop-batch').prop('disabled', true).text('Stop Migration');

            this.addLog('info', 'Migration process completed');
            this.loadStats();
        },

        updateProgress: function() {
            $.ajax({
                url: wcBcMigrator.apiUrl + 'migrate/stats',
                method: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
                },
                success: function(response) {
                    var total = response.total;
                    var completed = response.success + response.error;
                    var percentage = total > 0 ? Math.round((completed / total) * 100) : 0;

                    $('#progress-fill').css('width', percentage + '%').text(percentage + '%');
                }
            });
        },

        addLog: function(type, message) {
            var timestamp = new Date().toLocaleTimeString();
            var logEntry = $('<div class="log-entry ' + type + '">[' + timestamp + '] ' + message + '</div>');
            $('#log-entries').prepend(logEntry);

            // Keep only last 50 entries
            $('#log-entries .log-entry').slice(50).remove();

            // Also add to activity logs
            this.saveActivityLog(type, message);
        },

        saveActivityLog: function(type, message) {
            var logs = JSON.parse(localStorage.getItem('wc_bc_activity_logs') || '[]');
            logs.unshift({
                type: type,
                message: message,
                timestamp: new Date().toISOString()
            });

            // Keep only last 200 logs
            logs = logs.slice(0, 200);
            localStorage.setItem('wc_bc_activity_logs', JSON.stringify(logs));
        },

        saveSettings: function(e) {
            e.preventDefault();

            var formData = new FormData(e.target);

            // Save via AJAX
            $.ajax({
                url: wcBcMigrator.adminAjax,
                method: 'POST',
                data: {
                    action: 'wc_bc_save_settings',
                    nonce: wcBcMigrator.nonce,
                    store_hash: formData.get('bc_store_hash'),
                    access_token: formData.get('bc_access_token'),
                    client_id: formData.get('bc_client_id'),
                    client_secret: formData.get('bc_client_secret')
                },
                success: function() {
                    WCBCMigrator.showNotice('success', 'Settings saved successfully!');
                },
                error: function() {
                    WCBCMigrator.showNotice('error', 'Failed to save settings');
                }
            });
        },

        testConnection: function(e) {
            e.preventDefault();
            var button = $(e.target);
            button.prop('disabled', true).text('Testing...');

            $.ajax({
                url: wcBcMigrator.apiUrl + 'test-connection',
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
                },
                success: function(response) {
                    if (response.success) {
                        $('#connection-test-result').html(
                            '<div class="notice notice-success"><p>✓ Connected successfully to store: <strong>' +
                            response.store_name + '</strong> (' + response.store_domain + ')</p></div>'
                        );
                    } else {
                        $('#connection-test-result').html(
                            '<div class="notice notice-error"><p>✗ Connection failed: ' + response.error + '</p></div>'
                        );
                    }
                },
                error: function() {
                    $('#connection-test-result').html(
                        '<div class="notice notice-error"><p>✗ Connection test failed</p></div>'
                    );
                },
                complete: function() {
                    button.prop('disabled', false).text('Test Connection');
                }
            });
        },

        resetMigration: function(e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to reset all migration data? This action cannot be undone.')) {
                return;
            }

            // Reset localStorage
            localStorage.removeItem('wc_bc_categories_migrated');
            localStorage.removeItem('wc_bc_attributes_migrated');
            localStorage.removeItem('wc_bc_b2b_setup');
            localStorage.removeItem('wc_bc_activity_logs');

            // TODO: Add AJAX call to reset database tables

            this.showNotice('info', 'Migration data reset. Please refresh the page.');
        },

        exportErrors: function(e) {
            e.preventDefault();
            // TODO: Implement CSV export of error products
            this.showNotice('info', 'Export feature coming soon');
        },

        exportLogs: function(e) {
            e.preventDefault();
            var logs = JSON.parse(localStorage.getItem('wc_bc_activity_logs') || '[]');

            var csv = 'Timestamp,Type,Message\n';
            logs.forEach(function(log) {
                csv += '"' + log.timestamp + '","' + log.type + '","' + log.message + '"\n';
            });

            this.downloadCSV(csv, 'migration-logs.csv');
        },

        downloadCSV: function(csv, filename) {
            var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = filename;
            link.click();
        },

        showNotice: function(type, message) {
            var notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            $('.wrap h1').after(notice);

            setTimeout(function() {
                notice.fadeOut();
            }, 5000);
        },

        showLoadingOverlay: function(message) {
            $('#loading-overlay').addClass('active');
            $('#loading-overlay p').text(message || 'Processing...');
        },

        hideLoadingOverlay: function() {
            $('#loading-overlay').removeClass('active');
        }
    };

    $(document).ready(function() {
        WCBCMigrator.init();
    });

})(jQuery);