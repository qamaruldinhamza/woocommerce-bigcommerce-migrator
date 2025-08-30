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
                <div class="tab" data-tab="verification"><?php _e('Verification', 'wc-bc-migrator'); ?></div>
                <div class="tab" data-tab="orders"><?php _e('Order Migration', 'wc-bc-migrator'); ?></div>

                <div class="tab" data-tab="customers"><?php _e('Customer Migration', 'wc-bc-migrator'); ?></div>
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

            <!-- Verification Tab -->
            <div class="tab-content" id="verification-tab">
                <!-- Verification Statistics -->
                <div class="wc-bc-stats" id="verification-stats">
                    <div class="stat-card">
                        <h3><?php _e('Total to Verify', 'wc-bc-migrator'); ?></h3>
                        <div class="number" id="verify-stat-total">0</div>
                    </div>
                    <div class="stat-card pending">
                        <h3><?php _e('Pending', 'wc-bc-migrator'); ?></h3>
                        <div class="number" id="verify-stat-pending">0</div>
                    </div>
                    <div class="stat-card success">
                        <h3><?php _e('Verified', 'wc-bc-migrator'); ?></h3>
                        <div class="number" id="verify-stat-verified">0</div>
                    </div>
                    <div class="stat-card error">
                        <h3><?php _e('Failed', 'wc-bc-migrator'); ?></h3>
                        <div class="number" id="verify-stat-failed">0</div>
                    </div>
                </div>

                <!-- Verification Actions -->
                <div class="wc-bc-actions">
                    <div class="action-group">
                        <h3><?php _e('Initialize Verification', 'wc-bc-migrator'); ?></h3>
                        <p><?php _e('Set up the verification system and populate it with migrated products.', 'wc-bc-migrator'); ?></p>
                        <button class="button" id="init-verification">
							<?php _e('Initialize Verification System', 'wc-bc-migrator'); ?>
                        </button>
                        <button class="button button-secondary" id="populate-verification">
							<?php _e('Populate Verification Table', 'wc-bc-migrator'); ?>
                        </button>
                    </div>

                    <div class="action-group">
                        <h3><?php _e('Run Verification', 'wc-bc-migrator'); ?></h3>
                        <p><?php _e('Verify that migrated products still exist in BigCommerce.', 'wc-bc-migrator'); ?></p>
                        <label><?php _e('Batch Size:', 'wc-bc-migrator'); ?>
                            <input type="number" class="batch-size-input" id="verify-batch-size" value="50" min="1" max="200">
                        </label>
                        <button class="button" id="start-verification">
                            <?php _e('Start Verification & Weight Fix', 'wc-bc-migrator'); ?>
                        </button>
                        <button class="button button-secondary" id="stop-verification" disabled>
							<?php _e('Stop Verification', 'wc-bc-migrator'); ?>
                        </button>

                        <div class="progress-bar" id="verification-progress-bar">
                            <div class="progress-fill" id="verification-progress-fill">0%</div>
                        </div>
                    </div>

                    <div class="action-group">
                        <h3><?php _e('Verification Management', 'wc-bc-migrator'); ?></h3>
                        <p><?php _e('Retry failed verifications, fix weights, and download verification reports.', 'wc-bc-migrator'); ?></p>
                        <button class="button button-secondary" id="retry-verification">
                            <?php _e('Retry Failed Verifications', 'wc-bc-migrator'); ?>
                        </button>
                        <button class="button button-primary" id="verify-and-fix-weights">
                            <?php _e('Verify & Fix Weights', 'wc-bc-migrator'); ?>
                        </button>
                        <button class="button button-secondary" id="download-verification-log">
                            <?php _e('Download Verification Log', 'wc-bc-migrator'); ?>
                        </button>
                        <button class="button button-secondary" id="view-failed-verifications">
                            <?php _e('View Failed Verifications', 'wc-bc-migrator'); ?>
                        </button>
                    </div>
                </div>

                <!-- Failed Verifications Table -->
                <div class="failed-verifications-container" id="failed-verifications-container" style="display: none;">
                    <h3><?php _e('Failed Verifications', 'wc-bc-migrator'); ?></h3>
                    <div class="table-container">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                            <tr>
                                <th><?php _e('WC Product ID', 'wc-bc-migrator'); ?></th>
                                <th><?php _e('BC Product ID', 'wc-bc-migrator'); ?></th>
                                <th><?php _e('Product Name', 'wc-bc-migrator'); ?></th>
                                <th><?php _e('Error Message', 'wc-bc-migrator'); ?></th>
                                <th><?php _e('Last Verified', 'wc-bc-migrator'); ?></th>
                            </tr>
                            </thead>
                            <tbody id="failed-verifications-tbody">
                            <!-- Failed verifications will be populated here -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Verification Log -->
                <div class="log-container" id="verification-live-log" style="display: none;">
                    <h3><?php _e('Verification Progress', 'wc-bc-migrator'); ?></h3>
                    <div id="verification-log-entries"></div>
                </div>
            </div>

            <!-- Order Migration Tab -->
            <div class="tab-content" id="orders-tab">
                <!-- Order Migration Statistics -->
                <div class="wc-bc-stats" id="order-stats">
                    <div class="stat-card">
                        <h3><?php _e('Total Orders', 'wc-bc-migrator'); ?></h3>
                        <div class="number" id="order-stat-total">0</div>
                    </div>
                    <div class="stat-card pending">
                        <h3><?php _e('Pending', 'wc-bc-migrator'); ?></h3>
                        <div class="number" id="order-stat-pending">0</div>
                    </div>
                    <div class="stat-card success">
                        <h3><?php _e('Migrated', 'wc-bc-migrator'); ?></h3>
                        <div class="number" id="order-stat-success">0</div>
                    </div>
                    <div class="stat-card error">
                        <h3><?php _e('Failed', 'wc-bc-migrator'); ?></h3>
                        <div class="number" id="order-stat-error">0</div>
                    </div>
                </div>

                <!-- Order Migration Actions -->
                <div class="wc-bc-actions">
                    <div class="action-group">
                        <h3><?php _e('Prerequisites Check', 'wc-bc-migrator'); ?></h3>
                        <p><?php _e('Validate that products and customers are migrated before starting order migration.', 'wc-bc-migrator'); ?></p>
                        <button class="button" id="validate-order-dependencies">
                            <?php _e('Check Migration Dependencies', 'wc-bc-migrator'); ?>
                        </button>
                        <div id="dependency-results" class="dependency-results" style="display: none;"></div>
                    </div>

                    <div class="action-group">
                        <h3><?php _e('Initialize Order Migration', 'wc-bc-migrator'); ?></h3>
                        <p><?php _e('Prepare WooCommerce orders for migration. You can filter by date range and order status.', 'wc-bc-migrator'); ?></p>

                        <button class="button" id="prepare-orders">
                            <?php _e('Prepare Orders for Migration', 'wc-bc-migrator'); ?>
                        </button>
                        <button class="button button-danger" id="reset-order-migration" style="display: none;">
                            <?php _e('Reset Order Migration Data', 'wc-bc-migrator'); ?>
                        </button>
                    </div>

                    <div class="action-group">
                        <h3><?php _e('Batch Order Migration', 'wc-bc-migrator'); ?></h3>
                        <p><?php _e('Migrate orders in batches. Orders will preserve all data including products, customers, payments, and totals.', 'wc-bc-migrator'); ?></p>
                        <label><?php _e('Batch Size:', 'wc-bc-migrator'); ?>
                            <input type="number" class="batch-size-input" id="order-batch-size" value="5" min="1" max="20">
                        </label>
                        <button class="button" id="start-order-batch">
                            <?php _e('Start Order Migration', 'wc-bc-migrator'); ?>
                        </button>
                        <button class="button button-secondary" id="stop-order-batch" disabled>
                            <?php _e('Stop Migration', 'wc-bc-migrator'); ?>
                        </button>

                        <div class="progress-bar" id="order-progress-bar">
                            <div class="progress-fill" id="order-progress-fill">0%</div>
                        </div>
                    </div>

                    <div class="action-group">
                        <h3><?php _e('Error Handling', 'wc-bc-migrator'); ?></h3>
                        <p><?php _e('Retry migration for orders that failed during the initial process.', 'wc-bc-migrator'); ?></p>
                        <button class="button button-secondary" id="retry-order-errors">
                            <?php _e('Retry Failed Orders', 'wc-bc-migrator'); ?>
                        </button>
                        <button class="button button-secondary" id="view-failed-orders">
                            <?php _e('View Failed Orders', 'wc-bc-migrator'); ?>
                        </button>
                        <button class="button button-secondary" id="export-order-errors">
                            <?php _e('Export Order Error Report', 'wc-bc-migrator'); ?>
                        </button>
                    </div>
                </div>

                <!-- Failed Orders Table -->
                <div class="failed-orders-container" id="failed-orders-container" style="display: none;">
                    <h3><?php _e('Failed Order Migrations', 'wc-bc-migrator'); ?></h3>
                    <div class="table-container">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                            <tr>
                                <th><?php _e('WC Order ID', 'wc-bc-migrator'); ?></th>
                                <th><?php _e('Customer', 'wc-bc-migrator'); ?></th>
                                <th><?php _e('Order Total', 'wc-bc-migrator'); ?></th>
                                <th><?php _e('Order Date', 'wc-bc-migrator'); ?></th>
                                <th><?php _e('Payment Method', 'wc-bc-migrator'); ?></th>
                                <th><?php _e('Error Message', 'wc-bc-migrator'); ?></th>
                            </tr>
                            </thead>
                            <tbody id="failed-orders-tbody">
                            <!-- Failed orders will be populated here -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Order Migration Log -->
                <div class="log-container" id="order-live-log" style="display: none;">
                    <h3><?php _e('Order Migration Progress', 'wc-bc-migrator'); ?></h3>
                    <div id="order-log-entries"></div>
                </div>
            </div>

            <!-- Customer Migration Tab -->
            <div class="tab-content" id="customers-tab">
                <!-- Customer Migration Statistics -->
                <div class="wc-bc-stats" id="customer-stats">
                    <div class="stat-card">
                        <h3><?php _e('Total Customers', 'wc-bc-migrator'); ?></h3>
                        <div class="number" id="customer-stat-total">0</div>
                    </div>
                    <div class="stat-card pending">
                        <h3><?php _e('Pending', 'wc-bc-migrator'); ?></h3>
                        <div class="number" id="customer-stat-pending">0</div>
                    </div>
                    <div class="stat-card success">
                        <h3><?php _e('Migrated', 'wc-bc-migrator'); ?></h3>
                        <div class="number" id="customer-stat-success">0</div>
                    </div>
                    <div class="stat-card error">
                        <h3><?php _e('Failed', 'wc-bc-migrator'); ?></h3>
                        <div class="number" id="customer-stat-error">0</div>
                    </div>
                </div>

                <!-- Customer Migration Actions -->
                <div class="wc-bc-actions">
                    <div class="action-group">
                        <h3><?php _e('Initialize Customer Migration', 'wc-bc-migrator'); ?></h3>
                        <p><?php _e('Prepare all WooCommerce customers for migration. This will scan your customer accounts and create migration records.', 'wc-bc-migrator'); ?></p>
                        <button class="button" id="prepare-customers">
                            <?php _e('Prepare Customers for Migration', 'wc-bc-migrator'); ?>
                        </button>
                        <button class="button button-danger" id="reset-customer-migration" style="display: none;">
                            <?php _e('Reset Customer Migration Data', 'wc-bc-migrator'); ?>
                        </button>
                    </div>

                    <div class="action-group">
                        <h3><?php _e('Batch Customer Migration', 'wc-bc-migrator'); ?></h3>
                        <p><?php _e('Migrate customers in batches to avoid timeouts. Includes customer data, addresses, and B2B information.', 'wc-bc-migrator'); ?></p>
                        <label><?php _e('Batch Size:', 'wc-bc-migrator'); ?>
                            <input type="number" class="batch-size-input" id="customer-batch-size" value="10" min="1" max="50">
                        </label>
                        <button class="button" id="start-customer-batch">
                            <?php _e('Start Customer Migration', 'wc-bc-migrator'); ?>
                        </button>
                        <button class="button button-secondary" id="stop-customer-batch" disabled>
                            <?php _e('Stop Migration', 'wc-bc-migrator'); ?>
                        </button>

                        <div class="progress-bar" id="customer-progress-bar">
                            <div class="progress-fill" id="customer-progress-fill">0%</div>
                        </div>
                    </div>

                    <div class="action-group">
                        <h3><?php _e('Error Handling', 'wc-bc-migrator'); ?></h3>
                        <p><?php _e('Retry migration for customers that failed during the initial process.', 'wc-bc-migrator'); ?></p>
                        <button class="button button-secondary" id="retry-customer-errors">
                            <?php _e('Retry Failed Customers', 'wc-bc-migrator'); ?>
                        </button>
                        <button class="button button-secondary" id="export-customer-errors">
                            <?php _e('Export Customer Error Report', 'wc-bc-migrator'); ?>
                        </button>
                    </div>
                </div>

                <!-- Customer Migration Log -->
                <div class="log-container" id="customer-live-log" style="display: none;">
                    <h3><?php _e('Customer Migration Progress', 'wc-bc-migrator'); ?></h3>
                    <div id="customer-log-entries"></div>
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