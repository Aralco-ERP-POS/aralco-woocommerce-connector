<?php

defined( 'ABSPATH' ) or die(); // Prevents direct access to file.

function aralco_shipping_method() {
    class Aralco_Pickup_Shipping_Method extends WC_Shipping_Method {
        /**
         * Constructor for your shipping class
         *
         * @access public
         * @return void
         */
        public function __construct() {
            $this->id = ARALCO_SLUG . '_pickup_shipping';
            $this->method_title = __('Aralco Pickup from Store', ARALCO_SLUG);
            $this->method_description = __('Allows customers to pickup their purchase from a store location of their choice.', ARALCO_SLUG);

            $this->init();

            $this->enabled = isset($this->settings['enabled'])? $this->settings['enabled'] : 'yes';
            $this->title = isset($this->settings['title'])? $this->settings['title'] : __('Local Pickup', ARALCO_SLUG);

            parent::__construct();
        }

        /**
         * Init your settings
         *
         * @access public
         * @return void
         */
        function init() {
            // Load the settings API
            $this->init_form_fields();
            $this->init_settings();

            // Save settings in admin if you have any defined
            add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
        }

        /**
         * Define settings field for this shipping
         * @return void
         */
        function init_form_fields() {

            // We will add our settings here

            $stores = get_option(ARALCO_SLUG . '_stores', array());
            $store_list = array();
            $default_store_list = array();

            foreach ($stores as $key => $store){
                $store_list[$store['Id']] = $store['Code'] . ' - ' . $store['Name'];
                $default_store_list[] = (string)$store['Id'];
            }

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable', ARALCO_SLUG),
                    'type' => 'checkbox',
                    'description' => __('Enable local pickup.', ARALCO_SLUG),
                    'default' => 'yes'
                ),

                'title' => array(
                    'title' => __('Title', ARALCO_SLUG),
                    'type' => 'text',
                    'description' => __('Title to be display on site', ARALCO_SLUG),
                    'default' => __('Local Pickup', ARALCO_SLUG)
                ),

                'eligibleStores' => array(
                    'title' => __('Eligible Stores', ARALCO_SLUG),
                    'type' => 'multiselect',
                    'description' => __('Which stores are available for local pickup', ARALCO_SLUG),
                    'default' => $default_store_list,
                    'options' => $store_list
                ),
            );
        }

        public function calculate_shipping($package = array()) {
            $eligible_stores = $this->settings['eligibleStores'];
            if(!isset($eligible_stores)) $eligible_stores = array();
            $stores = get_option(ARALCO_SLUG . '_stores', array());

            foreach ($eligible_stores as $key => $eligible_store){
                $store_key = array_search($eligible_store, array_column($stores, 'Id'));
                if($store_key == false) continue;

                $store = $stores[$store_key];

                $rate = array(
                    'id' => $this->id . '_store_' . $store['Id'],
                    'label' => $this->title . ' - ' . $store['Name'],
                    'meta_data' => array('aralco_id' => $store['Id'])
                );
                $this->add_rate($rate);
            }
        }
    }
}

add_action('woocommerce_shipping_init', 'aralco_shipping_method');

function add_aralco_shipping_method($methods) {
    $methods[ARALCO_SLUG . '_pickup_shipping'] = 'Aralco_Pickup_Shipping_Method';
    return $methods;
}

add_filter('woocommerce_shipping_methods', 'add_aralco_shipping_method');

