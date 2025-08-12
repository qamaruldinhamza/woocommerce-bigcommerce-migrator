<?php
/**
 * Admin Page Template for WooCommerce to BigCommerce Migrator
 *
 * @package WC_BC_Migrator
 */

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

// Get saved settings
$store_hash = get_option('wc_bc_store_hash', '');
$access_token = get_option('wc_bc_access_token', '');
$client_id = get_option('wc_bc_client_id', '');
$client_secret = get_option('wc_bc_client_secret', '');
?>

	<div class="wrap">
		<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

		<div class="wc-bc-container">
			<!-- Tabs -->
			<div class="tabs">
				<div class="tab active" data-tab="migration"><?php _e('Migration', 'wc-bc-migrator'); ?></div>
				<div class="tab" data-tab="settings"><?php _e('Settings', 'wc-bc-migrator'); ?></div>
				<div class="tab" data-tab="mapping"><?php _e('Category Mapping', 'wc-bc-migrator'); ?></div>
				<div class="tab" data-tab="logs"><?php _e('Activity Logs', 'wc-bc-migrator'); ?></div>
			</div>

			<!-- Migration Tab -->
			<div class="tab-content active" id="migration-tab">
				<!-- Statistics -->
				<div class="wc-bc-stats" id="migration-stats">
					<div class="stat-card">
						<h3><?php _e('Total Products', 'wc-bc-migrator'); ?></h3>
						<div class="number" id="stat-total">0</div>
					</div>
					<div class="stat-card pending">
						<h3><?php _e('Pending', 'wc-bc-migrator'); ?></h3>
						<div class="number" id="stat-pending">0</div>
					</div>
					<div class="stat-card success">
						<h3><?php _e('Migrated', 'wc-bc-migrator'); ?></h3>
						<div class="number" id="stat-success">0</div>
					</div>
					<div class="stat-card error">
						<h3><?php _e('Failed', 'wc-bc-migrator'); ?></h3>
						<div class="number" id="stat-error">0</div>
					</div>
				</div>

				<!-- Actions -->
				<div class="wc-bc-actions">
					<div class="action-group">
						<h3><?php _e('Initialize Migration', 'wc-bc-migrator'); ?></h3>
						<p><?php _e('Prepare all products for migration. This will scan your WooCommerce products and create migration records.', 'wc-bc-migrator'); ?></p>
						<button class="button" id="prepare-migration">
							<?php _e('Prepare Products for Migration', 'wc-bc-migrator'); ?>
						</button>
						<button class="button button-danger" id="reset-migration" style="display: none;">
							<?php _e('Reset All Migration Data', 'wc-bc-migrator'); ?>
						</button>
					</div>

					<div class="action-group">
						<h3><?php _e('Batch Migration', 'wc-bc-migrator'); ?></h3>
						<p><?php _e('Process products in batches to avoid timeouts.', 'wc-bc-migrator'); ?></p>
						<label><?php _e('Batch Size:', 'wc-bc-migrator'); ?>
							<input type="number" class="batch-size-input" id="batch-size" value="10" min="1" max="50">
						</label>
						<button class="button" id="start-batch">
							<?php _e('Start Batch Migration', 'wc-bc-migrator'); ?>
						</button>
						<button class="button button-secondary" id="stop-batch" disabled>
							<?php _e('Stop Migration', 'wc-bc-migrator'); ?>
						</button>

						<div class="progress-bar" id="progress-bar">
							<div class="progress-fill" id="progress-fill">0%</div>
						</div>
					</div>

					<div class="action-group">
						<h3><?php _e('Error Handling', 'wc-bc-migrator'); ?></h3>
						<p><?php _e('Retry migration for products that failed during the initial process.', 'wc-bc-migrator'); ?></p>
						<button class="button button-secondary" id="retry-errors">
							<?php _e('Retry Failed Products', 'wc-bc-migrator'); ?>
						</button>
						<button class="button button-secondary" id="export-errors">
							<?php _e('Export Error Report', 'wc-bc-migrator'); ?>
						</button>
					</div>
				</div>

				<!-- Live Log -->
				<div class="log-container" id="live-log" style="display: none;">
					<h3><?php _e('Migration Progress', 'wc-bc-migrator'); ?></h3>
					<div id="log-entries"></div>
				</div>
			</div>

			<!-- Settings Tab -->
			<div class="tab-content" id="settings-tab">
				<div class="settings-form">
					<h2><?php _e('BigCommerce API Settings', 'wc-bc-migrator'); ?></h2>
					<form id="settings-form" method="post" action="">
						<?php wp_nonce_field('wc_bc_save_settings', 'wc_bc_settings_nonce'); ?>

						<div class="form-group">
							<label for="bc-store-hash"><?php _e('Store Hash', 'wc-bc-migrator'); ?></label>
							<input type="text" id="bc-store-hash" name="bc_store_hash" value="<?php echo esc_attr($store_hash); ?>" />
							<p class="description"><?php _e('Your BigCommerce store hash (found in API credentials)', 'wc-bc-migrator'); ?></p>
						</div>

						<div class="form-group">
							<label for="bc-access-token"><?php _e('Access Token', 'wc-bc-migrator'); ?></label>
							<input type="password" id="bc-access-token" name="bc_access_token" value="<?php echo esc_attr($access_token); ?>" />
							<p class="description"><?php _e('Your BigCommerce API access token', 'wc-bc-migrator'); ?></p>
						</div>

						<div class="form-group">
							<label for="bc-client-id"><?php _e('Client ID', 'wc-bc-migrator'); ?></label>
							<input type="text" id="bc-client-id" name="bc_client_id" value="<?php echo esc_attr($client_id); ?>" />
							<p class="description"><?php _e('Your BigCommerce API client ID (optional)', 'wc-bc-migrator'); ?></p>
						</div>

						<div class="form-group">
							<label for="bc-client-secret"><?php _e('Client Secret', 'wc-bc-migrator'); ?></label>
							<input type="password" id="bc-client-secret" name="bc_client_secret" value="<?php echo esc_attr($client_secret); ?>" />
							<p class="description"><?php _e('Your BigCommerce API client secret (optional)', 'wc-bc-migrator'); ?></p>
						</div>

						<button type="submit" class="button button-primary" name="save_settings">
							<?php _e('Save Settings', 'wc-bc-migrator'); ?>
						</button>
						<button type="button" class="button button-secondary" id="test-connection">
							<?php _e('Test Connection', 'wc-bc-migrator'); ?>
						</button>
					</form>

					<div id="connection-test-result" style="margin-top: 20px;"></div>
				</div>
			</div>

			<!-- Category Mapping Tab -->
			<div class="tab-content" id="mapping-tab">
				<div class="settings-form">
					<h2><?php _e('Category Mapping', 'wc-bc-migrator'); ?></h2>
					<p><?php _e('Map your WooCommerce categories to BigCommerce categories before migration.', 'wc-bc-migrator'); ?></p>

					<div class="action-group">
						<button class="button" id="load-categories">
							<?php _e('Load Categories', 'wc-bc-migrator'); ?>
						</button>
						<button class="button button-secondary" id="auto-map-categories">
							<?php _e('Auto-Map by Name', 'wc-bc-migrator'); ?>
						</button>
						<button class="button button-primary" id="save-category-mapping">
							<?php _e('Save Mapping', 'wc-bc-migrator'); ?>
						</button>
					</div>

					<div id="category-mapping-container" style="margin-top: 20px;">
						<div class="empty-state">
							<h3><?php _e('No categories loaded', 'wc-bc-migrator'); ?></h3>
							<p><?php _e('Click "Load Categories" to start mapping.', 'wc-bc-migrator'); ?></p>
						</div>
					</div>
				</div>
			</div>

			<!-- Logs Tab -->
			<div class="tab-content" id="logs-tab">
				<div class="settings-form">
					<h2><?php _e('Activity Logs', 'wc-bc-migrator'); ?></h2>

					<div class="action-group">
						<button class="button" id="refresh-logs">
							<?php _e('Refresh Logs', 'wc-bc-migrator'); ?>
						</button>
						<button class="button button-secondary" id="export-logs">
							<?php _e('Export Logs', 'wc-bc-migrator'); ?>
						</button>
						<button class="button button-danger" id="clear-logs">
							<?php _e('Clear All Logs', 'wc-bc-migrator'); ?>
						</button>
					</div>

					<div class="log-container" style="margin-top: 20px;">
						<div id="activity-logs">
							<div class="empty-state">
								<h3><?php _e('No logs available', 'wc-bc-migrator'); ?></h3>
								<p><?php _e('Migration activity will appear here.', 'wc-bc-migrator'); ?></p>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- Loading Overlay -->
	<div class="loading-overlay" id="loading-overlay">
		<div class="loading-content">
			<div class="spinner"></div>
			<p><?php _e('Processing...', 'wc-bc-migrator'); ?></p>
		</div>
	</div>


    <script type="text/javascript">
        wcBcMigrator = {
            "apiUrl" : "<?php echo trailingslashit( rest_url('wc-bc-migrator/v1')); ?>",
        }

    </script>
    <script type="text/javascript">
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
                    var attributesMigrated = localStorage.getItem('wc_bc_attributes_migrated') === 'true';
                    var b2bSetup = localStorage.getItem('wc_bc_b2b_setup') === 'true';

                    if (!categoriesMigrated || !attributesMigrated || !b2bSetup) {
                        this.showPrerequisiteNotice();
                    }
                },

                showPrerequisiteNotice: function() {
                    var notice = $('<div class="notice notice-warning"><p><strong>Setup Required:</strong> Please migrate categories, attributes, and set up B2B features before migrating products.</p></div>');
                    notice.append('<button class="button" id="migrate-categories">Migrate Categories</button> ');
                    notice.append('<button class="button" id="migrate-attributes">Migrate Attributes</button> ');
                    notice.append('<button class="button" id="setup-b2b">Setup B2B Features</button>');

                    $('.wc-bc-container').prepend(notice);

                    // Bind events to new buttons
                    notice.find('#migrate-categories').on('click', this.migrateCategories.bind(this));
                    notice.find('#migrate-attributes').on('click', this.migrateAttributes.bind(this));
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
    </script>

    <style>
        /* Admin Page Styles */
        .wrap {
            margin: 20px;
            font-size: 14px;
        }

        .batch-size-input {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 100px;
            margin-right: 10px;
        }

        .log-container {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
            max-height: 400px;
            overflow-y: auto;
        }

        .log-entry {
            padding: 8px;
            margin-bottom: 5px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 13px;
        }

        .log-entry.success { background: #d4edda; color: #155724; }
        .log-entry.error { background: #f8d7da; color: #721c24; }
        .log-entry.info { background: #d1ecf1; color: #0c5460; }

        .settings-form {
            background: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .form-group input[type="text"],
        .form-group input[type="password"] {
            width: 100%;
            max-width: 400px;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #ddd;
        }

        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
        }

        .tab.active {
            border-bottom-color: #0073aa;
            font-weight: bold;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* WooCommerce to BigCommerce Migrator Admin Styles */

        .wc-bc-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .wc-bc-header {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .wc-bc-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .stat-card h3 {
            margin: 0 0 10px 0;
            color: #666;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .number {
            font-size: 36px;
            font-weight: bold;
            color: #333;
            line-height: 1;
        }

        .stat-card.pending .number {
            color: #f39c12;
        }

        .stat-card.success .number {
            color: #27ae60;
        }

        .stat-card.error .number {
            color: #e74c3c;
        }

        .wc-bc-actions {
            background: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .action-group {
            margin-bottom: 30px;
            padding-bottom: 30px;
            border-bottom: 1px solid #eee;
        }

        .action-group:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .action-group h3 {
            margin: 0 0 10px 0;
            color: #23282d;
            font-size: 18px;
        }

        .action-group p {
            color: #666;
            margin-bottom: 15px;
        }

        .button {
            background: #0073aa;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin-right: 10px;
            transition: background 0.2s;
            text-decoration: none;
            display: inline-block;
        }

        .button:hover {
            background: #005a87;
            color: white;
        }

        .button:disabled {
            background: #ccc;
            cursor: not-allowed;
            opacity: 0.6;
        }

        .button.button-secondary {
            background: #666;
        }

        .button.button-secondary:hover {
            background: #555;
        }

        .button.button-danger {
            background: #e74c3c;
        }

        .button.button-danger:hover {
            background: #c0392b;
        }

        .progress-bar {
            width: 100%;
            height: 30px;
            background: #f0f0f0;
            border-radius: 15px;
            overflow: hidden;
            margin-top: 20px;
            display: none;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #0073aa, #005a87);
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
            min-width: 50px;
        }

        .batch-size-input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 100px;
            margin-right: 10px;
            font-size: 14px;
        }

        .log-container {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #e5e5e5;
        }

        .log-container h3 {
            margin-top: 0;
            color: #23282d;
        }

        .log-entry {
            padding: 10px 12px;
            margin-bottom: 5px;
            border-radius: 4px;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 13px;
            border-left: 4px solid;
        }

        .log-entry.success {
            background: #d4edda;
            color: #155724;
            border-left-color: #27ae60;
        }

        .log-entry.error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: #e74c3c;
        }

        .log-entry.info {
            background: #d1ecf1;
            color: #0c5460;
            border-left-color: #17a2b8;
        }

        .log-entry.warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: #ffc107;
        }

        .settings-form {
            background: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #23282d;
        }

        .form-group input[type="text"],
        .form-group input[type="password"] {
            width: 100%;
            max-width: 400px;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .form-group input[type="text"]:focus,
        .form-group input[type="password"]:focus {
            outline: none;
            border-color: #0073aa;
            box-shadow: 0 0 0 1px #0073aa;
        }

        .form-group .description {
            color: #666;
            font-size: 13px;
            margin-top: 5px;
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #ddd;
            background: #fff;
            padding: 0 20px;
            border-radius: 8px 8px 0 0;
        }

        .tab {
            padding: 15px 20px;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            transition: all 0.2s;
            font-weight: 500;
            color: #666;
        }

        .tab:hover {
            color: #0073aa;
        }

        .tab.active {
            border-bottom-color: #0073aa;
            color: #0073aa;
            font-weight: 600;
        }

        .tab-content {
            display: none;
            animation: fadeIn 0.3s;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Spinner */
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(0,0,0,.1);
            border-radius: 50%;
            border-top-color: #0073aa;
            animation: spin 1s ease-in-out infinite;
            margin-left: 10px;
            vertical-align: middle;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Notices */
        .notice {
            padding: 12px;
            margin: 10px 0;
            border-radius: 4px;
            border-left: 4px solid;
        }

        .notice-success {
            background: #d4edda;
            color: #155724;
            border-left-color: #27ae60;
        }

        .notice-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: #e74c3c;
        }

        .notice-warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: #ffc107;
        }

        .notice-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left-color: #17a2b8;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .wc-bc-stats {
                grid-template-columns: 1fr;
            }

            .tabs {
                flex-wrap: wrap;
            }

            .tab {
                padding: 10px 15px;
                font-size: 14px;
            }

            .action-group h3 {
                font-size: 16px;
            }
        }

        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }

        .loading-overlay.active {
            display: flex;
        }

        .loading-content {
            background: white;
            padding: 30px;
            border-radius: 8px;
            text-align: center;
        }

        .loading-content .spinner {
            width: 40px;
            height: 40px;
            margin: 0 auto 20px;
        }

        /* Empty states */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }

        .empty-state h3 {
            margin-bottom: 10px;
            color: #23282d;
        }

        .empty-state p {
            margin-bottom: 20px;
        }
    </style>

<?php
// Handle settings form submission
if (isset($_POST['save_settings']) && wp_verify_nonce($_POST['wc_bc_settings_nonce'], 'wc_bc_save_settings')) {
	update_option('wc_bc_store_hash', sanitize_text_field($_POST['bc_store_hash']));
	update_option('wc_bc_access_token', sanitize_text_field($_POST['bc_access_token']));
	update_option('wc_bc_client_id', sanitize_text_field($_POST['bc_client_id']));
	update_option('wc_bc_client_secret', sanitize_text_field($_POST['bc_client_secret']));

	echo '<div class="notice notice-success is-dismissible"><p>' . __('Settings saved successfully!', 'wc-bc-migrator') . '</p></div>';
}
?>