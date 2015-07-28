<?php 
	global $wpdb;
	$number_to_send = 20;
	$product 	= wp_count_posts( 'product' )->publish;
	$variations = wp_count_posts( 'product_variation' )->publish;
	$sum = $product + $variations;
	$prods = array('prods'=>array());
	$args = array( 
		'post_type' => 
		array(
			'product', 
			'product_variation'
		),
		'posts_per_page' => -1,
		'post_status' => array('publish')  
	);
	$prod_query = new WP_Query( $args );
	if( $prod_query->have_posts() ):
	    while ( $prod_query->have_posts() ) : $prod_query->the_post();
			$prod 		= get_product( get_the_ID() );
			$cost 		= get_post_meta( get_the_ID(), '_qb_cost',  true);
			$expense	= get_post_meta( get_the_ID(), '_qb_product_expense_accout',  true);
			$income		= get_post_meta( get_the_ID(), '_qb_product_income_accout',  true);
			$asset 		= get_post_meta( get_the_ID(), '_qb_product_asset_accout',  true);
			$sku 		= $prod->get_sku();
			$prods['prods'][$sku] = array(
						'id' 				=>get_the_ID(),
						'price'				=> $prod->get_price(),
						'managing_stock'	=> $prod->managing_stock(),
						'stock'				=> $prod->get_stock_quantity(),
						'sku'				=> $prod->get_sku(),
						'description'		=> wc_clean(substr( get_the_content(), 0, 1000 ) ),
						'name'				=> wc_clean(substr( get_the_title(), 0, 100) ),
						'taxable'			=> $prod->is_taxable(),
						'active'			=> true,
						'cost' 				=> $cost,
					);
			if(isset( CP()->qbo->asset_account) ){
				if(isset($asset) && $asset != CP()->qbo->asset_account){
					$prods['prods'][$sku]['asset_account'] = $asset;
				}
			}else{
				if(isset($asset)){
					$prods['prods'][$sku]['asset_account'] = $asset;
				}
			}
			if(isset( CP()->qbo->income_account)){
				if(isset($income) && $income != CP()->qbo->income_account){
					$prods['prods'][$sku]['income_account'] = $income;
				}
			}else{
				if(isset($income)){
					$prods['prods'][$sku]['income_account'] = $income;
				}
			}
			if(isset( CP()->qbo->expense_account )){
				if(isset($expense) && $expense != CP()->qbo->expense_account){
					$prods['prods'][$sku]['expense_account'] = $expense;
				}
			}else{
				if(isset($expense)){
					$prods['prods'][$sku]['expense_account'] = $expense;
				}
			}
	    endwhile;
	endif;
	$prods['prods']['export_mapping'] 						= 	isset( CP()->qbo->export_fields ) ? CP()->qbo->export_fields : '';
	$prods['prods']['export_mapping']['income_account'] 	=  	isset( CP()->qbo->income_account ) ? CP()->qbo->income_account : '';
	$prods['prods']['export_mapping']['asset_account'] 		=  	isset( CP()->qbo->asset_account ) ? CP()->qbo->asset_account : '';
	$prods['prods']['export_mapping']['expense_account'] 	=  	isset( CP()->qbo->expense_account ) ? CP()->qbo->expense_account : '';
	
	$qbo = maybe_unserialize(  CP()->client->qbo_get_items( cpencode( $prods ), CP()->qbo->license ) );
	
	if($qbo){
		$variable_prods = array();
		foreach($qbo as $key=>$product){
			if($key == 'cp_messages' || $key == 'messages'){
				CPM()->add_message($product);
			}elseif($key == 'not_in' ) {
				if(sizeof($product) > 0 ){
					foreach ($product as $value) {
						wp_set_object_terms( $value , 'not-in-quickbooks', 'qb_status'. false );	
					}
				}	
			}else{
				if(isset($product->web_item->id)){
					$product_id = $product->web_item->id;
				}else{
					$product_id = false;
				}
				
				if ($product_id) {
					$wc_prod = get_product($product_id);
					if($wc_prod->is_type('variable')){
						$variable_prods[] = $product_id;
					}
					if(CP()->qbo->sync_stock == 'yes'){
						if($product->qty && $product->qty!=''):
							$wc_prod->set_stock( $product->qty );
						endif;
					}
					
					if(CP()->qbo->sync_price == 'yes'){
						if($product->price &&  $product->price != ''):
							update_post_meta( $product_id, '_price', $product->price );
							update_post_meta( $product_id, '_regular_price', $product->price );
							if($wc_prod->is_type('variable')){
								WC_Product_Variable::sync( $this->id );
							}
						endif;
					}
					if(CP()->qbo->store_cost == 'yes'){
						if($product->cost && $product->cost != ''):
							update_post_meta($product_id, '_qb_cost', $product->cost);
						endif;
					}
					update_post_meta( $product_id, 'qbo_product_id', $product->id );
					update_post_meta( $product_id, 'qbo_data', $product );
					update_post_meta( $product_id, 'qbo_last_updated', current_time('timestamp') );
					wp_set_object_terms( $product_id , 'in-quickbooks', 'qb_status'. false );
					
				}else{
					if(isset($product->full_name) && $product->full_name != ''){
						$id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_sku' AND meta_value='%s' LIMIT 1", $product->full_name	 ) );
					}elseif(isset($product->name)){
						$id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_sku' AND meta_value='%s' LIMIT 1", $product->name ) );
					}else{
						$id = false;	
					}
					
	    		   //If the subscription supports it, non-website items are returned to be imported.
	    		   if( $id == false || $id =='' ){
	    		  	 	wp_set_object_terms( $product_id , 'not in quickbooks', 'qb_status'. false );
					   	if(isset($product->name) && $product->name != ''){
	    		  			$fallout_id = CP()->cp_insert_fallout(cptexturize($product->name), $error = 'QB Item Not Found in Website', 'review', 'product' );
					   	}
						update_post_meta( $fallout_id, 'qb_product', $product );	 
	    		  	 	CP()->cp_qbo_import_item( $product );
				 	}
				}
			}
		}
		if(sizeof($variable_prods) > 0){
				foreach($variable_prods as $variable_id){
					WC_Product_Variable::sync( $variable_id );
					WC_Product_Variable::sync_stock_status( $variable_id );
				}
			}
	}
	wp_set_object_terms( $query->post->ID , 'success', 'queue_status'. false );
	
