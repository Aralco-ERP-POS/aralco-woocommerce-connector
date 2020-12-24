<?php
/**
 * Shipping Methods Display
 *
 * In 2.1 we show methods per package. This allows for multiple methods per order if so desired.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/cart/cart-shipping.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce/Templates
 * @version 3.6.0
 */

defined('ABSPATH') || exit;

$formatted_destination = isset($formatted_destination)? $formatted_destination : WC()->countries->get_formatted_address($package['destination'], ', ');
$has_calculated_shipping = !empty($has_calculated_shipping);
$show_shipping_calculator = !empty($show_shipping_calculator);
$calculator_text = '';
?>
<tr class="woocommerce-shipping-totals shipping">
    <th><?php echo wp_kses_post($package_name); ?></th>
    <td data-title="<?php echo esc_attr($package_name); ?>">
        <?php if ($available_methods) :
            $aralco_shipping_settings = get_option('woocommerce_' . ARALCO_SLUG . '_pickup_shipping_settings', array(
                'enabled' => 'no',
                'title' => 'Local Pickup',
                'eligibleStores' => array()
            ));
            ?>
            <ul id="shipping_method" class="woocommerce-shipping-methods">
                <?php foreach ($available_methods as $method) :
                    /* @var $method WC_Shipping_Rate */

                    $input_classes = array('shipping_method');
                    $li_style = '';
                    if ($method->get_method_id() == ARALCO_SLUG . '_pickup_shipping' && count($available_methods) > 1) {
                        $input_classes[] = ARALCO_SLUG . '_pickup_shipping';
                        $li_style = 'display: none;';
                    } ?>
                    <li style="<?php echo $li_style ?>">
                        <?php if (1 < count($available_methods)) {
                            printf('<input type="radio" name="shipping_method[%1$d]" data-index="%1$d" id="shipping_method_%1$d_%2$s" value="%3$s" class="%4$s" autocomplete="off" %5$s />', $index, esc_attr(sanitize_title($method->id)), esc_attr($method->id), implode(' ', $input_classes), checked($method->id, $chosen_method, false)); // WPCS: XSS ok.
                        } else {
                            printf('<input type="hidden" name="shipping_method[%1$d]" data-index="%1$d" id="shipping_method_%1$d_%2$s" value="%3$s" class="%4$s" />', $index, esc_attr(sanitize_title($method->id)), esc_attr($method->id), implode(' ', $input_classes)); // WPCS: XSS ok.
                        }
                        printf('<label for="shipping_method_%1$s_%2$s">%3$s</label>', $index, esc_attr(sanitize_title($method->id)), wc_cart_totals_shipping_method_label($method)); // WPCS: XSS ok.

                        do_action('woocommerce_after_shipping_rate', $method, $index);
                        ?>
                    </li>
                <?php endforeach;
                $pickup_selected = wp_startswith($chosen_method, ARALCO_SLUG . '_pickup');
                if ($aralco_shipping_settings['enabled'] == 'yes' &&
                count($available_methods) > 1) {
                    printf('<li><input type="radio" id="show-local-shipping" autocomplete="off" %1$s/><label for="show-local-shipping">%2$s</label></li>', $pickup_selected ? 'checked="checked"' : '', $aralco_shipping_settings['title']);
                } ?>
            </ul>
            <p id="store-selector" <?php echo ($pickup_selected ? '' : 'style="display: none;"') ?>>
                <label>Pickup at store: <select id="store-select"><option></option><?php
                $stores = get_option(ARALCO_SLUG . '_stores', array());
                foreach ($aralco_shipping_settings['eligibleStores'] as $i => $storeNumber) {
                    $store = array_values(array_filter($stores, function($store) use ($storeNumber) {
                        return $store['Id'] == $storeNumber;
                    }))[0];
                    $store_radio_value = ARALCO_SLUG . '_pickup_shipping_store_' . $store['Id'];
                    $address = htmlspecialchars($store['StoreAddress']);
                    printf('<option value="%1$s" data-address="%2$s" %3$s>%4$s</option>', $store_radio_value, $address, selected($store_radio_value, $chosen_method, false), $store['Name']);
                } ?></select></label>
            </p>
            <p id="store-selector-error" style="display: none; color: #f00;"></p>
            <?php if (is_cart() && !$pickup_selected) : ?>
                <p class="woocommerce-shipping-destination">
                    <?php
                    if ($formatted_destination) {
                        // Translators: $s shipping destination.
                        printf(esc_html__('Shipping to %s.', 'woocommerce') . ' ', '<strong>' . esc_html($formatted_destination) . '</strong>');
                        $calculator_text = esc_html__('Change address', 'woocommerce');
                    } else {
                        echo wp_kses_post(apply_filters('woocommerce_shipping_estimate_html', __('Shipping options will be updated during checkout.', 'woocommerce')));
                    }
                    ?>
                </p>
            <?php endif; ?>
        <?php
        elseif (!$has_calculated_shipping || !$formatted_destination) :
            if (is_cart() && 'no' === get_option('woocommerce_enable_shipping_calc')) {
                echo wp_kses_post(apply_filters('woocommerce_shipping_not_enabled_on_cart_html', __('Shipping costs are calculated during checkout.', 'woocommerce')));
            } else {
                echo wp_kses_post(apply_filters('woocommerce_shipping_may_be_available_html', __('Enter your address to view shipping options.', 'woocommerce')));
            }
        elseif (!is_cart()) :
            echo wp_kses_post(apply_filters('woocommerce_no_shipping_available_html', __('There are no shipping options available. Please ensure that your address has been entered correctly, or contact us if you need any help.', 'woocommerce')));
        else :
            // Translators: $s shipping destination.
            echo wp_kses_post(apply_filters('woocommerce_cart_no_shipping_available_html', sprintf(esc_html__('No shipping options were found for %s.', 'woocommerce') . ' ', '<strong>' . esc_html($formatted_destination) . '</strong>')));
            $calculator_text = esc_html__('Enter a different address', 'woocommerce');
        endif;
        ?>

        <?php if ($show_package_details) : ?>
            <?php echo '<p class="woocommerce-shipping-contents"><small>' . esc_html($package_details) . '</small></p>'; ?>
        <?php endif; ?>

        <?php if ($show_shipping_calculator) : ?>
            <?php woocommerce_shipping_calculator($calculator_text); ?>
        <?php endif; ?>
    </td>
</tr>