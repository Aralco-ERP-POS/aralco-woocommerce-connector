<?php

defined( 'ABSPATH' ) or die(); // Prevents direct access to file.

/**
 * Class Aralco_Processing_Helper
 *
 * Provides helper methods that assist with registering and updating item in the WooCommerce Database.
 */
class Aralco_Processing_Helper {

    /**
     * Checks for changes to products in Aralco and pulls them into WooCommerce.
     *
     * Will only check for new changes that occurred since the last time the product sync was run.
     *
     * Config must be set before this method will function
     *
     * @param bool $everything passing true will pull every product instead of just changed products. THIS WILL TAKE
     * TIME
     * @return bool|int|WP_Error|WP_Error[] Returns a int count of the records modified if the update completed successfully,
     * false if no update was due, and a WP_Error instance if something went wrong.
     */
    static function sync_products($everything = false) {
        if ($everything) set_time_limit(3600); // Required for the amount of data that needs to be fetched
        try{
            $start_time = new DateTime();
        } catch(Exception $e) {}
        $options = get_option(ARALCO_SLUG . '_options');
        if(!isset($options[ARALCO_SLUG . '_field_api_location']) || !isset($options[ARALCO_SLUG . '_field_api_token'])){
            return new WP_Error(
                ARALCO_SLUG . '_messages',
                __('You must save the connection settings before you can sync any data.', ARALCO_SLUG)
            );
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
            $lastSync = $temp->format('Y-m-d\TH:i:s');
        }

        $result = Aralco_Connection_Helper::getProducts($lastSync);

        if(is_array($result)){ // Got Data
            // Sorting the array so items are processed in a consistent order (makes it easier for testing)
            usort($result, function($a, $b){
                if($a['ProductID'] == 2740) return -1;
                if($b['ProductID'] == 2740) return 1; //TODO: Remove when done testing
                return $a['ProductID'] <=> $b['ProductID'];
            });

            $count = 0;
            $errors = array();
            foreach($result as $item){
                $count++;
                $result = Aralco_Processing_Helper::process_item($item);
                if ($result instanceof WP_Error){
                    array_push($errors, $result);
                }
//                if ($count >= 20) break; //TODO: Remove when done testing
            }
            try{
                $time_taken = (new DateTime())->getTimestamp() - $start_time->getTimestamp();
                update_option(ARALCO_SLUG . '_last_sync_duration_products', $time_taken);
            } catch(Exception $e) {}

            if(count($errors) > 0){
                return $errors;
            }
            update_option(ARALCO_SLUG . '_last_sync', date("Y-m-d\TH:i:s"));
            update_option(ARALCO_SLUG . '_last_sync_product_count', $count);
            return true;
        }
        return $result;
    }

