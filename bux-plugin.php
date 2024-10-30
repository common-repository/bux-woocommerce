<?php
/**
 * Plugin Name: Bux Woocommerce
 * Plugin URI: https://bux.ph/static/bux-plugin-wp.zip
 * Description: Bux plugin for Woocommerce, you need to have Woocommerce installed
 * Version: 1.2.3
 * Author: UBX Philippines
 * Author URI: https://bux.ph
 */
// Make sure WooCommerce is active

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) return;

add_action( 'rest_api_init', 'bux_register_routes' );
function bux_register_routes() {
    register_rest_route( 'bux-api', 'update_order/(?P<id>\d+)', array(
                    'methods' => 'POST',
                    'callback' => 'bux_update_order',
		            'args' => array(
		                    'id' => array( 
		                        'validate_callback' => function( $param, $request, $key ) {
		                            return is_numeric( $param );
		                        }
		                    ),
		                ),
		            'permission_callback' => function() {
		                return true;
		                }, 
                )
            );

    register_rest_route( 'bux-api', 'update_credentials/', array(
                    'methods' => 'GET',
                    'callback' => 'bux_update_credentials',
		            'args' => array(),
		            'permission_callback' => function() {
		                return true;
		                }, 
                )
            );
}


   function restore_order_stock( $order ) {
	    $items = $order->get_items();

	    if ( ! get_option('woocommerce_manage_stock') == 'yes' && ! count( $items ) > 0 )
	            return; // We exit

	    foreach ( $order->get_items() as $item ) {
	        $product_id = $item->get_product_id();

	        if ( $product_id > 0 ) {
	            $product = $item->get_product();

	            if ( $product && $product->exists() && $product->managing_stock() ) {

	                $initial_stock = $product->get_stock_quantity();

	                $item_qty = apply_filters( 'woocommerce_order_item_quantity', $item->get_quantity(), $order, $item );

	                wc_update_product_stock( $product, $item_qty, 'increase' );

	                $updated_stock = $initial_stock + $item_qty;

	                do_action( 'woocommerce_auto_stock_restored', $product, $item );

	                $order_note[] = sprintf( __( 'Product ID #%s stock incremented from %s to %s.', 'woocommerce' ), $product_id, $initial_stock, $updated_stock);

	            }
	        }
	    }

	    $order_notes = count($order_note) > 1 ? implode(' | ', $order_note) : $order_note[0];
	    $order->add_order_note( $order_notes );
	}

function bux_update_order($request) {

	$order_id = $request->get_param( 'id' );
	$params = $request->get_body_params() ;
	$client_id = $params['client_id'];
	$order_status = $params['status'];
	$signature = $params['signature'];
	$pre_hash = $order_id . $order_status . "{" . get_option('bux_payment_client_secret') . "}";
	    // $order_id => $request['id'];

    if ($params['client_id'] != get_option('bux_payment_client_id')) {
    return new WP_Error( 'failed', 'Invalid Client ID', array('status' => 401) );

    }

    if ($signature != sha1($pre_hash)) {
    return new WP_Error( 'failed', 'Invalid Client ID', array('status' => 401) );

    }
	$order = wc_get_order($order_id);
	$pre_order_status = $order->get_status();

	    if (empty($order)) {
	    return new WP_Error( 'failed', 'Order does not exist', array('status' => 404) );

	    }

    // if (floatval($params['amount']) != floatval($order->get_total())) {
    // return new WP_Error( 'failed', 'Invalid Amount', array('status' => 403) );

    // }

	if($pre_order_status == "wc-pending-bux" || $pre_order_status == "wc-pending" || $pre_order_status == "pending-bux" || $pre_order_status == "pending"){
	    if($order_status == "wc-cancelled"){
	    	#only restore stock if not yet cancelled
			restore_order_stock($order);
			$order->update_status( $order_status, __( 'Payment Expired/Cancelled', 'bux-gateway' ) );
	    }else if($order_status == "wc-processing"){
	    	$order->payment_complete();
	    }else{
			$order->update_status( $order_status, __( 'Status Update', 'bux-gateway' ) );

	    }
	}
    return rest_ensure_response( '{"code": "success"}' );
}



