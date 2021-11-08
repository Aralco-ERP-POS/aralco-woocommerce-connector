<?php
/**
 * My Account Dashboard
 *
 * Shows the first intro screen on the account dashboard.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/myaccount/dashboard.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see         https://docs.woocommerce.com/document/template-structure/
 * @package     WooCommerce/Templates
 * @version     2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

$options = get_option(ARALCO_SLUG . '_options');
$use_quote = isset($options[ARALCO_SLUG . '_field_order_quote_text']) && $options[ARALCO_SLUG . '_field_order_quote_text'] == '1';
$points_enabled = isset($options[ARALCO_SLUG . '_field_enable_points']) && $options[ARALCO_SLUG . '_field_enable_points'] == '1';

?>

<p>
	<?php
	printf(
		/* translators: 1: user display name 2: logout url */
		__( 'Hello %1$s (not %1$s? <a href="%2$s">Log out</a>)', 'woocommerce' ),
		'<strong>' . esc_html( $current_user->display_name ) . '</strong>',
		esc_url( wc_logout_url() )
	);
	?>
</p>

<?php if($points_enabled) {

    $cached_user_aralco_data = get_user_meta($current_user->ID, 'aralco_data', true);
    $user_aralco_data = Aralco_Connection_Helper::getCustomer('Id', $cached_user_aralco_data['id']);
    if($user_aralco_data instanceof WP_Error || $user_aralco_data == false){
        $user_aralco_data = $cached_user_aralco_data;
        echo '<p>' . __('There was a problem refreshing your points.', ARALCO_SLUG) . '</p>';
    } else {
        $cached_user_aralco_data['points'] = $user_aralco_data['points'] ?? 0;
        update_user_meta($current_user->ID, 'aralco_data', $cached_user_aralco_data);
    }

    $points = 0;
    if(isset($user_aralco_data) && isset($user_aralco_data['points'])){
        $points = $user_aralco_data['points'];
    }

    ?>
    <p>You have <b><?php echo number_format_i18n($points) ?></b> points.</p>
<?php } ?>

<p>
	<?php
    $text = __( 'From your account dashboard you can view your <a href="%1$s">recent orders</a>, manage your <a href="%2$s">shipping and billing addresses</a>, and <a href="%3$s">edit your password and account details</a>.', 'woocommerce' );
    if($use_quote) {
        $text = __( 'From your account dashboard you can view your <a href="%1$s">recent quotes</a>, manage your <a href="%2$s">shipping and billing addresses</a>, and <a href="%3$s">edit your password and account details</a>.', ARALCO_SLUG );
    }

	printf(
        $text,
		esc_url( wc_get_endpoint_url( 'orders' ) ),
		esc_url( wc_get_endpoint_url( 'edit-address' ) ),
		esc_url( wc_get_endpoint_url( 'edit-account' ) )
	);
	?>
</p>

<?php
	/**
	 * My Account dashboard.
	 *
	 * @since 2.6.0
	 */
	do_action( 'woocommerce_account_dashboard' );

	/**
	 * Deprecated woocommerce_before_my_account action.
	 *
	 * @deprecated 2.6.0
	 */
	do_action( 'woocommerce_before_my_account' );

	/**
	 * Deprecated woocommerce_after_my_account action.
	 *
	 * @deprecated 2.6.0
	 */
	do_action( 'woocommerce_after_my_account' );

/* Omit closing PHP tag at the end of PHP files to avoid "headers already sent" issues. */