    /**
     * Processes a single item to add or update from Aralco into WooCommerce.
     *
     * @param array $item an associative array containing information about the Aralco product. See the Aralco API
     * documentation for the expected format.
     * @return bool|WP_Error
     */
    static function process_item($item) {
        $args = array(
            'posts_per_page'    => 1,
            'post_type'         => 'product',
            'meta_key'          => '_aralco_id',
            'meta_value'        => strval($item['ProductID'])
        );

        $results = (new WP_Query($args))->get_posts();
        $is_new = count($results) <= 0;

        if(!isset($item['Product']['Description'])){
            $item['Product']['Description'] = 'No Description';
        }
        if(!isset($item['Product']['SeoDescription'])){
            $item['Product']['SeoDescription'] = $item['Product']['Description'];
        }

        if(!$is_new){
            // Product already exists
            $post_id = $results[0]->ID;
        } else {
            // Product is new
            $post_id = wp_insert_post(array(
                'post_type'         => 'product',
                'post_status'       => 'publish',
                'comment_status'    => 'closed',
                'post_title'        => $item['Product']['Name'],
                'post_content'      => $item['Product']['Description'],
                'post_excerpt'      => $item['Product']['SeoDescription']
            ), true);
            if($post_id instanceof WP_Error){
                return $post_id;
            }
        }

        if($item['Product']['HasDimension']){
            wp_set_object_terms($post_id, 'variable', 'product_type', true);
        }

        $product = wc_get_product($post_id);

        if($is_new){
            $product->add_meta_data('_aralco_id', $item['ProductID'], true);
            try{
                $product->set_catalog_visibility('visible');
            } catch(Exception $e) {}
            $product->set_stock_status('instock');
            $product->set_total_sales(0);
            $product->set_downloadable(false);
            $product->set_virtual(false);
//            $product->set_manage_stock(false);
            $product->set_backorders('yes');
        }

        $product->set_manage_stock(false); // TODO: Remove later?
        $product->set_name($item['Product']['Name']);
        $product->set_description($item['Product']['Description']);
        $product->set_short_description($item['Product']['SeoDescription']);
        $product->set_regular_price($item['Product']['Price']);
        $product->set_sale_price(
            isset($item['Product']['DiscountPrice'])? $item['Product']['DiscountPrice'] : $item['Product']['Price']
        );
        $product->set_featured($item['Product']['Featured'] === true);
        if(isset($item['Product']['WebProperties']['Weight'])){
            $product->set_weight($item['Product']['WebProperties']['Weight']);
        }
        if(isset($item['Product']['WebProperties']['Length'])){
            $product->set_length($item['Product']['WebProperties']['Length']);
        }
        if(isset($item['Product']['WebProperties']['Width'])){
            $product->set_width($item['Product']['WebProperties']['Width']);
        }
        if(isset($item['Product']['WebProperties']['Height'])){
            $product->set_height($item['Product']['WebProperties']['Height']);
        }
        try{
            $product->set_sku($item['Product']['Code']);
        } catch(Exception $e) {}
//        update_post_meta($post_id, '_product_attributes', array());
//        update_post_meta($post_id, '_sale_price_dates_from', '');
//        update_post_meta($post_id, '_sale_price_dates_to', '');
        $product->set_price(isset($item['Product']['DiscountPrice'])? $item['Product']['DiscountPrice'] : $item['Product']['Price']);
//        update_post_meta($post_id, '_sold_individually', '');
//        wc_update_product_stock($post_id, $single['qty'], 'set');
//        update_post_meta( $post_id, '_stock', $single['qty'] );

        $slug = 'department-' . $item['Product']['DepartmentID'];
        $term = get_term_by( 'slug', $slug, 'product_cat' );
        if($term instanceof WP_Term){
            $product->set_category_ids(array($term->term_id));
        }

        $product->save();

        Aralco_Processing_Helper::process_item_images($post_id, $item);
//        Aralco_Processing_Helper::process_product_variations($product, $item); //TODO: Fix this
        return true;
    }

    static function process_item_images($post_id, $item){
        Aralco_Util::delete_all_attachments_for_post($post_id); // Removes all previously attached images
        delete_post_thumbnail($post_id); // Removes the thumbnail/featured image
        update_post_meta($post_id,'_product_image_gallery',''); // Removes the product gallery

        $images = Aralco_Connection_Helper::getImagesForProduct($item['ProductID']);
        $upload_dir = wp_upload_dir();

        foreach($images as $key => $image) {
            $type = '.jpg';
            if (strpos($image->mime_type, 'png') !== false) {
                $type = '.png';
            } else if (strpos($image->mime_type, 'gif') !== false) {
                $type = '.gif';
            }
            $image_name = 'product-' . $item['ProductID'] . $type;

            $unique_file_name = wp_unique_filename($upload_dir['path'], $image_name); // Generate unique name
            $filename = basename($unique_file_name); // Create image file name
            // Check folder permission and define file location
            if( wp_mkdir_p( $upload_dir['path'] ) ) {
                $file = $upload_dir['path'] . '/' . $filename;
            } else {
                $file = $upload_dir['basedir'] . '/' . $filename;
            }
            // Create the image file on the server
            file_put_contents($file, $image->image_data);
            // Check image file type
            $wp_filetype = wp_check_filetype( $filename, null );
            // Set attachment data
            $attachment = array(
                'post_mime_type' => $wp_filetype['type'],
                'post_title' => sanitize_file_name( $filename ),
                'post_content' => '',
                'post_status' => 'inherit'
            );
            // Create the attachment
            $attach_id = wp_insert_attachment( $attachment, $file, $post_id );
            // Include image.php
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            // Define attachment metadata
            $attach_data = wp_generate_attachment_metadata( $attach_id, $file );

            // Assign metadata to attachment
            wp_update_attachment_metadata( $attach_id, $attach_data );
            // asign to feature image
            if($key == 0) {
                // And finally assign featured image to post
                set_post_thumbnail( $post_id, $attach_id );
            }
            // assign to the product gallery
            if($key > 0) {
                // Add gallery image to product
                $attach_id_array = get_post_meta($post_id,'_product_image_gallery', true);
                $attach_id_array .= ','.$attach_id;
                update_post_meta($post_id,'_product_image_gallery',$attach_id_array);
            }
        }
    }

