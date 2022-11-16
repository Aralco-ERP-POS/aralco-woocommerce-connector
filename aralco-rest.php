<?php

add_action('rest_api_init', 'aralco_register_rest_routs');

function aralco_register_rest_routs() {
    register_rest_route(
        'aralco-wc/v1',
        '/widget/filters/(?P<department>[a-z0-9\-]+)',
        array(
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => 'aralco_get_filters_for_department'
        )
    );

    register_rest_route(
        'aralco-wc/v1',
        '/admin/new-sync',
        array(
            'methods' => 'GET',
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'callback' => 'aralco_new_sync'
        )
    );

    register_rest_route(
        'aralco-wc/v1',
        '/admin/continue-sync',
        array(
            'methods' => 'GET',
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'callback' => 'aralco_continue_sync'
        )
    );

    register_rest_route(
        'aralco-wc/v1',
        '/cart/apply-points',
        array(
            'methods' => 'GET',
            'permission_callback' => function() {
                return is_user_logged_in();
            },
            'callback' => 'aralco_apply_points_to_cart'
        )
    );

    register_rest_route(
        'aralco-wc/v1',
        '/cart/remove-points',
        array(
            'methods' => 'GET',
            'permission_callback' => function() {
                return is_user_logged_in();
            },
            'callback' => 'aralco_remove_points_to_cart'
        )
    );
}

function aralco_new_sync() {
    if(!isset($_GET['ignore'])) {
        $chunk_data = get_option(ARALCO_SLUG . '_chunking_data');
        if (is_array($chunk_data)) { // Sync was already started, send the information to resume it.
            return [
                'resume' => '1',
                'statusText' => 'Resuming Sync...',
                'progress' => $chunk_data['progress'],
                'total' => $chunk_data['total'],
                'nonce' => wp_create_nonce('wp_rest')
            ];
        }
    }

    $everything = isset($_GET['force-sync-now']);

    $chunk_data = array(
        'total' => 1,
        'progress' => 0,
        'queue' => []
    );

    if(isset($_GET['sync-departments'])){
        $chunk_data['queue'][] = 'departments_init';
        $chunk_data['queue'][] = 'departments';
    }
    if(isset($_GET['sync-groupings'])){
        $chunk_data['queue'][] = 'groupings_init';
        $chunk_data['queue'][] = 'groupings';
    }
    if(isset($_GET['sync-grids'])){
        $chunk_data['queue'][] = 'grids_init';
        $chunk_data['queue'][] = 'grids';
    }
    if(isset($_GET['sync-suppliers'])){
        $chunk_data['queue'][] = 'suppliers_init';
        $chunk_data['queue'][] = 'suppliers';
    }
    if(isset($_GET['sync-products'])){
        $chunk_data['queue'][] = 'products_init';
        $chunk_data['queue'][] = 'products';
    }
    if(isset($_GET['sync-stock'])){
        $chunk_data['queue'][] = 'stock_init';
        $chunk_data['queue'][] = 'stock';
    }
    if(isset($_GET['sync-customer-groups'])){
        $chunk_data['queue'][] = 'customer_groups_init';
        $chunk_data['queue'][] = 'customer_groups';
    }
    if(isset($_GET['sync-taxes'])){
        $chunk_data['queue'][] = 'taxes_init';
        $chunk_data['queue'][] = 'taxes';
    }
    if(isset($_GET['sync-stores'])){
        $chunk_data['queue'][] = 'stores_init';
        $chunk_data['queue'][] = 'stores';
    }

    $lastSync = get_option(ARALCO_SLUG . '_last_sync');
    if(!isset($lastSync) || $lastSync === false || $everything){
        $lastSync = date("Y-m-d\TH:i:s", mktime(0, 0, 0, 1, 1, 1900));
    }

    $server_time = Aralco_Connection_Helper::getServerTime();
    if($server_time instanceof WP_Error){
        return $server_time;
    } else if (is_array($server_time) && isset($server_time['UtcOffset'])) {
        $sign = ($server_time['UtcOffset'] > 0) ? '+' : '-';
        $server_time['UtcOffset'] -= 60; // Adds an extra hour to the sync to adjust for server de-syncs
        if ($server_time['UtcOffset'] < 0) {
            $server_time['UtcOffset'] = $server_time['UtcOffset'] * -1;
        }
        $temp = DateTime::createFromFormat('Y-m-d\TH:i:s', $lastSync);
        $temp->modify($sign . $server_time['UtcOffset'] . ' minutes');
        $chunk_data['last_sync'] = $temp->format('Y-m-d\TH:i:s');
    }

    update_option(ARALCO_SLUG . '_chunking_data', $chunk_data, 'no');
    return [
        'statusText' => 'Starting New Sync...',
        'progress' => $chunk_data['progress'],
        'total' => $chunk_data['total'],
        'nonce' => wp_create_nonce('wp_rest')
    ];
}

