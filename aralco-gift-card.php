<?php
require_once(ABSPATH.'wp-admin/includes/plugin.php');

class Aralco_Gift_Cards {
    public function __construct(){
        add_filter('woocommerce_gc_account_session_timeout_minutes', function(){return 0;});
        add_action('template_redirect', array($this, 'process_redeem'), 9, 1);
        add_action('wc_ajax_apply_gift_card_to_session', array($this, 'apply_gift_card_to_session'), 9, 1);
        add_action('woocommerce_after_checkout_validation', array($this, 'update_giftcard_totals'), 10, 2);
        add_action('wp_enqueue_scripts', array($this, 'include_mask_js'));
    }

    /**
     * Process the FE redeem form.
     *
     * @return void
     */
    public function process_redeem() {
        if (!is_plugin_active('woocommerce-gift-cards/woocommerce-gift-cards.php')) return;

        if (!empty($_POST) && !empty($_POST['wc_gc_redeem_save'])) {

            if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce(wc_clean($_REQUEST['_wpnonce']), 'customer_redeems_gift_card')) {
                wc_add_notice(__('Failed to redeem your gift card. Please try again later, or get in touch with us for assistance.', 'woocommerce-gift-cards'), 'error');
                wp_safe_redirect(add_query_arg(array()));
                exit;
            }

            $code = !empty($_POST['wc_gc_redeem_code'])? sanitize_text_field(wp_unslash($_POST['wc_gc_redeem_code'])) : '';

            if (!$code || preg_match('/^([a-zA-Z0-9]{4}[\-]){3}[a-zA-Z0-9]{4}$/', $code) !== 1) {
                wc_add_notice(__('Please enter a valid gift card code.', 'woocommerce-gift-cards'), 'error');
                wp_safe_redirect(add_query_arg(array()));
                exit;
            } else if (strlen($code) !== 19) {
                wc_add_notice(__('Gift card code must be 19 characters.', 'woocommerce-gift-cards'), 'error');
                wp_safe_redirect(add_query_arg(array()));
                exit;
            }

            $gc_results = WC_GC()->db->giftcards->query(array(
                'return' => 'objects',
                'code' => $code,
                'limit' => 1
            ));

            if (count($gc_results) === 0) {
                $agc = Aralco_Connection_Helper::getGiftCardAmount(str_replace('-', '', $code));
                if (is_array($agc) && isset($agc['balance'])) {
                    if ($agc['balance'] <= 0) {
                        wc_add_notice(__('Gift card has no remaining balance.', 'woocommerce-gift-cards'), 'error');
                    } else {
                        $email = is_user_logged_in()? wp_get_current_user()->user_email : get_option('admin_email');
                        $name = is_user_logged_in()? wp_get_current_user()->display_name : __('Guest', ARALCO_SLUG);
                        try {
                            $result = WC_GC()->db->giftcards->add(array(
                                'is_virtual' => 'off',
                                'code' => $code,
                                'order_id' => -1,
                                'order_item_id' => -1,
                                'recipient' => $email,
                                'sender' => $name,
                                'sender_email' => $email,
                                'balance' => $agc['balance'],
                                'expire_date' => 0
                            ));
                            if ($result) {
                                $gc_results = array(WC_GC()->db->giftcards->get($result));
                            }
                        } catch (Exception $e) {
                            wc_add_notice(__('Failed to import gift card from Aralco: ', ARALCO_SLUG) . $e->getMessage(), 'error');
                        }
                    }
                }
            }

            if (count($gc_results) > 0) {

                $gc_data = array_shift($gc_results);
                $gc = new WC_GC_Gift_Card($gc_data);

                try {

                    $gc->redeem(get_current_user_id());
                    // Re-init cart giftcards.
                    WC_GC()->cart->destroy_cart_session();
                    wc_add_notice(__('The gift card has been added to your account.', 'woocommerce-gift-cards'));
                } catch (Exception $e) {
                    wc_add_notice($e->getMessage(), 'error');
                }
            } else {
                wc_add_notice(__('Invalid gift card code.', 'woocommerce-gift-cards'), 'error');
            }

            wp_safe_redirect(add_query_arg(array()));
            exit;
        }
    }

    /**
     * Add gift card order item.
     *
     * @since 1.2.0
     *
     * @return void
     */
    public function apply_gift_card_to_session() {
        check_ajax_referer( 'redeem-card', 'security' );
        $args = wc_clean( $_POST );

        if (!wc_gc_is_ui_disabled() || WC_GC()->cart->cart_contains_gift_card() || empty($args) || !isset($args['wc_gc_cart_code']) ||
            preg_match('/^([a-zA-Z0-9]{4}[\-]){3}[a-zA-Z0-9]{4}$/', $args['wc_gc_cart_code']) !== 1) {
            return;
        }

        $code = $args['wc_gc_cart_code'];
        $results = WC_GC()->db->giftcards->query( array( 'return' => 'objects', 'code' => $code, 'limit' => 1 ));

        if(count($results) > 0) return; // Gift Card already exists. Nothing to do.

        $agc = Aralco_Connection_Helper::getGiftCardAmount(str_replace('-', '', $code));
        if (is_array($agc) && isset($agc['balance'])) {
            if ($agc['balance'] > 0) {
                $email = is_user_logged_in()? wp_get_current_user()->user_email : get_option('admin_email');
                $name = is_user_logged_in()? wp_get_current_user()->display_name : __('Guest', ARALCO_SLUG);
                try {
                    $result = WC_GC()->db->giftcards->add(array(
                        'is_virtual' => 'off',
                        'code' => $code,
                        'order_id' => -1,
                        'order_item_id' => -1,
                        'recipient' => $email,
                        'sender' => $name,
                        'sender_email' => $email,
                        'balance' => $agc['balance'],
                        'expire_date' => 0
                    ));
                } catch (Exception $e) {
                    //...
                }
            }
        }
    }

    public function update_giftcard_totals($fields, $errors) {
        if (!is_plugin_active('woocommerce-gift-cards/woocommerce-gift-cards.php')) return;

        $giftcards = WC_GC()->giftcards->get();

        if(count($giftcards) > 0){

            /** @var WC_GC_Gift_Card_Data $gcd */
            foreach ($giftcards as $gc){
//                $errors->add('error', '<pre>' . print_r($gc, true) . '</pre>');
                $gcd = $gc['giftcard'];
                $old_bal = $gcd->get_balance();
                $agc = Aralco_Connection_Helper::getGiftCardAmount(str_replace('-', '', $gcd->get_code()));
                if (is_array($agc) && isset($agc['balance'])) {
                    if (abs($old_bal - $agc['balance']) > 0.01){
                        $gcd->set_balance($agc['balance']);
                        $gcd->save();

                        $errors->add('error', __('One or more of your giftcard\'s balances have changed. Pleases review your details and the place the order again.', ARALCO_SLUG));
                    }
                } else {
                    $errors->add('error', sprintf(__('The gift card \'%s\' does not exist in Aralco and cannot be used.', ARALCO_SLUG), $gcd->get_code()));
                }
            }


        }
    }

    public function include_mask_js() {
        wp_enqueue_script( 'inputmask', plugin_dir_url( __FILE__ ) . 'assets/js/jquery.inputmask.min.js', array( 'jquery' ));
        wp_enqueue_script( 'inputmask-checkout', plugin_dir_url( __FILE__ ) . 'assets/js/inputmask-checkout.js', array( 'jquery' ));
    }
}

new Aralco_Gift_Cards();