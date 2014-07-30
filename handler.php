<?php
include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
//error_reporting( E_ALL );

class WC_ShipStation_Handler {
	function __construct() {
		global $woocommerce;

		$this->domain = 'woo-shipstation';
		
		$login_ok = false;
		if ( !empty ( $_GET['auth_key'] ) )
		{
			if ( get_option( 'woo_sf_auth_key' ) != $_GET['auth_key'] )
				$this->die_log( __( 'Wrong auth_key passed.', $this->domain ) );
			$login_ok = true;
		}
		if ( !$login_ok )
		{
			if( isset( $_SERVER['HTTP_SS_AUTH_USER'] ) ) 
				$_SERVER['PHP_AUTH_USER'] = $_SERVER['HTTP_SS_AUTH_USER'];
			if( isset( $_SERVER['HTTP_SS_AUTH_PW'] ) ) 
				$_SERVER['PHP_AUTH_PW'] = $_SERVER['HTTP_SS_AUTH_PW'];
			//401 check
			if ( !isset( $_SERVER['PHP_AUTH_USER'] ) ) {
				header( "WWW-Authenticate: Basic realm=\"XML Api for Woo\"" );
				header( "HTTP/1.0 401 Unauthorized" );
				_e( 'User/pass are required!', $this->domain );
				exit;
			}
			if ( empty( $_SERVER['PHP_AUTH_USER'] ) || empty( $_SERVER['PHP_AUTH_PW'] ) )
				 $this->die_log( __( 'Basic HTTP Authentication is required. Please, add http://username:password@ to current url.', $this->domain ) );
			//bad user/pass?
			if ( $_SERVER['PHP_AUTH_USER'] != get_option( 'woo_sf_username' ) || $_SERVER['PHP_AUTH_PW'] != get_option( 'woo_sf_password' ) )
				$this->die_log( __( "Basic HTTP Authentication failed. Please, update username/password in 'Custom Store Setup' at ShipStation site", $this->domain ) );
		}

		if ( get_option( 'woo_sf_log_requests' ) )
		{
			$params = $_GET;
			unset( $params['woo-shipstation-api'] );
			$this->write_log( "input parameters->" . http_build_query( $params ), true );
		}

		// have Woo?
		if ( !is_object( $woocommerce ) )
			$this->die_log( __( 'Please, install/activate WooCommerce plugin at first', $this->domain ) );


		//no options?
		if ( !get_option( 'woo_sf_username' ) || !get_option( 'woo_sf_password' ) )
			$this->die_log( __( "Please, setup username & password to access xml", $this->domain ) );

		//action missed or wrong?
		if ( !isset( $_GET['action'] ) )
			$this->die_log( __( "Missing 'action' parameter", $this->domain ) );

		if ( !in_array( $_GET['action'], array( 'export', 'shipnotify' ) ) )
			$this->die_log( __( "Incorrect 'action' parameter", $this->domain ) );

		$woocommerce->mailer(); //init mailer -- required!!

		// need in this array ( as we remember # in settings )
		$order_statuses = get_terms( "shop_order_status", "hide_empty=0" );
		$this->order_statuses = array();
		foreach ( $order_statuses as $status ) {
			$this->order_statuses[] = $status->name;
		}

		if ( $_GET['action'] == 'export' )
			$this->export_orders();

		if ( $_GET['action'] == 'shipnotify' )
			$this->update_order();
	}

	function get_status_by_id( $status_id ) {
		return $this->order_statuses[ $status_id ];
	}

