<?php
/**
 * QBO Product Settings
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'QBO_Settings_Sales' ) ) :

/**
 * WC_Settings_Products
 */
class QBO_Settings_Sales extends QBO_Settings_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id    			= 'orders';
		$this->label 			= __( 'Orders', 'cartpipe' );
		$taxes 					= get_option('qbo_sales_tax_info', false);
		
		if(isset($taxes->errors) || $taxes == '1' || !$taxes){
			delete_option('qbo_sales_tax_info');
		}
		
		$codes 				 	= get_option('qbo_sales_tax_codes', false);
		if(isset($codes->errors) || $codes == '1' || !$codes){
			delete_option('qbo_sales_tax_codes');
		}
		$payments 			 	= get_option('qbo_payment_methods', false);
		
		if(isset($payments->errors) || $payments == '1' || !$payments){
			
			delete_option('qbo_payment_methods');
		}
		
		$this->taxes 			= isset( $taxes ) && $taxes != '' && $taxes ? $taxes : CP()->needs['tax_rates'] = true;
		$this->tax_codes		= isset( $codes ) && $codes != '' && $codes ? $codes : CP()->needs['tax_codes'] = true ;
		$this->payment_methods	= isset( $payments ) && $payments != '' && $payments ? $payments : CP()->needs['payment_methods'] = true;
		
		if(sizeof(CP()->needs) > 0){
	    	foreach(CP()->needs as $key => $need){
	    		switch ($key) {
					case 'tax_rates':
						if($need){
							if(CP()->client):
								$this->taxes  = CP()->client->qbo_get_sales_tax_info( CP()->qbo->license ) ;
								if(! $this->taxes->errors ){
									update_option( 'qbo_sales_tax_info' , $this->taxes );
									$need = false;
								}else{
									$this->taxes = false;
									delete_option( 'qbo_sales_tax_info');
								}
								
							endif;
						}
						break;
					case 'tax_codes':
						if($need){
							if(CP()->client):
								
								$this->tax_codes = CP()->client->qbo_get_sales_tax_codes( CP()->qbo->license );
								if(! $this->tax_codes->errors ){
									update_option('qbo_sales_tax_codes', $this->tax_codes);
									$need = false;
								}else{
									$this->tax_codes = false;
									delete_option( 'qbo_sales_tax_codes');
								}
							endif;
						}
						break;
					case 'payment_methods':
						if($need){
							if(CP()->client):
								$this->payment_methods = CP()->client->qbo_get_payment_methods( CP()->qbo->license );
								 
								if(! $this->payment_methods->errors ){
									
									update_option('qbo_payment_methods', $this->payment_methods);
									$need = false;
								}else{
									$this->payment_methods = false;
									delete_option( 'qbo_payment_methods');
								}
							endif;
						}
						break;
				}
	    	}
			
		}
		add_filter( 'qbo_settings_tabs_array', array( $this, 'add_settings_page' ), 20 );
		add_action( 'qbo_settings_' . $this->id, array( $this, 'output' ) );
		add_action( 'qbo_settings_save_' . $this->id, array( $this, 'save' ) );
		//add_action( 'qbo_sections_' . $this->id, array( $this, 'output_sections' ) );
	}

	

	/**
	 * Output the settings
	 */
	public function output() {
		global $current_section;

		$settings = $this->get_settings( $current_section );

 		QBO_Admin_Settings::output_fields( $settings );
	}

	/**
	 * Save settings
	 */
	public function save() {
		global $current_section;

		$settings = $this->get_settings( $current_section );
		QBO_Admin_Settings::save_fields( $settings );
	}

	/**
	 * Get settings array
	 *
	 * @return array
	 */
	public function get_settings( $current_section = '' ) {
		//if ( $current_section == 'inventory' ) {
			$tax_classes			= self::get_tax_classes();
			$tax_rates 				= self::get_tax_rates();
			$wc_payment_methods		= self::get_payment_methods();
			$accounts 				 	= get_option('qbo_accounts', false);
			if(CP()->qbo->license_info->level == 'Basic'){
					
					CPM()->add_message(
								sprintf(
									'If you\'d like to send receipts or invoices to QuickBooks Online, think about upgrading to the <a target="_blank" href="%s">Standard</a> or <a target="_blank" href="%s">Premium Service</a>.', 
									CP()->qbo->license_info->product_url,  
									CP()->qbo->license_info->product_url
								)
							);
				}
			if(isset($accounts->errors) || $accounts == '1' || !$accounts){
				delete_option('qbo_accounts');
			}
			$this->accounts = isset( $accounts ) && $accounts != '' && $accounts ? $accounts : CP()->needs['accounts'] = true;
			if(sizeof(CP()->needs) > 0){
		    	foreach(CP()->needs as $key => $need){
		    		switch ($key) {
						case 'accounts':
							if($need){
								if(CP()->client):
									$this->accounts  = CP()->client->qbo_get_accounts( CP()->qbo->license );
									if(!($this->accounts->errors)){
										update_option( 'qbo_accounts' , $this->accounts );
										$need = false;
									}else{
										$this->accounts = false;
										delete_option( 'qbo_accounts');
									}
								endif;
							}
							break;
					}
		    	}
			}
			if ( is_plugin_active('woocommerce/woocommerce.php') ) {
			$settings = apply_filters( 'qbo_sales_settings', array(

				array( 'title' => __( 'QuickBooks Online Order Settings', 'cartpipe' ), 'type' => 'title', 'desc' => '', 'id' => 'qbo_orders' ),
				array(
					'title'             => __( 'Order Status Trigger', 'cartpipe' ),
					'desc'              => __( 'Please select the order status that will trigger the order to be sent to QuickBooks Online.', 'cartpipe' ),
					'id'                => 'qbo[order_trigger]',
					'type'              => 'select',
					'options'			=> wc_get_order_statuses(),
					'css'               => '',
					'default'           => '',
					'autoload'          => false
				),
				array(
					'title'             => __( 'Order Posting Type', 'cartpipe' ),
					'desc'              => __( 'Please select how you\'d like the order to transfer to QuickBooks Online.', 'cartpipe' ),
					'id'                => 'qbo[order_type]',
					'type'              => 'select',
					'options'			=> array(
												'sales-receipt'	=>	'Sales Receipt',
												'invoice'		=>	'Invoice'
											),
					'css'               => '',
					'default'           => '',
					'autoload'          => false
				),
				array(
					'title'             => __( 'Create Payment in QuickBooks?', 'cartpipe' ),
					'desc'              => __( 'Would you like to receive a payment on account in QuickBooks once the order has reached a \'completed\' status on the website', 'cartpipe' ),
					'id'                => 'qbo[create_payment]',
					'type'              => 'checkbox',
					'css'               => '',
					'default'           => '',
					'dependency'		=> array(
											'setting'	=> 'qbo[order_type]',
											'value'		=> 'invoice'
										),
					'autoload'          => false
				),
				array(
					'title'             => __( 'Deposit Account', 'cartpipe' ),
					'desc'              => __( 'Please select the Deposit Account to use for sales receipts and receipt of payments in QuickBooks Online.', 'cartpipe' ),
					'id'                => 'qbo[deposit_account]',
					'type'              => 'select',
					'options'			=> $this->accounts,
					'css'               => '',
					'default'           => '',
					'autoload'          => false
				),
					array(
					'title'			=> __( '', 'cartpipe' ),
					'desc'          => __( 'Refresh Accounts?', 'cartpipe' ),
					'label'			 => __( 'Refresh', 'cartpipe' ),
					//'id'            => 'qbo[sync_stock]',
					'type'          => 'button',
					'url'			=> '#',
					'data-type'		=> 'accounts',
					'linked'		=> 'qbo[deposit_account]',
					'class'			=> 'button refresh accounts	',
					'autoload'      => false
				),
				array(
					'title'             => __( 'Tax Rate Mappings', 'cartpipe' ),
					'desc'              => __( 'Please map your website tax rates to the corresponding tax rate in QuickBooks Online.', 'cartpipe' ),
					'id'                => 'qbo[taxes]',
					'type'              => 'mapping',
					'options'			=> $this->taxes,//wc_get_order_statuses(),
					'labels'			=> $tax_rates,
					'auto_create'		=> true, 
					'css'               => '',
					'default'           => '',
					'autoload'          => false
				),
					array(
					'title'			=> __( '', 'cartpipe' ),
					'desc'          => __( 'Refresh Tax Rates?', 'cartpipe' ),
					'label'			 => __( 'Refresh', 'cartpipe' ),
					//'id'            => 'qbo[sync_stock]',
					'type'          => 'button',
					'url'			=> '#',
					'data-type'		=> 'taxrates',
					'linked'		=> 'qbo[taxes]',
					'class'			=> 'button refresh taxrates',
					'autoload'      => false
				),
				array(
					'title'             => __( 'Tax Code Mappings', 'cartpipe' ),
					'desc'              => __( 'Please map your website tax classes to the corresponding tax code in QuickBooks Online.', 'cartpipe' ),
					'id'                => 'qbo[tax_codes]',
					'type'              => 'mapping',
					'options'			=> $this->tax_codes,//wc_get_order_statuses(),
					'labels'			=> $tax_classes,
					'css'               => '',
					'default'           => '',
					'autoload'          => false
				),
					array(
					'title'			=> __( '', 'cartpipe' ),
					'desc'          => __( 'Refresh Tax Codes?', 'cartpipe' ),
					'label'         => __( 'Refresh', 'cartpipe' ),
					//'id'            => 'qbo[sync_stock]',
					'type'          => 'button',
					'url'			=> '#',
					'data-type'		=> 'taxcodes',
					'linked'		=> 'qbo[tax_codes]',
					'class'			=> 'button refresh taxcodes',
					'autoload'      => false
				),
				array(
					'title'             => __( 'Payment Method Mappings', 'cartpipe' ),
					'desc'              => __( 'Please map your website payment methods to the corresponding payment methods in QuickBooks Online.', 'cartpipe' ),
					'id'                => 'qbo[payment_methods]',
					'type'              => 'mapping',
					'options'			=> $this->payment_methods,//wc_get_order_statuses(),
					'labels'			=> $wc_payment_methods,
					'css'               => '',
					'default'           => '',
					'autoload'          => false
				),
				array(
					'title'			=> __( '', 'cartpipe' ),
					'desc'          => __( 'Refresh Payment Methods?', 'cartpipe' ),
					'label'          => __( 'Refresh', 'cartpipe' ),
					//'id'            => 'qbo[sync_stock]',
					'type'          => 'button',
					'url'			=> '#',
					'data-type'		=> 'payments',
					'linked'		=> 'qbo[payment_methods]',
					'class'			=> 'button refresh payments',
					'autoload'      => false
				),

				
				array( 'type' => 'sectionend', 'id' => 'qbo_orders'),
			));
		}else{
			$settings = array();
			CPM()->add_message('You need to install WooCommerce before you can start using the Cartpipe WooCommerce / QuickBooks Online Integration');
		}

		return apply_filters( 'qbo_get_settings_' . $this->id, $settings, $current_section );
	}
	public function get_tax_rates(){
		global $wpdb;
		$return = null;
		$tax_rates = $wpdb->get_results( 
			"SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates
			ORDER BY tax_rate_order
			"  );
		if($tax_rates){
			foreach($tax_rates as $rate){
				$return[$rate->tax_rate_id] = sprintf('%s - %s - %s (%s)', $rate->tax_rate_country, $rate->tax_rate_state, $rate->tax_rate_name, $rate->tax_rate.'%');
			}
		}
		return $return;
	}
	public function get_tax_classes(){
		$return = array();
		if(class_exists('WC_Tax') ){
			$classes = WC_Tax::get_tax_classes();
			if($classes){
				foreach($classes as $class){
					$return[str_replace(' ', '-', strtolower($class))] = $class;
				}
			}
		}
		$return = array_merge( $return, array('standard'=>'Standard'));
		return $return;
	}
	public function get_payment_methods(){
		$return 	= array();			
		if ( is_plugin_active('woocommerce/woocommerce.php') ) {
			$gateways 	= WC()->payment_gateways->payment_gateways();
			
			if($gateways){
			 	foreach ($gateways as $key=>$value){
					$return[$key] = $value->title;
				} 
			}
		}
		return $return;
	}
}

endif;

return new QBO_Settings_Sales();
