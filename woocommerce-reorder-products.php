<?php
/*----------------------------------------------------------------------------------------------------------------------
Plugin Name: WooCommerce Reorder Products
Description: A plugin for cloning a previous order's products into a new order within the admin interface.
Version: 1.0.1
Author: New Order Studios
Author URI: http://neworderstudios.com/
----------------------------------------------------------------------------------------------------------------------*/

if ( is_admin() && !@$_REQUEST['post_ID'] ) {
    new wcReorderProducts();
}

class wcReorderProducts {

	public function __construct() {
		load_plugin_textdomain( 'woocommerce-reorder-products', false, basename( dirname(__FILE__) ) . '/i18n' );
		
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'replication_options' ) );
		add_action( 'wp_ajax_get_prior_cust_orders', array( $this, 'get_prior_orders' ) );
		add_action( 'wp_ajax_get_order_items', array( $this, 'get_order_items' ) );
		add_action( 'wp_ajax_get_order_fees', array( $this, 'get_order_fees' ) );
	}

	/**
	 * Let's set up the UI 
	 */
	public function replication_options() {
		echo '<div style="padding-top:8px;clear:both;"><a class="button button-primary button-large" href="#" id="wc_reorder_products_start">';
		echo __( 'Add products from a previous order', 'woocommerce-reorder-products' ) . '</a>';
		echo '<img src="images/loading.gif" style="display:none;padding-top:12px;" id="wc_reorder_products_loading" />';
		echo '<a style="display:none;margin-top:5px;" class="button button-primary button-large" href="#" id="wc_reorder_products_submit">';
		echo __( 'Add Products', 'woocommerce-reorder-products' ) . '</a></div>';

		wc_enqueue_js( $this->ajax_logic() );
	}

	/**
	 * Furnish a selection of recent previous orders from the specified customer as JSON.
	 */
	public function get_prior_orders() {
		$args = array(
			'numberposts'	=> -1,
			'meta_key'		=> '_customer_user',
			'meta_value'	=> $_REQUEST['userID'],
			'post_type'		=> 'shop_order',
			'post_status'	=> 'completed'
		);

		$orders = get_posts( $args );
		echo json_encode( $orders );
		die();
	}

	/**
	 * Furnish a specified order's line items as JSON.
	 */
	public function get_order_items() {
		$order = new WC_Order( $_REQUEST['orderID'] );
		echo json_encode( $order->get_items( 'line_item' ));
		die();
	}

	/**
	 * Furnish an order's fees as JSON.
	 */
	public function get_order_fees() {
		$order = new WC_Order( $_REQUEST['orderID'] );
		echo json_encode( $order->get_fees() );
		die();
	}

	/**
	 * AJAX logic.
	 */
	private function ajax_logic() {
		?>

		<script type="text/javascript">
		jQuery('document').ready(function($){

			if($('table.woocommerce_order_items tbody#order_line_items tr.item').length > 0){

				$('#wc_reorder_products_start').remove();

			}else{

				$('#wc_reorder_products_start').click(setupOrderList);
				$('#wc_reorder_products_submit').click(sendCloneRequest);
				$('#customer_user').change(clearOrderList);

				function setupOrderList(){
					$('#wc_reorder_products_start').hide();
					$('#wc_reorder_products_loading').fadeIn();

					$.post(ajaxurl + '?action=get_prior_cust_orders',{userID:$('#customer_user').val()},function(r){
						$('#wc_reorder_products_loading').hide();
						var orders = JSON.parse(r);

						if(!orders.length){
							$('#wc_reorder_products_start').after('<select id="wc_reorder_products_orderlist" disabled><option>No orders have been completed for this customer.</option></select> &nbsp;');
						}else{
							$('#wc_reorder_products_submit').show();
							$('#wc_reorder_products_start').after('<select id="wc_reorder_products_orderlist"></select> &nbsp;');
							$.each(JSON.parse(r),function(i,order){
								if(order.ID != woocommerce_admin_meta_boxes.post_id) $('#wc_reorder_products_orderlist').append('<option value="' + order.ID + '">' + order.post_title + ' (#' + order.ID + ')</option>');
							});
						}
					});

					return false;
				}

				function clearOrderList(){
					$('#wc_reorder_products_submit,#wc_reorder_products_loading').hide();
					$('#wc_reorder_products_orderlist').remove();
					$('#wc_reorder_products_start').show();
				}

				function sendCloneRequest(){
					var oid = $('#wc_reorder_products_orderlist').val();

					$.post(ajaxurl + '?action=get_order_items',{orderID:oid,userID:$('#customer_user').val()},function(r){
						$('#wc_reorder_products_loading').hide();
						var items = JSON.parse(r);
						var resave = false;
						var item_count = Object.keys(items).length;
						$.each(items, function(index,value) {
							var data = {
								action:      'woocommerce_add_order_item',
								item_to_add: value.product_id,
								order_id:    woocommerce_admin_meta_boxes.post_id,
								security:    woocommerce_admin_meta_boxes.order_item_nonce
							};
							$.post(woocommerce_admin_meta_boxes.ajax_url,data,function(response) {
								$('table.woocommerce_order_items tbody#order_line_items').append(response);

								if(value.qty > 1){
									total = $('table.woocommerce_order_items tbody#order_line_items tr.item:last input.line_total').data('total') * value.qty;
									$('table.woocommerce_order_items tbody#order_line_items tr.item:last input.quantity').val(value.qty);
									$('input.line_total,input.line_subtotal','table.woocommerce_order_items tbody#order_line_items tr.item:last').val(total);
									resave = true;
								}

								if(!--item_count){
									$('select#add_item_id, #add_item_id_chosen .chosen-choices').css('border-color','').val('');
									$('select#add_item_id').trigger('chosen:updated');
									if(resave) $('.wc-order-add-item .save-action').click();

									$.post(ajaxurl + '?action=get_order_fees',{orderID:oid,userID:$('#customer_user').val()},function(r){
										var fees = JSON.parse(r);
										if(!Object.keys(fees).length) $('#wc_reorder_products_loading').fadeOut();

										$.each(fees, function(i,v){
											var data = {
												action:   'woocommerce_add_order_fee',
												order_id: woocommerce_admin_meta_boxes.post_id,
												security: woocommerce_admin_meta_boxes.order_item_nonce
											};

											$.post(woocommerce_admin_meta_boxes.ajax_url, data, function(response) {
												$('table.woocommerce_order_items tbody#order_fee_line_items').append(response);
												
												$('table.woocommerce_order_items tbody#order_fee_line_items tr.fee:last input[type="text"]:first').val(v.name);
												$('table.woocommerce_order_items tbody#order_fee_line_items tr.fee:last .line_total').val(v.line_total);

												$('.wc-order-add-item .save-action').click();
												$('#wc_reorder_products_loading').fadeOut();
											});
										});
									});
								}
							});
						});
					});

					clearOrderList();
					$('#wc_reorder_products_start').hide();
					$('#wc_reorder_products_loading').fadeIn();

					return false;
				}
			}
		});
		</script>

		<?php
	}

}
