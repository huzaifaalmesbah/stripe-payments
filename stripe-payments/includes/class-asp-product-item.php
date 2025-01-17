<?php

class ASP_Product_Item {
	public $currency;
	public $name;
	public $quantity;
	public $description;
	public $tax_amount;

	protected $post_id = false;
	protected $post;
	protected $last_error    = '';
	protected $cust_quantity = false;
	protected $tax;
	protected $shipping;
	protected $zero_cent = false;
	protected $price;
	protected $asp_main;
	protected $coupon = false;
	protected $price_with_discount;
	protected $button_key = false;
	protected $items      = array();
	protected $sess;
	protected $overriden_data = false;

	public function __construct( $post_id = false ) {
		$this->asp_main = AcceptStripePayments::get_instance();
		if ( false !== $post_id ) {
			//let's try to load item from product
			$this->post_id = $post_id;
			$this->load_from_product();

			$btn_uniq_id = isset( $_GET['btn_uniq_id'] ) ? sanitize_text_field( stripslashes ( $_GET['btn_uniq_id'] ) ) : '';
			if ( ! empty( $btn_uniq_id ) ) {
                ASP_Debug_Logger::log( 'Using the btn_uniq_id parameter. Value of btn_uniq_id: ' . $btn_uniq_id, true );
				$this->sess = ASP_Session::get_instance();
				$this->overriden_data = $this->sess->get_transient_data( 'overriden_data_' . $btn_uniq_id );
			}
		}
	}

	private function in_cents( $amount ) {
		if ( ! $this->zero_cent ) {
			$amount = round( $amount * 100, 2 );
		} else {
			$amount = round( $amount, 0 );
		}
		return $amount;
	}

	private function from_cents( $amount ) {
		if ( ! $this->zero_cent ) {
			$amount = round( $amount / 100, 2 );
		} else {
			$amount = round( $amount, 0 );
		}
		return $amount;
	}

	public function get_last_error() {
		return $this->last_error;
	}

	public function get_product_id() {
		return $this->post_id;
	}

	public function get_button_text() {
		$button_text = get_post_meta( $this->post_id, 'asp_product_button_text', true );
		if ( empty( $button_text ) ) {
			$button_text = $this->asp_main->get_setting( 'button_text' );
		}
		return $button_text;
	}

	public function get_button_class() {
		$button_class = get_post_meta( $this->post_id, 'asp_product_button_class', true );
		return $button_class;
	}

	public function get_stock_items() {
		$stock_items = get_post_meta( $this->post_id, 'asp_product_stock_items', true );
		return $stock_items;
	}

	public function stock_control_enabled() {
		$stock_control_enabled = get_post_meta( $this->post_id, 'asp_product_enable_stock', true );
		return $stock_control_enabled;
	}

	public function get_tax() {
		if ( false !== $this->overriden_data && isset( $this->overriden_data['tax'] ) ) {
			return $this->overriden_data['tax'];
		}
		if ( ! empty( $this->tax ) ) {
			return $this->tax;
		}
		$this->tax = get_post_meta( $this->post_id, 'asp_product_tax', true );
		if ( empty( $this->tax ) ) {
			$this->tax = 0;
		}
		return $this->tax;
	}

	public function set_tax( $tax ) {
		$this->tax = $tax;
	}

	public function get_shipping( $in_cents = false ) {
		if ( ! isset( $this->shipping ) ) {
			$this->shipping = get_post_meta( $this->post_id, 'asp_product_shipping', true );
		}
		if ( empty( $this->shipping ) ) {
			$this->shipping = 0;
		}
		if ( $in_cents ) {
			return $this->in_cents( $this->shipping );
		}
		return $this->shipping;
	}

	public function set_shipping( $shipping, $in_cents = false ) {
		$this->shipping = $in_cents ? $this->from_cents( $shipping ) : $shipping;
	}

	public function get_items_total( $in_cents = false, $with_discount = false ) {
		$items_total = 0;
		if ( ! empty( $this->items ) ) {
			foreach ( $this->items as $item ) {
				$items_total += $item['price'];
			}
		} else {
			return 0;
		}

		if ( $with_discount && $this->coupon && 'perc' === $this->coupon['discount_type'] ) {
			$items_total = $this->apply_discount_to_amount( $items_total, false );
		}

		return $in_cents ? $this->in_cents( $items_total ) : $items_total;
	}

	public function get_items() {
		return $this->items;
	}

	public function add_item( $name, $price ) {
		$this->items[] = array(
			'name'  => $name,
			'price' => floatval( $price ),
		);
	}

	public function get_thumb() {
		$this->thumb = get_post_meta( $this->post_id, 'asp_product_thumbnail', true );
		return $this->thumb;
	}

