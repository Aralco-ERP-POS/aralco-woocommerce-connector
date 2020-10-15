<?php

add_action('plugins_loaded', 'aralco_init_gateway_class');
add_filter('woocommerce_payment_gateways', 'aralco_add_gateway_class');

function aralco_init_gateway_class() {
    class Aralco_Payment_Gateway extends WC_Payment_Gateway {
        function __construct() {
            $this->id = 'aralco_account_credit';
            $this->method_title = 'Account Credit';
            $this->method_description = 'Allows customers to pay using account credit.';

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');

            if (!is_user_logged_in()) {
                $this->enabled = false;
            }

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', ARALCO_SLUG),
                    'type' => 'checkbox',
                    'label' => __('Enable Aralco payment on account', ARALCO_SLUG),
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => __('Title', ARALCO_SLUG),
                    'type' => 'text',
                    'description' => __('Payment method title that the customer will see on your website.', ARALCO_SLUG),
                    'default' => __('Account Credit', ARALCO_SLUG),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description', ARALCO_SLUG),
                    'type' => 'textarea',
                    'description' => __('Payment method description that the customer will see on your website. Use {limit} and {balance} as placeholders for the customer\'s credit limit and balance.', ARALCO_SLUG),
                    'desc_tip' => true,
                    'default' => __('Pay with your account credit.

Limit: {limit}
Balance: {balance}', ARALCO_SLUG)
                )
            );
        }

        function process_payment($order_id) {
            do_action('aralco_refresh_user_data', wp_get_current_user()->nickname);

            global $woocommerce;
            $order = new WC_Order($order_id);

            if (!is_user_logged_in()) {
                $order->update_status('failed', '');
                $error_message = 'This payment option is available to logged in users only.';
                wc_add_notice(__('Payment error: ', ARALCO_SLUG) . $error_message, 'error');
                return array();
            }

            $aralco_user = get_user_meta(get_current_user_id(), 'aralco_data', true);

            if (!is_array($aralco_user)) {
                $order->update_status('failed', '');
                $error_message = 'There was an issue retrieving you customer information. Please try again later.';
                wc_add_notice(__('Payment error: ', ARALCO_SLUG) . $error_message, 'error');
                return array();
            }

            $outstanding_invoices = Aralco_Connection_Helper::getInvoice($aralco_user['id']);
            $creditAlertAmountDefault = Aralco_Connection_Helper::getSetting('CreditTimeLimitMinAmount');
            $creditAlertDayDefault = Aralco_Connection_Helper::getSetting('CreditTimeLimitDay');

            if($outstanding_invoices instanceof WP_Error) $outstanding_invoices = [];
            $creditAlertAmountDefault = ($creditAlertAmountDefault instanceof WP_Error)? 0 : doubleval($creditAlertAmountDefault['Value']);
            $creditAlertDayDefault = ($creditAlertDayDefault instanceof WP_Error)? 0 : intval($creditAlertDayDefault['Value']);

            $aralco_user['creditLimit'] = $aralco_user['creditLimit'] ?? 0;
            $aralco_user['accountBalance'] = $aralco_user['accountBalance'] ?? 0;
            $aralco_user['creditAlertDay'] = $aralco_user['creditAlertDay'] ?? $creditAlertDayDefault ?? 0;
            $aralco_user['creditAlertAmount'] = $aralco_user['creditAlertAmount'] ?? $creditAlertAmountDefault ?? 0;

            if ($aralco_user['creditLimit'] <= 0 && $aralco_user['accountBalance'] >= 0) {
                $order->update_status('failed', '');
                $error_message = 'You have no credit. An account credit or a limit greater then zero is required.';
                wc_add_notice(__('Payment error: ', ARALCO_SLUG) . $error_message, 'error');
                return array();
            }

            if(is_array($outstanding_invoices) && count($outstanding_invoices) > 0){
                $bal = array_sum(array_column($outstanding_invoices, 'balance'));
                if($aralco_user['creditAlertAmount'] > 0 && $bal > $aralco_user['creditAlertAmount']) {
                    if($aralco_user['creditAlertDay'] > 0) {
                        foreach ($outstanding_invoices as $invoice) {
                            if (new DateTime($invoice['date']) < (new DateTime())->modify('-' . $aralco_user['creditAlertDay'] . ' days')) {
                                $order->update_status('failed', '');
                                $error_message = 'You have an overdue credit balance. This must be paid first.';
                                wc_add_notice(__('Payment error: ', ARALCO_SLUG) . $error_message, 'error');
                                return array();
                            }
                        }
                    } else {
                        $order->update_status('failed', '');
                        $error_message = 'You have an outstanding credit balance. This must be paid first.';
                        wc_add_notice(__('Payment error: ', ARALCO_SLUG) . $error_message, 'error');
                        return array();
                    }
                }
            }

            if (isset($aralco_user['accountBalance']) && $aralco_user['accountBalance'] > 0) {
                $order->update_status('failed', '');
                $error_message = 'You have an outstanding credit balance. This must be paid first.';
                wc_add_notice(__('Payment error: ', ARALCO_SLUG) . $error_message, 'error');
                return array();
            }

            $total = $order->get_total();
            $limit = doubleval($aralco_user['creditLimit']);
            $bal = doubleval(isset($aralco_user['accountBalance']) && $aralco_user['accountBalance'] > 0 ? $aralco_user['accountBalance'] : 0);
            if($bal + $total > $limit) {
                $order->update_status('failed', '');
                $error_message = 'You do not have enough credit to complete this transaction. You are short ' . wc_price($bal + $total - $limit) . ' in credit.';
                wc_add_notice(__('Payment error: ', ARALCO_SLUG) . $error_message, 'error');
                return array();
            }

            $aralco_user['accountBalance'] = $bal + $total;
            update_user_meta(get_current_user_id(), 'aralco_data', $aralco_user);

            $order->payment_complete();

            $woocommerce->cart->empty_cart();

            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
        }

        public function get_description() {
            do_action('aralco_refresh_user_data', wp_get_current_user()->nickname);
            $aralco_user = get_user_meta(get_current_user_id(), 'aralco_data', true);

            if(!is_array($aralco_user)) {
                return apply_filters('woocommerce_gateway_description', "Oops! We were unable to fetch information about your account. Please report this!", $this->id);
            }

            $limit = '<b>' . wc_price(isset($aralco_user['creditLimit']) && $aralco_user['creditLimit'] > 0 ? $aralco_user['creditLimit'] : 0) . '</b>';
            $bal = '<b>' . wc_price(isset($aralco_user['accountBalance']) && $aralco_user['accountBalance'] > 0 ? $aralco_user['accountBalance'] : 0) . '</b>';
            $description = str_replace(['{limit}', '{balance}'], [$limit, $bal], $this->description);

            return apply_filters('woocommerce_gateway_description', $description, $this->id);
        }
    }
}

function aralco_add_gateway_class($methods) {
    $methods[] = 'Aralco_Payment_Gateway';
    return $methods;
}

