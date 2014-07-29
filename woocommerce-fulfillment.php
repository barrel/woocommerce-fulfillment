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
add_action( "parse_request", "woo_ss_parse_request" );
function woo_ss_parse_request( &$wp ) {
	if ( $wp->request == "woo-fulfillment-api" ) {
		include_once dirname( __FILE__ ) . "/handler.php";
		$hander = new WC_Fulfillment_Handler();
		die();
	}
}


// July 2013: add custom fields
register_activation_hook( __FILE__, 'woo_ss_plugin_activate' );
function woo_ss_plugin_activate() {
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
		add_action( 'woocommerce_settings_tabs_woo_ss', array( &$this, 'settings_tab_action' ) , 10 );
		add_action( 'woocommerce_update_options_woo_ss', array( &$this, 'save_settings' ) , 10 );
		add_action( 'woocommerce_checkout_order_processed', array(&$this, 'process_new_order'), 10, 2);
	}
	
	function submit_order($data){
		$api_uri = "http://www.werecognizeyou.com/WebServices/Fulfillment/api/FulfillmentOrder/SubmitOrder";

		$ch = curl_init();

		curl_setopt($ch, CURLOPT_AUTOREFERER, TRUE);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_URL, $api_uri);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
		curl_setopt($ch, CURLOPT_POST, count($data));
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

		$json = curl_exec($ch);
		curl_close($ch);
		$result = @json_decode($json);

		printf("<pre>%s</pre>", print_r(array(http_build_query($data), $result), true)); exit;
		if ($result) return $result;
		else return $json;
	}

	function process_new_order($order_id, $posted) {
		$order = new WC_Order($order_id);
		$order_array = array(
			"Username" => 'woo',
			"Password" => 'cashort1',
			"Order"    => array(
				"OrderDate"    => $order->order_date,
/*
				"Freight"      => '',
				"MiscCharges"  => '',
*/
				"SalesTax"     => $order->order_tax,
/*
				"DiscountType" => array(
					
				),
				"DiscountAmount"        => '',
				"PurchaseOrderNumber"   => '',
				"ReferenceOrderNumber"  => '',
*/
				"Address"               => array(
					"Description"  => 'Shipping Address',
					"AttnContact"  => "{$order->billing_first_name} {$order->billing_last_name}",
					"Line1"        => $order->shipping_address_1,
					"Line2"        => $order->shipping_address_2,
					"City"         => $order->shipping_city,
					"State"        => $order->shipping_state,
					"County"       => $order->shipping_state,
					"Zip"          => $order->shipping_postcode,
					"Country"      => $order->shipping_country,
					"EmailAddress" => $order->billing_email,
				),
				"Phone" => array(
					"Phone1" => $order->billing_phone,
				),
				"OrderDetails" => array(
					array(
						"ItemNumber"     => 1,
						"Quantity"       => 1,
						"UnitPrice"      => 1,
						//"DiscountAmount" => '',
						"Freight"        => 1,
					)
				),
			)
		);
		printf("<pre>%s</pre>", print_r($posted, true));
		$this->submit_order($order_array);
		
		/*
    [id] => 1411
    [status] => pending
    [order_date] => 2014-07-29 17:49:46
    [modified_date] => 2014-07-29 17:49:46
    [customer_note] => 
    [order_key] => 
    [billing_first_name] => Wes
    [billing_last_name] => Moore
    [billing_company] => 
    [billing_address_1] => 547 W 142nd St
    [billing_address_2] => 7S
    [billing_city] => New York
    [billing_postcode] => 10013
    [billing_country] => US
    [billing_state] => AZ
    [billing_email] => wes.turner@barrelny.com
    [billing_phone] => 5026082144
    [shipping_first_name] => Wes
    [shipping_last_name] => Moore
    [shipping_company] => 
    [shipping_address_1] => 547 W 142nd St
    [shipping_address_2] => 7S
    [shipping_city] => New York
    [shipping_postcode] => 10013
    [shipping_country] => US
    [shipping_state] => AZ
    [shipping_method] => 
    [shipping_method_title] => 
    [payment_method] => 
    [payment_method_title] => 
    [order_discount] => 
    [cart_discount] => 
    [order_tax] => 
    [order_shipping] => 
    [order_shipping_tax] => 
    [order_total] => 
    [taxes] => 
    [customer_user] => 
    [user_id] => 0
    [completed_date] => 2014-07-29 17:49:46
    [billing_address] => 
    [formatted_billing_address] => 
    [shipping_address] => 
    [formatted_shipping_address] => 
    [post_status] => publish
    [prices_include_tax] => 
    [tax_display_cart] => excl
    [display_totals_ex_tax] => 1
    [display_cart_ex_tax] => 1
		*/
	}

	function tab() {
		$class = 'nav-tab';
		if ( $this->current_tab == 'woo_ss' ) $class .= ' nav-tab-active';
		echo '<a href="' . admin_url( 'admin.php?page=woocommerce&tab=woo_ss' ) . '" class="' . $class . '">Fulfillment</a>';
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
				'name' => __( 'Settings', $this->domain ),
				'type' => 'title',
				'desc' => '',
				'id' => 'settings' 
			),
			array(
				'name' => __( 'Username', $this->domain ),
				'desc' => __( '', $this->domain ),
				'tip' => '',
				'id' => 'woo_ss_username',
				'css' => '',
				'std' => '',
				'type' => 'text',
			),
			array(
				'name' => __( 'Password', $this->domain ),
				'desc' => __( '', $this->domain ),
				'tip' => '',
				'id' => 'woo_ss_password',
				'css' => '',
				'std' => '',
				'type' => 'text',
			),
			array(
				'name' => __( 'Url to custom page', $this->domain ),
				'desc' => __( 'Set Permalinks in Settings>Permalinks. Do NOT use Default', $this->domain ),
				'tip' => '',
				'id' => 'woo_ss_api_url',
				'css' => 'min-width:300px;color:grey',
				'std' => $this->url,
				'default' => $this->url, //for Woocommerce 2.0
				'type' => 'text',
			),
			array(
				'name' => __( 'Log requests', $this->domain ),
				'desc' => "<a target='_blank' href='{$this->url_log}'>" . __( 'View Log', $this->domain ) . "</a>",
				'tip' => '',
				'id' => 'woo_ss_log_requests',
				'css' => '',
				'std' => '',
				'type' => 'checkbox',
			),
			array( 
				'type' => 'sectionend', 
				'id' => 'settings' 
			),

			array(	
				'name' => __( 'Alternate Authentication', $this->domain ),
				'type' => 'title',
				'desc' => '<font color="red">' . __( 'For use on webservers which run PHP in CGI mode. Add "?auth_key=value" to test url', $this->domain ) . '</font>',
				'id' => 'testing' 
			),
			array(
				'name' => __( 'Authentication Key', $this->domain ),
				'desc' => __( 'Enter long, random string here.', $this->domain ),
				'tip' => '',
				'id' => 'woo_ss_auth_key',
				'css' => 'min-width:300px;color:grey',
				'std' => '',
				'type' => 'text',
			),
			array( 
				'type' => 'sectionend', 
				'id' => 'altAuth' 
			),
			array(	
				'name' => __( 'Export', $this->domain ),
				'type' => 'title',
				'desc' => '',
				'id' => 'export' 
			),
		);

		// may 2013
		$this->form_fields[] =
			array(
			'name' => __( 'Number of Records to Export Per Page', $this->domain ),
			'desc' => '',
			'tip' => '',
			'id' => 'woo_ss_export_pagesize',
			'css' => '',
			'std' => '100',
			'default' => '100',
			'type' => 'select',
			'options' => array ( 50 => 50, 75 => 75, 100 => 100, 150 => 150 )
		);
		

		//add checkboxes for export status
		$count_status = count( $this->order_statuses );
		for ( $i = 0; $i < $count_status; $i++ ) {
			$status = $this->order_statuses[ $i ];
			$mode = "";
			if ( $i == 0 )
				$mode = "start";
			if ( $i == $count_status - 1 )
				$mode = "end";

			$this->form_fields[] =
				array(
					'name' => __( 'Order Status to look for when importing into Fulfillment', $this->domain ),
					'desc' => ucwords( $status ),
					'tip' => '',
					'id' => 'woo_ss_export_status_' . md5( $status ),
					'css' => '',
					'std' => '',
					'checkboxgroup' => $mode,
					'type' => 'checkbox',
				);
		}

		// for export shipment
		$count_status = count( $this->shipping_methods );
		reset( $this->shipping_methods );
		$method = current( $this->shipping_methods );
		for ( $i = 0; $i < $count_status; $i++ ) {
			$mode = "";
			if ( $i == 0 )
				$mode = "start";
			if ( $i == $count_status - 1 )
				$mode = "end";

			$this->form_fields[] =
				array(
					'name' => __( 'Shipping Methods to expose to Fulfillment', $this->domain ),
					'desc' => $method->title,
					'tip' => '',
					'id' => 'woo_ss_export_shipping_' . $method->id,
					'css' => '',
					'std' => '',
					'checkboxgroup' => $mode,
					'type' => 'checkbox',
				);
			$method = next( $this->shipping_methods );
		}

		// July 2013 : export Coupon Code
		$this->form_fields[] =
			array(
			'name' => __( 'Export Order Coupon Code(s) to', $this->domain ),
			'desc' => '',
			'tip' => '',
			'id' => 'woo_ss_export_coupon_position',
			'css' => '',
			'std' => 'CustomField1',
			'default' => 'CustomField1',
			'type' => 'select',
			'options' => array ( "None" => "None", "CustomField1" => "CustomField1", "CustomField2" => "CustomField2", "CustomField3" => "CustomField3" )
		);

		// 
		$this->form_fields[] =
			array(
				'name' => __( 'System Notes', $this->domain ),
				'desc_tip' => __( 'System Notes will be excluded from field InternalNotes (case insensitive)', $this->domain ),
				'tip' => '',
				'id' => 'woo_ss_export_system_notes',
				'css' => '',
				'std' => '',
				'type' => 'textarea',
				'args' => 'cols=60 rows=5', 
				'custom_attributes'=> array( 'cols' => 60, 'rows' => 5 ), //for Woocommerce 2.0
			);


		$this->form_fields = array_merge( $this->form_fields, array(
			array( 'type' => 'sectionend', 'id' => 'export' ),
			array(	'name' => __( 'Import', $this->domain ),
				'type' => 'title',
				'desc' => $this->ship_details_plugin . $this->shipment_tracking_plugin,
				'id' => 'import' ),
				array(
					'name' => __( 'Order Status to move it to when the shipnotify action is presented', $this->domain ),
					'desc' => '',
					'tip' => '',
					'id' => 'woo_ss_import_status',
					'css' => '',
					'std' => '',
					'type' => 'select',
					'options' => $this->order_statuses
				),
			array( 'type' => 'sectionend', 'id' => 'import' ),
		) );
	}

	function load_settings() {
		global $woocommerce;

		$this->shipment_tracking_plugin = $this->ship_details_plugin = "";
		if ( is_plugin_active( "wooshippinginfo/wootrackinfo.php" ) )
			$this->ship_details_plugin = '<h3><font color="blue">' . __( "Plugin 'Shipping Details for WooCommerce' detected. Script will update tracking information", $this->domain ) . '</font></h3>';
		if ( is_plugin_active( "woocommerce-shipment-tracking/shipment-tracking.php" ) )
			$this->shipment_tracking_plugin = '<h3><font color="blue">' . __( "Plugin 'Shipment Tracking for WooCommerce' detected. Script will update tracking information", $this->domain ) . '</font></h3>';

		// July 2013 url fixed
		$this->url = home_url( '/' ) . 'woo-fulfillment-api';

		$folder = basename( dirname( __FILE__ ) );
		$this->url_log = site_url() . "/wp-content/plugins/$folder/handler.txt";

		$order_statuses = get_terms( "shop_order_status", "hide_empty=0" );
		$this->order_statuses = array();
		foreach ( $order_statuses as $status ) {
			$this->order_statuses[] = $status->name ;
		}

		$this->shipping_methods = $woocommerce->shipping->load_shipping_methods();

		if( !get_option( 'woo_ss_export_system_notes' ) ) {
			$default_notes = file_get_contents( dirname( __FILE__ ) . '/data/system-notes.txt' );
			update_option( 'woo_ss_export_system_notes' , $default_notes );
		}
	}

	function settings_tab_action() {
		global $woocommerce_settings;
		$current_tab = 'woo_ss';

		$this->load_settings();

		// Display settings for this tab ( make sure to add the settings to the tab ).
		$this->init_form_fields();
		$woocommerce_settings[ $current_tab ] = $this->form_fields;
		woocommerce_admin_fields( $woocommerce_settings[ $current_tab ] );
	}



	function save_settings() {
		global $woocommerce_settings;
		$current_tab = 'woo_ss';

		$this->load_settings();

		$this->init_form_fields();
		$woocommerce_settings[ $current_tab ] = $this->form_fields;
		woocommerce_update_options( $woocommerce_settings[ $current_tab ] );

		delete_option( 'woo_ss_api_url' ); // don't update api url
		return true;
	}
}

$GLOBALS['WC_Fulfillment'] = new WC_Fulfillment();
