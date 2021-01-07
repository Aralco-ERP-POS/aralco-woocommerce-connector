<?php
/**
 * Email Addresses
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/email-addresses.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce/Templates/Emails
 * @version 3.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$text_align = is_rtl() ? 'right' : 'left';
$address    = $order->get_formatted_billing_address();
$shipping   = $order->get_formatted_shipping_address();

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

?><table id="addresses" cellspacing="0" cellpadding="0" style="width: 100%; vertical-align: top; margin-bottom: 40px; padding:0;" border="0">
	<tr>
		<td style="text-align:<?php echo esc_attr( $text_align ); ?>; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; border:0; padding:0;" valign="top" width="50%">
			<h2><?php esc_html_e( 'Billing address', 'woocommerce' ); ?></h2>

			<address class="address">
				<?php echo wp_kses_post( $address ? $address : esc_html__( 'N/A', 'woocommerce' ) ); ?>
				<?php if ( $order->get_billing_phone() ) : ?>
					<br/><?php echo wc_make_phone_clickable( $order->get_billing_phone() ); ?>
				<?php endif; ?>
				<?php if ( $order->get_billing_email() ) : ?>
					<br/><?php echo esc_html( $order->get_billing_email() ); ?>
				<?php endif; ?>
			</address>
		</td>
		<?php if ( ! wc_ship_to_billing_address_only() && $order->needs_shipping_address() && $shipping ) : ?>
			<td style="text-align:<?php echo esc_attr( $text_align ); ?>; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; padding:0;" valign="top" width="50%">
                <?php if($is_aralco_local_pickup) { ?>
                    <h2><?php esc_html_e( 'Pickup at store location', ARALCO_SLUG ); ?></h2>
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
                    <h2><?php esc_html_e( 'Shipping address', 'woocommerce' ); ?></h2>
                    <address class="address"><?php echo wp_kses_post( $shipping ); ?></address>
                <?php } ?>
			</td>
		<?php endif; ?>
	</tr>
</table>