	public function get_name() {
		$this->name = $this->post->post_title;
		return $this->name;
	}

	public function get_description() {
		$this->description = get_post_meta( $this->post_id, 'asp_product_description', true );
		return $this->description;
	}

	public function get_quantity() {
		$this->quantity = get_post_meta( $this->post_id, 'asp_product_quantity', true );
		if ( ! is_numeric( $this->quantity ) ) {
			$this->quantity = absint( $this->quantity );
		}
		if ( empty( $this->quantity ) ) {
			$this->quantity = 1;
		}
		if ( $this->cust_quantity ) {
			$this->quantity = $this->cust_quantity;
		}
		return $this->quantity;
	}

	public function get_coupon_discount_amount() {
		$price           = $this->get_price();
		$items_total     = $this->get_items_total();
		$discount_amount = $this->get_discount_amount( $price + $items_total );
		return $discount_amount;
	}

	public function get_price( $in_cents = false, $price_with_discount = false ) {
		if ( is_null( $this->price ) ) {
			$this->price = get_post_meta( $this->post_id, 'asp_product_price', true );
			$this->price = empty( $this->price ) ? 0 : round( $this->price * 100 ) / 100;
		}
		if ( $price_with_discount && $this->coupon ) {
			$discount_amount           = $this->get_discount_amount( $this->price, $in_cents );
			$this->price_with_discount = $this->price - $discount_amount;
		}
		if ( $in_cents ) {
			if ( $price_with_discount && $this->coupon ) {
				return $this->in_cents( $this->price_with_discount );
			}
			return $this->in_cents( $this->price );
		}
		if ( $price_with_discount && $this->coupon ) {
			return $this->price_with_discount;
		}
		return $this->price;
	}
	/**
	* Returns min amount for donation product
	*
	* @param bool $in_cents Return amount in cents if set to `true`
	*
	* @return integer|float
	*
	* @since 2.0.31
	*/
	public function get_min_amount( $in_cents = false ) {
		$min_amount = get_post_meta( $this->post_id, 'asp_product_min_amount', true );
		$min_amount = empty( $min_amount ) ? 0 : $min_amount;
		return $in_cents ? $this->in_cents( $min_amount ) : $min_amount;
	}

	/**
	* Returns product type
	*
	* @return string
	*
	* @since 2.0.31
	*/
	public function get_type() {
		$type = get_post_meta( $this->post_id, 'asp_product_type', true );
		return $type;
	}

	public function apply_discount_to_amount( $amount, $in_cents = false ) {
		if ( $this->coupon ) {
			if ( 'perc' === $this->coupon['discount_type'] ) {
				$perc            = AcceptStripePayments::is_zero_cents( $this->get_currency() ) ? 0 : 2;
				$discount_amount = round( $amount * ( $this->coupon['discount'] / 100 ), $perc );
			} else {
				$discount_amount = $this->coupon['discount'];
				if ( $in_cents && ! AcceptStripePayments::is_zero_cents( $this->get_currency() ) ) {
					$discount_amount = $discount_amount * 100;
				}
			}
			if ( $in_cents ) {
				$discount_amount = round( $discount_amount, 0 );
			}
			$amount = $amount - $discount_amount;
		}
		return $amount;
	}

	public function get_discount_amount( $total, $in_cents = false ) {
		$discount_amount = 0;
		if ( $this->coupon ) {
			if ( 'perc' === $this->coupon['discount_type'] ) {
				$perc            = AcceptStripePayments::is_zero_cents( $this->get_currency() ) ? 0 : 2;
				$discount_amount = round( $total * ( $this->coupon['discount'] / 100 ), $perc );
			} else {
				$discount_amount = $this->coupon['discount'];
				if ( ! $this->coupon['per_order'] ) {
					$discount_amount = $discount_amount * $this->get_quantity();
				}
				if ( $in_cents && ! AcceptStripePayments::is_zero_cents( $this->get_currency() ) ) {
					$discount_amount = $discount_amount * 100;
				}
			}
			if ( $in_cents ) {
				$discount_amount = round( $discount_amount, 0 );
			}
			$this->coupon['discountAmount'] = $discount_amount;
		}
		return $discount_amount;
	}

	public function get_total( $in_cents = false ) {
		$total = $this->get_price( $in_cents );

		$items_total = $this->get_items_total( $in_cents );

		$total += $items_total;

		$total = $total * $this->get_quantity();

		$total = $total - $this->get_discount_amount( $total, $in_cents );

		if ( $this->get_tax() ) {
			$total = $total + $this->get_tax_amount( $in_cents, true );
		}

		$shipping = $this->get_shipping( $in_cents );

		if ( ! empty( $shipping ) ) {
			$total = $total + $this->get_shipping( $in_cents );
		}

		if ( $total < 0 ) {
			$total = 0;
		}

		return $total;
	}

