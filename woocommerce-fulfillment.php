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

class WC_Fulfillment {
	public $domain;

	public function __construct() {

		$this->domain = 'woo-fulfillment';
		$this->current_tab = ( isset( $_GET['tab'] ) ) ? $_GET['tab'] : 'general';

		load_plugin_textdomain( $this->domain, false, basename( dirname( __FILE__ ) ) . '/languages' );

		add_action( 'woocommerce_settings_tabs', array( &$this, 'tab' ), 10 );
		add_action( 'woocommerce_settings_tabs_woo_sf', array( &$this, 'settings_tab_action' ), 10 );
		add_action( 'woocommerce_update_options_woo_sf', array( &$this, 'save_settings' ), 10 );
		add_action( 'woocommerce_payment_complete', array(&$this, 'process_order'), 10 );

		add_filter( 'cron_schedules', array(&$this, 'cron_add_interval') );
		add_action( 'init', array(&$this, 'cron_setup_schedule') );
		add_action( 'cron_repeat_event', array(&$this, 'cron_process_all') );

		add_filter( 'woo_sf_filter_country', array(&$this, 'convert_country_codes'));
	}
	
	function cron_add_interval( $schedules ) {
		$schedules['hourly'] = array(
			'interval' => 3600, 
			'display' => __( 'Once Hourly' )
		);
		return $schedules;
	}

	function cron_setup_schedule() {
		if ( ! wp_next_scheduled( 'cron_repeat_event' ) ) {
			$start = strtotime("Today 7 PM");
			wp_schedule_event( $start, 'hourly', 'cron_repeat_event'); 
		}
	}

	function cron_process_all(){
		$orders = new WP_Query(array(
			'post_type'      => 'shop_order',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'tax_query'      => array(
				array(
					'taxonomy' => 'shop_order_status',
					'field' => 'slug',
					'terms' => array('processing', 'completed')
				)
			)
		));
		foreach($orders as $order) {
			$wc_order = new WC_Order($order->ID);
			switch($wc_order->status) {
				case 'processing': $this->process_order($order->ID); break;
				case 'completed' : $this->update_order($order->ID); break;
			}
		}
	}

	function xtrim ($in){
		return trim($in, "/");
	}
	
	function api_url($endpoint) {
		$url = array(
			esc_url( get_option('woo_sf_api_domain') ),
			get_option('woo_sf_api_url'),
			$endpoint
		);
		return implode('/', array_map(array(&$this, 'xtrim'), $url));
	}
	
	function api_init($overrides = array()) {
		$ch = curl_init();
		$options = $overrides + array(
			CURLOPT_AUTOREFERER    => 1,
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
				wp_die( $data );
			} else {
				return false;
			}
		}
	}
	
	function api($type = "update", $data){
		switch ($type){
			case 'submit': $end = "SubmitOrder"; break;
			case 'update': $end = "GetOrderHistory"; break;
		}
		return $this->api_init(array(
			CURLOPT_URL        => $this->api_url($end),
			CURLOPT_POST       => count($data),
			CURLOPT_POSTFIELDS => http_build_query($data),
		));
	}

	/**
	 * Note: CA Short ERP only supports 3-digit country codes.
	 * TODO: Modify filter to support countries beyond US.
	**/
	function convert_country_codes($code) {
		switch($code){
			case "US": return "USA"; break;
		}
		return $code;
	}

	function process_order($order_id) {
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
			"Username" => get_option('woo_sf_username'),
			"Password" => get_option('woo_sf_password'),
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

		if ( $fulfilled && $fulfilled->OrderSubmitResult === 0 && is_numeric($fulfilled->OrderNumber) ) {
			update_post_meta($order_id, 'woo_sf_order_id', $fulfilled->OrderNumber);
			$order->update_status('completed');
		}
	}

	function tab() {
		$class = 'nav-tab';
		if ( $this->current_tab == 'woo_sf' ) $class .= ' nav-tab-active';
		echo '<a href="' . admin_url( 'admin.php?page=woocommerce&tab=woo_sf' ) . '" class="' . $class . '">Fulfillment</a>';
	}

	function shipname( $ship_method ) {
		return str_replace( "_", " ", str_replace( "WC_", "", $ship_method ) );
	}

	function init_form_fields()	{

		$this->form_fields = array(
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
			$endpoint[$idx] = sprintf('<p class="update-nag" style="margin-top: 0;">%s</p>', str_replace($end, sprintf('<b>%s</b>', $end), $this->api_url($end)) );
			
		$this->form_fields = array_merge( $this->form_fields, array(
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
	}

	function load_settings() {
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
		$order_statuses = get_terms( "shop_order_status", "hide_empty=0" );
		$this->order_statuses = array();
		foreach ( $order_statuses as $status ) {
			$this->order_statuses[] = $status->name ;
		}

		// Shipping Methods
		$this->shipping_methods = $woocommerce->shipping->load_shipping_methods();

	}

	function settings_tab_action() {
		global $woocommerce_settings;
		$current_tab = 'woo_sf';

		$this->load_settings();
		$this->init_form_fields();
		$woocommerce_settings[ $current_tab ] = $this->form_fields;
		woocommerce_admin_fields( $woocommerce_settings[ $current_tab ] );
	}

	function save_settings() {
		global $woocommerce_settings;
		$current_tab = 'woo_sf';

		$this->load_settings();

		$this->init_form_fields();
		$woocommerce_settings[ $current_tab ] = $this->form_fields;
		woocommerce_update_options( $woocommerce_settings[ $current_tab ] );

		return true;
	}
}

$GLOBALS['WC_Fulfillment'] = new WC_Fulfillment();