function bux_update_credentials($request) {

	$key_arr = explode(":::", base64_decode($request->get_param( 'key' )));
	$client_id = $key_arr[0];
	$api_key = $key_arr[1];
	$client_secret = base64_decode($request->get_param( 'signature' ));
	update_option('bux_payment_api_key', $api_key);
	update_option('bux_payment_client_id', $client_id);
	update_option('bux_payment_client_secret', $client_secret);
	wp_redirect( '/wp-admin/admin.php?page=bux-payments' );
	exit;
    // return rest_ensure_response( '{"code":'. $access_token .'}' );
}

function bux_add_to_gateways( $gateways ) {
	$gateways[] = 'BUX_Gateway';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'bux_add_to_gateways' );


add_action('admin_menu', 'bux_plugin_admin_add_page'); 
add_action( 'admin_init', 'bux_register_settings' );

function bux_plugin_admin_add_page(){    
	$page_title = 'Bux Woocommerce';   
	$menu_title = 'Bux';   
	$capability = 'manage_options';   
	$menu_slug  = 'bux-payments';   
	$function   = 'bux_plugin_options_page';   
	$icon_url   = plugin_dir_url(__FILE__ ) . 'images/bux-ico-16.svg';   
	$position   = 58;    
	add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $function,$icon_url, $position ); 
}


function bux_register_settings() { // whitelist options
  register_setting( 'bux-options', 'bux_payment_client_id' );
  register_setting( 'bux-options', 'bux_payment_client_secret' );
  register_setting( 'bux-options', 'bux_payment_api_key' );
  $args = array(
            'default' => 24,
            );
  register_setting( 'bux-options', 'bux_payment_default_expiry' , $args);
  $args = array(
            'default' => false,
            );
  register_setting( 'bux-options', 'bux_redirect_disable' , $args);

  $args = array(
            'default' => false,
            );
  register_setting( 'bux-options', 'bux_test_mode' , $args);

}

function bux_plugin_options_page() {
?>

<div>
<form action="options.php" method="post">
<div style="display: flex; padding-top: 10px"><img src="<?php echo plugin_dir_url(  __FILE__ ) . 'images/buxlogo.png' ?>" style="height: 20px; margin-right: 5px; margin-top: 15px"><h2> Bux Settings</h2></div>
<p>Set your credentials and expiry. <i>Click the <b>Link Button</b> below to automatically fetch credentials from your Bux account.</p>
<?php settings_fields('bux-options'); ?>
<?php if ( isset( $_GET['settings-updated'] ) ) {
    echo "<div class='updated' style='width: 500px; margin-top: 20px; margin-bottom: 20px'><p>Bux settings updated successfully.</p></div>";
} ?>
     <table class="form-table">
        <tr valign="top">
        <th scope="row">API Key</th>
        <td><input type="text" name="bux_payment_api_key" style="width: 360px" value="<?php echo esc_attr( get_option('bux_payment_api_key') ); ?>"/></td>
        </tr>

        <tr valign="top">
        <th scope="row">Client ID</th>
        <td><input type="text" name="bux_payment_client_id" style="width: 360px" value="<?php echo esc_attr( get_option('bux_payment_client_id') ); ?>"/></td>
        </tr>


        <tr valign="top">
        <th scope="row">Client Secret</th>
        <td><input type="password" name="bux_payment_client_secret" style="width: 360px" value="<?php echo esc_attr( get_option('bux_payment_client_secret') ); ?>"/></td>
        </tr>

        <tr valign="top">
        <th scope="row">Default Expiry (hrs)</th>
        <td><input type="number" name="bux_payment_default_expiry" style="width: 360px" value="<?php echo esc_attr( get_option('bux_payment_default_expiry') ); ?>" min="2" max="168" required/></td>
        </tr>

        <tr valign="top">
        <th scope="row">Test Mode</th>
        <td><input type="checkbox" id="bux_test_mode" name="bux_test_mode" value="1"<?php checked( 1 == get_option('bux_test_mode')) ?> /></td>
        </tr>

        <tr valign="top">
        <th scope="row">Disable Redirect?</th>
        <td><input type="checkbox" id="bux_redirect_disable" name="bux_redirect_disable" value="1"<?php checked( 1 == get_option('bux_redirect_disable')) ?> /></td>
        </tr>
    </table>
    <br>
<div style="display: flex;">
  <?php  submit_button(); ?>
<a href="https://app.bux.ph/authorize/?ecom=WP&amp;store_name=WooCommerce%3A%20<?php echo bloginfo('name'); ?>&amp;redirect_url=<?php echo site_url(); ?>/wp-json/bux-api/update_credentials/?" target="_blank" style="text-decoration: none; margin-left: 50px; margin-top: 35px; height: 26px"><span style="background: #0085ba;border-color: #0073aa #006799 #006799;box-shadow: 0 2px 0 #006799;color: #fff;text-decoration: none !important;border-radius: 3px;font-size: 13px; padding: 6px 10px 6px 10px"><img src="<?php echo plugin_dir_url(  __FILE__  ) . 'images/buxlogo.png' ?>" style="height: 12px; margin-right: 5px;">Click to Link Bux Account</span></a>
</div>
</form>
</div>
 
<?php
}