	public function set_price( $price, $in_cents = false ) {
		//workaround for zero-cents currencies for Sub addon 2.0.10 or less
		$this->zero_cent = AcceptStripePayments::is_zero_cents( $this->get_currency() );
		if ( $this->zero_cent && class_exists( 'ASPSUB_main' ) && isset( $this->plan ) && version_compare( ASPSUB_main::ADDON_VER, '2.0.10' ) <= 0 ) {
			$price = $price * 100;
		}
		//end workaround
		if ( $in_cents ) {
			$price = $this->from_cents( $price );
		}
		$this->price = $price;

	}

	public function set_quantity( $quantity ) {
		$this->cust_quantity = $quantity;
	}

	public function get_tax_amount( $in_cents = false, $price_with_discount = false ) {
		$total = $this->get_price( false );

		$items_total = $this->get_items_total( false );

		$total += $items_total;

		$total = $total * $this->get_quantity();

		$total = $total - $this->get_discount_amount( $total, false );

		$this->tax_amount = AcceptStripePayments::get_tax_amount( $total, $this->get_tax(), $this->zero_cent );
		if ( $in_cents ) {
			return $this->in_cents( $this->tax_amount );
		}
		return $this->tax_amount;
	}

	public function get_currency() {
		if ( empty( $this->currency ) ) {
			$this->currency = get_post_meta( $this->post_id, 'asp_product_currency', true );
			$this->currency = empty( $this->currency ) ? $this->asp_main->get_setting( 'currency_code' ) : $this->currency;
		}
		$this->zero_cent = AcceptStripePayments::is_zero_cents( $this->currency );
		return $this->currency;
	}

	public function set_currency( $curr ) {
		$this->currency  = $curr;
		$this->zero_cent = AcceptStripePayments::is_zero_cents( $curr );
	}

	public function is_variable() {
		return $this->get_price() === 0;
	}

	public function is_currency_variable() {
		if ( $this->get_type() !== 'donation' ) {
			return false;
		}
		$currency_variable = get_post_meta( $this->post_id, 'asp_product_currency_variable', true );
		return $currency_variable;
	}

	public function get_redir_url() {
		$url = get_post_meta( $this->post_id, 'asp_product_thankyou_page', true );
		if ( empty( $url ) ) {
			$url = $this->asp_main->get_setting( 'checkout_url' );
		}
		return $url;
	}

	public function collect_billing_addr() {
		return get_post_meta( $this->post_id, 'asp_product_collect_billing_addr', true );
	}

	public function gen_item_data() {
		$ret       = array();
		$item_info = array(
			'name'     => $this->get_name(),
			'amount'   => $this->get_price( true ),
			'currency' => $this->get_currency(),
			'quantity' => $this->get_quantity(),
		);
		$thumb     = $this->get_thumb();
		if ( ! empty( $thumb ) ) {
			$item_info['images'] = array( $this->get_thumb() );
		}
		$descr = $this->get_description();
		if ( ! empty( $descr ) ) {
			$item_info['description'] = $this->get_description();
		}
		$ret[] = $item_info;
		$tax   = $this->get_tax();
		if ( ! empty( $tax ) ) {
			$tax_str  = apply_filters( 'asp_customize_text_msg', __( 'Tax', 'stripe-payments' ), 'tax_str' );
			$tax_info = array(
				'name'     => sprintf( '%s (%s%%)', $tax_str, $this->get_tax() ),
				'amount'   => $this->get_tax_amount( true ),
				'currency' => $this->get_currency(),
				'quantity' => $this->get_quantity(),
			);
			$ret[]    = $tax_info;
		}
		$ship = $this->get_shipping();
		if ( ! empty( $ship ) ) {
			$ship_str = apply_filters( 'asp_customize_text_msg', __( 'Shipping', 'stripe-payments' ), 'shipping_str' );
			$tax_info = array(
				'name'     => sprintf( '%s', $ship_str ),
				'amount'   => $this->get_shipping( true ),
				'currency' => $this->get_currency(),
				'quantity' => $this->get_quantity(),
			);
			$ret[]    = $tax_info;
		}
		return $ret;
	}

	public function get_coupon() {
		return $this->coupon;
	}