function aralco_continue_sync() {
    $chunk_data = get_option(ARALCO_SLUG . '_chunking_data');
    $options = get_option(ARALCO_SLUG . '_options');
    if (!is_array($chunk_data)) { // No sync was started. Called out of order?
        return new WP_Error(ARALCO_SLUG . '_nothing_to_continue', 'There is no sync to continue. Please start one first.', ['status' => 400]);
    }

    $message = 'Unknown status';
    $complete = 0;
    $chunked_amount = intval($options[ARALCO_SLUG . '_field_sync_chunking']);
    $warnings = [];

    if(count($chunk_data['queue']) > 0) {
        switch ($chunk_data['queue'][0]) {
            case 'departments_init':
                $departments = Aralco_Connection_Helper::getDepartments();
                if($departments instanceof WP_Error) return $departments;
                $chunk_data['data'] = $departments;
                $chunk_data['total'] = count($departments);
                $chunk_data['progress'] = 0;
                $message = 'Preparing to sync departments...';
                array_shift($chunk_data['queue']);
                break;
            case 'departments':
                $left = count($chunk_data['data']);
                if ($chunked_amount > $left) $chunked_amount = $left;
                if($chunked_amount > 0){
                    $chunk_to_process = array_slice($chunk_data['data'], 0, $chunked_amount);
                    $result = Aralco_Processing_Helper::sync_departments($chunk_to_process);
                    if($result instanceof WP_Error) return $result;
                    $chunk_data['data'] = array_slice($chunk_data['data'], $chunked_amount);
                }
                $message = 'Syncing departments...';
                $chunk_data['progress'] = $chunk_data['total'] - $left + $chunked_amount;
                if(count($chunk_data['data']) <= 0){
                    array_shift($chunk_data['queue']);
                }
                break;
            case 'groupings_init':
                $chunk_data['data'] = [];
                $chunk_data['page'] = 0;
                $chunk_data['number_of_pages'] = 1;
                $chunk_data['number_of_records'] = 1;
                $chunk_data['total'] = 1;
                $chunk_data['progress'] = 0;
                $message = 'Preparing to sync groupings...';
                array_shift($chunk_data['queue']);
                break;
            case 'groupings':
                $chunk_data['page']++;
                if($chunk_data['page'] <= $chunk_data['number_of_pages']) {
                    $groupings_result = Aralco_Connection_Helper::getGroupings($chunk_data['number_of_records'] <= 1, $chunk_data['page'], $chunked_amount);
                    if($groupings_result instanceof WP_Error) return $groupings_result;
                    $chunk_to_process = $groupings_result['data'];
                    if($groupings_result['number_of_records'] > 0) {
                        $chunk_data['number_of_records'] = $groupings_result['number_of_records'];
                    }
                    if($groupings_result['number_of_records'] > 0) {
                        $chunk_data['number_of_records'] = $groupings_result['number_of_records'];
                    }
                    $result = Aralco_Processing_Helper::sync_groupings($chunk_to_process);
                    if($result instanceof WP_Error) return $result;
                }
                $message = 'Syncing groupings...';
                $chunk_data['progress'] = $chunk_data['page'] * $chunked_amount;
                $chunk_data['total'] = $chunk_data['number_of_records'];
                if($chunk_data['progress'] >= $chunk_data['total']) {
                    $chunk_data['progress'] = $chunk_data['total'];
                    array_shift($chunk_data['queue']);
                }
                break;
            case 'grids_init':
                $grids = Aralco_Connection_Helper::getGrids();
                if($grids instanceof WP_Error) return $grids;
                $chunk_data['data'] = $grids;
                $chunk_data['total'] = count($grids);
                $chunk_data['progress'] = 0;
                $message = 'Preparing to sync grids...';
                array_shift($chunk_data['queue']);
                break;
            case 'grids':
                $left = count($chunk_data['data']);
                if ($chunked_amount > $left) $chunked_amount = $left;
                if($chunked_amount > 0){
                    $chunk_to_process = array_slice($chunk_data['data'], 0, $chunked_amount);
                    $result = Aralco_Processing_Helper::sync_grids($chunk_to_process);
                    if($result instanceof WP_Error) return $result;
                    $chunk_data['data'] = array_slice($chunk_data['data'], $chunked_amount);
                }
                $message = 'Syncing grids...';
                $chunk_data['progress'] = $chunk_data['total'] - $left + $chunked_amount;
                if(count($chunk_data['data']) <= 0){
                    array_shift($chunk_data['queue']);
                }
                break;
            case 'suppliers_init':
                $chunk_data['total'] = 1;
                $chunk_data['progress'] = 0;
                $message = 'Preparing to sync suppliers...';
                array_shift($chunk_data['queue']);
                break;
            case 'suppliers':
                $result = Aralco_Processing_Helper::sync_suppliers();
//                if($result instanceof WP_Error) return $result;
                $chunk_data['total'] = 1;
                $chunk_data['progress'] = 1;
                $message = 'Syncing suppliers...';
                array_shift($chunk_data['queue']);
                break;
            case 'products_init':
                $result = Aralco_Processing_Helper::process_disabled_products();
                if($result instanceof WP_Error) return $result;
                $result = Aralco_Processing_Helper::process_shipping_product();
                if($result instanceof WP_Error) return $result;
                $result = Aralco_Processing_Helper::process_giftcard_product();
                if($result instanceof WP_Error) return $result;
                $chunk_data['data'] = [];
                $chunk_data['page'] = 0;
                $chunk_data['number_of_pages'] = 1;
                $chunk_data['number_of_records'] = 1;
                $chunk_data['total'] = 1;
                $chunk_data['progress'] = 0;
                $message = 'Preparing to sync products...';
                array_shift($chunk_data['queue']);
                break;
            case 'products':
                $chunk_data['page']++;
                if($chunk_data['page'] <= $chunk_data['number_of_pages']) {
                    $product_update = Aralco_Connection_Helper::getProducts($chunk_data['last_sync'], $chunk_data['page'], $chunked_amount);
                    if($product_update instanceof WP_Error) return $product_update;
                    $chunk_to_process = $product_update['data'];
                    $chunk_data['number_of_pages'] = $product_update['number_of_pages'];
                    $chunk_data['number_of_records'] = $product_update['number_of_records'];
                    $result = Aralco_Processing_Helper::sync_products(false, $chunk_to_process);
                    if($result instanceof WP_Error) return $result;
                    if(is_array($result)){
                        foreach ($result as $error){
                            if(in_array($error->get_error_code(), [
                                ARALCO_SLUG . '_dimension_not_enabled',
                                ARALCO_SLUG . '_taxonomy_missing',
                                ARALCO_SLUG . '_term_missing'
                            ])){
                                $warnings[] = $error;
                            } else {
                                return $error;
                            }
                        }
                    }
                }
                $message = 'Syncing products...';
                $chunk_data['progress'] = $chunk_data['page'] * $chunked_amount;
                $chunk_data['total'] = $chunk_data['number_of_records'];
                if($chunk_data['progress'] >= $chunk_data['total']) {
                    $chunk_data['progress'] = $chunk_data['total'];
                    array_shift($chunk_data['queue']);
                }
                break;
            case 'stock_init':
                $stock = Aralco_Connection_Helper::getProductStock($chunk_data['last_sync']);
                if($stock instanceof WP_Error) return $stock;
                $chunk_data['data'] = $stock;
                $chunk_data['total'] = count($stock);
                $chunk_data['progress'] = 0;
                $message = 'Preparing to sync stock...';
                array_shift($chunk_data['queue']);
                break;
            case 'stock':
                $left = count($chunk_data['data']);
                if ($chunked_amount > $left) $chunked_amount = $left;
                if($chunked_amount > 0){
                    $chunk_to_process = array_slice($chunk_data['data'], 0, $chunked_amount);
                    $result = Aralco_Processing_Helper::sync_stock(null, false, $chunk_to_process);
                    if($result instanceof WP_Error) return $result;
                    $chunk_data['data'] = array_slice($chunk_data['data'], $chunked_amount);
                }
                $message = 'Syncing stock...';
                $chunk_data['progress'] = $chunk_data['total'] - $left + $chunked_amount;
                if(count($chunk_data['data']) <= 0){
                    array_shift($chunk_data['queue']);
                }
                break;
            case 'customer_groups_init':
                $chunk_data['total'] = 1;
                $chunk_data['progress'] = 0;
                $message = 'Preparing to sync customer groupings...';
                array_shift($chunk_data['queue']);
                break;
            case 'customer_groups':
                $result = Aralco_Processing_Helper::sync_customer_groups();
                if($result instanceof WP_Error) return $result;
                $chunk_data['total'] = 1;
                $chunk_data['progress'] = 1;
                $message = 'Syncing customer groups...';
                array_shift($chunk_data['queue']);
                break;
            case 'taxes_init':
                $chunk_data['total'] = 1;
                $chunk_data['progress'] = 0;
                $message = 'Preparing to sync taxes...';
                array_shift($chunk_data['queue']);
                break;
            case 'taxes':
                $result = Aralco_Processing_Helper::sync_taxes();
                if($result instanceof WP_Error) return $result;
                $chunk_data['total'] = 1;
                $chunk_data['progress'] = 1;
                $message = 'Syncing taxes...';
                array_shift($chunk_data['queue']);
                break;
            case 'stores_init':
                $chunk_data['total'] = 1;
                $chunk_data['progress'] = 0;
                $message = 'Preparing to sync stores...';
                array_shift($chunk_data['queue']);
                break;
            case 'stores':
                $result = Aralco_Processing_Helper::sync_stores();
                if($result instanceof WP_Error) return $result;
                $chunk_data['total'] = 1;
                $chunk_data['progress'] = 1;
                $message = 'Syncing stores...';
                array_shift($chunk_data['queue']);
                break;
        }
    }

    if(count($chunk_data['queue']) <= 0) {
        $complete = 1;
        $message = "Done!";
        delete_option(ARALCO_SLUG . '_chunking_data');
        update_option(ARALCO_SLUG . '_last_sync', date("Y-m-d\TH:i:s"));
    } else {
        update_option(ARALCO_SLUG . '_chunking_data', $chunk_data);
    }

    $toReturn = [
        'complete' => $complete,
        'statusText' => $message,
        'progress' => $chunk_data['progress'],
        'total' => $chunk_data['total'],
        'nonce' => wp_create_nonce('wp_rest')
    ];

    if(count($warnings) > 0){
        $toReturn['warnings'] = $warnings;
    }

    return $toReturn;
}

