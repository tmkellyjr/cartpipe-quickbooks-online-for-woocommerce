<?php
/*Plugin Name: Cartpipe QuickBooks Online Integration for WooCommerce
Plugin URI: Cartpipe.com
Description: Cartpipe Client for WooCommerce / QuickBooks Online Integration
Author: Cartpipe.com
Version: 1.0.11
Author URI: Cartpipe.com
*/

/* comment added in a branch */
if(!class_exists('CP_QBO_Client')){
	define("CP_API", "https://api.cartpipe.com");
	define("CP_URL", "https://www.cartpipe.com");
	
	Class CP_QBO_Client{
		/*
		 * Instance
		 */
		protected static $_instance = null;
		
		/*
		 * CartPipe Consumber Key
		 */
		protected $cp_consumer_key = null;
		
		/*
		 * CartPipe Consumber Secret
		 */
		protected $cp_consumer_secret = null;
		
		/*
		 * CartPipe Service URL
		 */
		protected $cp_url = null;
		
		protected $trigger = null;
		/*
		 * API Client
		 */
		public $client = null;
		
		public $needs = array(
							'tax_rates'			=>false, 
							'tax_codes'			=>false, 
							'payment_methods' 	=> false
						);
		
		public $qbo		= null;
		
		public static function instance() {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}
			
			return self::$_instance;
			
		}
		/**
		 * Cloning is forbidden.
		 *
		 * @since 1.0
		 */
		public function __clone() {
			_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'cartpipe' ), '1.0.1' );
		}
	
		/**
		 * Unserializing instances of this class is forbidden.
		 *
		 * @since 1.0
		 */
		public function __wakeup() {
			_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'cartpipe' ), '1.0.1' );
		}
		/**
		 * Constructor
		 *
		 * @since 1.0
		 */
		function __construct(){
			$this->includes();
			$this->init();
			add_action( 'woocommerce_api_edit_customer', array( $this, 'cp_qbo_update_customer_info'), 10, 2);
			add_action('init', array( $this,'qbo_init'));
			add_action('admin_menu', array($this,'menu_items'));
			add_action( 'admin_menu', array( $this, 'settings_menu' ), 10 );
			add_action( 'add_meta_boxes', array($this, 'cp_add_meta_boxes') );
			add_action( 'qbo_settings_saved', array( $this, 'cp_reset_transients'));
			add_action('woocommerce_product_options_pricing', array('CP_QBO_Product_Meta_Box', 'display_costs_field'));
			add_action('woocommerce_process_product_meta', array($this, 'save_costs_field'));
			add_filter( 'woocommerce_order_get_items', array($this, 'cp_add_qbo_tax_id'), 0, 2 );
			add_action( 'manage_cp_queue_posts_custom_column' , array( $this, 'cp_queue_custom_columns' ) );
			add_filter('manage_edit-quickbooks_queue_columns', array( $this, 'cp_queue_page_columns' ) );
			add_action( 'woocommerce_checkout_order_processed', array( $this, 'cp_qbo_check_customer_exists' ) , 12, 2 );
			if(	isset($this->qbo->order_trigger) ){
				add_action( 'woocommerce_order_status_'.str_replace('wc-','',$this->qbo->order_trigger), array( $this, 'sod_qbo_send_order') , 12 );
			}
			add_action( 'woocommerce_order_status_completed', array( $this, 'cp_qbo_conditional_send_payment') , 13 );
			add_action( 'admin_enqueue_scripts', array( &$this,'cp_load_admin_js' )) ;
			add_filter( 'woocommerce_admin_order_actions', array(&$this, 'cp_add_qb_transfer'), 10, 2 );
		}
		function cp_add_qbo_tax_id( $items, $order_abstract){
			if($items){
				foreach ($items as $key => $item){
					if($item['type'] == 'tax'){
						$taxes = CP()->qbo->taxes;
						if($taxes){
							if(isset($taxes[$item['rate_id']])){
								$items[$key]['qbo_id'] = $taxes[$item['rate_id']];
							}
						}
						
					}
				}
			}
			return $items;
		}
		function cp_reset_transients(){
			delete_transient('cp_last_sync');
		}
		public static function save_costs_field( $post_id ){
			$cost 				= $_POST['_qb_cost'];
			$asset_account 		= $_POST['_qb_product_asset_accout'];
			$income_account		= $_POST['_qb_product_income_accout'];
			$expense_account	= $_POST['_qb_product_expense_accout'];
			if( !empty( $cost ) ){
				update_post_meta( $post_id, '_qb_cost', esc_attr( $cost ) );
			}
			if( !empty( $asset_account ) ){
				update_post_meta( $post_id, '_qb_product_asset_accout', esc_attr( $asset_account ) );
			}
			if( !empty( $income_account ) ){
				update_post_meta( $post_id, '_qb_product_income_accout', esc_attr( $income_account ) );
			}
			if( !empty( $expense_account ) ){
				update_post_meta( $post_id, '_qb_product_expense_accout', esc_attr( $expense_account ) );
			}
		}
		function cp_admin_notices(){
			$notices = get_option( 'cp_admin_notices' );
			if($notices){
				foreach ($notices as $notice) {
			      echo "<div class='updated'><p>$notice</p></div>";
			    }
			}
			delete_option( 'cp_admin_notices' );
		}
		function cp_add_meta_boxes(){
			
			include(plugin_dir_path( __FILE__ ).'/includes/admin-meta-boxes.php');
			include(plugin_dir_path( __FILE__ ).'/includes/meta-boxes/class-qbo-orders-meta-box.php');
			include(plugin_dir_path( __FILE__ ).'/includes/meta-boxes/class-qbo-products-meta-box.php');
			include(plugin_dir_path( __FILE__ ).'/includes/meta-boxes/class-qbo-fallout-meta-box.php');
		}
		function plugin_url(){
			return plugins_url('', __FILE__);
		}
		function cp_add_qb_transfer($actions, $order){
		    global $post;
			$qb_data 	= get_post_meta( $post->ID, '_quickbooks_data', true);
			$queued		= get_post_meta( $post->ID,'_cp_is_queued', true);
			if($queued ==  'yes' ){
				$actions['queued'] = array(
						'url' 		=> '',//wp_nonce_url( admin_url( 'admin-ajax.php?action=cp_transfer_order_qbo&order_id=' . $post->ID ), 'cp-transfer-order-qbo' ),
						'name' 		=> __( 'Queued to send to QuickBooks', 'cartpipe' ),
						'action' 	=> "transfer queued view"
					);
			} elseif ($queued == 'success') {
				$actions['success'] = array(
						'url' 		=> wp_nonce_url( admin_url( 'admin-ajax.php?action=cp_add_message&&sent=yes&order_id=' . $post->ID ), 'cp-transfer-order-qbo' ),
						'name' 		=> __( 'Successfully sent to QuickBooks', 'cartpipe' ),
						'action' 	=> "transfer resend success view"
					);
			}else {
				$actions['transfer'] = array(
							'url' 		=> wp_nonce_url( admin_url( 'admin-ajax.php?action=cp_transfer_order_qbo&order_id=' . $post->ID ), 'cp-transfer-order-qbo' ),
							'name' 		=> __( 'Transfer', 'cartpipe' ),
							'action' 	=> "transfer view"
						);
			}		
			
		    return $actions;
		    //stop editing
		}
		function cp_load_admin_js($hook){
			
			global $post;
			wp_enqueue_script( 'jquery' );
			wp_register_style( 'cp-admin-css', plugins_url('/assets/css/cp.css', __FILE__), false, '1.0.0' );
			wp_register_style( 'cp-font-css', plugins_url('/assets/css/cp-font.css', __FILE__), false, '1.0.0' );
			wp_register_style( 'cp-font-awesome', plugins_url('/assets/css/font-awesome.min.css', __FILE__), false, '1.0.0' );
			$order_nonce 	= wp_create_nonce( "transfer-order" );
			$product_nonce 	= wp_create_nonce( "sync-product" );
			$options_nonce	= wp_create_nonce( "cp-options-nonce" );
			// Register plugin Scripts
			wp_register_script( 'cp-charts-js', plugins_url('/assets/js/cp.chart.min.js', __FILE__) );
			wp_register_script( 'cp-chart-functions', plugins_url('/assets/js/cp.chart.functions.js', __FILE__),'jquery','', true );
			wp_register_script( 'cp-metabox-orders', plugins_url('/assets/js/cp.order.metabox.js', __FILE__),'jquery','', true );
			wp_register_script( 'cp-metabox-products', plugins_url('/assets/js/cp.product.metabox.js', __FILE__),'jquery','', true );
 			wp_enqueue_style( 'cp-admin-css' );
			wp_enqueue_style( 'cp-font-css' );
			wp_enqueue_style( 'cp-font-awesome' );
			// Enqeue those suckers
			wp_enqueue_script( 'cp-charts-js' );
			wp_enqueue_script( 'cp-chart-functions' );
			wp_enqueue_script( 'cp-metabox-orders' );
			wp_enqueue_script( 'cp-metabox-products' );
			
			//if(isset( $hook ) && $hook == 'cart-pipe_page_qbo-settings'){
				
				wp_register_script( 'cp-options', plugins_url('/assets/js/cp.options.js', __FILE__),array('jquery', 'jquery-blockui'),'', true );
				$options_metabox_data = array(
					'refresh_nonce'			=>	$options_nonce,
					'ajax_url'				=> 	admin_url('admin-ajax.php'),
					'plugin_url'			=> 	plugins_url('', __FILE__)
					
				);
				wp_enqueue_script( 'cp-options' );
				wp_localize_script( 'cp-options', 'cp_options', $options_metabox_data );
			//}
			if(isset($post)){
				$order_metabox_data = array(
					'post_id'				=>	$post->ID, 
					'transfer_order_nonce'	=>	$order_nonce,
					'ajax_url'				=> 	admin_url('admin-ajax.php'),
					'plugin_url'			=> 	plugins_url('', __FILE__)
					
				);
				wp_localize_script( 'cp-metabox-orders', 'cp_order_meta_box', $order_metabox_data );
			}
			if(isset($post)){
				$product_metabox_data = array(
					'post_id'				=>	$post->ID, 
					'sync_item_nonce'		=>	$product_nonce,
					'ajax_url'				=> 	admin_url('admin-ajax.php'),
					'plugin_url'			=> 	plugins_url('', __FILE__)
				);
				wp_localize_script( 'cp-metabox-products', 'cp_product_meta_box', $product_metabox_data );
			
			}
			
		}
		function includes(){
			include_once(plugin_dir_path( __FILE__ ).'cartpipe-functions.php');
			include_once(plugin_dir_path( __FILE__ ).'cartpipe-help.php');
			include_once(plugin_dir_path( __FILE__ ). 'includes/admin-settings.php' );
			include_once(plugin_dir_path( __FILE__ ).'includes/cp-messages.php');
			include_once(plugin_dir_path( __FILE__ ).'includes/cp-ajax.php');
			include_once(plugin_dir_path( __FILE__ ).'includes/cp-heartbeat.php');
			include_once(plugin_dir_path( __FILE__ ).'includes/cp-api-client.php');
			
			
			
		}
		function init(){
			
			$options 	= get_option('qbo') ? maybe_unserialize( get_option('qbo') ) : array();
			
			$this->qbo 	= new stdClass;
			$defaults	= array(
				'consumer_key' 		=> NULL,
				'consumer_secret'	=> NULL,
				'license'			=> NULL,
				'license_info'		=> NULL,
				'sync_frequency'	=> NULL,
				'sync_price'		=> NULL,
				'sync_stock'		=> NULL,
				'order_type'		=> NULL,
				'order_trigger'		=> NULL,
				'create_payment'	=> NULL,
				'taxes'				=> NULL,
				'tax_codes'			=> NULL,
				'payment_methods'	=> NULL,
				'asset_account'		=> NULL,
				'income_account'	=> NULL,
				'expense_account'	=> NULL,
				'delete_uninstall'	=> NULL, 
			
			);
			$options = array_merge($defaults, $options);
			
			if(sizeof($options) > 0 ){
				foreach($options as $key=>$value){
					if(!empty($key) && !empty($value)){
						$this->qbo->$key = $value;
					}
				}
			}
			
			if(isset($this->qbo->consumer_key)){
				$this->cp_consumer_key 		= $this->qbo->consumer_key; 	
			}
			if(isset($this->qbo->consumer_secret)){
				$this->cp_consumer_secret 	= $this->qbo->consumer_secret; 	
			}
			if(isset($this->qbo->api)){
				$this->cp_url 	= $this->qbo->api; 	
			}
			
 			if( $this->cp_url && $this->cp_consumer_key && $this->cp_consumer_secret ){
				$accounts 				 	= get_option('qbo_accounts', false);
				$this->qbo->accounts 		= isset( $accounts ) && $accounts != '' && $accounts ? $accounts : false;
				$this->client 				= new CP_Client( $this->cp_consumer_key, $this->cp_consumer_secret, $this->cp_url );
				
				$license 					= false;//get_transient( 'cartpipe_license_status' );
				$notices 					= get_transient( 'cartpipe_notices' );
				if ( false === $license ) {
					$license = $this->client->check_service( $this->qbo->license, get_home_url());
					
					set_transient( 'cartpipe_license_status', $license, 43200 );
					$this->qbo->license_info = $license;
					
				}else{
					$this->qbo->license_info = $license;
				}
				
			} 
		
		}	
		
		
		
		
		function qbo_init() {
			
			include(plugin_dir_path( __FILE__ ).'cartpipe-post-types.php');
		}
		function menu_items(){
			$main_page = add_menu_page( __( 'Cartpipe', 'cartpipe' ), __( 'Cartpipe', 'cartpipe' ), 'manage_woocommerce', 'cartpipe', null, null, '50' );
		}
		function settings_menu(){
			$settings_page 	= add_submenu_page( 'cartpipe', __( 'QuickBooks Online Settings', 'cartpipe' ),  __( 'QuickBooks Online Settings', 'cartpipe' ) , 'manage_woocommerce', 'qbo-settings', array( $this, 'settings_page' ) );
		}
		public function settings_page() {
			QBO_Admin_Settings::output();
		}
		function cp_qbo_import_item( $product ){
			if(isset($product->name)){
				$export_mappings = isset( CP()->qbo->import_fields ) ? CP()->qbo->import_fields : null;
				if(isset($export_mappings)){
					$new_product = array(
						'post_title'   => wc_clean( $product->$export_mappings['name'] ),
						'post_status'  => ( isset( $product->status ) ? wc_clean( $product->status ) : 'publish' ),
						'post_type'    => 'product',
						'post_excerpt' => ( isset(  $product->$export_mappings['description'] ) ? wc_clean( $product->$export_mappings['description']  ) : '' ),
						'post_content' => ( isset(  $product->$export_mappings['description']) ? wc_clean( $product->$export_mappings['description']  ) : '' ),
						'post_author'  => get_current_user_id(),
					);
					
				}else{
					$new_product = array(
						'post_title'   => wc_clean( $product->name ),
						'post_status'  => ( isset( $product->status ) ? wc_clean( $product->status ) : 'publish' ),
						'post_type'    => 'product',
						'post_excerpt' => ( isset( $product->description ) ? wc_clean( $product->description ) : '' ),
						'post_content' => ( isset( $product->description ) ? wc_clean( $product->description ) : '' ),
						'post_author'  => get_current_user_id(),
					);
				}
		
				// Attempts to create the new product
				$id = wp_insert_post( $new_product, true );
		
				// Checks for an error in the product creation
				if ( is_wp_error( $id ) ) {
					return new WP_Error( 'cp_api_cannot_create_product', $id->get_error_message(), array( 'status' => 400 ) );
				}
				// Save product meta fields
				$meta = $this->save_product_meta( $id, $product );
				wc_delete_product_transients( $id );
				if ( is_wp_error( $meta ) ) {
					return $meta;
				}
			}
		}
		function save_product_meta($id, $product){
			$product_type = null;
			$export_mappings = CP()->qbo->import_fields;
			if ( isset( $product->type ) ) {
				switch ($product->type) {
					case 'Inventory':
						$type = 'simple';	
						break;
					case 'Service':
						$type = 'simple';
						$product->virtual = true;
						break;
				}
				$product_type = wc_clean( $product->type );
				wp_set_object_terms( $id, $type, 'product_type' );
			} 
			
	
			// Tax status
			if ( isset( $product->taxable ) ) {
				if($product->taxable == true){
					update_post_meta( $id, '_tax_status', wc_clean( 'taxable' ) );
				}else{
					update_post_meta( $id, '_tax_status', wc_clean( 'none' ) );
				}
			}
			if ( isset( $product->id ) ) {
				update_post_meta( $id, 'qbo_product_id', $product->id );
			}
			// Tax Class
			if ( isset( $product->tax_class ) ) {
				update_post_meta( $id, '_tax_class', wc_clean( $product->tax_class ) );
			}
	
			if ( isset( $product->name ) ) {
				if(isset($product->full_name) && $product->full_name !='' ){
					$new_sku 	= $product->full_name;	
				}else{
					$new_sku 	= $product->name;
				}
				
				$unique_sku = wc_product_has_unique_sku( $id, $new_sku );
				if ( ! $unique_sku ) {
					return new WP_Error( 'cp_api_product_sku_already_exists', __( 'The SKU already exists on another product', 'cartpipe' ), array( 'status' => 400 ) );
				} else {
					update_post_meta( $id, '_sku', $new_sku );
				}
			} 
			if ( isset( $product->price ) ) {
				$regular_price = wc_format_decimal(  $product->price );
				update_post_meta( $id, '_regular_price', $regular_price );
				update_post_meta( $id, '_price', $regular_price );
			}
			
			if ( $product->track_qty == 'true'  ) {
				$stock_status = ( ('true' === $product->track_qty) && (intval($product->qty) >  0 )) ? 'instock' : 'outofstock';
			} else {
				$stock_status = 'instock';
			}
	
			// Stock Data
			if ( 'yes' == get_option( 'woocommerce_manage_stock' ) ) {
				// Manage stock
				if ( $product->track_qty == 'true' ) {
					$managing_stock = 'yes';
					update_post_meta( $id, '_manage_stock', $managing_stock );
				} elseif ( intval($product->qty ) > 0 ) {
					$managing_stock = 'yes';
					update_post_meta( $id, '_manage_stock', $managing_stock );
				} else {
					$managing_stock = 'no';
				}
				if ( 'yes' == $managing_stock ) {
					wc_update_product_stock_status( $id, $stock_status );
	
					// Stock quantity
					if ( isset( $product->qty ) ) {
						wc_update_product_stock( $id, intval( $product->qty ) );
					}
				} else {
					update_post_meta( $id, '_manage_stock', 'no' );
					update_post_meta( $id, '_stock', '' );
					wc_update_product_stock_status( $id, $stock_status );
				}
	
			} else {
				wc_update_product_stock_status( $id, $stock_status );
			}
		}
		function cp_qbo_update_customer_info($id, $data){
			if ( isset( $data['qbo_cust_id'] ) ) {
				update_user_meta( $id, 'qbo_cust_id', wc_clean( $data['qbo_cust_id'] ) );
			}
			if ( isset( $data['qbo_FullyQualifiedName'] ) ) {
				update_user_meta( $id, 'qbo_FullyQualifiedName', wc_clean( $data['qbo_FullyQualifiedName'] ) );
			}
		}
		function has_transferred( $data ){
			$has_transferred = false;
			
			foreach ( $data as $key=>$value ){
				
				switch ($key) {
					case 'sales_recipt':
					case 'sales_receipt':
						if($value->has_transferred){
							$has_transferred = true;			
						}
						break;
					
					case 'invoice':
						if($value->has_transferred){
							$has_transferred = true;			
						}
						break;
					case 'payment':
						if($value->has_transferred){
							$has_transferred = true;			
						}
						break;
				}
			}
			return $has_transferred;
		}
		function cp_qbo_check_customer_exists( $order_id, $posted ){
			$post = array(
			  'post_title'    	=> 'Order #'. $order_id . ' - check customer',
			  'post_content'  	=> '',
			  'post_status'   	=> 'publish',
			  'post_type'		=> 'cp_queue'
			);

			// Insert the post into the database
			$post_id = wp_insert_post( $post );
			if( $post_id ){
				wp_set_object_terms( $post_id , 'check-customer', 'queue_action' );
				wp_set_object_terms( $post_id , 'queued', 'queue_status' );
				update_post_meta( $post_id, 'reference_post_id', $order_id );
			}
		}
		function sod_qbo_send_order( $order_id, $force = false ){
			$data = maybe_unserialize( get_post_meta( $order_id, '_quickbooks_data', true ) );
			//Hasn't been sent
			if(CP()->qbo->license_info->level != 'Basic'){
				if( !$data || ($data && $force) ){
					$post = array(
					  'post_title'    	=> 'Order #'. $order_id . ' - ' . str_replace( '-', '', $this->qbo->order_type ),
					  'post_content'  	=> '',
					  'post_status'   	=> 'publish',
					  'post_type'		=> 'cp_queue'
					);
		
					// Insert the post into the database
					$post_id = wp_insert_post( $post );
					if( $post_id ){
						wp_set_object_terms( $post_id , 'create-'.$this->qbo->order_type, 'queue_action' );
						wp_set_object_terms( $post_id , 'queued', 'queue_status' );
						update_post_meta( $post_id, 'reference_post_id', $order_id );
					}
				}else{
					CPM()->add_message( 'Order #' . $order_id . ' has already been sent. Please try manually resending if you\'d like to recreate the order in QuickBooks.');
				}
			}
			return $post_id;
		}
	function cp_qbo_conditional_send_payment( $order_id ){
		$data 		= get_post_meta( $order_id, '_qbo_payment_number', true);
		$invoice	= get_post_meta( $order_id, '_qbo_invoice_number', true);
		if( !$data ){
			if(CP()->qbo->license_info->level != 'Basic'){
				if($this->qbo->order_type == 'invoice' && $this->qbo->create_payment == 'yes'){
					if(!isset($invoice) || $invoice == ''){
						$post = array(
						  'post_title'    	=> 'Order #'. $order_id . ' - Invoice',
						  'post_content'  	=> '',
						  'post_status'   	=> 'publish',
						  'post_type'		=> 'cp_queue'
						);
			
						// Insert the post into the database
						$post_id = wp_insert_post( $post );
						if( $post_id ){
							wp_set_object_terms( $post_id , 'create-invoice', 'queue_action' );
							wp_set_object_terms( $post_id , 'queued', 'queue_status' );
							update_post_meta( $post_id, 'reference_post_id', $order_id );
						}
					}
					$post = array(
					  'post_title'    	=> 'Order #'. $order_id . ' - Receive Payment On Account',
					  'post_content'  	=> '',
					  'post_status'   	=> 'publish',
					  'post_type'		=> 'cp_queue'
					);
		
					// Insert the post into the database
					$post_id = wp_insert_post( $post );
					if( $post_id ){
						wp_set_object_terms( $post_id , 'create-payment', 'queue_action' );
						wp_set_object_terms( $post_id , 'queued', 'queue_status' );
						update_post_meta( $post_id, 'reference_post_id', $order_id );
					}
					return $post_id;
				}
			}
		}else{
			CPM()->add_message( 'Receive payment for order #' . $order_id . ' has already been recorded in QuickBooks under ');
		}
	}
	function cp_queue_product( $prod_id ){
		if(CP()->qbo->license_info->level != 'Basic'){
			$sku 				= get_post_meta( $prod_id , '_sku', true );
			$post = array(
			  'post_title'    	=> 'Product #'. $prod_id . ', sku ' . $sku  ,
			  'post_content'  	=> '',
			  'post_status'   	=> 'publish',
			  'post_type'		=> 'cp_queue'
			);

			// Insert the post into the database
			$post_id = wp_insert_post( $post );
			if( $post_id ){
				wp_set_object_terms( $post_id , 'sync-item', 'queue_action' );
				wp_set_object_terms( $post_id , 'queued', 'queue_status' );
				update_post_meta( $post_id, 'reference_post_id', $prod_id );
			}
			return $post_id;
			}
		}
	function cp_queue_inventory( ){
		
			
			$post = array(
			  'post_title'    	=> 'Sync Start',
			  'post_content'  	=> '',
			  'post_status'   	=> 'publish',
			  'post_type'		=> 'cp_queue'
			);

			// Insert the post into the database
			$post_id = wp_insert_post( $post );
			if( $post_id ){
				wp_set_object_terms( $post_id , 'sync-inventory', 'queue_action' );
				wp_set_object_terms( $post_id , 'queued', 'queue_status' );
			}
			return $post_id;
		}
	function check_license( ){
		
			
			$post = array(
			  'post_title'    	=> 'Check Service',
			  'post_content'  	=> '',
			  'post_status'   	=> 'publish',
			  'post_type'		=> 'cp_queue'
			);

			// Insert the post into the database
			$post_id = wp_insert_post( $post );
			if( $post_id ){
				wp_set_object_terms( $post_id , 'check-service', 'queue_action' );
				wp_set_object_terms( $post_id , 'queued', 'queue_status' );
			}
			return $post_id;
		}
	function cp_queue_page_columns($columns){
			$columns['reference_post_id'] 	= 'Reference Item';
			$columns['queue_message'] 		= 'Message';
			return ($columns);
		}
	function cp_lookup_queue_items( $post_id ){
		$post_type = get_post_type( $post_id );
		switch ($post_type) {
			case 'shop_order':
				$args = array(
					'post_type'  		=> 'cp_queue',
					'meta_key' 			=> 'reference_post_id',
					'meta_value'		=> $post_id,
					'posts_per_page'	=> -1,
					
				);
				$items = new WP_Query( $args );			
				break;
			
			case 'product':
				$args = array(
					'post_type'  		=> 'cp_queue',
					'posts_per_page'	=> -1,
					'order'				=> 'DESC',
					'orderby'			=> 'date',
					'tax_query' => array(
						array(
							'taxonomy' => 'queue_action',
							'field' => 'slug',
							'terms' => array('sync-inventory')
							)
						)
					);
				$sync = new WP_Query( $args ); 
				$args = array(
					'post_type'  		=> 'cp_queue',
					'meta_key' 			=> 'reference_post_id',
					'meta_value'		=> $post_id,
					'posts_per_page'	=> -1,
					'order'				=> 'DESC',
					'orderby'			=> 'date',
					'tax_query' => array(
						array(
							'taxonomy' => 'queue_action',
							'field' => 'slug',
							'terms' => array('sync-item')
							)
						)
					);
				$item 				= new WP_Query( $args );	
				$last_updated 		= get_post_meta( $post_id, 'qbo_last_updated', true);
				if(!$last_updated){
					$posts 			= $sync->posts;
					$last_updated	= $posts[0]->post_date;
					
				}
				if(is_numeric($last_updated)){
					$last_updated = date('D, d M Y H:i:s', $last_updated);
				}
				if($item->posts){
					$item->posts 	= array_merge( $sync->posts, $item->posts );
				}
				break;
		}
		
		if(isset($items->posts)):
			return $items->posts;
		else:
			return array();
		endif;
	}
	function cp_lookup_fallout_items( $post_id ){
		$post_type = get_post_type( $post_id );
		switch ($post_type) {
			case 'shop_order':
				$args = array(
					'post_type'  		=> 'cp_fallout',
					'meta_key' 			=> 'reference_post_id',
					'meta_value'		=> $post_id,
					'posts_per_page'	=> -1,
					
				);
				$items = new WP_Query( $args );			
				break;
			
			case 'product':
				$args = array(
					'post_type'  		=> 'cp_fallout',
					'posts_per_page'	=> -1,
					'order'				=> 'DESC',
					'orderby'			=> 'date',
					'tax_query' => array(
						array(
							'taxonomy' => 'queue_action',
							'field' => 'slug',
							'terms' => array('sync-inventory')
							)
						)
					);
				$sync = new WP_Query( $args ); 
				$args = array(
					'post_type'  		=> 'cp_queue',
					'meta_key' 			=> 'reference_post_id',
					'meta_value'		=> $post_id,
					'posts_per_page'	=> -1,
					'order'				=> 'DESC',
					'orderby'			=> 'date',
					'tax_query' => array(
						array(
							'taxonomy' => 'queue_action',
							'field' => 'slug',
							'terms' => array('sync-item')
							)
						)
					);
				$item 				= new WP_Query( $args );	
				$last_updated 		= get_post_meta( $post_id, 'qbo_last_updated', true);
				if(!$last_updated){
					$posts 			= $sync->posts;
					$last_updated	= $posts[0]->post_date;
					
				}
				if(is_numeric($last_updated)){
					$last_updated = date('D, d M Y H:i:s', $last_updated);
				}
				 
				if($item->posts){
					$item->posts 	= array_merge( $sync->posts, $item->posts );
				}
				break;
		}
		
		if(isset($items->posts)):
			return $items->posts;
		else:
			return array();
		endif;
	}
	function cp_insert_queue_item( $ref_id, $action, $status ){
		$post = array(
		  'post_title'    	=> 'Order #'. $ref_id . ' - ' . str_replace( '-', '', $action ) ,
		  'post_content'  	=> '',
		  'post_status'   	=> 'publish',
		  'post_type'		=> 'cp_queue'
		);

		// Insert the post into the database
		$post_id = wp_insert_post( $post );
		if( $post_id ){
			wp_set_object_terms( $post_id , $action, 'queue_action' );
			wp_set_object_terms( $post_id , $status, 'queue_status' );
			update_post_meta( $post_id, 'reference_post_id', $ref_id );
		}
	}
	function cp_insert_fallout( $title, $ref_id, $error, $action, $type ){
		$post = array(
		  'post_title'    	=> $title ,
		  'post_content'  	=> '',
		  'post_status'   	=> 'publish',
		  'post_type'		=> 'cp_fallout'
		);

		// Insert the post into the database
		$post_id = wp_insert_post( $post );
		if( $post_id ){
			wp_set_object_terms( $post_id , $error, 'error_code' );
			wp_set_object_terms( $post_id , $type, 'fallout_type' );
			wp_set_object_terms( $post_id , $action, 'fallout_action' );
			update_post_meta( $post_id, 'reference_post_id', $ref_id );
		}
		return $post_id;
	}
	function cp_queue_custom_columns($column, $post_id )	{
			global $post;
			if($column == 'reference_post_id'){
				
				$ref_id 		= get_post_meta($post->ID, 'reference_post_id', true);
				$post_type 		= get_post_type($ref_id);
				$post_type_obj 	= get_post_type_object( $post_type ); 
				echo $post_type_obj->labels->singular_name . ' ' . $ref_id;
			};
		}
		
	}

		
}
function CP() {

	return CP_QBO_Client::instance();
}

// Global for backwards compatibility.
$GLOBALS['CP_QBO_Client'] = CP();