	// should return data for any order that was modified between the start and end date,
	// regardless of the order's status???
	// we must return XML as reply
	function export_orders() {
		global $wpdb, $woocommerce;

		$this->validate_input( array( "start_date", "end_date" ) );

		// format MM/dd/yyyy HH:mm
		$start_time = $this->to_mysql_time( $_GET['start_date'] );
		$end_time = $this->to_mysql_time( $_GET['end_date'] ) ;

		//scan options twice!
		$export_order_statuses = array();
		foreach ( $this->order_statuses as $status ) {
			if ( get_option( 'woo_sf_export_status_' . md5( $status ) ) == "yes" )
				$export_order_statuses[] = $status;
		}

		$shipping_methods = $woocommerce->shipping->load_shipping_methods();
		$export_shipping_methods = array();
		foreach ( $shipping_methods as $method ) {
			if ( get_option( 'woo_sf_export_shipping_' . $method->id ) == "yes" )
				$export_shipping_methods[] = $method->id;
		}

		//fix FedEx
		if ( in_array( 'fedex_wsdl', $export_shipping_methods ) )
			$export_shipping_methods[] = 'FedEx';

		// weight unit is global setting!
		$wu = get_option( 'woocommerce_weight_unit' );
		$wu_in_kg = ( $wu == "kg" );
		if ( $wu == "kg" ) {
			$wu = "Grams";
		} elseif ( $wu == "g" ) {
			$wu = "Grams";
		} elseif ( $wu == "lbs" ) {
			$wu = "Pounds";
		}

		$system_notes = explode( "\n", get_option( 'woo_sf_export_system_notes' ) );
		$system_notes = array_filter ( array_map( "trim", $system_notes ) );

		// we will search for private notes
		remove_filter( 'comments_clauses', 'woocommerce_exclude_order_comments' );

		$xml = new SimpleXMLElement( "<Orders></Orders>" );

		// may 2013
		$page_size = get_option( 'woo_sf_export_pagesize' );
		if( !$page_size )
			$page_size = 100;
		$page_num = isset($_GET['page']) ? intval( $_GET['page'] ) : 0;

		$limit = "";
		if( $page_num > 0 )
		{
			$total_orders = 0;
			$posts = $wpdb->get_results( "SELECT * FROM {$wpdb->posts} WHERE post_type='shop_order' AND post_status='publish' AND '{$start_time}'<=post_modified AND post_modified<='{$end_time}'" );
			foreach ( $posts as $post ) {
				$order = new WC_Order( $post->ID );
				if ( !in_array( $order->status, $export_order_statuses ) )
					continue;
				if ( !$this->can_export_shipping( $export_shipping_methods, $order->shipping_method ) )
					continue;
			
				$total_orders++;
			}
			
			$xml['pages'] = ceil( $total_orders / $page_size ); 
			if( $page_num > $xml['pages'] )
				$page_num = $xml['pages'];

			// apply limits 
			$xml['page'] = $page_num; 
			$limit = " LIMIT " . ( $page_num - 1 ) * $page_size . "," . $page_size; 
		}

		// July 2013 : export Coupon
		$export_coupon_position = get_option( 'woo_sf_export_coupon_position' );
		if( !$export_coupon_position )
			$export_coupon_position = "CustomField1";

		$tz_offset = get_option( 'gmt_offset' ) * 3600; // use the WordPress TimeZone (Settings -> General)
		$exported = 0;
		// may 2013 $limit added
		$posts = $wpdb->get_results( "SELECT * FROM {$wpdb->posts} WHERE post_type='shop_order' AND post_status='publish' AND '{$start_time}'<=post_modified AND post_modified<='{$end_time}' $limit" );
		foreach ( $posts as $post ) {
			$order = new WC_Order( $post->ID );
			if ( !in_array( $order->status, $export_order_statuses ) )
				continue;
			if ( !$this->can_export_shipping( $export_shipping_methods, $order->shipping_method ) )
				continue;

			$exported++;

			$o = $xml->addChild( 'Order' );
			// July 2013 : Trim # instead of first character
			$o->OrderNumber = ltrim( $order->get_order_number(), '#' );
			$o->OrderDate = gmdate( "m/d/Y H:i", strtotime( $order->order_date ) - $tz_offset );
			$o->OrderStatus = $order->status;
			$o->LastModified = gmdate( "m/d/Y H:i", strtotime( $order->modified_date ) - $tz_offset );
			$o->ShippingMethod = $order->shipping_method . "|" . $order->shipping_method_title;


			$total = $order->get_total();// may return empty string
			$o->OrderTotal = $total ? $total : "0.00";

			$o->TaxAmount = $order->get_total_tax();
			$o->ShippingAmount = $order->get_shipping();

			$args = array(
				'post_id' => $order->id,
				'approve' => 'approve',
				'type' => '',
				'orderby' => 'comment_date'
			);
			$comments = get_comments( $args );

			$private_notes = array();
			foreach( $comments as $comment ) {
				//must skip customer notes 
				$is_customer_note = get_comment_meta( $comment->comment_ID, 'is_customer_note', true );
				if( $is_customer_note ) {
					continue;
				}

				//must skip system notes too
				$is_system_note = false;
				foreach( $system_notes as $text ) {
					if( stristr( $comment->comment_content, $text ) !== FALSE )
						$is_system_note = true;
				}
				if( $is_system_note ) 
					continue;

				$private_notes[] = $comment->comment_content;
			}

			$o->CustomerNotes = $order->customer_note;
			$o->InternalNotes = join( "|", array_splice( $private_notes, 0, 3 ) );

			// July 2013 : export custom fields
			for( $pos=1; $pos <= 3; $pos++ ) {
				$custom = "custom_field_shipstation_{$pos}";
				if( !empty( $order->order_custom_fields[$custom] ) ) {
					$o->{"CustomField{$pos}"} = join( "|", $order->order_custom_fields[$custom] );
				}
			}

			// July 2013 : export Coupon
			if( $export_coupon_position != 'None' ) {
				$o->$export_coupon_position = join( "|", $order->get_used_coupons() );
			}

			$c = $o->addChild( "Customer" );
			$c->CustomerCode = $order->billing_email;

			$bill = $c->addChild( "BillTo" );
			$bill->Name = $order->billing_first_name . " " . $order->billing_last_name;
			$bill->Company = $order->billing_company;
			$bill->Phone = $order->billing_phone;
			$bill->Email = $order->billing_email;

			$ship = $c->addChild( "ShipTo" );
			$ship->Name = $order->shipping_first_name . " " . $order->shipping_last_name;
			$ship->Company = $order->shipping_company;
			$ship->Address1 = $order->shipping_address_1;
			$ship->Address2 = $order->shipping_address_2;
			$ship->City = $order->shipping_city;
			$ship->State = $order->shipping_state;
			$ship->PostalCode = $order->shipping_postcode;
			$ship->Country = $order->shipping_country;
			$ship->Phone = $order->billing_phone;//DEBUG!

			$items = $o->addChild( "Items" );
			$rows = $order->get_items();
			foreach ( $rows as $i ) {
				$item = $items->addChild( "Item" );

				$p = $order->get_product_from_item( $i );
				$item->SKU = $p->sku;
				$item->Name = $i['name'];

				// we fills WeightUnits for kg!
				if ( $wu_in_kg )
					$item->Weight = 1000 * $p->weight;
				else
					$item->Weight = $p->weight;
				$item->WeightUnits = $wu;

				$item->Quantity = $i['qty'];
				//was $item->UnitPrice = $p->price;
				if ( $i['qty'] )
					$item->UnitPrice = round( $i['line_total'] / $i['qty'], 2 );
				else
					$item->UnitPrice = "0.00";

				// code derived from class WC_Product
				$item->ImageUrl = ''; //no image
				$image_id = 0;
				if ( has_post_thumbnail( $p->id ) ) {
					$image_id = get_post_thumbnail_id( $p->id );
				} elseif ( ( $parent_id = wp_get_post_parent_id( $p->id ) ) && has_post_thumbnail( $parent_id ) ) {
					$image_id = get_post_thumbnail_id( $parent_id );
				}
				if($image_id)
				{
					$image_details = wp_get_attachment_image_src( $image_id, 'shop_thumbnail' );
					$item->ImageUrl = $image_details[0];
				}

				if ( $i["item_meta"] )
					$opts = $item->addChild( "Options" );

				$ver2 = substr( $woocommerce->version, 0, 1 ) == '2';

				$item_meta = new WC_Order_Item_Meta( $i['item_meta'] );
				//we will parse output of function WC_Order_Item_Meta::display() 
				$meta_lines = explode( ", \n", $item_meta->display( true, true ) );

				foreach ( $meta_lines as $line ) {
					$parts = explode( ': ', $line );
					if( count( $parts ) <2 ) 
						continue;
					$opt = $opts->addChild( "Option" );
					$opt->Name = $parts[0];
					$opt->Value = $parts[1];
				}
			}

		}

		Header( 'Content-type: text/xml' );

		//format it!
		$dom = dom_import_simplexml( $xml )->ownerDocument;
		$dom->formatOutput = true;
		echo $dom->saveXML();

		$this->write_log( sprintf( __( "%s exported", $this->domain ), $exported ), true );
	}

