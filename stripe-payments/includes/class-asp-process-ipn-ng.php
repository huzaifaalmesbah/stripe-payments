<?php
class ASP_Process_IPN_NG {

	public $asp_redirect_url = '';
	public function __construct() {
		$process_ipn = filter_input( INPUT_POST, 'asp_process_ipn', FILTER_SANITIZE_NUMBER_INT );
		if ( $process_ipn ) {
			$this->asp_class = AcceptStripePayments::get_instance();
			add_action( 'plugins_loaded', array( $this, 'process_ipn' ), 2147483647 );
		}
	}

	public function ipn_completed( $err_msg = '' ) {
		if ( ! empty( $err_msg ) ) {
			$asp_data = array( 'error_msg' => $err_msg );
			ASP_Debug_Logger::log( $err_msg, false ); //Log the error

			$this->sess->set_transient_data( 'asp_data', $asp_data );

			//send email to notify site admin (if option enabled)
			$opt = get_option( 'AcceptStripePayments-settings' );
			if ( isset( $opt['send_email_on_error'] ) && $opt['send_email_on_error'] ) {
				$to      = $opt['send_email_on_error_to'];
				$from    = get_option( 'admin_email' );
				$headers = 'From: ' . $from . "\r\n";
				$subj    = __( 'Stripe Payments Error', 'stripe-payments' );
				$body    = __( 'Following error occurred during payment processing:', 'stripe-payments' ) . "\r\n\r\n";
				$body   .= $err_msg . "\r\n\r\n";
				$body   .= __( 'Debug data:', 'stripe-payments' ) . "\r\n";
				$post    = filter_var( $_POST, FILTER_DEFAULT, FILTER_REQUIRE_ARRAY ); //phpcs:ignore
				foreach ( $post as $key => $value ) {
					$value = is_array( $value ) ? wp_json_encode( $value ) : $value;
					$body .= $key . ': ' . $value . "\r\n";
				}
				$schedule_result = wp_schedule_single_event( time(), 'asp_send_scheduled_email', array( $to, $subj, $body, $headers ) );
				if ( ! $schedule_result ) {
					wp_mail( $to, $subj, $body, $headers );
				}
			}
		} else {
			ASP_Debug_Logger::log( 'Payment has been processed successfully.' );
		}
		ASP_Debug_Logger::log( sprintf( 'Redirecting to results page "%s"', $this->asp_redirect_url ) . "\r\n" );
		wp_safe_redirect( $this->asp_redirect_url );
		exit;
	}

