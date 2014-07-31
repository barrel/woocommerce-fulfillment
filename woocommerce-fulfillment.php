<?php
/*
Plugin Name: WooCommerce - Fulfillment
Plugin URI: http://www.woothemes.com/woocommerce/
Version: 1.0
Description: Add custom fulfillment support to WooCommerce.
Author: BarrelNY
Author URI: http://barrelny.com/
Text Domain: woo-fulfillment
*/

/**
 * Required functions
 */
if ( ! function_exists( 'woothemes_queue_update' ) )
	require_once( 'woo-includes/woo-functions.php' );

// TODO: determine if below is needed
/**
 * Plugin updates
 */
woothemes_queue_update( plugin_basename( __FILE__ ), '9de8640767ba64237808ed7f245a49bb', '18734' );

// we CAN'T use "wc-api". SS uses next format to call url: [Your XML Endpoint]?action=export&start_date=[Start Date]&end_date=[End Date]&page=1
// we keep access via hook
add_action( "parse_request", "woo_sf_parse_request" );
function woo_sf_parse_request( &$wp ) {
	if ( $wp->request == "woo-fulfillment-api" ) {
		include_once dirname( __FILE__ ) . "/handler.php";
		$hander = new WC_Fulfillment_Handler();
		die();
	}
}


// July 2013: add custom fields
register_activation_hook( __FILE__, 'woo_sf_plugin_activate' );
function woo_sf_plugin_activate() {
	global $wpdb;
	
	$wpdb->insert( $wpdb->postmeta, array( 'meta_key' => 'custom_field_Fulfillment_1' ) );
	$wpdb->insert( $wpdb->postmeta, array( 'meta_key' => 'custom_field_Fulfillment_2' ) );
	$wpdb->insert( $wpdb->postmeta, array( 'meta_key' => 'custom_field_Fulfillment_3' ) );
}
// TODO: review above this

class WC_Fulfillment {
	public $domain;
	public $shop_taxonomy = 'shop_order_status';

	/**
	 * Constructor: Filters and Actions.
	 * @return	void
	**/
	public function __construct() {

		$this->domain = 'woo-fulfillment';
		$this->current_tab = ( isset( $_GET['tab'] ) ) ? $_GET['tab'] : 'general';

		load_plugin_textdomain( $this->domain, false, basename( dirname( __FILE__ ) ) . '/languages' );

		add_action( 'woocommerce_settings_tabs', array( &$this, 'add_settings_tab' ), 10 );
		add_action( 'woocommerce_settings_tabs_woo_sf', array( &$this, 'settings_tab_action' ), 10 );
		add_action( 'woocommerce_update_options_woo_sf', array( &$this, 'save_settings' ), 10 );
		add_action( 'woocommerce_payment_complete', array(&$this, 'process_order'), 10 );

		add_action( 'init', array(&$this, 'cron_setup_schedule') );
		add_filter( 'cron_schedules', array(&$this, 'cron_add_interval') );
		add_action( 'woo_sf_cron_repeat_event', array(&$this, 'cron_process_all') );
		add_filter( 'woo_sf_filter_country', array(&$this, 'convert_country_codes'));
	}
	
	/**
	 * Output settings tab.
	 *
	 * @return	void
	**/
	public function add_settings_tab() {
		$class = ($this->current_tab=='woo_sf'?'nav-tab-active ':'').'nav-tab';
		printf('<a href="%s" class="%s">Fulfillment</a>', admin_url( 'admin.php?page=woocommerce&tab=woo_sf' ), $class);
	}

	/**
	 * Output settings fields.
	 *
	 * @return	void
	**/
	public function settings_tab_action() {
		global $woocommerce_settings;

		$current_tab = 'woo_sf';

		// Detect shipment tracking/details extensions and load settings
		$this->load_settings();

		// Form fields
		$woocommerce_settings[ $current_tab ] = $this->load_form_fields();
		woocommerce_admin_fields( $woocommerce_settings[ $current_tab ] );
	}