	function can_export_shipping( $methods, $method )
	{
		foreach ( $methods as $m ) {
			if ( strpos( $method, $m) === 0) // shipping method MUST start from this string!
				return true;				// example, find "table_rate" inside "table_rate-2: 4"
		}

		return false;
	}

	//SS passed order_number, carrier( USPS, UPS, FedEx ), service, tracking_number
	function update_order()	{

		$this->validate_input( array( "order_number", "carrier" ) );
		// tracking_number is blank/null/empty? 
		if ( empty ( $_GET['tracking_number'] ) )
			$_GET['tracking_number'] = 'None';

		$orderid_formatted = $_GET['order_number'];
		// use WC functions and filter !
		$order = new WC_Order( @apply_filters( 'woocommerce_shortcode_order_tracking_order_id', $orderid_formatted ) );
		if ( !$order->id )
			$this->die_log( sprintf( __( "%s is not order number", $this->domain ), $orderid_formatted ) );
		// is number!
		$orderid = $order->id;
		
		$import_status = $this->get_status_by_id( get_option( 'woo_sf_import_status' ) );

		$ship_ts = time() + get_option( 'gmt_offset' ) * 3600; //current time

		// shipstation passed XML?
		$post = file_get_contents( 'php://input' );
		//$post = file_get_contents(dirname(__FILE__)."/ss-post.txt");
		if ( $post ) {
			$xml = @simplexml_load_string( $post );
			if ( isset( $xml->ShipDate ) ) 
				$ship_ts = $this->us_date_to_timestamp( $xml->ShipDate );
		}
	
		// Carrier USPS, UPS, FedEx

		// add extra if Plugin 'Shipping Details for WooCommerce' detected
		if ( is_plugin_active( "wooshippinginfo/wootrackinfo.php" ) ) {
			update_post_meta( $orderid, '_order_custtrackurl', '' );
			update_post_meta( $orderid, '_order_custcompname', '' );
			update_post_meta( $orderid, '_order_trackno', $_GET['tracking_number'] );
			update_post_meta( $orderid, '_order_trackurl', $_GET['carrier'] );
		}

		// add extra if Plugin 'Shipment Tracking for WooCommerce' detected
		if ( is_plugin_active( "woocommerce-shipment-tracking/shipment-tracking.php" ) ) {
			update_post_meta( $orderid, '_tracking_provider', strtolower( $_GET['carrier'] ) );
			update_post_meta( $orderid, '_tracking_number', $_GET['tracking_number'] );
			update_post_meta( $orderid, '_date_shipped', $ship_ts );
		}

		//no plugins to store tracking,
		if ( !is_plugin_active( "wooshippinginfo/wootrackinfo.php" ) &&
			!is_plugin_active( "woocommerce-shipment-tracking/shipment-tracking.php" ) ) {
			$ship_date = date( get_option( 'date_format' ), $ship_ts );//default WP format!
			$note = "Order shipped via:{$_GET['carrier']}\r\n";
			$note .= "Ship date:{$ship_date}\r\n";
			$note .= "Tracking no:{$_GET['tracking_number']}";
			$order->add_order_note( $note, $is_customer_note = 1 );
		}
		
		$order->update_status( $import_status );
		
		$this->write_log( sprintf( __("Order %s -> %s", $this->domain ), $orderid_formatted, $import_status) );
	}

