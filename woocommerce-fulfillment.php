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

/**
 * Plugin updates
 */
//woothemes_queue_update( plugin_basename( __FILE__ ), '9de8640767ba64237808ed7f245a49bb', '18734' );

register_activation_hook( __FILE__, 'woo_sf_plugin_activate' );
function woo_sf_plugin_activate() {}

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

		add_action( 'admin_init', array(&$this, 'cron_setup_schedule') );
		add_filter( 'cron_schedules', array(&$this, 'cron_add_interval') );
		add_action( 'woo_sf_cron_repeat_event', array(&$this, 'cron_process_all') );
		add_filter( 'woo_sf_filter_country', array(&$this, 'convert_country_codes'));
		
		if ( is_admin() && current_user_can('administrator') && !empty($_GET['cron']))
			$this->cron_process_all();
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

		$this->shipment_tracking_plugin = "";
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
				'desc' => $this->shipment_tracking_plugin,
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
		foreach ( $endpoint as $idx => $end ) {
			$endpoint[$idx] = sprintf(
				'<p class="update-nag" style="margin-top: 0;">%s</p>', 
				str_replace($end, sprintf('<b>%s</b>', $end), $this->api_url($end)) 
			);
		}
		
		$inline_message = '<div class="%s update-nag"><p><b>%s</b></p></div>';
		$r = $this->api_ready();
		$class = $r ? 'updated':'error';
		$stati = $r ? 'API Configured' : 'Check Configuration';
		$api_config = sprintf($inline_message, $class, $stati);
		
		$r = !$r ? false : $this->api('update', array(
			"startDate" => date('Y-m-d', strtotime('yesterday')),
			"endDate" => date('Y-m-d'),
		));
		
		$r = @$r->ServiceResult === 0;
		$class = $r ? 'updated':'error';
		$stati = $r ? 'API Ready' : 'Service Error';
		$api_status = sprintf($inline_message, $class, $stati);

		$form_fields = array_merge( $form_fields, array(
			array(
				'name' => __( 'API Status', $this->domain ),
				'type' => 'title',
				'desc' => $api_config.$api_status,
				'id' => 'endpoint' 
			),
			array( 
				'type' => 'sectionend', 
				'id' => 'endpoint' 
			),
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
		if ( !$this->api_ready() ) return;
		$order = new WC_Order($order_id);
		$order_details = array();
		$order_results_codes = array(0,3);
		
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
		// can't submit anything without details
		if (empty($order_details)) return;

		$order_array = array(
			"Order"    => array(
				"OrderDate"            => $order->order_date,
				"ReferenceOrderNumber" => $order_id,
				/*
				"MiscCharges"          => '',
				"PurchaseOrderNumber"  => '',
				*/
				"Address"              => array(
					"Description"  => $shipping_name,
					"AttnContact"  => $shipping_name,
					"Line1"        => $order->shipping_address_1,
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
		
		if (!empty($order->order_shipping)) $order_array["Order"]["Freight"] = $order->order_shipping;
		if (!empty($order->order_tax)) $order_array["Order"]["SalesTax"] = $order->order_tax;
		if (!empty($order->order_discount)) $order_array["Order"]["DiscountType"] = 1;
		if (!empty($order->order_discount)) $order_array["Order"]["DiscountAmount"] = $order->order_discount;
		if (!empty($order->shipping_address_2)) $order_array["Order"]["Address"]["Line2"] = $order->shipping_address_2;

		$fulfilled = $this->api('submit', $order_array);

		// update newly created orders or existing orders that haven't been marked as completed
		if ( $fulfilled && in_array($fulfilled->OrderSubmitResult, $order_results_codes) && $fulfilled->OrderNumber > 0) {
			// add fulfillment order number and mark as completed
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
		/** 
		 * @startDate - TODO: get date of oldest order without updates
		 * @endDate - today is not inclusive and future scope is ok 
		**/
		$request = array(
			"startDate" => '2013-1-1', 
			"endDate"   => date('Y-m-d', strtotime('tomorrow')), 
		);
		$response = $this->api('update', $request);
		if ( $response && !empty($response->Orders)) {
			foreach($response->Orders as $order) {
				// nothing shipped yet
				if ( $order->OrderDetailShippedCount === 0 ) continue;

				// shipped and has woocommerce order number
				if ( !empty($order->ThirdPartyOrderNumber)) {
					$order_id = $order->ThirdPartyOrderNumber;
					$open_orders[$order_id] = $order->OrderNumber;
					foreach($order->Shipments as $shipment) {
						$provider = @$shipment->ServiceProvider;
						$date_shipped = @$shipment->ShipDate;
						$tracking_no = @$shipment->TrackingNumber;
						
						// add extra if Plugin 'Shipment Tracking for WooCommerce' detected
						if ( is_plugin_active( "woocommerce-shipment-tracking/shipment-tracking.php" ) ) {
							update_post_meta( $order_id, '_tracking_provider', $provider );
							update_post_meta( $order_id, '_tracking_number', $tracking_no );
							update_post_meta( $order_id, '_date_shipped', strtotime($date_shipped) );
						}
					}
				}
			}
		}
		return $open_orders;
	}
	
	/**
	 * Check if required configuration is set for API to work.
	 *
	 * @return	bool
	**/
	private function api_ready() {
		$option_keys = array( 'woo_sf_api_domain', 'woo_sf_api_url', 'woo_sf_username', 'woo_sf_password');
		foreach( $option_keys as $option_key )
			if ( !get_option($option_key) )
				return false;
		return true;
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

		if ($result && @$result->ServiceResult === 0) {
			return $result;
		} else {
			if (WP_DEBUG === TRUE) {
				// Service Error
				switch(@$result->ServiceResult){
					case 1: $title="General_Failure";break;
					case 2: $title="Invalid_Token";break;
					case 3: $title="Invalid_API_Key";break;
					case 4: $title="Validation_Error";break;
					default: $title="Unknown";
				}
				
				// Get submitted data
				$url_parts = explode('?', $options[CURLOPT_URL]);
				$detail = (isset($options[CURLOPT_POSTFIELDS])
					? $options[CURLOPT_POSTFIELDS] : array_pop( $url_parts )
				);
				$details = explode('&', urldecode($detail));
				$submitted = is_array($details) ? implode('<br/>', $details): false;
				
				// show response as json or raw data
				if (!empty($result->Message)) $data = sprintf('<div class="error"><p>%s</p></div>', $result->Message);
				elseif ($result) $data = sprintf('<pre class="update-nag">%s</pre>', print_r($result, true));
				
				// append submitted data to message
				if ($submitted) $data .= sprintf('<p class="update-nag">%s</p>', $submitted);
				$title .= ": ".basename($options[CURLOPT_URL]);
				wp_die( $data, $title );
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
	private function api($type, $data = array()){
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
		if ($type !== 'submit') {
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
		$term_id = get_option('woo_sf_import_status', 'complete');
		$term_by = empty($term_id) ? 'slug' : 'id';
		$this->complete_status = get_term_by($term_by, $term_id, $this->shop_taxonomy);
		if ( !wp_next_scheduled('woo_sf_cron_repeat_event') && $this->api_ready() ) {
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
			$this->process_order($order->ID);
		}
	}

}

$GLOBALS['WC_Fulfillment'] = new WC_Fulfillment();
