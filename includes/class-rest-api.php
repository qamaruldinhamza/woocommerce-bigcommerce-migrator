<?php
/**
 * REST API Class
 */
class WC_BC_REST_API {

	public function register_routes() {
		register_rest_route('wc-bc-migrator/v1', '/migrate/batch', array(
			'methods' => 'POST',
			'callback' => array($this, 'process_batch'),
			'permission_callback' => array($this, 'check_permission'),
		));

		register_rest_route('wc-bc-migrator/v1', '/migrate/prepare', array(
			'methods' => 'POST',
			'callback' => array($this, 'prepare_migration'),
			'permission_callback' => array($this, 'check_permission'),
		));

		register_rest_route('wc-bc-migrator/v1', '/migrate/stats', array(
			'methods' => 'GET',
			'callback' => array($this, 'get_stats'),
			'permission_callback' => array($this, 'check_permission'),
		));

		register_rest_route('wc-bc-migrator/v1', '/migrate/retry-errors', array(
			'methods' => 'POST',
			'callback' => array($this, 'retry_errors'),
			'permission_callback' => array($this, 'check_permission'),
		));
	}

	public function check_permission() {
		return current_user_can('manage_options');
	}

	public function prepare_migration() {
		$batch_processor = new WC_BC_Batch_Processor();
		$result = $batch_processor->prepare_products();

		return new WP_REST_Response($result, 200);
	}

	public function process_batch($request) {
		$batch_size = $request->get_param('batch_size') ?: 10;

		$batch_processor = new WC_BC_Batch_Processor();
		$result = $batch_processor->process_batch($batch_size);

		return new WP_REST_Response($result, 200);
	}

	public function get_stats() {
		$stats = WC_BC_Database::get_migration_stats();

		$formatted_stats = array(
			'total' => 0,
			'pending' => 0,
			'success' => 0,
			'error' => 0,
		);

		foreach ($stats as $stat) {
			$formatted_stats[$stat->status] = (int) $stat->count;
			$formatted_stats['total'] += (int) $stat->count;
		}

		return new WP_REST_Response($formatted_stats, 200);
	}

	public function retry_errors($request) {
		$batch_size = $request->get_param('batch_size') ?: 10;

		$batch_processor = new WC_BC_Batch_Processor();
		$result = $batch_processor->retry_errors($batch_size);

		return new WP_REST_Response($result, 200);
	}
}