	//common functions
	function fix_shipping( $ship_method ) { // WC_Flat_Rate to flat_rate
		return strtolower( str_replace( "WC_", "", $ship_method ) );
	}

	function validate_input( $req_flds ) {
		$missed = array();
		foreach ( $req_flds as $f ) {
			if ( empty( $_GET[ $f ] ) )
				$missed[] = $f;
		}

		if ( $missed )
			$this->die_log( __( "Missed parameters:", $this->domain ) . join( ", ", $missed ) );
	}

	// MM/dd/yyyy HH:mm to yyyy-mm-dd HH:mm
	// from GMT to current timezone
	function to_mysql_time( $us_time ) {
		if ( preg_match( '#(\d+)/(\d+)/(\d+) (\d+):(\d+)#', $us_time, $m ) )
			$ts = gmmktime( $m[4], $m[5], 1, $m[1], $m[2], $m[3] ); // GMT!
		else
			$this->die_log( sprintf( __( "Wrong date format %s ( MM/dd/yyyy HH:mm required )", $this->domain ), $us_time ) );

		$dt = gmdate( "Y-m-d H:i", $ts + get_option( 'gmt_offset' ) * 3600 );
		return $dt;
	}

	// MM/dd/yyyy to 111111111111111
	// from GMT to timestamp
	function us_date_to_timestamp( $us_date ) {
		if ( preg_match( '#(\d+)/(\d+)/(\d+)#', $us_date, $m ) )
			$ts = gmmktime( 0, 0, 1, $m[1], $m[2], $m[3] ); // GMT!
		else
			$this->die_log( sprintf( __( "Wrong date format %s ( MM/dd/yyyy required )", $this->domain ), $us_date ) );

		return $ts;
	}

	function write_log( $msg, $no_echo = false ) {

		$msg = date( "Y-m-d H:i:s" ) . "|$_SERVER[REMOTE_ADDR]|$msg\n";
		error_log( $msg, 3, dirname( __FILE__ ) . "/handler.txt" );

		if ( $no_echo )
			return;

		echo $msg . "<br>";
		flush();
	}

	function die_log( $msg ) {
		// return 5xxx on error in shipnotify
		if ( $_GET['action'] == 'shipnotify' )
			header( $_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500 );

		$this->write_log( $msg );
		die();
	}
}