	/**
	 * Load settings data.
	 *
	 * @return	void
	**/
	public function load_settings() {
		global $woocommerce;

		$this->shipment_tracking_plugin = $this->ship_details_plugin = "";
		if ( is_plugin_active( "wooshippinginfo/wootrackinfo.php" ) )
			$this->ship_details_plugin = sprintf(
				'<p class="update-nag below-h2" style="margin-top: 0px;">%s</p>',
				__( "Plugin <b>Shipping Details for WooCommerce</b> detected. Script will update tracking information", $this->domain )
			);

		if ( is_plugin_active( "woocommerce-shipment-tracking/shipment-tracking.php" ) )
			$this->shipment_tracking_plugin = sprintf(
				'<p class="update-nag below-h2" style="margin-top: 0px;">%s</p>',
				__( "Plugin <b>Shipment Tracking for WooCommerce</b> detected. Script will update tracking information", $this->domain )
			);

		// Fulfillment API URL
		$this->url = home_url( '/' ) . 'woo-fulfillment-api';

		// Order Statuses
		$order_statuses = get_terms( $this->shop_taxonomy, "hide_empty=0" );
		$this->order_statuses = array();
		foreach ( $order_statuses as $status ) {
			$this->order_statuses[$status->term_id] = $status->name;
		}

		// Shipping Methods
		$this->shipping_methods = $woocommerce->shipping->load_shipping_methods();
	}

	/**
	 * Return the array of registered settings fields.
	 *
	 * @return	array
	**/
	private function load_form_fields() {
		$form_fields = array(
			array(	
				'name' => __( 'Fulfillment plugin for Woocommerce', $this->domain ),
				'type' => 'title',
				'desc' => '',
				'id' => 'about' 
			),
			array( 
				'type' => 'sectionend', 
				'id' => 'about' 
			),
			array(	
				'name' => __( 'Import', $this->domain ),
				'type' => 'title',
				'desc' => $this->ship_details_plugin . $this->shipment_tracking_plugin,
				'id' => 'import' 
			),
			array(
				'name' => __( 'Order Status After Fulfilled', $this->domain ),
				'desc' => '',
				'tip' => '',
				'id' => 'woo_sf_import_status',
				'css' => '',
				'std' => '',
				'type' => 'select',
				'options' => $this->order_statuses
			),
			array( 'type' => 'sectionend', 'id' => 'import' ),
			array(	
				'name' => __( 'Settings', $this->domain ),
				'type' => 'title',
				'desc' => '',
				'id' => 'settings' 
			),
			array(
				'name' => __( 'API Domain', $this->domain ),
				'desc' => __( '', $this->domain ),
				'tip' => '',
				'id' => 'woo_sf_api_domain',
				'css' => '',
				'class' => 'input-text regular-input',
				'std' => '',
				'type' => 'text',
			),
			array(
				'name' => __( 'API Service Route', $this->domain ),
				'desc' => __( '', $this->domain ),
				'tip' => '',
				'id' => 'woo_sf_api_url',
				'css' => '',
				'class' => 'input-text regular-input',
				'std' => '',
				'type' => 'text',
			),
			array(
				'name' => __( 'Username', $this->domain ),
				'desc' => __( '', $this->domain ),
				'tip' => '',
				'id' => 'woo_sf_username',
				'css' => '',
				'std' => '',
				'type' => 'text',
			),
			array(
				'name' => __( 'Password', $this->domain ),
				'desc' => __( '', $this->domain ),
				'tip' => '',
				'id' => 'woo_sf_password',
				'css' => '',
				'std' => '',
				'type' => 'text',
			),
			array( 
				'type' => 'sectionend', 
				'id' => 'settings' 
			),
		);
		
		$endpoint = array( 'SubmitOrder', 'GetOrderHistory');
		foreach ( $endpoint as $idx => $end )
			$endpoint[$idx] = sprintf(
				'<p class="update-nag" style="margin-top: 0;">%s</p>', 
				str_replace($end, sprintf('<b>%s</b>', $end), $this->api_url($end)) 
			);
			
		$form_fields = array_merge( $form_fields, array(
			array(	
				'name' => __( 'Registered Endpoints', $this->domain ),
				'type' => 'title',
				'desc' => implode('<br/>', $endpoint),
				'id' => 'endpoint' 
			),
			array( 
				'type' => 'sectionend', 
				'id' => 'endpoint' 
			),
		));
		
		return $form_fields;
	}