    /**
     * Based on https://stackoverflow.com/questions/47518280 but heavily modified.
     * Create a product variation for a defined variable product.
     *
     * @param WC_Product $product The base product
     * @param array $aralco_product The data from aralco about the product
     * @return bool|WP_Error
     */
    static function process_product_variations($product, $aralco_product){
        // Fetch the grids for this item and assign grids to product
        $grids = array();
        for ($i = 1; $i <= 4; $i++){
            if(isset($aralco_product['Product']['DimensionId' . $i])){
                $terms = array();
                $grids['grid' . $i] = get_terms(array(
                    'hide_empty' => false,
                    'taxonomy'   => wc_attribute_taxonomy_name('grid-' . $aralco_product['Product']['DimensionId' . $i])
                ));
                /**
                 * @var $grid WP_Term
                 */
                foreach($grids['grid' . $i] as $grid){
                    array_push($terms, $grid->name);
                }
                wp_set_object_terms($product->get_id(), $terms, wc_attribute_taxonomy_name(
                    'grid-' . $aralco_product['Product']['DimensionId' . $i]));
            } else break;
        }
        if (count($grids) == 0) return true; // Nothing to do.




        // Generate a list of possible combinations
        $combos = Aralco_Processing_Helper::generate_grid_sets($grids);

        // DEBUG
//        print_r(array(
//            'product' => array(
//                'id' => $aralco_product['ProductID'],
//                'code' => $aralco_product['Product']['Code'],
//                'name' => $aralco_product['Product']['Name']
//            ),
//            'grids' => $grids,
//            'combos' => $combos
//        ));

        foreach ($combos as $combo){
            $variation_post = array(
                'post_title'  => $product->get_title(),
                'post_name'   => 'product-'.$product->get_id().'-variation',
                'post_status' => 'publish',
                'post_parent' => $product->get_id(),
                'post_type'   => 'product_variation',
                'guid'        => $product->get_permalink()
            );

            // Creating the product variation
            $variation_id = wp_insert_post($variation_post);

            if ($variation_id instanceof WP_Error) {
                print_r($variation_id);
                return false;
            }

            // Get an instance of the WC_Product_Variation object
            $variation = new WC_Product_Variation($variation_id);

            // Iterating through the variations attributes
            /**
             * Type hint for IDE
             * @var $term WP_Term
             */
            foreach ($combo as $key => $term) {

                $taxonomy = wc_attribute_taxonomy_name('grid-' . $aralco_product['Product']['DimensionId' . ($key + 1)]);

//                // Get the post Terms names from the parent variable product.
//                $post_term_names = wp_get_post_terms( $product->get_id(), $taxonomy, array('fields' => 'names') );
//
//                // Check if the post term exist and if not we set it in the parent variable product.
//                if( ! in_array( $term->name, $post_term_names ) )
//                    wp_set_post_terms( $product->get_id(), $term->name, $taxonomy, true );

                // Set/save the attribute data in the product variation
                update_post_meta( $variation_id, 'attribute_'.$taxonomy, $term );
            }

            ## Set/save all other data

            // SKU
//            try{
//                $variation->set_sku($aralco_product['Product']['Code']);
//            }catch(Exception $e){}

            // Prices
            $variation->set_price(isset($aralco_product['Product']['DiscountPrice'])?
                $aralco_product['Product']['DiscountPrice'] : $aralco_product['Product']['Price']);
            $variation->set_sale_price(isset($aralco_product['Product']['DiscountPrice']) ?
                $aralco_product['Product']['DiscountPrice'] : $aralco_product['Product']['Price']);
            $variation->set_regular_price($aralco_product['Product']['Price']);

            // Stock
//            if( ! empty($variation_data['stock_qty']) ){
//                $variation->set_stock_quantity( $variation_data['stock_qty'] );
//                $variation->set_manage_stock(true);
//                $variation->set_stock_status('');
//            } else {
                $variation->set_manage_stock(false);
//            }

//            $variation->set_weight(''); // weight (reseting)

            $variation->save(); // Save the data
        }
        return true;
    }

