<?php 
	$data 					= array();
	$WC_Order 				= wc_get_order( $ref_id );
	$items 					= $WC_Order->get_items();
	$taxes 					= $WC_Order->get_taxes();
	$shipping				= $WC_Order->get_shipping_methods(); 
	$qbo_tax_codes 			= CP()->qbo->tax_codes;
	$qbo_id 				= false;
	foreach($items as $key=>$value){
		if($value['tax_class'] == ''){
			$value['tax_class'] = 'standard';
		}
		if(sizeof($qbo_tax_codes) > 0){
			$qbo_tax_code = $qbo_tax_codes[$value['tax_class']];
		}
		if(isset( $value['variation_id'] ) && absint($value['variation_id']) > 0 ){
			$qbo_id = get_post_meta( $value['variation_id'], 'qbo_product_id', true );
			if(!$qbo_id){
				$qbo_id = get_post_meta( $value['product_id'], 'qbo_product_id', true );
			}
		}elseif(( $value['product_id'] ) ){
			$qbo_id = get_post_meta( $value['product_id'], 'qbo_product_id', true );
		} 
		$new_items[]= array(
			'name' 			=> isset($value['variation_id']) && ($value['variation_id'] > 0 ) ? get_post_meta( $value['variation_id'], '_sku', true ) : $value['name'],
			'qty'			=> $value['qty'],
			'tax_class'		=> $value['tax_class'],
			'web_id'		=> isset($value['variation_id']) && ($value['variation_id'] > 0 ) ? $value['variation_id'] : $value['product_id'],
			'subtotal' 		=> $value['line_subtotal'],
			'qbo_product_id'=> $qbo_id ? $qbo_id : '', 
			'qbo_tax_code'	=> $qbo_tax_code
		);
		
	};
	
	$order_items				= json_encode( $new_items );
	$data = array(
			'order_id'				=> $ref_id,
			'refRenumber' 			=> $WC_Order->get_order_number(),
			'txnTime' 				=> $WC_Order->post->post_date,
			'billing_first_name'	=> $WC_Order->billing_first_name,
			'billing_last_name'		=> $WC_Order->billing_last_name,
			'billing_address_1'		=> $WC_Order->billing_address_1,
			'billing_address_2'		=> $WC_Order->billing_address_2,
			'billing_city'			=> $WC_Order->billing_city,			
			'billing_state'			=> $WC_Order->billing_state,
			'billing_postcode'		=> $WC_Order->billing_postcode,
			'shipping_first_name'	=> $WC_Order->shipping_first_name,
			'shipping_last_name'	=> $WC_Order->shipping_last_name,
			'shipping_address_1'	=> $WC_Order->shipping_address_1,
			'shipping_address_2'	=> $WC_Order->shipping_address_2,
			'shipping_city'			=> $WC_Order->shipping_city,
			'shipping_state'		=> $WC_Order->shipping_state,
			'shipping_postcode'		=> $WC_Order->shipping_postcode,
			'shipping_country'		=> $WC_Order->shipping_country,
			'qbo_cust_id'			=> get_post_meta( $ref_id, '_qbo_cust_id', true),
			'order_items' 			=> $new_items,
			'order_total'			=> $WC_Order->get_total(),
			'order_subtotal'		=> $WC_Order->get_subtotal(),
			'zero_tax'				=> CP()->qbo->zero_tax_code,
			'posting_type'			=> 'salesreceipt'
	);
	if(sizeof($taxes) > 0){
		$data['taxes'] = $taxes;
	}
	if(sizeof($shipping) > 0){
		$shipping_amount	= 0;
		foreach($shipping as $method){
			
			$shipping_amount += (float) $method['cost'];	
			$data['shipping_method'] = $method['name'];
		}
		$data['shipping_amount'] = $shipping_amount;
		$data['shipping_taxcode'] = CP()->qbo->shipping_item_taxcode;
		$data['foreign_shipping_taxcode'] = CP()->qbo->foreign_shipping_item_taxcode;
		
	}
	
	$qbo = CP()->client->qbo_add_order( $ref_id, cpencode( $data ), CP()->qbo->license );
	
	$qbo->ref_id = $ref_id;
	
	if($qbo->data && $qbo->data != ''){
		$qbo->has_transferred = true;
		$data 					= maybe_unserialize( get_post_meta( $ref_id, '_quickbooks_data', true) );
		$data['sales_recipt'] 	= $qbo;
		update_post_meta( $ref_id , '_quickbooks_data', maybe_serialize( $data ) );
		update_post_meta( $ref_id , '_qbo_cust_id', $qbo->cust_id);
		update_post_meta( $ref_id , '_cp_is_queued', 'success');
		update_post_meta( $ref_id , 'cp_last_request', $qbo->last_request);
		wp_set_object_terms( $query->post->ID , 'success', 'queue_status'. false );
		wp_set_object_terms($ref_id , 'in-quickbooks', 'qb_status'. false );
	}else{
		$errors = explode(':', $qbo->errors);
		if($errors[0] == '3200'){
			wp_delete_post( $query->post->ID );
			sleep(60);
			CP()->sod_qbo_send_order( $ref_id );
		}else{
			if($qbo->cp_messages){
				CPM()->add_message($qbo->cp_messages, $ref_id, true);
			}
			CP()->cp_insert_fallout('Order #'.$ref_id,$ref_id, $qbo->errors, 'create-sales-receipt', 'order');
			update_post_meta( $ref_id , '_cp_errors', $qbo->errors);
			update_post_meta( $ref_id , 'cp_last_request', $qbo->last_request);
			wp_set_object_terms( $query->post->ID , 'failed', 'queue_status'. false );
			wp_set_object_terms($ref_id , 'not-in-quickbooks', 'qb_status'. false );
		}
	}

	return $qbo;