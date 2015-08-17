jQuery( function ( $ ) {
	$('[data-dependency]').each(function(){
		var dependency 	= $(this).data('dependency');
		var value 		= $(this).data('value');
		var c_value 	= $('[name="' + dependency + '"]').val();
		
		if(value == c_value){
			$(this).show();
		}else{
			$(this).hide();
		}
		
	});
	$('select[name="qbo[order_type]"]').on('change', function() {
		$('[data-dependency]').each(function(){
			var dependency 	= $(this).data('dependency');
			
			if(dependency == 'qbo[order_type]'){//(this).attr('name')){
				 var value 		= $(this).data('value');
				 var c_value 	= $('[name="' + dependency + '"]').val();
				 
				 if(value == c_value){
					$(this).show('slow');
				}else{
					$(this).hide('slow');
				}
			}
		});
  		
	});
	$( '#qbo-order-data' )
	.on( 'click', 'a.transfer-to.button', function() {
		$( '#qbo-order-data' ).block({
			message: null,
			overlayCSS: {
				background: '#fff url(' + cp_order_meta_box.plugin_url + '/assets/images/ajax-loader.gif) no-repeat center',
				opacity: 0.6
			}
		});

		var data = {
			action:    'cp_transfer_single_order',
			post_id:   cp_order_meta_box.post_id,
			security:  cp_order_meta_box.transfer_order_nonce,
		};

		$.post( cp_order_meta_box.ajax_url, data, function( response ) {
			$( '#qbo-order-data' ).unblock();
			window.location.reload();
		});

		
		return false;
	});
	$( '#qbo-order-data' )
	.on( 'click', 'a.transfer-resend.button', function() {
		$( '#qbo-order-data' ).block({
			message: null,
			overlayCSS: {
				background: '#fff url(' + cp_order_meta_box.plugin_url + '/assets/images/ajax-loader.gif) no-repeat center',
				opacity: 0.6
			}
		});

		var data = {
			action:    'cp_resend_order_qbo',
			post_id:   cp_order_meta_box.post_id,
			security:  cp_order_meta_box.transfer_order_nonce,
		};

		$.post( cp_order_meta_box.ajax_url, data, function( response ) {
			//$( '#qbo-order-data' ).unblock();
			window.location.reload();
			
		});

		
		return false;
	});
	$('a.button.transfer').on('click', function(e) {
		var url 	= $(this).attr('href');
		var vars 	= [], hash;
		$this 		= $(this);
		$(this).closest('table').block({
			message: null,
			overlayCSS: {
				background: '#fff url(' + cp_order_meta_box.plugin_url + '/assets/images/ajax-loader.gif) no-repeat center',
				opacity: 0.6
			}
		});
		if(url){
			var parent 	= $(this).parent().parent().parent(); 
			    var q 	= url.split('?')[1];
			    if(	q != undefined	){
			        q = q.split('&');
			        for(var i = 0; i < q.length; i++){
			            hash = q[i].split('=');
			            vars.push(hash[1]);
			            vars[hash[0]] = hash[1];
			        }
			}
			if(vars['sent']){
				var data = {
				action:	    vars['action'],
				message:   'Order #' + vars['order_id'] + ' has already been sent to QuickBooks.',
				security:	vars['_wpnonce'],
				};
				$.post( cp_order_meta_box.ajax_url, data, function( response ) {
					console.log(vars['action']); 
					window.location.reload();
				});	
			}else{
				parent.toggleClass("queued");
				var data = {
					action:	    vars['action'],
					order_id:   vars['order_id'],
					security:	vars['_wpnonce'],
				};
				$.post( cp_order_meta_box.ajax_url, data, function( response ) {
					console.log(response); 
					//parent.toggleClass("queued");
					//window.location.reload();
				});
			}
		}else{
			
		}
		return false;
	});
	$( '#qbo-order-data' )
	.on( 'change', '#qb_resend', function() {
		
		if ($(this).attr("checked")) {
			$('a.transfer-resend').removeClass('hide');
			$('a.transfer-resend').show('slow');
			
		}else{
			$('a.transfer-resend').hide('slow');
			
		}
		return false;
	});
	$('i.cp-logo').each(function(){
		$(this).insertBefore('#qbo-order-data h3.hndle span:first-child');
	});
});