function bux_plugin_links( $links ) {
	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=offline_gateway' ) . '">' . __( 'Configure', 'bux-gateway' ) . '</a>'
	);
	return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'bux_plugin_links' );


add_action( 'plugins_loaded', 'bux_payment_gateway_init', 11 );

 
function add_bux_note($ref_code) {
	echo '<h2 class="h2thanks">Please present the following code:</h2><p class="pthanks">'. $ref_code.'</p>';
}


function bux_wc_register_post_statuses() {
	register_post_status( 'wc-pending-bux', array(
	'label' => _x( 'Pending Bux Payment', 'WooCommerce Order status', 'text_domain' ),
	'public' => true,
	'exclude_from_search' => false,
	'show_in_admin_all_list' => true,
	'show_in_admin_status_list' => true,
	'label_count' => _n_noop( 'Pending (%s)', 'Pending (%s)', 'text_domain' )
	) );

}
add_filter( 'init', 'bux_wc_register_post_statuses' );

/*
add_action( 'woocommerce_cart_calculate_fees','bux_wc_add_custom_surcharge' );
function bux_wc_add_custom_surcharge() {
  global $woocommerce;

	if ( is_admin() && ! defined( 'DOING_AJAX' ) )
		return;

	$surcharge = 20;	
    $chosen_gateway = $woocommerce->session->get( 'chosen_payment_method' );

	if ($chosen_gateway == 'bux_gateway') {
		$woocommerce->cart->add_fee( 'Transaction Fee', $surcharge, true, '' );
	}

}

function bux_wc_cart_update_script() {
    if (is_checkout()) :
    ?>
    <script>
      jQuery( function( $ ) {
  
         // woocommerce_params is required to continue, ensure the object exists
         if ( typeof woocommerce_params === 'undefined' ) {
            return false;
         }
  
         $checkout_form = $( 'form.checkout' );
  
         $checkout_form.on( 'change', 'input[name="payment_method"]', function() {
               $checkout_form.trigger( 'update' );
         });
  
  
      });
    </script>
    <?php
    endif;
}
add_action( 'wp_footer', 'bux_wc_cart_update_script', 999 );
*/

function bux_wc_add_order_statuses( $order_statuses ) {
	$order_statuses['wc-pending-bux'] = _x( 'Pending Bux Payment', 'WooCommerce Order status', 'text_domain' );
	return $order_statuses;
}
add_filter( 'wc_order_statuses', 'bux_wc_add_order_statuses' );

