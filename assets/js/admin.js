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

            // Verification actions
            $('#init-verification').on('click', this.initVerification.bind(this));
            $('#populate-verification').on('click', this.populateVerification.bind(this));
            $('#start-verification').on('click', this.startVerification.bind(this));
            $('#stop-verification').on('click', this.stopVerification.bind(this));
            $('#retry-verification').on('click', this.retryVerification.bind(this));
            $('#verify-and-fix-weights').on('click', this.verifyAndFixWeights.bind(this));
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

        this.addVerificationLogEntry('Starting verification and weight fixing process...');
        this.processVerificationBatch(batchSize);

        // Set up interval to continue processing
        this.verificationInterval = setInterval(function() {
            if (WCBCMigrator.isVerificationRunning) {
                WCBCMigrator.processVerificationBatch(batchSize);
            }
        }, 3000); // Process every 3 seconds
    };

    // Process a single verification batch
    WCBCMigrator.processVerificationBatch = function(batchSize) {
        $.ajax({
            url: wcBcMigrator.apiUrl + 'verification/update-weights', // Changed to weight endpoint
            method: 'POST',
            data: { batch_size: parseInt(batchSize) },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcBcMigrator.nonce);
            },
            success: function(data) {
                if (data.success) {
                    WCBCMigrator.addVerificationLogEntry('Verification batch completed: ' + data.updated + ' products verified and weight fixed, ' + data.failed + ' failed. Remaining: ' + (data.remaining || 0));

                    // Update stats
                    WCBCMigrator.loadVerificationStats();
                    WCBCMigrator.updateVerificationProgress();

                    // Stop if no more pending
                    if (data.remaining === 0 || data.processed === 0) {
                        WCBCMigrator.stopVerification();
                        WCBCMigrator.addLog('success', 'Verification and weight fixing completed!');
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

    // Update the existing stopVerification method to handle both types
    WCBCMigrator.stopVerification = function() {
        this.isVerificationRunning = false;
        if (this.verificationInterval) {
            clearInterval(this.verificationInterval);
            this.verificationInterval = null;
        }

        $('#start-verification').prop('disabled', false);
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


    $('.tab').on('click', function() {
        var tabId = $(this).data('tab');
        $('.tab').removeClass('active');
        $(this).addClass('active');
        $('.tab-content').removeClass('active');
        $('#' + tabId + '-tab').addClass('active');

        // Load verification stats when verification tab is activated
        if (tabId === 'verification') {
            WCBCMigrator.loadVerificationStats();
        }
    });

})(jQuery);