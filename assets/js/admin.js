(function($) {
    'use strict';

    var WCBCMigrator = {
        isRunning: false,
        shouldStop: false,
        currentStep: 'idle',
        isCustomerMigrationRunning: false,
        shouldStopCustomers: false,

        init: function() {
            this.bindEvents();
            this.hideLoadingOverlay();
            this.loadStats();
            this.checkMigrationStatus();
            this.loadCustomerStats();
        },

        bindEvents: function() {
            // Tab switching
            $('.tab').on('click', function() {
                var tabId = $(this).data('tab');
                $('.tab').removeClass('active');
                $(this).addClass('active');
                $('.tab-content').removeClass('active');
                $('#' + tabId + '-tab').addClass('active');

                if (tabId === 'customers') {
                    WCBCMigrator.loadCustomerStats();
                }

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

            // Verification actions
            $('#init-verification').on('click', this.initVerification.bind(this));
            $('#populate-verification').on('click', this.populateVerification.bind(this));
            $('#start-verification').on('click', this.startVerification.bind(this));
            $('#stop-verification').on('click', this.stopVerification.bind(this));
            $('#retry-verification').on('click', this.retryVerification.bind(this));
            $('#verify-and-fix-weights').on('click', this.verifyAndFixWeights.bind(this));




            // Add these event bindings to the bindEvents function
            $('#prepare-customers').on('click', this.prepareCustomers.bind(this));
            $('#start-customer-batch').on('click', this.startCustomerMigration.bind(this));
            $('#stop-customer-batch').on('click', this.stopCustomerMigration.bind(this));
            $('#retry-customer-errors').on('click', this.retryCustomerErrors.bind(this));
            $('#reset-customer-migration').on('click', this.resetCustomerMigration.bind(this));
            $('#export-customer-errors').on('click', this.exportCustomerErrors.bind(this));



            $('#validate-order-dependencies').on('click', this.validateOrderDependencies.bind(this));
            $('#prepare-orders').on('click', this.prepareOrders.bind(this));
            $('#start-order-batch').on('click', this.startOrderMigration.bind(this));
            $('#stop-order-batch').on('click', this.stopOrderMigration.bind(this));
            $('#retry-order-errors').on('click', this.retryOrderErrors.bind(this));
            $('#view-failed-orders').on('click', this.viewFailedOrders.bind(this));
            $('#export-order-errors').on('click', this.exportOrderErrors.bind(this));
            $('#reset-order-migration').on('click', this.resetOrderMigration.bind(this));

            $('#sync-product-data').on('click', this.startProductSync.bind(this));
            $('#stop-sync').on('click', this.stopProductSync.bind(this));


            /*// Add this line to your existing bindEvents function
            $('#set-default-variants').on('click', this.setDefaultVariants.bind(this));
            $('#stop-default-variants').on('click', this.stopDefaultVariants.bind(this));*/
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

            // Show progress indicator
            this.showLoadingOverlay('Preparing products for migration...');

            $.ajax({
                url: wcBcMigrator.apiUrl + 'migrate/prepare',
                method: 'POST',
                timeout: 300000, // 5 minute timeout
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
                },
                success: function(response) {
                    if (response.success) {
                        WCBCMigrator.addLog('success', 'Prepared ' + response.inserted + ' products for migration');
                        if (response.skipped > 0) {
                            WCBCMigrator.addLog('info', 'Skipped ' + response.skipped + ' products (already prepared)');
                        }
                        if (response.batches_processed) {
                            WCBCMigrator.addLog('info', 'Processed in ' + response.batches_processed + ' batches');
                        }
                    } else if (response.partial_success) {
                        // Handle partial success
                        WCBCMigrator.addLog('warning', 'Preparation partially completed: ' + (response.inserted || 0) + ' products prepared');
                        WCBCMigrator.addLog('info', 'You can run preparation again to continue or start migration with current data');
                    } else {
                        WCBCMigrator.addLog('error', 'Preparation failed: ' + (response.error || response.message || 'Unknown error'));
                    }

                    WCBCMigrator.loadStats();
                },
                error: function(xhr, status, error) {
                    if (status === 'timeout') {
                        WCBCMigrator.addLog('warning', 'Preparation timed out - some products may have been prepared. Check stats and retry if needed.');
                    } else {
                        WCBCMigrator.addLog('error', 'Preparation failed: Network error or server timeout');
                    }
                    WCBCMigrator.loadStats(); // Still load stats to see what was processed
                },
                complete: function() {
                    button.prop('disabled', false).text('Prepare Products for Migration');
                    WCBCMigrator.hideLoadingOverlay();
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
                //alert('Please complete categories, attributes, and B2B setup before migrating products.');
                //return;
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
        },

        // Load customer migration statistics
        loadCustomerStats: function() {
            $.ajax({
                url: wcBcMigrator.apiUrl + 'customers/stats',
                method: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
                },
                success: function(response) {
                    if (response.success && response.stats) {
                        $('#customer-stat-total').text(response.stats.total || 0);
                        $('#customer-stat-pending').text(response.stats.pending || 0);
                        $('#customer-stat-success').text(response.stats.success || 0);
                        $('#customer-stat-error').text(response.stats.error || 0);

                        // Show reset button if there's data
                        if (response.stats.total > 0) {
                            $('#reset-customer-migration').show();
                        }
                    }
                },
                error: function() {
                    console.log('Failed to load customer stats');
                }
            });
        },

// Prepare customers for migration
        prepareCustomers: function(e) {
            e.preventDefault();
            var button = $(e.target);
            button.prop('disabled', true).text('Preparing...');

            this.showLoadingOverlay('Preparing customers for migration...');

            $.ajax({
                url: wcBcMigrator.apiUrl + 'customers/prepare',
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
                },
                success: function(response) {
                    if (response.success) {
                        WCBCMigrator.addCustomerLog('success', 'Prepared ' + response.inserted + ' customers for migration');
                        if (response.skipped > 0) {
                            WCBCMigrator.addCustomerLog('warning', 'Skipped ' + response.skipped + ' customers (already prepared)');
                        }
                        WCBCMigrator.loadCustomerStats();
                    } else {
                        WCBCMigrator.addCustomerLog('error', 'Error: ' + response.message);
                    }
                },
                error: function() {
                    WCBCMigrator.addCustomerLog('error', 'Failed to prepare customers');
                },
                complete: function() {
                    button.prop('disabled', false).text('Prepare Customers for Migration');
                    WCBCMigrator.hideLoadingOverlay();
                }
            });
        },

// Start customer migration
        startCustomerMigration: function(e) {
            e.preventDefault();
            if (this.isCustomerMigrationRunning) return;

            this.isCustomerMigrationRunning = true;
            this.shouldStopCustomers = false;

            $('#start-customer-batch').prop('disabled', true);
            $('#stop-customer-batch').prop('disabled', false);
            $('#customer-progress-bar').show();
            $('#customer-live-log').show();

            this.addCustomerLog('info', 'Starting customer migration...');
            this.processCustomerBatch();
        },

// Process customer batch
        processCustomerBatch: function() {
            if (this.shouldStopCustomers) {
                this.finishCustomerMigration();
                return;
            }

            var batchSize = $('#customer-batch-size').val() || 10;

            $.ajax({
                url: wcBcMigrator.apiUrl + 'customers/migrate',
                method: 'POST',
                data: { batch_size: batchSize },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
                },
                success: function(response) {
                    if (response.success) {
                        if (response.processed > 0) {
                            WCBCMigrator.addCustomerLog('success', 'Processed ' + response.processed + ' customers');
                        }

                        if (response.errors > 0) {
                            WCBCMigrator.addCustomerLog('error', response.errors + ' customers failed');
                        }

                        WCBCMigrator.loadCustomerStats();
                        WCBCMigrator.updateCustomerProgress();

                        if (response.remaining > 0 && !WCBCMigrator.shouldStopCustomers) {
                            setTimeout(function() {
                                WCBCMigrator.processCustomerBatch();
                            }, 1500); // 1.5 second delay between batches
                        } else {
                            WCBCMigrator.finishCustomerMigration();
                        }
                    } else {
                        WCBCMigrator.addCustomerLog('error', 'Error: ' + response.message);
                        WCBCMigrator.finishCustomerMigration();
                    }
                },
                error: function() {
                    WCBCMigrator.addCustomerLog('error', 'Customer batch processing failed');
                    WCBCMigrator.finishCustomerMigration();
                }
            });
        },

// Stop customer migration
        stopCustomerMigration: function(e) {
            e.preventDefault();
            this.shouldStopCustomers = true;
            $('#stop-customer-batch').prop('disabled', true).text('Stopping...');
        },

// Finish customer migration
        finishCustomerMigration: function() {
            this.isCustomerMigrationRunning = false;
            this.shouldStopCustomers = false;

            $('#start-customer-batch').prop('disabled', false);
            $('#stop-customer-batch').prop('disabled', true).text('Stop Migration');

            this.addCustomerLog('info', 'Customer migration process completed');
            this.loadCustomerStats();
        },

// Update customer migration progress
        updateCustomerProgress: function() {
            $.ajax({
                url: wcBcMigrator.apiUrl + 'customers/stats',
                method: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
                },
                success: function(response) {
                    if (response.success && response.stats) {
                        var total = response.stats.total || 0;
                        var completed = (response.stats.success || 0) + (response.stats.error || 0);
                        var percentage = total > 0 ? Math.round((completed / total) * 100) : 0;

                        $('#customer-progress-fill').css('width', percentage + '%').text(percentage + '%');
                    }
                }
            });
        },

// Add customer log entry
        addCustomerLog: function(type, message) {
            var timestamp = new Date().toLocaleTimeString();
            var logEntry = $('<div class="log-entry ' + type + '">[' + timestamp + '] ' + message + '</div>');
            $('#customer-log-entries').prepend(logEntry);

            // Keep only last 50 entries
            $('#customer-log-entries .log-entry').slice(50).remove();

            // Also add to main activity logs
            this.saveActivityLog(type, '[CUSTOMERS] ' + message);
        },

// Retry customer errors
        retryCustomerErrors: function(e) {
            e.preventDefault();
            var button = $(e.target);
            button.prop('disabled', true).text('Retrying...');

            // This would need a new API endpoint for retrying customer errors
            WCBCMigrator.addCustomerLog('info', 'Retry customer errors functionality coming soon');

            setTimeout(function() {
                button.prop('disabled', false).text('Retry Failed Customers');
            }, 2000);
        },

// Reset customer migration
        resetCustomerMigration: function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to reset all customer migration data? This action cannot be undone.')) {
                return;
            }

            // This would need a new API endpoint for resetting customer migration
            WCBCMigrator.addCustomerLog('info', 'Customer migration reset functionality coming soon');
        },

        // Export customer errors
        exportCustomerErrors: function(e) {
            e.preventDefault();
            WCBCMigrator.addCustomerLog('info', 'Export customer errors functionality coming soon');
        },


        // Products Sync    :
        startProductSync: function(e) {
            e.preventDefault();
            if (this.isSyncRunning) return;

            this.isSyncRunning = true;
            this.shouldStopSync = false;

            $('#sync-product-data').prop('disabled', true);
            $('#stop-sync').prop('disabled', false);
            $('#sync-progress-bar').show();
            $('#sync-live-log').show();

            this.addSyncLog('info', 'Starting product data sync...');
            this.processSyncBatch();
        },

        stopProductSync: function(e) {
            e.preventDefault();
            this.shouldStopSync = true;
            $('#stop-sync').prop('disabled', true).text('Stopping...');
        },

        processSyncBatch: function() {
            if (this.shouldStopSync) {
                this.finishProductSync();
                return;
            }

            var batchSize = $('#sync-batch-size').val() || 20;

            $.ajax({
                url: wcBcMigrator.apiUrl + 'products/sync-data',
                method: 'POST',
                data: { batch_size: batchSize },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
                },
                success: function(response) {
                    if (response.success) {
                        if (response.processed > 0) {
                            WCBCMigrator.addSyncLog('success', 'Processed ' + response.processed + ' products. Updated: ' + response.updated + ', Failed: ' + response.failed);
                        }

                        WCBCMigrator.updateSyncProgress(response);

                        if (response.remaining > 0 && !WCBCMigrator.shouldStopSync) {
                            setTimeout(function() {
                                WCBCMigrator.processSyncBatch();
                            }, 1000);
                        } else {
                            WCBCMigrator.finishProductSync();
                        }
                    } else {
                        WCBCMigrator.addSyncLog('error', 'Error: ' + response.message);
                        WCBCMigrator.finishProductSync();
                    }
                },
                error: function() {
                    WCBCMigrator.addSyncLog('error', 'Sync batch processing failed');
                    WCBCMigrator.finishProductSync();
                }
            });
        },

        finishProductSync: function() {
            this.isSyncRunning = false;
            this.shouldStopSync = false;

            $('#sync-product-data').prop('disabled', false);
            $('#stop-sync').prop('disabled', true).text('Stop Sync');

            this.addSyncLog('info', 'Product sync process completed');
        },

        updateSyncProgress: function(response) {
            if (response.total_products > 0) {
                var percentage = Math.round(((response.total_products - response.remaining) / response.total_products) * 100);
                $('#sync-progress-fill').css('width', percentage + '%').text(percentage + '%');
            }
        },

        addSyncLog: function(type, message) {
            var timestamp = new Date().toLocaleTimeString();
            var logEntry = $('<div class="log-entry ' + type + '">[' + timestamp + '] ' + message + '</div>');
            $('#sync-log-entries').prepend(logEntry);

            // Keep only last 50 entries
            $('#sync-log-entries .log-entry').slice(50).remove();
        }
    };

    $(document).ready(function() {
        WCBCMigrator.init();
    });


    // Add verification methods to the WCBCMigrator object
    WCBCMigrator.verificationInterval = null;
    WCBCMigrator.isVerificationRunning = false;

    // Initialize verification system
    WCBCMigrator.initVerification = function(e) {
        e.preventDefault();
        var button = $(e.target);
        button.prop('disabled', true).text('Initializing...');

        this.showLoadingOverlay('Initializing verification system...');

        $.ajax({
            url: wcBcMigrator.apiUrl + 'verification/init',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
            },
            success: function(data) {
                if (data.success) {
                    WCBCMigrator.addLog('success', 'Verification system initialized successfully!');
                    WCBCMigrator.loadVerificationStats();
                } else {
                    WCBCMigrator.addLog('error', 'Error: ' + data.message);
                }
            },
            error: function() {
                WCBCMigrator.addLog('error', 'Error initializing verification system');
            },
            complete: function() {
                button.prop('disabled', false).text('Initialize Verification System');
                WCBCMigrator.hideLoadingOverlay();
            }
        });
    };

    // Populate verification table
    WCBCMigrator.populateVerification = function(e) {
        e.preventDefault();
        var button = $(e.target);
        button.prop('disabled', true).text('Populating...');

        this.showLoadingOverlay('Populating verification table...');

        $.ajax({
            url: wcBcMigrator.apiUrl + 'verification/populate',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
            },
            success: function(data) {
                if (data.success) {
                    WCBCMigrator.addLog('success', 'Verification table populated! Inserted: ' + data.inserted + ', Skipped: ' + data.skipped);
                    WCBCMigrator.loadVerificationStats();
                } else {
                    WCBCMigrator.addLog('error', 'Error: ' + data.message);
                }
            },
            error: function() {
                WCBCMigrator.addLog('error', 'Error populating verification table');
            },
            complete: function() {
                button.prop('disabled', false).text('Populate Verification Table');
                WCBCMigrator.hideLoadingOverlay();
            }
        });
    };

    // Load verification statistics
    WCBCMigrator.loadVerificationStats = function() {
        $.ajax({
            url: wcBcMigrator.apiUrl + 'verification/stats',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
            },
            success: function(data) {
                if (data.success && data.stats) {
                    WCBCMigrator.updateVerificationStats(data.stats);
                }
            }
        });
    };

    // Update verification statistics display
    WCBCMigrator.updateVerificationStats = function(stats) {
        $('#verify-stat-total').text(stats.total || 0);
        $('#verify-stat-pending').text(stats.pending || 0);
        $('#verify-stat-verified').text(stats.verified || 0);
        $('#verify-stat-failed').text(stats.failed || 0);
    };

    // Start verification process
    WCBCMigrator.startVerification = function(e) {
        e.preventDefault();
        if (this.isVerificationRunning) return;

        var batchSize = $('#verify-batch-size').val();
        this.isVerificationRunning = true;

        $('#start-verification').prop('disabled', true);
        $('#stop-verification').prop('disabled', false);
        $('#verification-live-log').show();

        this.addVerificationLogEntry('Starting verification and fix process for products and variations...');

        // Use the main verification endpoint instead of weight-specific
        this.processVerificationBatch(batchSize);
    };

    // Update processVerificationBatch to use the main verification endpoint:
    WCBCMigrator.processVerificationBatch = function(batchSize) {
        $.ajax({
            url: wcBcMigrator.apiUrl + 'verification/verify', // Changed from 'verification/update-weights'
            method: 'POST',
            data: { batch_size: batchSize },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
            },
            success: function(data) {
                if (data.success) {
                    WCBCMigrator.addVerificationLogEntry('Verification batch completed: ' + data.verified + ' items verified, ' + data.failed + ' failed. Remaining: ' + (data.remaining || 0));

                    // Update stats
                    WCBCMigrator.loadVerificationStats();
                    WCBCMigrator.updateVerificationProgress();

                    // Check if we should continue processing
                    if (data.remaining > 0 && data.processed > 0) {
                        // Check if verification is still running before scheduling next batch
                        if (WCBCMigrator.isVerificationRunning) {
                            // Schedule next batch after 1 second delay
                            setTimeout(function() {
                                // Double-check verification is still running
                                if (WCBCMigrator.isVerificationRunning) {
                                    WCBCMigrator.processVerificationBatch(batchSize);
                                }
                            }, 1000);
                        }
                    } else {
                        // No more products to process or no products were processed
                        WCBCMigrator.stopVerification();
                        WCBCMigrator.addLog('success', 'Verification and fixing completed for all products and variations!');
                    }
                } else {
                    WCBCMigrator.addVerificationLogEntry('Error: ' + data.message, 'error');
                    WCBCMigrator.stopVerification();
                }
            },
            error: function() {
                WCBCMigrator.addVerificationLogEntry('Error processing verification batch', 'error');
                WCBCMigrator.stopVerification();
            }
        });
    };