	public function process_ipn() {
		ASP_Debug_Logger::log( 'Payment processing started.' );

		$this->sess = ASP_Session::get_instance();

		$post_thankyou_page_url = filter_input( INPUT_POST, 'asp_thankyou_page_url', FILTER_SANITIZE_STRING );

		$this->asp_redirect_url = empty( $post_thankyou_page_url ) ? $this->asp_class->get_setting( 'checkout_url' ) : base64_decode( $post_thankyou_page_url ); //phpcs:ignore

		$prod_id = filter_input( INPUT_POST, 'asp_product_id', FILTER_SANITIZE_NUMBER_INT );

		if ( ! empty( $prod_id ) ) {
			ASP_Debug_Logger::log( sprintf( 'Got product ID: %d', $prod_id ) );
		}

		$item = new ASP_Product_Item( $prod_id );

		ASP_Debug_Logger::log( 'Firing asp_ng_process_ipn_product_item_override filter.' );

		$item = apply_filters( 'asp_ng_process_ipn_product_item_override', $item );

		$err = $item->get_last_error();

		if ( $err ) {
			$this->ipn_completed( $err );
		}

		if ( $item->get_redir_url() ) {
			$this->asp_redirect_url = $item->get_redir_url();
		}

		$pi = filter_input( INPUT_POST, 'asp_payment_intent', FILTER_SANITIZE_STRING );

		$completed_order = get_posts(
			array(
				'post_type'  => 'stripe_order',
				'meta_key'   => 'pi_id',
				'meta_value' => $pi,
			)
		);

		wp_reset_postdata();

		if ( ! empty( $completed_order ) ) {
			//already processed - let's redirect to results page
			$this->ipn_completed();
			exit;
		}

		$is_live = filter_input( INPUT_POST, 'asp_is_live', FILTER_VALIDATE_BOOLEAN );

		ASPMain::load_stripe_lib();
		$key = $is_live ? $this->asp_class->APISecKey : $this->asp_class->APISecKeyTest;
		\Stripe\Stripe::setApiKey( $key );

		ASP_Debug_Logger::log( 'Firing asp_ng_process_ipn_payment_data_item_override filter.' );

		$p_data = apply_filters( 'asp_ng_process_ipn_payment_data_item_override', false, $pi );

		if ( false === $p_data ) {

			$p_data = new ASP_Payment_Data( $pi );
		}

		$p_last_err = $p_data->get_last_error();

		if ( ! empty( $p_last_err ) ) {
			$this->ipn_completed( $p_last_err );
		}

		$button_key = $item->get_button_key();

		$post_quantity = filter_input( INPUT_POST, 'asp_quantity', FILTER_SANITIZE_NUMBER_INT );
		if ( $post_quantity ) {
			$item->set_quantity( $post_quantity );
		}

		$price = $item->get_price();
		if ( empty( $price ) ) {
			$post_price = filter_input( INPUT_POST, 'asp_amount', FILTER_SANITIZE_NUMBER_FLOAT );
			if ( $post_price ) {
				$price = $post_price;
			} else {
				$price = $p_data->get_amount();
			}
			$price = AcceptStripePayments::from_cents( $price, $item->get_currency() );
			$item->set_price( $price );
		}

		$item_price = $item->get_price();

		//variatoions
		$variations        = array();
		$posted_variations = filter_input( INPUT_POST, 'asp_stripeVariations', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
		if ( $posted_variations ) {
			// we got variations posted. Let's get variations from product
			$v = new ASPVariations( $prod_id );
			if ( ! empty( $v->variations ) ) {
				//there are variations configured for the product
				ASP_Debug_Logger::log( 'Processing variations.' );
				foreach ( $posted_variations as $grp_id => $var_id ) {
					$var = $v->get_variation( $grp_id, $var_id[0] );
					if ( ! empty( $var ) ) {
						$item_price    = $item_price + $var['price'];
						$variations[]  = array( $var['group_name'] . ' - ' . $var['name'], $var['price'] );
						$var_applied[] = $var;
					}
				}
			}
			$item->set_price( $item_price );
		}

		//coupon
		$coupon_code = filter_input( INPUT_POST, 'asp_coupon-code', FILTER_SANITIZE_STRING );
		if ( $coupon_code ) {
			ASP_Debug_Logger::log( sprintf( 'Coupon code provided: %s', $coupon_code ) );
		}
		$coupon_valid = $item->check_coupon( $coupon_code );

		if ( $coupon_code && $coupon_valid ) {
			ASP_Debug_Logger::log( 'Coupon is valid for the product.' );
		} else {
			ASP_Debug_Logger::log( 'Coupon is invalid for the product.' );
		}

		$amount_in_cents = intval( $item->get_total( true ) );
		$amount_paid     = $p_data->get_amount();

		if ( $amount_in_cents !== $amount_paid ) {
			$curr = $p_data->get_currency();
			$err  = sprintf(
				// translators: placeholders ar expected and received amounts
				__( 'Invalid payment amount received. Expected %1$s, got %2$s.', 'stripe-payments' ),
				AcceptStripePayments::formatted_price( $amount_in_cents, $curr, true ),
				AcceptStripePayments::formatted_price( $amount_paid, $curr, true )
			);
			$this->ipn_completed( $err );
		}

		$opt = get_option( 'AcceptStripePayments-settings' );

		ASP_Debug_Logger::log( 'Constructing checkout result and order data.' );

		$p_curr            = $p_data->get_currency();
		$p_amount          = $p_data->get_amount();
		$p_charge_data     = $p_data->get_charge_data();
		$p_charge_created  = $p_data->get_charge_created();
		$p_trans_id        = $p_data->get_trans_id();
		$p_billing_details = $p_data->get_billing_details();

		$data                       = array();
		$data['product_id']         = $prod_id ? $prod_id : null;
		$data['paid_amount']        = AcceptStripePayments::from_cents( $p_amount, $p_curr );
		$data['currency_code']      = strtoupper( $p_curr );
		$data['item_quantity']      = $item->get_quantity();
		$data['charge']             = $p_charge_data;
		$data['stripeToken']        = '';
		$data['stripeTokenType']    = 'card';
		$data['is_live']            = $is_live;
		$data['charge_description'] = $item->get_description();
		$data['item_name']          = $item->get_name();
		$data['item_price']         = $price;
		$data['stripeEmail']        = $p_billing_details->email;
		$data['customer_name']      = $p_billing_details->name;
		$purchase_date              = gmdate( 'Y-m-d H:i:s', $p_charge_created );
		$purchase_date              = get_date_from_gmt( $purchase_date, get_option( 'date_format' ) . ', ' . get_option( 'time_format' ) );
		$data['purchase_date']      = $purchase_date;
		$data['charge_date']        = $purchase_date;
		$data['charge_date_raw']    = $p_charge_created;
		$data['txn_id']             = $p_trans_id;
		$data['button_key']         = $button_key;

		$item_url = $item->get_download_url();

		$data['item_url'] = $item_url;

		$data['billing_address'] = $p_data->get_billing_addr_str();

		$data['shipping_address'] = $p_data->get_shipping_addr_str();

		$data['additional_items'] = array();

		ASP_Debug_Logger::log( 'Firing asp_ng_payment_completed filter.' );

		$data = apply_filters( 'asp_ng_payment_completed', $data, $prod_id );

		$item_price    = $item->get_price();
		$currency_code = $item->get_currency();

		$custom_fields = array();
		$cf_name       = filter_input( INPUT_POST, 'asp_stripeCustomFieldName', FILTER_SANITIZE_STRING );
		if ( $cf_name ) {
			$cf_value        = filter_input( INPUT_POST, 'asp_stripeCustomField', FILTER_SANITIZE_STRING );
			$custom_fields[] = array(
				'name'  => $cf_name,
				'value' => $cf_value,
			);
		}

		//compatability with ACF addon
		$acf_fields = filter_input( INPUT_POST, 'asp_stripeCustomFields', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
		if ( $acf_fields ) {
			$_POST['stripeCustomFields'] = $acf_fields;
		}
		$custom_fields = apply_filters( 'asp_process_custom_fields', $custom_fields, array( 'product_id' => $prod_id ) );

		if ( ! empty( $custom_fields ) ) {
			$data['custom_fields'] = $custom_fields;
		}

		if ( ! empty( $var_applied ) ) {
			//process variations URLs if needed
			foreach ( $var_applied as $key => $var ) {
				if ( ! empty( $var['url'] ) ) {
					$var                 = apply_filters( 'asp_variation_url_process', $var, $data );
					$var_applied[ $key ] = $var;
				}
			}
			$data['var_applied'] = $var_applied;
			foreach ( $variations as $variation ) {
				$data['additional_items'][ $variation[0] ] = $variation[1];
			}
		}

		//check if coupon was used
		if ( $coupon_valid ) {
			$coupon = $item->get_coupon();
		}
		if ( isset( $coupon ) ) {
			// translators: %s is coupon code
			$data['additional_items'][ sprintf( __( 'Coupon "%s"', 'stripe-payments' ), $coupon['code'] ) ] = floatval( '-' . $coupon['discountAmount'] );
			$data['additional_items'][ __( 'Subtotal', 'stripe-payments' ) ]                                = $item->get_price( false, true );
			//increase coupon redeem count
			$curr_redeem_cnt = get_post_meta( $coupon['id'], 'asp_coupon_red_count', true );
			$curr_redeem_cnt++;
			update_post_meta( $coupon['id'], 'asp_coupon_red_count', $curr_redeem_cnt );
		}
		$tax = $item->get_tax();
		if ( ! empty( $tax ) ) {
			$tax_str = apply_filters( 'asp_customize_text_msg', __( 'Tax', 'stripe-payments' ), 'tax_str' );
			$tax_amt = $item->get_tax_amount( false, true );
			$data['additional_items'][ ucfirst( $tax_str ) ] = $tax_amt;
			$data['tax_perc']                                = $item->get_tax();
			$data['tax']                                     = $tax_amt;
		}
		$ship = $item->get_shipping();
		if ( ! empty( $ship ) ) {
			$ship_str = apply_filters( 'asp_customize_text_msg', __( 'Shipping', 'stripe-payments' ), 'shipping_str' );
			$data['additional_items'][ ucfirst( $ship_str ) ] = $item->get_shipping();
			$data['shipping']                                 = $item->get_shipping();
		}

		//custom fields
		$custom_fields = $this->sess->get_transient_data( 'custom_fields' );
		if ( ! empty( $custom_fields ) ) {
			$data['custom_fields'] = $custom_fields;
		}

		$metadata = array();

		//Check if we need to include custom field in metadata
		if ( ! empty( $data['custom_fields'] ) ) {
			$cf_str = '';
			foreach ( $data['custom_fields'] as $cf ) {
				$cf_str .= $cf['name'] . ': ' . $cf['value'] . ' | ';
			}
			$cf_str = rtrim( $cf_str, ' | ' );
			//trim the string as metadata value cannot exceed 500 chars
			$cf_str                    = substr( $cf_str, 0, 499 );
			$metadata['Custom Fields'] = $cf_str;
		}

		//Check if we need to include variations data into metadata
		if ( ! empty( $variations ) ) {
			$var_str = '';
			foreach ( $variations as $variation ) {
				$var_str .= '[' . $variation[0] . '], ';
			}
			$var_str = rtrim( $var_str, ', ' );
			//trim the string as metadata value cannot exceed 500 chars
			$var_str                = substr( $var_str, 0, 499 );
			$metadata['Variations'] = $var_str;
		}

		if ( ! empty( $data['shipping_address'] ) ) {
			//add shipping address to metadata
			$shipping_address             = str_replace( "\n", ', ', $data['shipping_address'] );
			$shipping_address             = rtrim( $shipping_address, ', ' );
			$metadata['Shipping Address'] = $shipping_address;
		}

		if ( ! empty( $metadata ) ) {
			ASP_Debug_Logger::log( 'Firing asp_ng_handle_metadata filter.' );
			$metadata_handled = apply_filters( 'asp_ng_handle_metadata', $metadata );

			if ( true !== $metadata_handled ) {
				// metadata wasn't handled. Let's update metadata
				ASP_Debug_Logger::log( 'Updating payment metadata.' );
				$res = \Stripe\PaymentIntent::update( $pi, array( 'metadata' => $metadata ) );
			}
		}

		$product_details  = __( 'Product Name: ', 'stripe-payments' ) . $data['item_name'] . "\n";
		$product_details .= __( 'Quantity: ', 'stripe-payments' ) . $data['item_quantity'] . "\n";
		$product_details .= __( 'Item Price: ', 'stripe-payments' ) . AcceptStripePayments::formatted_price( $data['item_price'], $data['currency_code'] ) . "\n";

		//check if there are any additional items available like tax and shipping cost
		$product_details        .= AcceptStripePayments::gen_additional_items( $data );
		$product_details        .= '--------------------------------' . "\n";
		$product_details        .= __( 'Total Amount: ', 'stripe-payments' ) . AcceptStripePayments::formatted_price( $data['paid_amount'], $data['currency_code'] ) . "\n";
		$data['product_details'] = nl2br( $product_details );

		//Insert the order data to the custom post
		$dont_create_order = $this->asp_class->get_setting( 'dont_create_order' );
		if ( ! $dont_create_order ) {
			$order                 = ASPOrder::get_instance();
			$order_post_id         = $order->insert( $data, $data['charge'] );
			$data['order_post_id'] = $order_post_id;
			update_post_meta( $order_post_id, 'order_data', $data );
			update_post_meta( $order_post_id, 'charge_data', $data['charge'] );
			update_post_meta( $order_post_id, 'trans_id', $p_trans_id );
			update_post_meta( $order_post_id, 'pi_id', $pi );
		}

		//stock control
		if ( get_post_meta( $data['product_id'], 'asp_product_enable_stock', true ) ) {
			$stock_items = intval( get_post_meta( $data['product_id'], 'asp_product_stock_items', true ) );
			$stock_items = $stock_items - $data['item_quantity'];
			if ( $stock_items < 0 ) {
				$stock_items = 0;
			}
			update_post_meta( $data['product_id'], 'asp_product_stock_items', $stock_items );
			$data['stock_items'] = $stock_items;
		}

		//Action hook with the checkout post data parameters.
		ASP_Debug_Logger::log( 'Firing asp_stripe_payment_completed action.' );
		do_action( 'asp_stripe_payment_completed', $data, $data['charge'] );

		//Let's handle email sending stuff
		if ( isset( $opt['send_emails_to_buyer'] ) ) {
			if ( $opt['send_emails_to_buyer'] ) {
				$from = $opt['from_email_address'];
				$to   = $data['stripeEmail'];
				$subj = $opt['buyer_email_subject'];
				$body = asp_apply_dynamic_tags_on_email_body( $opt['buyer_email_body'], $data );

				$subj = apply_filters( 'asp_buyer_email_subject', $subj, $data );
				$body = apply_filters( 'asp_buyer_email_body', $body, $data );
				$from = apply_filters( 'asp_buyer_email_from', $from, $data );

				$headers = array();
				if ( ! empty( $opt['buyer_email_type'] ) && 'html' === $opt['buyer_email_type'] ) {
					$headers[] = 'Content-Type: text/html; charset=UTF-8';
					$body      = nl2br( $body );
				}
				$headers[] = 'From: ' . $from;

				$schedule_result = wp_schedule_single_event( time(), 'asp_send_scheduled_email', array( $to, $subj, $body, $headers ) );
				if ( ! $schedule_result ) {
					// can't schedule event for email notification. Let's send email without scheduling
					wp_mail( $to, $subj, $body, $headers );
					ASP_Debug_Logger::log( 'Notification email sent to buyer: ' . $to . ', from email address used: ' . $from );
				} else {
					ASP_Debug_Logger::log( 'Notification email to buyer scheduled: ' . $to . ', from email address used: ' . $from );
				}
			}
		}

		if ( isset( $opt['send_emails_to_seller'] ) ) {
			if ( $opt['send_emails_to_seller'] ) {
				$from = $opt['from_email_address'];
				$to   = $opt['seller_notification_email'];
				$subj = $opt['seller_email_subject'];
				$body = asp_apply_dynamic_tags_on_email_body( $opt['seller_email_body'], $data, true );

				$subj = apply_filters( 'asp_seller_email_subject', $subj, $data );
				$body = apply_filters( 'asp_seller_email_body', $body, $data );
				$from = apply_filters( 'asp_seller_email_from', $from, $data );

				$headers = array();
				if ( ! empty( $opt['seller_email_type'] ) && 'html' === $opt['seller_email_type'] ) {
					$headers[] = 'Content-Type: text/html; charset=UTF-8';
					$body      = nl2br( $body );
				}
				$headers[] = 'From: ' . $from;

				$schedule_result = wp_schedule_single_event( time(), 'asp_send_scheduled_email', array( $to, $subj, $body, $headers ) );
				if ( ! $schedule_result ) {
					// can't schedule event for email notification. Let's send email without scheduling
					wp_mail( $to, $subj, $body, $headers );
					ASP_Debug_Logger::log( 'Notification email sent to seller: ' . $to . ', from email address used: ' . $from );
				} else {
					ASP_Debug_Logger::log( 'Notification email to seller scheduled: ' . $to . ', from email address used: ' . $from );
				}
			}
		}

		$this->sess->set_transient_data( 'asp_data', $data );

		$this->ipn_completed();
	}
}

new ASP_Process_IPN_NG();