    /**
     * Given the grids of a product, a list of combinations is generated.
     *
     * @param array $grids the grids to generate combinations from
     * @return array a unorded array of every possible grid combination
     */
    static function generate_grid_sets($grids){
        $combos = array();
        if (isset($grids['grid1'])) {
            foreach($grids['grid1'] as $term1){
                if (isset($grids['grid2'])){
                    foreach($grids['grid2'] as $term2){
                        if (isset($grids['grid3'])){
                            foreach($grids['grid3'] as $term3){
                                if (isset($grids['grid4'])){
                                    foreach($grids['grid4'] as $term4){
                                        array_push($combos, array($term1, $term2, $term3, $term4));
                                    }
                                } else {
                                    array_push($combos, array($term1, $term2, $term3));
                                }
                            }
                        } else {
                            array_push($combos, array($term1, $term2));
                        }
                    }
                } else {
                    array_push($combos, array($term1));
                }
            }
        }
        return $combos;
    }

    /**
     * Downloads and registers all the grids
     *
     * @return true|WP_Error True if everything works, or WP_Error instance if something goes wrong
     */
    static function sync_grids(){
        try{
            $start_time = new DateTime();
        } catch(Exception $e) {}

        // Get the grids.
        $raw_grids = Aralco_Connection_Helper::getGrids();
        if($raw_grids instanceof WP_Error || (isset($raw_grids[0]) && !isset($raw_grids[0]['CategoryId']))) {
            return $raw_grids; // Something isn't right. Probably API error
        }
        if(!isset($raw_grids[0])){
            return true; // Nothing to do;
        }

        // Clean up the grids so we can loop cleaner
        $grids = array();
        foreach($raw_grids as $key => $grid){
            // We are going to nest all the grid values instead of having a flat list of name/value pairs
            if(!isset($grids[$grid['CategoryId']])){
                $grids[$grid['CategoryId']] = array();
                $grids[$grid['CategoryId']]['DepartmentId'] = $grid['DepartmentId'];
                $grids[$grid['CategoryId']]['Type'] = $grid['Type'];
                $grids[$grid['CategoryId']]['CategoryId'] = $grid['CategoryId'];
                $grids[$grid['CategoryId']]['CategoryName'] = $grid['CategoryName'];
                $grids[$grid['CategoryId']]['values'] = array();
            }
            $grids[$grid['CategoryId']]['values'][$grid['ValueId']] = array(
                'ValueId' => $grid['ValueId'],
                'ValueName' => $grid['ValueName']
            );
        }
        unset($raw_grids);

        // Start data entry
        global $wpdb;
        $i1 = 0;
        foreach($grids as $key => $grid){
            // Part 1: The top level grid groupings
            $does_exist = taxonomy_exists(wc_attribute_taxonomy_name('grid-' . $grid['CategoryId']));
            if($does_exist) {
                $id = wc_attribute_taxonomy_id_by_name('grid-' . $grid['CategoryId']);
                wc_update_attribute($id, array(
                    'id' => $id,
                    'name' => $grid['CategoryName'],
                    'slug' => 'grid-' . $grid['CategoryId'],
                    'type' => 'select',
                    'order_by' => 'menu_order',
                    'has_archives' => false
                ));
            } else {
                wc_create_attribute(array(
                    'name' => $grid['CategoryName'],
                    'slug' => 'grid-' . $grid['CategoryId'],
                    'type' => 'select',
                    'order_by' => 'menu_order',
                    'has_archives' => false
                ));
            }
            // Part 2: Dealing with the values
            $i2 = 0;
            foreach($grid['values'] as $k => $value) {
                $taxonomy = wc_attribute_taxonomy_name('grid-' . $grid['CategoryId']);
                $slug = sprintf('%s-val-%s', $taxonomy, '' . $value['ValueId']);
                $existing = get_term_by('slug', $slug, $taxonomy);
                if ($existing == false){
                    $result = wp_insert_term($value['ValueName'], $taxonomy, array(
                        'slug' => $slug
                    ));
                    if($result instanceof WP_Error){
//                        return $result;
                        // Ignore and continue for now. //TODO
                        continue;
                    }
                    $id = $result['term_id'];
                } else {
                    $id = $existing->term_id;
                    wp_update_term($id, $taxonomy, array(
                        'name' => $value['ValueName'],
                    ));
                }
                delete_term_meta($id, 'order');
                add_term_meta($id, 'order', $i2++);
                $temp_key = 'order_' . wc_attribute_taxonomy_name('grid-' . $grid['CategoryId']);
                delete_term_meta($id, $temp_key);
                add_term_meta($id, $temp_key, $i1);
                delete_term_meta($id, 'aralco_grid_id');
                add_term_meta($id, 'aralco_grid_id', $grid['CategoryId']);
            }
            $i1++;
        }

        try{
            $time_taken = (new DateTime())->getTimestamp() - $start_time->getTimestamp();
            update_option(ARALCO_SLUG . '_last_sync_duration_grids', $time_taken);
        } catch(Exception $e) {}
        update_option(ARALCO_SLUG . '_last_sync_grid_count', $i1);
        return true;
    }

