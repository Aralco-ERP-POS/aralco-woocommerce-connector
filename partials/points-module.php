<?php
$options = get_option(ARALCO_SLUG . '_options');
$points_enabled = isset($options[ARALCO_SLUG . '_field_enable_points']) && $options[ARALCO_SLUG . '_field_enable_points'] == '1';
$points_cache = get_user_meta(get_current_user_id(),'points_cache', true);
if(!$points_cache) {
    $points_cache = array();
}
if($points_enabled && is_user_logged_in()) {
    $points_cache['total'] = WC()->cart->get_subtotal();
    update_user_meta(get_current_user_id(), 'points_cache', $points_cache);
    $cached_user_aralco_data = get_user_meta(get_current_user_id(), 'aralco_data', true);
    $user_aralco_data = Aralco_Connection_Helper::getCustomer('Id', $cached_user_aralco_data['id']);
    $notice = '';
    if($user_aralco_data instanceof WP_Error || $user_aralco_data == false){
        $user_aralco_data = $cached_user_aralco_data;
        $notice = '<p>' . __('There was a problem refreshing your points.', ARALCO_SLUG) . '</p>';
    } else {
        $cached_user_aralco_data['points'] = $user_aralco_data['points'] ?? 0;
        update_user_meta(get_current_user_id(), 'aralco_data', $cached_user_aralco_data);
    }


    $points = 0;
    if(isset($user_aralco_data) && isset($user_aralco_data['points'])){
        $points = (int)$user_aralco_data['points'] ?? 0;
    }
    $points_multiplier = get_option(ARALCO_SLUG . '_points_exchange', 0)
    ?>

    <tr class="fee">
        <th><?php esc_html_e('Points', ARALCO_SLUG); ?></th>
        <td><?php
            echo $notice;
            if(isset($points_cache["apply_to_order"])) {
                echo wc_price($points_cache["apply_to_order"] * -1); ?>
                <a class="remove-points" href="#"><?php _e('Remove') ?></a><?php
            } else {
                printf(
                    __('You have %s points. (approx. %s)', ARALCO_SLUG),
                    number_format_i18n($points),
                    wc_price(round($points * $points_multiplier, 2))
                )?>
                <br>
                <a class="use-points" href="#"><?php esc_html_e('Use Points', ARALCO_SLUG); ?></a>
                <label class="points-entry-label" style="display: none">
                    <?php printf(esc_html__("Points in %s:", ARALCO_SLUG), get_woocommerce_currency()) ?>
                    <input type="number" class="points-entry-dollars" name="points-entry-dollars" placeholder="<?php echo esc_attr(strip_tags(wc_price(20))) ?>">
                </label>
                <p class="points-errors" style="display: none;color: #cc0000;"></p>
                <button class="apply-points" style="display: none"><?php esc_html_e("Apply", ARALCO_SLUG) ?></button>
                <a class="points-cancel" href="#" style="display: none"><?php esc_html_e("Cancel", ARALCO_SLUG) ?></a>
                <?php
            }
            ?>
            <script>
                const aralcoApiSettings = {
                    root: "<?php echo esc_url_raw(rest_url()) ?>",
                    nonce: "<?php echo wp_create_nonce('wp_rest') ?>"
                };
            </script>
        </td>
    </tr>
<?php }