	/**
	 * Save settings routine.
	 *
	 * @return	bool
	**/
	public function save_settings() {
		global $woocommerce_settings;
		$current_tab = 'woo_sf';

		$this->load_settings();

		$woocommerce_settings[ $current_tab ] = $this->load_form_fields();
		woocommerce_update_options( $woocommerce_settings[ $current_tab ] );

		return true;
	}

	/**
	 * Process an order for fulfillment.
	 *
	 * @param	(int) $order_id
	 * @return	void
	**/
	public function process_order($order_id) {
		$order = new WC_Order($order_id);
		$order_details = array();
		
		$shipping_name = '';
		if ( !empty($order->shipping_first_name)) $shipping_name .= $order->shipping_first_name;
		else $shipping_name .= $order->billing_first_name;
		
		if ( !empty($order->shipping_last_name)) $shipping_name .= " ".$order->shipping_last_name;
		else $shipping_name .= " ".$order->billing_last_name;
		
		$order_items = $order->get_items();
		
		foreach( $order_items as $item_id => $item ) {
			/**
			 * NOTE: CA Short ERP does not support flat discount per item;
			 * DiscountAmount only applied to Order->DiscountAmount
			**/
			$product = $order->get_product_from_item($item);
			$order_details[] = array(
				"ItemNumber"     => $product->get_sku(),
				"Quantity"       => $item['qty'],
				"UnitPrice"      => $product->get_price(),
				"Freight"        => 0,
			);
		}

		$order_array = array(
			"Order"    => array(
				"OrderDate"            => $order->order_date,
				"Freight"              => $order->order_shipping,
				"SalesTax"             => $order->order_tax,
				"DiscountType"         => 1,
				"DiscountAmount"       => $order->order_discount,
				"ReferenceOrderNumber" => $order_id,
				/*
				"MiscCharges"          => '',
				"PurchaseOrderNumber"  => '',
				*/
				"Address"              => array(
					"Description"  => $shipping_name,
					"AttnContact"  => $shipping_name,
					"Line1"        => $order->shipping_address_1,
					"Line2"        => $order->shipping_address_2,
					"City"         => $order->shipping_city,
					"State"        => $order->shipping_state,
					"Zip"          => $order->shipping_postcode,
					"Country"      => apply_filters('woo_sf_filter_country', $order->shipping_country ),
					"EmailAddress" => $order->billing_email,
				),
				"Phone"        => array( "Phone1" => $order->billing_phone ),
				"OrderDetails" => $order_details,
			)
		);

		$fulfilled = $this->api('submit', $order_array);

		// add fulfillment order number and mark as completed
		if ( $fulfilled && @$fulfilled->OrderSubmitResult === 0 && @$fulfilled->OrderNumber > 0 ) {
			update_post_meta($order_id, 'woo_sf_order_id', $fulfilled->OrderNumber);
			$order->update_status($this->complete_status->slug);
		}
	}
	
	/**
	 * Update completed orders with fulfillment status.
	 *
	 * @param	(int) $order_id
	 * @return	array (of integers)
	**/
	public function update_orders() {
		$open_orders = array();
		$request = array(
			"startDate" => '2013-1-1', // TODO: get date of oldest order without updates
			"endDate"   => date('Y-m-d'),
		);
		$orderHistoryResponse = $this->api('update', $request);
		$orders = $orderHistoryResponse->Orders;
		foreach ($orders as $order) {
			if ( !empty($order->ThirdPartyOrderNumber)) {
				$order_id = $order->ThirdPartyOrderNumber;
				$open_orders[$order_id] = $order->OrderNumber;
				$shipments = $order->Shipments;
				// TODO: Add order note and update shipment info
			}
		}
		return $open_orders;
	}

	/**
	 * Prepare and execute CURL for the API call.
	 *
	 * @param	(array) $overrides
	 * @return	object | bool | void
	**/
	private function api_init($overrides = array()) {
		$ch = curl_init();
		$options = $overrides + array(
			CURLOPT_AUTOREFERER    => 1,
			CURLOPT_TIMEOUT        => 3,
			CURLOPT_CONNECTTIMEOUT => 60,
			CURLOPT_HEADER         => 0,
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_FOLLOWLOCATION => TRUE
		);
		curl_setopt_array($ch, $options);
		$data = curl_exec($ch);
		curl_close($ch);
		$result = @json_decode($data);

		if ($result) {
			return $result;
		} else {
			if (WP_DEBUG === TRUE) {
				wp_die( $data, basename($options[CURLOPT_URL]) );
			} else {
				return false;
			}
		}
	}
	