    /**
     * @return true|WP_Error
     */
    static function sync_departments(){
        try{
            $start_time = new DateTime();
        } catch(Exception $e) {}

        $departments = Aralco_Connection_Helper::getDepartments();

        $count = 0;
        // Creation Pass (1/2)
        foreach($departments as $department){
            $count++;
            $slug = 'department-' . $department['Id'];
            $term = get_term_by( 'slug', $slug, 'product_cat' );
            if($term === false){
                $result = wp_insert_term(
                    $department['Name'],
                    'product_cat',
                    array(
                        'description' => isset($department['Description']) ? $department['Description'] : '',
                        'slug' => $slug
                    ));
                if ($result instanceof WP_Error) return $result;
                $term_id = $result['term_id'];
            } else {
                $result = wp_update_term($term->term_id, 'product_cat', array(
                    'description' => isset($department['Description']) ? $department['Description'] : '',
                    'slug' => $slug
                ));
                if ($result instanceof WP_Error) return $result;
                $term_id = $term->term_id;
            }
            Aralco_Processing_Helper::process_department_images($term_id, $department['Id']);
        }

        // Relationship Pass (2/2)
        foreach($departments as $department){
            if(!isset($department['ParentId'])) continue; // No parent. Nothing to do.

            $parent_slug = 'department-' . $department['ParentId'];
            $parent_term = get_term_by( 'slug', $parent_slug, 'product_cat' );
            if($parent_term === false) continue; // Parent not enabled for ecommerce or child is orphaned. Either way, nothing to do.

            $child_slug = 'department-' . $department['Id'];
            $child_term = get_term_by( 'slug', $child_slug, 'product_cat' );
            if($child_term === false) continue; // Child somehow doesn't exist. Would never happen but leaving in for sanity

            $result = wp_update_term($child_term->term_id, 'product_cat', array(
                'description' => isset($department['Description']) ? $department['Description'] : '',
                'slug' => $child_slug,
                'parent' => $parent_term->term_id
            ));
            if ($result instanceof WP_Error) return $result;
        }

        try{
            $time_taken = (new DateTime())->getTimestamp() - $start_time->getTimestamp();
            update_option(ARALCO_SLUG . '_last_sync_duration_departments', $time_taken);
        } catch(Exception $e) {}
        update_option(ARALCO_SLUG . '_last_sync_department_count', $count);
        return true;
    }

    static function process_department_images($term_id, $department_id){
        $existing = get_term_meta($term_id, 'thumbnail_id', true);
        if(!empty($existing)){
            wp_delete_attachment($existing, true);
            delete_term_meta($term_id, 'thumbnail_id');
        }

        $image = Aralco_Connection_Helper::getImageForDepartment($department_id);
        if(!$image instanceof Aralco_Image){
            return; // Nothing to do.
        }
        $upload_dir = wp_upload_dir();

        $type = '.jpg';
        if (strpos($image->mime_type, 'png') !== false) {
            $type = '.png';
        } else if (strpos($image->mime_type, 'gif') !== false) {
            $type = '.gif';
        }
        $image_name = 'department-' . $department_id . $type;

        $unique_file_name = wp_unique_filename($upload_dir['path'], $image_name); // Generate unique name
        $filename = basename($unique_file_name); // Create image file name
        // Check folder permission and define file location
        if( wp_mkdir_p( $upload_dir['path'] ) ) {
            $file = $upload_dir['path'] . '/' . $filename;
        } else {
            $file = $upload_dir['basedir'] . '/' . $filename;
        }
        // Create the image file on the server
        file_put_contents($file, $image->image_data);
        // Check image file type
        $wp_filetype = wp_check_filetype( $filename, null );
        // Set attachment data
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => sanitize_file_name( $filename ),
            'post_content' => '',
            'post_status' => 'publish'
        );
        // Create the attachment
        $attach_id = wp_insert_attachment( $attachment, $file );
        // Include image.php
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        // Define attachment metadata
        $attach_data = wp_generate_attachment_metadata( $attach_id, $file );

        // Assign metadata to attachment
        wp_update_attachment_metadata( $attach_id, $attach_data );
        // asign to feature image
        update_term_meta($term_id,'thumbnail_id', $attach_id);
    }
}