function aralco_validate_order($posted) {
    $packages = WC()->shipping->get_packages();

    $chosen_methods = WC()->session->get('chosen_shipping_methods');

    if (is_array($chosen_methods)) {
        $applicable_methods = array_filter($chosen_methods, function($method){
            return strpos($method, ARALCO_SLUG . '_pickup_shipping') === 0;
        });

        if(count($applicable_methods) > 0){

            $aralco_ids = array();

            foreach(WC()->cart->cart_contents as $key => $cartItem){
                $aralco_id = get_post_meta($cartItem['product_id'], '_aralco_id', true);
                if($aralco_id != false) $aralco_ids[] = $aralco_id;
            }

            $store_id = $packages[0]['rates'][$applicable_methods[0]]->get_meta_data()['aralco_id'];

//            $results = Aralco_Connection_Helper::getProductStockByIDs($aralco_ids);


            $products_to_update = [];

            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                /** @var WC_Product $product_obj */
                $product_obj = $cart_item['data'];
                $grids = get_post_meta($product_obj->get_id(), '_aralco_grids', true);
                $aralco_product_id = get_post_meta($product_obj->get_id(), '_aralco_id', true);
                if($aralco_product_id == false) {
                    $aralco_product_id = get_post_meta($product_obj->get_parent_id(), '_aralco_id', true);
                }
                $products_to_update[] = array(
                    'ProductId' => $aralco_product_id,
                    'StoreId' => $store_id,
                    'SerialNumber' => '', //TODO: Fill in later
                    'GridId1' => (is_array($grids) && isset($grids['gridId1']) && !empty($grids['gridId1'])) ? $grids['gridId1'] : null,
                    'GridId2' => (is_array($grids) && isset($grids['gridId2']) && !empty($grids['gridId2'])) ? $grids['gridId2'] : null,
                    'GridId3' => (is_array($grids) && isset($grids['gridId3']) && !empty($grids['gridId3'])) ? $grids['gridId3'] : null,
                    'GridId4' => (is_array($grids) && isset($grids['gridId4']) && !empty($grids['gridId4'])) ? $grids['gridId4'] : null,
                );
            }

            $inventory = Aralco_Connection_Helper::getProductStockByIDs($products_to_update);

            $products_out_of_stock = array();
            $products_short_in_stock = array();

            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                /** @var WC_Product $product_obj */
                $product_obj = $cart_item['data'];
                $grids = get_post_meta($product_obj->get_id(), '_aralco_grids', true);
                if($grids == false) $grids = array();
                $id_to_use = $product_obj->get_parent_id();
                if($id_to_use <= 0) $id_to_use = $product_obj->get_id();
                $aralco_product_id = get_post_meta($id_to_use, '_aralco_id', true);

                $grids['gridId1'] = $grids['gridId1'] ?? 0;
                $grids['gridId2'] = $grids['gridId2'] ?? 0;
                $grids['gridId3'] = $grids['gridId3'] ?? 0;
                $grids['gridId4'] = $grids['gridId4'] ?? 0;

                $stock_array = array_values(array_filter($inventory, function($item) use ($grids, $aralco_product_id){
                    return $item['ProductID'] == $aralco_product_id &&
                        (string)$item['GridID1'] == (string)$grids['gridId1'] &&
                        (string)$item['GridID2'] == (string)$grids['gridId2'] &&
                        (string)$item['GridID3'] == (string)$grids['gridId3'] &&
                        (string)$item['GridID4'] == (string)$grids['gridId4'];
                }));

                if (count($stock_array) <= 0) {
                    $stock = array('Available' => 0);
                } else {
                    $stock = $stock_array[0];
                }

                $name = $product_obj->get_name();
                if(count($cart_item['variation']) > 0){
                    /** @var WC_Product_Variation $product_obj */
                    $name .= ' - ' . $product_obj->get_attribute_summary();
                }

                if($stock['Available'] <= 0) {
                    $products_out_of_stock[] = $name;
                } else if ($stock['Available'] < $cart_item['quantity']) {
                    $sell_by = get_post_meta($product_obj->get_id(), '_aralco_sell_by', true);
                    if($sell_by == false) {
                        $sell_by = array(
                            "code" => "",
                            "multi" => 1,
                            "decimals" => 0
                        );
                    }

                    if($sell_by['multi'] <= 1) $sell_by['decimals'] = 0;

                    $products_short_in_stock[] = array(
                        'name' => $name,
                        'missing' => number_format((($cart_item['quantity'] - $stock['Available']) / $sell_by['multi']), $sell_by['decimals']) . $sell_by['code'],
                    );
                }
            }

            if(count($products_out_of_stock) > 0 || count($products_short_in_stock) > 0) {
                $message = '';

                if(count($products_out_of_stock) > 0) {
                    $message .= 'The following products are out of stock at the selected store:<ul><li>';
                    $message .= implode('</li><li>', $products_out_of_stock) . '</li></ul>';
                }

                if(count($products_short_in_stock) > 0) {
                    $message .= 'The following products do not have enough inventory to satisfy your order at the selected store:<ul>';
                    foreach ($products_short_in_stock as $key => $item) {
                        $message .= '<li>' . $item['name'] . ' - ' . $item['missing'] . ' Short</li>';
                    }
                    $message .= '</ul>';
                }

                $message .= "Please remove those items from your cart or select a different pickup store.";

                $message_type = 'error';
                if (!wc_has_notice($message, $message_type)) {
                    wc_add_notice($message, $message_type);
                }
            }

        }
    }
}

add_action('woocommerce_review_order_before_cart_contents', 'aralco_validate_order', 10);
add_action('woocommerce_after_checkout_validation', 'aralco_validate_order', 10);

function aralco_prevent_stock_reduction_on_pickup_in_store($can_reduce_stock, $order){
    if(array_values($order->get_shipping_methods())[0]->get_method_id() == ARALCO_SLUG . '_pickup_shipping'){
        return false;
    }
    return $can_reduce_stock;
}

add_filter('woocommerce_can_reduce_order_stock', 'aralco_prevent_stock_reduction_on_pickup_in_store', 10, 2);