	/**
	 * Obtain the fully-qualified API URL from settings.
	 *
	 * @param	(string) $endpoint
	 * @return	void
	**/
	private function api_url($endpoint) {
		$url = array(
			esc_url( get_option('woo_sf_api_domain') ),
			get_option('woo_sf_api_url'),
			$endpoint
		);
		return implode('/', array_map(array(&$this, 'xtrim'), $url));
	}
	
	/**
	 * Perform an API call for fulfillment.
	 *
	 * @param	(string) $type
	 * @param	(array) $data
	 * @return	void
	**/
	private function api($type, $data){
		switch ($type){
			case 'submit': $end = "SubmitOrder"; break;
			case 'update': $end = "GetOrderHistory"; break;
			default: return false;
		}
		$data = array(
			"Username" => get_option('woo_sf_username'),
			"Password" => get_option('woo_sf_password'),
		) + $data;
		$args = array( CURLOPT_URL => $this->api_url($end) );
		$query_args = http_build_query($data);

		// GET or POST request
		if ($type == 'update') {
			$args[CURLOPT_URL] .= '?'.$query_args;
		} else {
			$args = array(
				CURLOPT_POST       => count($data),
				CURLOPT_POSTFIELDS => $query_args,
			) + $args;
		}
		return $this->api_init($args);
	}

	/**
	 * Helper function to trim forward slash from url parts.
	 *
	 * @param	(string) $in
	 * @return	string
	**/
	public function xtrim ($in){
		return trim($in, "/");
	}
	
	/**
	 * Filter to convert country codes from 2 to 3-digit format.
	 * NOTE: CA Short ERP only supports 3-digit country codes.
	 * TODO: Modify filter to support countries beyond US.
	 *
	 * @param	(string) $code
	 * @return	string
	**/
	public function convert_country_codes($code) {
		switch($code){
			case "US": return "USA"; break;
		}
		return $code;
	}

	/**
	 * Add new interval to array of existing cron intervals.
	 *
	 * @param	(array) $schedules
	 * @return	array
	**/
	public function cron_add_interval( $schedules ) {
		$schedules['hourly'] = array(
			'interval' => 3600, 
			'display' => __( 'Once Hourly' )
		);
		return $schedules;
	}

	/**
	 * Schedule virtual cron job based on interval.
	 *
	 * @return	void
	**/
	public function cron_setup_schedule() {
		$this->complete_status = get_term_by('id', get_option('woo_sf_import_status'), $this->shop_taxonomy);
		if ( ! wp_next_scheduled( 'woo_sf_cron_repeat_event' ) ) {
			$start = strtotime("Today 12 PM");
			wp_schedule_event( $start, 'hourly', 'woo_sf_cron_repeat_event'); 
		} elseif ( !empty($_GET['clear_cron'])) {
			wp_clear_scheduled_hook( $_GET['clear_cron'] );
		}
	}

	/**
	 * Process and update fulfillment status on all open orders.
	 *
	 * @return	void
	**/
	public function cron_process_all(){
		$processing = get_term_by('slug', 'processing', $this->shop_taxonomy);
		$orders = new WP_Query(array(
			'post_type'      => 'shop_order',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'tax_query'      => array(
				array(
					'taxonomy' => $this->shop_taxonomy,
					'terms'    => $processing->term_id,
					'operator' => 'IN'
				)
			)
		));
		$open_orders = $this->update_orders();
		foreach($orders->posts as $order) {
			// already in fulfillment, but never got updated
			if ( in_array($order->ID, array_keys($open_orders)) ) {
				update_post_meta($order->ID, 'woo_sf_order_id', $open_orders[$order->ID]);
				$order = new WC_Order($order->ID);
				$order->update_status($this->complete_status->slug);
				continue;
			}
			if ( $order->ID < 1433 || $order->ID == 1438) continue; // TODO: remove after testing
			$this->process_order($order->ID);
		}
	}

}

$GLOBALS['WC_Fulfillment'] = new WC_Fulfillment();