	private function load_coupon( $coupon_code ) {
		//let's find coupon
		$coupon = get_posts(
			array(
				'meta_key'       => 'asp_coupon_code',
				'meta_value'     => $coupon_code,
				'posts_per_page' => 1,
				'offset'         => 0,
				'post_type'      => 'asp_coupons',
			)
		);
		wp_reset_postdata();
		if ( empty( $coupon ) ) {
			//coupon not found
			$this->last_error = __( 'Coupon not found.', 'stripe-payments' );
			return false;
		}
		$coupon = $coupon[0];
		//check if coupon is active
		if ( ! get_post_meta( $coupon->ID, 'asp_coupon_active', true ) ) {
			$this->last_error = __( 'Coupon is not active.', 'stripe-payments' );
			return false;
		}
		//check if coupon start date has come
		$start_date = get_post_meta( $coupon->ID, 'asp_coupon_start_date', true );
		if ( empty( $start_date ) || strtotime( $start_date ) > time() ) {
			$this->last_error = __( 'Coupon is not available yet.', 'stripe-payments' );
			return false;
		}
		//check if coupon has expired
		$exp_date = get_post_meta( $coupon->ID, 'asp_coupon_exp_date', true );
		if ( ! empty( $exp_date ) && strtotime( $exp_date ) < time() ) {
			$this->last_error = __( 'Coupon has expired.', 'stripe-payments' );
			return false;
		}
		//check if redemption limit is reached
		$red_limit = get_post_meta( $coupon->ID, 'asp_coupon_red_limit', true );
		$red_count = get_post_meta( $coupon->ID, 'asp_coupon_red_count', true );
		if ( ! empty( $red_limit ) && intval( $red_count ) >= intval( $red_limit ) ) {
			$this->last_error = __( 'Coupon redemption limit is reached.', 'stripe-payments' );
			return false;
		}

		$per_order = get_post_meta( $coupon->ID, 'asp_coupon_per_order', true );

		$this->coupon = array(
			'code'          => $coupon_code,
			'id'            => $coupon->ID,
			'discount'      => get_post_meta( $coupon->ID, 'asp_coupon_discount', true ),
			'discount_type' => get_post_meta( $coupon->ID, 'asp_coupon_discount_type', true ),
			'per_order'     => empty( $per_order ) ? 0 : 1,
		);
		return true;
	}

	public function check_coupon( $coupon_code = false ) {
		if ( ! $coupon_code ) {
			$this->last_error = 'No coupon code provided';
			return false;
		}
		$coupon_code = trim( $coupon_code );
		$this->load_coupon( $coupon_code );
		if ( ! $this->coupon ) {
			return false;
		}
		//check if coupon is allowed for the product
		$only_for_allowed_products = get_post_meta( $this->coupon['id'], 'asp_coupon_only_for_allowed_products', true );
		if ( $only_for_allowed_products ) {
			$allowed_products = get_post_meta( $this->coupon['id'], 'asp_coupon_allowed_products', true );
			if ( is_array( $allowed_products ) && ! in_array( strval( $this->post_id ), $allowed_products, true ) ) {
				$this->last_error = __( 'Coupon is not allowed for this product.', 'stripe-payments' );
				$this->coupon     = false;
				return false;
			}
		}
		return true;
	}

	public function get_download_url() {
		$post_url = isset( $_GET['asp_item_url'] ) ? sanitize_text_field( stripslashes ( $_GET['asp_item_url'] ) ) : '';
		if ( $post_url ) {
			$item_url = $post_url;
		} else {
			$item_url = get_post_meta( $this->post_id, 'asp_product_upload', true );
			$item_url = $item_url ? $item_url : '';

			if ( ! $item_url ) {
				return '';
			}
			$item_url = base64_encode( $item_url );
		}
		$item_url = apply_filters(
			'asp_item_url_process',
			$item_url,
			array(
				'button_key' => $this->get_button_key(),
				'product_id' => $this->post_id,
			)
		);
		$item_url = base64_decode( $item_url );
		return $item_url;
	}

	public function get_button_key() {
		if ( ! $this->button_key ) {
			$this->button_key = md5( htmlspecialchars_decode( $this->get_name() ) . $this->get_price( true ) );
		}
		return $this->button_key;
	}

	public function load_from_product( $post_id = false ) {
		if ( false === $post_id ) {
			$post_id = $this->post_id;
		}
		if ( false === $post_id ) {
			$this->last_error = __( 'No product ID provided.', 'stripe-payments' );
			return false;
		}

		$post_id    = intval( $post_id );
		$this->post = get_post( $post_id );
		if ( ! $this->post || ( get_post_type( $post_id ) !== ASPMain::$products_slug && get_post_type( $post_id ) !== ASPMain::$temp_prod_slug ) ) {
			// translators: %d is product id
			$this->last_error = sprintf( __( "Can't find product with ID %d", 'stripe-payments' ), $post_id );
                        ASP_Debug_Logger::log( "Can't find product with ID: " . $post_id, false );
			return false;
		}
		$this->zero_cent = AcceptStripePayments::is_zero_cents( $this->get_currency() );
		return true;
	}

	public function get_meta( $meta, $single = true ) {
		return get_post_meta( $this->post_id, $meta, $single );
	}
}