// Update stopVerification to not need interval clearing
    WCBCMigrator.stopVerification = function() {
        this.isVerificationRunning = false;

        // Remove interval clearing since we're not using intervals anymore
        // if (this.verificationInterval) {
        //     clearInterval(this.verificationInterval);
        //     this.verificationInterval = null;
        // }

        $('#start-verification').prop('disabled', false);
        $('#stop-verification').prop('disabled', true);

        WCBCMigrator.addVerificationLogEntry('Verification process stopped');
    };

    // Add verification log entry
    WCBCMigrator.addVerificationLogEntry = function(message, type) {
        type = type || 'info';
        var timestamp = new Date().toLocaleTimeString();
        var logEntry = $('<div class="log-entry ' + type + '">[' + timestamp + '] ' + message + '</div>');
        $('#verification-log-entries').prepend(logEntry);

        // Keep only last 50 entries
        $('#verification-log-entries .log-entry').slice(50).remove();
    };

    // Retry verification
    WCBCMigrator.retryVerification = function(e) {
        e.preventDefault();
        var button = $(e.target);
        var batchSize = $('#verify-batch-size').val();

        button.prop('disabled', true).text('Retrying...');

        $.ajax({
            url: wcBcMigrator.apiUrl + 'verification/retry',
            method: 'POST',
            data: { batch_size: parseInt(batchSize) },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
            },
            success: function(data) {
                if (data.success) {
                    WCBCMigrator.addLog('success', 'Retried failed verifications');
                    WCBCMigrator.loadVerificationStats();
                } else {
                    WCBCMigrator.addLog('error', 'Error retrying verifications: ' + data.message);
                }
            },
            error: function() {
                WCBCMigrator.addLog('error', 'Failed to retry verifications');
            },
            complete: function() {
                button.prop('disabled', false).text('Retry Failed Verifications');
            }
        });
    };

    // Verify and fix weights
    // Add these properties to track weight verification
    WCBCMigrator.weightVerificationInterval = null;
    WCBCMigrator.isWeightVerificationRunning = false;

    // Update the verifyAndFixWeights function to work like recurring batches
    WCBCMigrator.verifyAndFixWeights = function(e) {
        e.preventDefault();

        if (this.isWeightVerificationRunning) return;

        var batchSize = $('#verify-batch-size').val();
        this.isWeightVerificationRunning = true;

        $('#verify-and-fix-weights').prop('disabled', true).text('Verifying & Fixing Weights...');
        $('#stop-verification').prop('disabled', false);
        $('#verification-live-log').show();

        this.addVerificationLogEntry('Starting weight verification and fixing process...');
        this.processWeightVerificationBatch(batchSize);

        // Set up interval to continue processing
        this.weightVerificationInterval = setInterval(function() {
            if (WCBCMigrator.isWeightVerificationRunning) {
                WCBCMigrator.processWeightVerificationBatch(batchSize);
            }
        }, 3000); // Process every 3 seconds
    };

    // Add new method for processing weight verification batches
    WCBCMigrator.processWeightVerificationBatch = function(batchSize) {
        $.ajax({
            url: wcBcMigrator.apiUrl + 'verification/update-weights',
            method: 'POST',
            data: { batch_size: parseInt(batchSize) },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
            },
            success: function(data) {
                if (data.success) {
                    WCBCMigrator.addVerificationLogEntry('Weight verification batch completed: ' + data.updated + ' products updated, ' + data.failed + ' failed. Remaining: ' + (data.remaining || 0));
                    WCBCMigrator.loadVerificationStats();
                    WCBCMigrator.updateVerificationProgress();

                    // Stop if no more pending or no products processed
                    if ((data.remaining === 0) || (data.processed === 0)) {
                        WCBCMigrator.stopWeightVerification();
                        WCBCMigrator.addLog('success', 'Weight verification and fixing completed!');
                    }
                } else {
                    WCBCMigrator.addVerificationLogEntry('Error: ' + data.message, 'error');
                    WCBCMigrator.stopWeightVerification();
                }
            },
            error: function() {
                WCBCMigrator.addVerificationLogEntry('Error processing weight verification batch', 'error');
                WCBCMigrator.stopWeightVerification();
            }
        });
    };

    // Add method to stop weight verification
    WCBCMigrator.stopWeightVerification = function() {
        this.isWeightVerificationRunning = false;
        if (this.weightVerificationInterval) {
            clearInterval(this.weightVerificationInterval);
            this.weightVerificationInterval = null;
        }

        $('#verify-and-fix-weights').prop('disabled', false).text('Verify & Fix Weights');
        $('#stop-verification').prop('disabled', true);
    };

    // Add progress tracking for verification
    WCBCMigrator.updateVerificationProgress = function() {
        // Get current stats to calculate progress
        $.ajax({
            url: wcBcMigrator.apiUrl + 'verification/stats',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
            },
            success: function(data) {
                if (data.success && data.stats) {
                    var total = parseInt(data.stats.total) || 0;
                    var completed = parseInt(data.stats.verified) + parseInt(data.stats.failed);
                    var percentage = total > 0 ? Math.round((completed / total) * 100) : 0;

                    $('#verification-progress-fill').css('width', percentage + '%').text(percentage + '%');
                }
            }
        });
    };

    WCBCMigrator.isOrderMigrationRunning = false;
    WCBCMigrator.shouldStopOrders = false;


    // Order Migration Functions
    WCBCMigrator.loadOrderStats = function() {
        $.ajax({
            url: wcBcMigrator.apiUrl + 'orders/stats',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
            },
            success: function(response) {
                if (response.success && response.stats) {
                    $('#order-stat-total').text(response.stats.total || 0);
                    $('#order-stat-pending').text(response.stats.pending || 0);
                    $('#order-stat-success').text(response.stats.success || 0);
                    $('#order-stat-error').text(response.stats.error || 0);

                    // Show reset button if there's data
                    if (response.stats.total > 0) {
                        $('#reset-order-migration').show();
                    }
                }
            },
            error: function() {
                console.log('Failed to load order stats');
            }
        });
    };

    WCBCMigrator.validateOrderDependencies = function(e) {
        e.preventDefault();
        var button = $(e.target);
        button.prop('disabled', true).text('Validating...');

        $.ajax({
            url: wcBcMigrator.apiUrl + 'orders/validate',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
            },
            success: function(response) {
                WCBCMigrator.displayDependencyResults(response);
            },
            error: function() {
                WCBCMigrator.addOrderLog('error', 'Failed to validate dependencies');
            },
            complete: function() {
                button.prop('disabled', false).text('Check Migration Dependencies');
            }
        });
    };

    WCBCMigrator.displayDependencyResults = function(results) {
        var container = $('#dependency-results');
        var html = '<div class="dependency-validation">';

        if (results.ready_to_migrate) {
            html += '<div class="notice notice-success"><p><strong>✓ Ready to migrate orders!</strong></p></div>';
        } else {
            html += '<div class="notice notice-warning"><p><strong>⚠ Issues found:</strong></p></div>';
        }

        // Show individual checks
        if (results.checks) {
            html += '<ul class="dependency-checks">';
            Object.keys(results.checks).forEach(function(key) {
                var check = results.checks[key];
                var icon = check.status ? '✓' : '✗';
                var className = check.status ? 'success' : 'error';
                html += '<li class="' + className + '">' + icon + ' ' + check.name + ': ' + check.message + '</li>';
            });
            html += '</ul>';
        }

        // Show errors and warnings
        if (results.errors && results.errors.length > 0) {
            html += '<div class="validation-errors"><strong>Errors:</strong><ul>';
            results.errors.forEach(function(error) {
                html += '<li>' + error + '</li>';
            });
            html += '</ul></div>';
        }

        if (results.warnings && results.warnings.length > 0) {
            html += '<div class="validation-warnings"><strong>Warnings:</strong><ul>';
            results.warnings.forEach(function(warning) {
                html += '<li>' + warning + '</li>';
            });
            html += '</ul></div>';
        }

        html += '</div>';
        container.html(html).show();
    };

    WCBCMigrator.prepareOrders = function(e) {
        e.preventDefault();
        var button = $(e.target);
        button.prop('disabled', true).text('Preparing...');

        this.showLoadingOverlay('Preparing orders for migration...');

        $.ajax({
            url: wcBcMigrator.apiUrl + 'orders/prepare',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
            },
            success: function(response) {
                if (response.success) {
                    WCBCMigrator.addOrderLog('success', 'Prepared ' + response.inserted + ' orders for migration');
                    if (response.skipped > 0) {
                        WCBCMigrator.addOrderLog('warning', 'Skipped ' + response.skipped + ' orders (already prepared)');
                    }
                    if (response.errors > 0) {
                        WCBCMigrator.addOrderLog('error', response.errors + ' orders had errors');
                    }
                    WCBCMigrator.loadOrderStats();
                } else {
                    WCBCMigrator.addOrderLog('error', 'Error: ' + response.message);
                }
            },
            error: function() {
                WCBCMigrator.addOrderLog('error', 'Failed to prepare orders');
            },
            complete: function() {
                button.prop('disabled', false).text('Prepare Orders for Migration');
                WCBCMigrator.hideLoadingOverlay();
            }
        });
    };

    WCBCMigrator.startOrderMigration = function(e) {
        e.preventDefault();
        if (this.isOrderMigrationRunning) return;

        this.isOrderMigrationRunning = true;
        this.shouldStopOrders = false;

        $('#start-order-batch').prop('disabled', true);
        $('#stop-order-batch').prop('disabled', false);
        $('#order-progress-bar').show();
        $('#order-live-log').show();

        this.addOrderLog('info', 'Starting order migration...');
        this.processOrderBatch();
    };

    WCBCMigrator.processOrderBatch = function() {
        if (this.shouldStopOrders) {
            this.finishOrderMigration();
            return;
        }

        var batchSize = $('#order-batch-size').val() || 5;

        $.ajax({
            url: wcBcMigrator.apiUrl + 'orders/migrate',
            method: 'POST',
            data: { batch_size: batchSize },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
            },
            success: function(response) {
                if (response.success) {
                    if (response.processed > 0) {
                        WCBCMigrator.addOrderLog('success', 'Processed ' + response.processed + ' orders');
                    }

                    if (response.errors > 0) {
                        WCBCMigrator.addOrderLog('error', response.errors + ' orders failed');
                    }

                    WCBCMigrator.loadOrderStats();
                    WCBCMigrator.updateOrderProgress();

                    if (response.remaining > 0 && !WCBCMigrator.shouldStopOrders) {
                        setTimeout(function() {
                            WCBCMigrator.processOrderBatch();
                        }, 2000); // 2 second delay between batches (orders are more complex)
                    } else {
                        WCBCMigrator.finishOrderMigration();
                    }
                } else {
                    WCBCMigrator.addOrderLog('error', 'Error: ' + response.message);
                    WCBCMigrator.finishOrderMigration();
                }
            },
            error: function() {
                WCBCMigrator.addOrderLog('error', 'Order batch processing failed');
                WCBCMigrator.finishOrderMigration();
            }
        });
    };

    WCBCMigrator.stopOrderMigration = function(e) {
        e.preventDefault();
        this.shouldStopOrders = true;
        $('#stop-order-batch').prop('disabled', true).text('Stopping...');
    };

    WCBCMigrator.finishOrderMigration = function() {
        this.isOrderMigrationRunning = false;
        this.shouldStopOrders = false;

        $('#start-order-batch').prop('disabled', false);
        $('#stop-order-batch').prop('disabled', true).text('Stop Migration');

        this.addOrderLog('info', 'Order migration process completed');
        this.loadOrderStats();
    };

    WCBCMigrator.updateOrderProgress = function() {
        $.ajax({
            url: wcBcMigrator.apiUrl + 'orders/stats',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
            },
            success: function(response) {
                if (response.success && response.stats) {
                    var total = response.stats.total || 0;
                    var completed = (response.stats.success || 0) + (response.stats.error || 0);
                    var percentage = total > 0 ? Math.round((completed / total) * 100) : 0;

                    $('#order-progress-fill').css('width', percentage + '%').text(percentage + '%');
                }
            }
        });
    };

    WCBCMigrator.addOrderLog = function(type, message) {
        var timestamp = new Date().toLocaleTimeString();
        var logEntry = $('<div class="log-entry ' + type + '">[' + timestamp + '] ' + message + '</div>');
        $('#order-log-entries').prepend(logEntry);

        // Keep only last 50 entries
        $('#order-log-entries .log-entry').slice(50).remove();

        // Also add to main activity logs
        this.saveActivityLog(type, '[ORDERS] ' + message);
    };

    WCBCMigrator.retryOrderErrors = function(e) {
        e.preventDefault();
        var button = $(e.target);
        var batchSize = $('#order-batch-size').val() || 5;

        button.prop('disabled', true).text('Retrying...');

        $.ajax({
            url: wcBcMigrator.apiUrl + 'orders/retry',
            method: 'POST',
            data: { batch_size: batchSize },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
            },
            success: function(response) {
                if (response.success) {
                    WCBCMigrator.addOrderLog('success', 'Retried ' + response.processed + ' failed orders');
                    WCBCMigrator.loadOrderStats();
                } else {
                    WCBCMigrator.addOrderLog('error', 'Error retrying orders: ' + response.message);
                }
            },
            error: function() {
                WCBCMigrator.addOrderLog('error', 'Failed to retry order errors');
            },
            complete: function() {
                button.prop('disabled', false).text('Retry Failed Orders');
            }
        });
    };

    WCBCMigrator.viewFailedOrders = function(e) {
        e.preventDefault();
        var button = $(e.target);
        button.prop('disabled', true).text('Loading...');

        $.ajax({
            url: wcBcMigrator.apiUrl + 'orders/failed',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
            },
            success: function(response) {
                if (response.success) {
                    WCBCMigrator.displayFailedOrders(response.failed_orders);
                    $('#failed-orders-container').show();
                } else {
                    WCBCMigrator.addOrderLog('error', 'Error loading failed orders: ' + response.message);
                }
            },
            error: function() {
                WCBCMigrator.addOrderLog('error', 'Failed to load failed orders');
            },
            complete: function() {
                button.prop('disabled', false).text('View Failed Orders');
            }
        });
    };

    WCBCMigrator.displayFailedOrders = function(failedOrders) {
        var tbody = $('#failed-orders-tbody');
        tbody.empty();

        if (failedOrders.length === 0) {
            tbody.append('<tr><td colspan="6">No failed orders found.</td></tr>');
            return;
        }

        failedOrders.forEach(function(order) {
            var row = '<tr>' +
                '<td>' + order.wc_order_id + '</td>' +
                '<td>' + (order.customer_name || 'Guest') + '</td>' +
                '<td>$' + parseFloat(order.order_total).toFixed(2) + '</td>' +
                '<td>' + order.order_date + '</td>' +
                '<td>' + (order.payment_method || 'N/A') + '</td>' +
                '<td>' + (order.migration_message || 'Unknown error') + '</td>' +
                '</tr>';
            tbody.append(row);
        });
    };

    WCBCMigrator.exportOrderErrors = function(e) {
        e.preventDefault();
        // TODO: Implement order error export
        WCBCMigrator.addOrderLog('info', 'Export order errors functionality coming soon');
    };

    WCBCMigrator.resetOrderMigration = function(e) {
        e.preventDefault();
        if (!confirm('Are you sure you want to reset all order migration data? This action cannot be undone.')) {
            return;
        }

        // TODO: Add API endpoint for resetting order migration
        WCBCMigrator.addOrderLog('info', 'Order migration reset functionality coming soon');
    };

    $('.tab').on('click', function() {
        var tabId = $(this).data('tab');
        $('.tab').removeClass('active');
        $(this).addClass('active');
        $('.tab-content').removeClass('active');
        $('#' + tabId + '-tab').addClass('active');

        if (tabId === 'customers') {
            WCBCMigrator.loadCustomerStats();
        } else if (tabId === 'verification') {
            WCBCMigrator.loadVerificationStats();
        } else if (tabId === 'orders') {
            WCBCMigrator.loadOrderStats();
        }
    });

    // Add this new event handler inside your existing (function($) { ... })(jQuery); block

    $('#update-custom-fields').on('click', function() {
        if (!confirm('Are you sure you want to update custom field names for all migrated products? This cannot be undone.')) {
            return;
        }

        var logContainer = $('#verification-live-log');
        var logEntries = $('#verification-log-entries');
        var button = $(this);
        logContainer.show();
        logEntries.html('');
        button.prop('disabled', true);

        var progressBar = $('#cf-update-progress-bar');
        var progressFill = $('#cf-update-progress-fill');
        progressBar.show();

        function updateCustomFieldsBatch() {
            $.ajax({
                url: wcBcMigrator.apiUrl + 'products/update-custom-fields',
                method: 'POST',
                data: {
                    batch_size: 20
                },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
                },
                success: function(response) {
                    if (response.success) {
                        logEntries.prepend('<div class="log-entry success">Batch processed: ' + response.processed + ' products. Updated: ' + response.updated + ', Failed: ' + response.failed + '. Remaining: ' + response.remaining + '</div>');

                        if (response.remaining > 0 && response.processed > 0) {
                            // Calculate progress
                            $.ajax({
                                url: wcBcMigrator.apiUrl + 'migrate/stats',
                                method: 'GET',
                                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce); },
                                success: function(stats_response) {
                                    let total = stats_response.success; // Total successfully migrated products
                                    let progress = total > 0 ? ((total - response.remaining) / total) * 100 : 0;
                                    progressFill.css('width', progress + '%').text(Math.round(progress) + '%');
                                }
                            });

                            updateCustomFieldsBatch(); // Process next batch
                        } else {
                            logEntries.prepend('<div class="log-entry success">All custom fields have been updated successfully!</div>');
                            progressFill.css('width', '100%').text('100%');
                            button.prop('disabled', false);
                        }
                    } else {
                        logEntries.prepend('<div class="log-entry error">Error: ' + response.message + '</div>');
                        button.prop('disabled', false);
                    }
                },
                error: function() {
                    logEntries.prepend('<div class="log-entry error">An unexpected error occurred. Please check the server logs.</div>');
                    button.prop('disabled', false);
                }
            });
        }

        updateCustomFieldsBatch();
    });


    // Add these properties to track the default variants process
    WCBCMigrator.isDefaultVariantsRunning = false;
    WCBCMigrator.shouldStopDefaultVariants = false;

    $('#set-default-variants').on('click', function() {
        if (!confirm('Are you sure you want to set default variant options for all migrated products?')) {
            return;
        }

        WCBCMigrator.isDefaultVariantsRunning = true;
        WCBCMigrator.shouldStopDefaultVariants = false;

        var logContainer = $('#verification-live-log');
        var logEntries = $('#verification-log-entries');
        var startButton = $(this);
        var stopButton = $('#stop-default-variants');

        logContainer.show();
        logEntries.html('');
        startButton.prop('disabled', true);
        stopButton.prop('disabled', false);

        var progressBar = $('#variant-default-progress-bar');
        var progressFill = $('#variant-default-progress-fill');
        progressBar.show();

        function setDefaultVariantsBatch() {
            // Check if we should stop
            if (WCBCMigrator.shouldStopDefaultVariants) {
                WCBCMigrator.finishDefaultVariants();
                return;
            }

            $.ajax({
                url: wcBcMigrator.apiUrl + 'products/set-default-variants',
                method: 'POST',
                data: {
                    batch_size: 20
                },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
                },
                success: function(response) {
                    if (response.success) {
                        logEntries.prepend('<div class="log-entry success">Batch processed: ' + response.processed + ' products. Updated: ' + response.updated + ', Failed: ' + response.failed + '. Remaining: ' + response.remaining + '</div>');

                        if (response.remaining > 0 && response.processed > 0 && !WCBCMigrator.shouldStopDefaultVariants) {
                            // Calculate progress
                            $.ajax({
                                url: wcBcMigrator.apiUrl + 'migrate/stats',
                                method: 'GET',
                                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce); },
                                success: function(stats_response) {
                                    let total = stats_response.success;
                                    let progress = total > 0 ? ((total - response.remaining) / total) * 100 : 0;
                                    progressFill.css('width', progress + '%').text(Math.round(progress) + '%');
                                }
                            });

                            // Schedule next batch with 1 second delay
                            setTimeout(function() {
                                if (!WCBCMigrator.shouldStopDefaultVariants) {
                                    setDefaultVariantsBatch();
                                }
                            }, 1000);
                        } else {
                            WCBCMigrator.finishDefaultVariants();
                            if (!WCBCMigrator.shouldStopDefaultVariants) {
                                logEntries.prepend('<div class="log-entry success">All default variant options have been set successfully!</div>');
                                progressFill.css('width', '100%').text('100%');
                            }
                        }
                    } else {
                        logEntries.prepend('<div class="log-entry error">Error: ' + response.message + '</div>');
                        WCBCMigrator.finishDefaultVariants();
                    }
                },
                error: function() {
                    logEntries.prepend('<div class="log-entry error">An unexpected error occurred. Please check the server logs.</div>');
                    WCBCMigrator.finishDefaultVariants();
                }
            });
        }

        setDefaultVariantsBatch();
    });

    // Stop button handler
    $('#stop-default-variants').on('click', function(e) {
        e.preventDefault();
        WCBCMigrator.shouldStopDefaultVariants = true;
        $('#stop-default-variants').prop('disabled', true).text('Stopping...');
    });
    // Finish function
    WCBCMigrator.finishDefaultVariants = function() {
        this.isDefaultVariantsRunning = false;
        this.shouldStopDefaultVariants = false;

        $('#set-default-variants').prop('disabled', false);
        $('#stop-default-variants').prop('disabled', true).text('Stop Process');

        var logEntries = $('#verification-log-entries');
        if (this.shouldStopDefaultVariants) {
            logEntries.prepend('<div class="log-entry info">Default variants process stopped by user</div>');
        } else {
            logEntries.prepend('<div class="log-entry info">Default variants process completed</div>');
        }
    };

})(jQuery);