function aralco_get_filters_for_department($data) {
    $term = get_term_by('slug', $data['department'], 'product_cat');
    if ($term instanceof WP_Error) return $term;
    if ($term instanceof WP_Term) {

        $filters = array();
        $temp_filters = get_term_meta($term->term_id, 'aralco_filters', true);
        if (is_array($temp_filters)) {
            foreach ($temp_filters as $temp_filter) {
                array_push($filters, 'grouping-' . Aralco_Util::sanitize_name($temp_filter));
            }
        }

        $return = array();
        foreach ($filters as $filter) {
            /**
             * @var $the_taxonomy WP_Taxonomy
             */
            $the_taxonomy = get_taxonomy(wc_attribute_taxonomy_name($filter));
            $the_terms = get_terms(array(
                'taxonomy' => wc_attribute_taxonomy_name($filter)
                /*, 'hide_empty' => false*/
            ));

            $options = array();
            if ($the_taxonomy instanceof WP_Taxonomy && !($the_terms instanceof WP_Error)) {
                $options[''] = __('Any', ARALCO_SLUG);
                foreach ($the_terms as $the_term) {
                    $options[$the_term->slug] = $the_term->name;
                }
                if ($options > 1) {
                    $return['filter_' . $filter] = array(
                        'label' => $the_taxonomy->label,
                        'options' => $options
                    );
                }
            }
        }
        return $return;
    }
    return array();
}