function bux_payment_gateway_init() {

    class BUX_Gateway extends WC_Payment_Gateway {

		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {
	  
			$this->id                 = 'bux_gateway';
			$this->icon               = apply_filters('woocommerce_offline_icon', '');
			$this->has_fields         = false;
			$this->method_title       = __( 'Bux', 'bux-gateway' );
			$this->method_description = __( 'Pay through GCash, Online Banking (UnionBank, BPI, RCBC), Over the counter (7-Eleven, Cebuana, Palawan, etc)' );
		  
			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();
		  
			// Define user set variables
			$this->title        = $this->get_option( 'title' );
			$this->description  = $this->get_option( 'description' );
			$this->instructions = $this->get_option( 'instructions', $this->description );


			$this->API_BASE_URL = 'https://api.bux.ph/v1';
			$this->BUX_BASE_URL = 'https://app.bux.ph';
			// Actions
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		  
		}
	
	
		/**
		 * Initialize Gateway Settings Form Fields
		 */
		public function init_form_fields() {
	  
			$this->form_fields = apply_filters( 'wc_offline_form_fields', array(
		  
				'enabled' => array(
					'title'   => __( 'Enable/Disable', 'bux-gateway' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable Bux', 'bux-gateway' ),
					'default' => 'yes'
				),
				
				'title' => array(
					'title'       => __( 'Title', 'bux-gateway' ),
					'type'        => 'text',
					'default'     => __( 'Bux', 'bux-gateway' ),
				),
				
				'description' => array(
					'title'       => __( 'Description', 'bux-gateway' ),
					'type'        => 'textarea',
					'default' => __( 'Pay through 7-eleven, Cebuana, Palawan, Mlhuiller, LBC, SM, Bayad Center, Robinsons, etc.', 'bux-gateway' ),
				),
				
				'instructions' => array(
					'title'       => __( 'Instructions', 'bux-gateway' ),
					'type'        => 'textarea',
					'description' => __( 'Visible Payment Instructions after Checkout', 'bux-gateway' ),
					'default'     => __( 'Present bar code to the selected channels branch', 'bux-gateway' ),
					'desc_tip'    => true,
				),
			) );
		}
	
	
		/**
		 * Output for the order received page.
		 */
		public function thankyou_page($order_id) {
			$blog_name = get_bloginfo('name');


			$order = wc_get_order($order_id);
			// Reduce stock levels
			$order->reduce_order_stock();
			// Remove cart
			WC()->cart->empty_cart();

			$notes = wc_get_order_notes( array(
			    'order_id' => $order_id,
			    'order' => 'ASC',
			    'type'     => 'internal', // use 'internal' for admin and system notes, empty for all
			) );

			$basicauth = get_option('bux_payment_api_key');
			$url = $this->API_BASE_URL . '/api/check_code/';

			if(get_option('bux_test_mode')){

				$url = $this->API_BASE_URL . '/api/sandbox/check_code/';
			}

			$blog_name = get_bloginfo('name');
			$data = array( 
				'description'=> "[WC - ".$blog_name."] Order ".$order_id,
				'order_id'=> $order_id,
				'mode'=> "WP",
				'client_id' => get_option('bux_payment_client_id'),
			);

			$bdy = json_encode($data);
			$headers = array( 
					'x-api-key' => $basicauth,
					'Content-type' => 'application/json'
					);

			$pload = array(
				'method' => 'POST',
				'blocking' => true,
				'headers' => $headers,
				'body' => $bdy
				);

			$response = wp_remote_post($url, $pload);

			
			$body = wp_remote_retrieve_body( $response );

			$order_data = json_decode( $body );

			if($order_data->status == 'Paid'){

	
				print( '<section class="woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received" style="padding:30px 50px; border:2px solid #b794f4; background-color: #FFFFFF; font-size:15px; margin-bottom: 20px">');
				print( '<span style="font-size:18px;">You have successfully paid for your order.</span><br />');
			  	print( '<table style="table-layout:fixed; font-size:15px;">');
			    print( '<tr style="height:5px;"><td style="padding: 5px"><b>Bux Reference:</b></td><td style="padding: 5px">' .$order_data->ref_code . '</td></tr>');	   
			    print( '<tr style="height:5px;"><td style="padding: 5px"><b>Order #:</b></td><td style="padding: 5px"> ' . $order_id . '</td></tr>');
			    print( '<tr style="height:5px;"><td style="padding: 5px"><b>Seller Name:</b></td><td style="padding: 5px"> ' . $order_data->seller_name . '</td></tr>');
			  	print( '<tr style="height:5px;" ><td style="padding: 5px"><b>Total Due:</b></td><td style="padding: 5px">₱ ' . $order_data->amount . '</td></tr>');
			    print( '<tr style="height:5px;"><td style="padding: 5px"><b>Transaction Date:</b></td><td style="padding: 5px"> ' . $order_data->created . '</td></tr>');
			    print( '</table>');	    

				print( '<span style="font-weight:normal">We have also sent confirmation to your indicated email.</span><br />');
				print( '<span style="font-weight:normal">For payment concerns, please email <a href="mailto:support+bux@ubx.ph">support+bux@ubx.ph</a></span><br /><br />');
				print( '<div style="text-align:center;"> <a href="'. $order_data->link . '" target="_blank"> <button style="color:#FFFFFF; background-color: #555555; padding:12px 28px; width:100%; ">View Transaction Details</button></a></div></section>');

			}else if($order_data->payment_url){ //gcash or ewallet


				print( '<section class="woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received" style="padding:30px 50px; border:2px solid #b794f4; background-color: #FFFFFF; font-size:15px; margin-bottom: 20px">');
			    print( '<div style="display: flex; flex-direction: row; justify-content: center">' );
				print( '<span style="font-size:18px;">Go to ' . $order_data->channel . ' using the link below to proceed with payment.</span></div><br />');
				print( '<a href="' . $order_data->payment_url . '">Go to Payment</a>');

			  	print( '<table style="table-layout:fixed; font-size:15px;">');

			    print( '<tr style="height:5px;"><td style="padding: 5px"><b>Order #:</b></td><td style="padding: 5px"> ' . $order_id . '</td></tr>');
			    print( '<tr style="height:5px;"><td style="padding: 5px"><b>Seller Name:</b></td><td style="padding: 5px"> ' . $order_data->seller_name . '</td></tr>');
			  	print( '<tr style="height:5px;" ><td style="padding: 5px"><b>Total Due:</b></td><td style="padding: 5px">₱ ' . $order_data->amount . '</td></tr>');
			    print( '<tr style="height:5px;"><td style="padding: 5px"><b>Transaction Date:</b></td><td style="padding: 5px"> ' . $order_data->created . '</td></tr>');
			    print( '<tr style="height:5px;"><td style="padding: 5px"><b>Payment Deadline:</b></td><td style="padding: 5px"> ' . $order_data->expiry . '</td></tr>');
			    print( '</table>');	    
				print( '<span style="font-weight:normal; font-size: 12px">We have also sent these details to your indicated email.</span><br />');
				print( '<span style="font-weight:normal; font-size: 12px">For payment concerns, please email <a href="mailto:support+bux@ubx.ph">support+bux@ubx.ph</a></span><br /><br />');
				print( '<div style="text-align:center;"> <a href="'. $order_data->link . '" target="_blank"> <button style="color:#FFFFFF; background-color: #555555; padding:12px 28px; width:100%; ">View Transaction Details</button></a></div></section>');
			}else{


				print( '<section class="woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received" style="padding:30px 50px; border:2px solid #b794f4; background-color: #FFFFFF; font-size:15px; margin-bottom: 20px">');
			    print( '<div style="display: flex; flex-direction: row; justify-content: center">' );
				print( '<span style="font-size:18px;">Show this document to any ' . $order_data->channel . ' branch for payment.</span></div><br />');

			    print( '<div style="display: flex; flex-direction: row; justify-content: center">' );
			    print( '<div style="max-width:300px;"> <img src="'. $order_data->image_url  .'">' );   
				print( '<div style="display:flex; flex-direction: row; justify-content: center; max-width: 300px"><b>' . $order_data->ref_code. '</b></div><br />');
				print( '<div style="display:flex; flex-direction: row; justify-content: center; max-width: 300px"><img src="'. plugin_dir_url( __FILE__  ) . 'images/powered_by_bux.png" style="height:20px;"> </div></div>');
			    print( '</div><br /><br />' );
			  	print( '<table style="table-layout:fixed; font-size:15px;">');

			    print( '<tr style="height:5px;"><td style="padding: 5px"><b>Bux Reference:</b></td><td style="padding: 5px">' .$order_data->ref_code . '</td></tr>');	   
			    print( '<tr style="height:5px;"><td style="padding: 5px"><b>Order #:</b></td><td style="padding: 5px"> ' . $order_id . '</td></tr>');
			    print( '<tr style="height:5px;"><td style="padding: 5px"><b>Seller Name:</b></td><td style="padding: 5px"> ' . $order_data->seller_name . '</td></tr>');
			  	print( '<tr style="height:5px;" ><td style="padding: 5px"><b>Total Due:</b></td><td style="padding: 5px">₱ ' . $order_data->amount . '</td></tr>');
			    print( '<tr style="height:5px;"><td style="padding: 5px"><b>Transaction Date:</b></td><td style="padding: 5px"> ' . $order_data->created . '</td></tr>');
			    print( '<tr style="height:5px;"><td style="padding: 5px"><b>Payment Deadline:</b></td><td style="padding: 5px"> ' . $order_data->expiry . '</td></tr>');
			    print( '</table>');	    
				print( '<span style="font-weight:normal; font-size: 12px">We have also sent these details to your indicated email.</span><br />');
				print( '<span style="font-weight:normal; font-size: 12px">For payment concerns, please email <a href="mailto:support+bux@ubx.ph">support+bux@ubx.ph</a></span><br /><br />');
				print( '<div style="text-align:center;"> <a href="'. $order_data->link . '" target="_blank"> <button style="color:#FFFFFF; background-color: #555555; padding:12px 28px; width:100%; ">View Transaction Details</button></a></div></section>');
			}


		}

		/**
		 * Process the payment and return the result
		 *
		 * @param int $order_id
		 * @return array
		 */
		public function process_payment( $order_id ) {

			if(($this->get_order_total() < 50)){
				$error_message = 'Bux payments minimum amount is 50.00 PHP';
				wc_add_notice( __('Payment error:', 'woothemes') . $error_message, 'error' );
				return array(
					'result' 	=> 'error'
				);
			}
			$order = wc_get_order( $order_id );
			$basicauth = get_option('bux_payment_api_key');

			$url = $this->API_BASE_URL . '/api/woocommerce/checkout/';

			if(get_option('bux_test_mode')){

				$url = $this->API_BASE_URL . '/api/sandbox/woocommerce/checkout/';
			}

			$blog_name = get_bloginfo('name');
			$data = array( 
				'amount' => $this->get_order_total(),
				'fee' => 0,
				'description'=> "[WC - ".$blog_name."] Order ".$order_id,
				'order_id'=> $order_id,
				'email' => $order->get_billing_email(),
				'phone' => $order->get_billing_phone(),
				'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'expiry' => get_option('bux_payment_default_expiry'),
				'client_id' => get_option('bux_payment_client_id'),
				'notification_url' => site_url(),
			);
			$bdy = json_encode($data);
			$headers = array( 
					'x-api-key' => $basicauth,
					'Content-type' => 'application/json'
					);

			$pload = array(
				'method' => 'POST',
				'blocking' => true,
				'headers' => $headers,
				'body' => $bdy
				);

			$response = wp_remote_post($url, $pload);

			
			$body = wp_remote_retrieve_body( $response );

			$order_data = json_decode( $body );
			$checkout_base = $this->BUX_BASE_URL . '/checkout/';

			if(get_option('bux_test_mode')){

				$checkout_base = $this->BUX_BASE_URL . '/test/checkout/';
			}

			if($order_data->status == "success"){
				//$order->update_status( 'wc-pending-bux', __( 'Awaiting payment', 'bux-gateway' ) );
				
				$return_url = $this->get_return_url( $order ) . '&order_id=' . $order_id;
				// Return thankyou redirect
				if(get_option('bux_redirect_disable')){

					return array(
						'result' 	=> 'success',
						'redirect'	=> $checkout_base . $order_data->uid
					);	
				}else{
					return array(
						'result' 	=> 'success',
						'redirect'	=> $checkout_base . $order_data->uid . '/?redirect_url=' . urlencode($return_url)
					);	
				}
			}else{
				$error_message = ' Please try again later';
				wc_add_notice( __('Payment error:', 'woothemes') . $error_message, 'error' );
				return array(
					'result' 	=> 'error'
				);

			}
		}
	
  } // end \WC_Gateway_Offline class
}

?>