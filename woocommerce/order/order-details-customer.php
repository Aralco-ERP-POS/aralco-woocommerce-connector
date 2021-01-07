<?php
/**
 * Order Customer Details
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/order/order-details-customer.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce/Templates
 * @version 3.4.4
 */

defined( 'ABSPATH' ) || exit;

$show_shipping = ! wc_ship_to_billing_address_only() && $order->needs_shipping_address();

$shipping_rate = array_values($order->get_shipping_methods())[0];
$is_aralco_local_pickup = $shipping_rate->get_method_id() == ARALCO_SLUG . '_pickup_shipping';
if($is_aralco_local_pickup) {
    $aralco_store_id = $shipping_rate->get_meta_data()[0]->get_data()['value'];
    $stores = $stores = get_option(ARALCO_SLUG . '_stores', array());
    $store = array_filter($stores, function($store) use ($aralco_store_id) {
        return $store['Id'] === intval($aralco_store_id);
    });
    if(count($store) == 0) $is_aralco_local_pickup = false;
    $store = $store[0];
}

?>
<section class="woocommerce-customer-details">

	<?php if ( $show_shipping ) : ?>

	<section class="woocommerce-columns woocommerce-columns--2 woocommerce-columns--addresses col2-set addresses">
		<div class="woocommerce-column woocommerce-column--1 woocommerce-column--billing-address col-1">

	<?php endif; ?>

	<h2 class="woocommerce-column__title"><?php esc_html_e( 'Billing address', 'woocommerce' ); ?></h2>

	<address>
		<?php echo wp_kses_post( $order->get_formatted_billing_address( esc_html__( 'N/A', 'woocommerce' ) ) ); ?>

		<?php if ( $order->get_billing_phone() ) : ?>
			<p class="woocommerce-customer-details--phone"><?php echo esc_html( $order->get_billing_phone() ); ?></p>
		<?php endif; ?>

		<?php if ( $order->get_billing_email() ) : ?>
			<p class="woocommerce-customer-details--email"><?php echo esc_html( $order->get_billing_email() ); ?></p>
		<?php endif; ?>
	</address>

	<?php if ( $show_shipping ) : ?>

		</div><!-- /.col-1 -->

		<div class="woocommerce-column woocommerce-column--2 woocommerce-column--shipping-address col-2">
            <?php if($is_aralco_local_pickup) { ?>
                <h2 class="woocommerce-column__title"><?php esc_html_e( 'Pickup at store location', ARALCO_SLUG ); ?></h2>
                <address class="address">
                    <?php echo $store['Code']; ?> - <?php echo $store['Name']; ?><br>
                    <?php echo $store['Address']; ?><br>
                    <?php echo $store['City']; ?> <?php echo $store['ProvinceState']; ?> <?php echo $store['ZipPostalCode']; ?>
                    <?php
                    if(strlen(trim($store['Phone'])) > 0) { ?>
                        <br/><?php echo wc_make_phone_clickable(trim($store['Phone']));
                    } ?>
                </address>
            <?php } else { ?>
                <h2 class="woocommerce-column__title"><?php esc_html_e( 'Shipping address', 'woocommerce' ); ?></h2>
                <address>
                    <?php echo wp_kses_post( $order->get_formatted_shipping_address( esc_html__( 'N/A', 'woocommerce' ) ) ); ?>
                </address>
            <?php } ?>
		</div><!-- /.col-2 -->

	</section><!-- /.col2-set -->

	<?php endif; ?>

	<?php do_action( 'woocommerce_order_details_after_customer_details', $order ); ?>

</section>