function aralco_apply_points_to_cart() {
    $options = get_option(ARALCO_SLUG . '_options');
    $points_enabled = isset($options[ARALCO_SLUG . '_field_enable_points']) && $options[ARALCO_SLUG . '_field_enable_points'] == '1';
    if(!$points_enabled){
        return new WP_Error("points_disabled", __("Points are disabled.", ARALCO_SLUG), array('status' => 400));
    }

    $points_cache = get_user_meta(get_current_user_id(),'points_cache', true);
    if(!$points_cache) {
        $points_cache = array();
    }

    if(!isset($_GET['amount'])){
        return new WP_Error("amount_missing", __("Amount required.", ARALCO_SLUG), array('status' => 400));
    }
    $amount = $_GET['amount'];

    if($amount != floor($amount)){
        return new WP_Error("decimal_not_allowed", __("You must enter a whole number.", ARALCO_SLUG), array('status' => 400));
    }

    if($amount > $points_cache['total']){
        return new WP_Error("amount_over_total", __("You cannot use more points then the order total.", ARALCO_SLUG), array('status' => 400));
    }

    if($amount <= 0){
        return new WP_Error("amount_over_total", __("Must be greater then 0.", ARALCO_SLUG), array('status' => 400));
    }

    $user_aralco_data = get_user_meta(get_current_user_id(), 'aralco_data', true);

    $points = 0;
    if(isset($user_aralco_data) && isset($user_aralco_data['points'])){
        $points = $user_aralco_data['points'];
    }

    $points_multiplier = get_option(ARALCO_SLUG . '_points_exchange', 0);

    if($amount > $points * $points_multiplier){
        return new WP_Error("amount_over_total", __("Amount to use cannot exceed the order total", ARALCO_SLUG), array('status' => 400));
    }

    $points_cache['apply_to_order'] = $amount;
    update_user_meta(get_current_user_id(),'points_cache', $points_cache);
    return array('status' => 'OK');
}

function aralco_remove_points_to_cart() {
    $options = get_option(ARALCO_SLUG . '_options');
    $points_enabled = isset($options[ARALCO_SLUG . '_field_enable_points']) && $options[ARALCO_SLUG . '_field_enable_points'] == '1';
    if(!$points_enabled){
        return new WP_Error("points_disabled", __("Points are disabled.", ARALCO_SLUG), array('status' => 400));
    }

    $points_cache = get_user_meta(get_current_user_id(),'points_cache', true);
    if(!$points_cache) {
        $points_cache = array();
    }
    unset($points_cache["apply_to_order"]);
    update_user_meta(get_current_user_id(),'points_cache', $points_cache);
    return array('status' => 'OK');
}