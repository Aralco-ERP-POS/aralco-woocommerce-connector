<?php

defined( 'ABSPATH' ) or die(); // Prevents direct access to file.

/**
 * Class Aralco_Image
 *
 * Contains the image data and mime type of an image retrieved from Aralco
 *
 * @property string image_data the raw data of the image
 * @property string mime_type the mime type string associated with the image data
 */
class Aralco_Image {
    /**
     * Aralco_Image constructor.
     * @param string $image_data the raw data of the image
     * @param string $mime_type the mime type string associated with the image data
     */
    public function __construct($image_data, $mime_type = 'image/jpeg'){
        $this->image_data = $image_data;
        $this->mime_type = $mime_type;
    }
}

/**
 * Class Aralco_Connection_Helper
 *
 * Provides helper methods to interact with the Aralco Ecommerce API.
 *
 * Config must be set before any of these methods will function
 */
class Aralco_Connection_Helper {

    /**
     * Checks if the proper options are set as a prerequisite to connecting to the Aralco Ecommerce API.
     *
     * @return bool returns true if the configuration is present, otherwise false.
     */
    static function hasValidConfig() {
        $options = get_option(ARALCO_SLUG . '_options');
        return isset($options[ARALCO_SLUG . '_field_api_location']) && isset($options[ARALCO_SLUG . '_field_api_token']);
    }

    /**
     * Tests the current configuration to see if a connection can be established.
     *
     * @return bool|WP_Error returns true if a connection could be made, otherwise an instance of WP_Error is returned
     * with a detailed description of what went wrong.
     */
    static function testConnection() {
        if(!Aralco_Connection_Helper::hasValidConfig()){
            return new WP_Error(ARALCO_SLUG . '_invalid_config', 'You must save the connection settings before you can test them.');
        }

        $options = get_option(ARALCO_SLUG . '_options');
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $options[ARALCO_SLUG . '_field_api_location'] .
                                        'api/Product/Search?EcommerceOnly=true');
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Return instead of printing
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Authorization: Basic ' . $options[ARALCO_SLUG . '_field_api_token'],
            'Content-Type: application/json; charset=utf-8'
        )); // Basic Auth and Content-Type for post
        curl_setopt($curl, CURLOPT_POST, 1); // Set to POST instead of GET
        curl_setopt($curl, CURLOPT_POSTFIELDS, "{}"); // Set Body
        $data = curl_exec($curl); // Get cURL result body
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE); // Get status code
        curl_close($curl); // Close the cURL handler

        $baseInfo['CustomData'] = array();
        if(in_array($http_code, array(200, 204))){
            return true; // Connection Test was Successful.
        }

        $message = "Unknown error";
        if (isset($data)){
            if(strpos($data, '{') !== false){
                $data = json_decode($data, true);
                $message = $data['message'] . ' ';
                if(isset($data['exceptionMessage'])){
                    $message .= $data['exceptionMessage'];
                }
            } else {
                $message = $data;
            }
        }

        return new WP_Error(
            ARALCO_SLUG . '_connection_failed',
            __('Connection Failed', ARALCO_SLUG) . ' (' . $http_code . '): ' . __($message, ARALCO_SLUG)
        );
    }

    /**
     * Gets all the product changes since a certain date
     *
     * @param string $start_time as timestamp
     * @return array|WP_Error
     */
    static function getProducts($start_time = "1900-01-01T00:00:00") {
        if(!Aralco_Connection_Helper::hasValidConfig()){
            return new WP_Error(ARALCO_SLUG . '_invalid_config', 'You must save the connection settings before you can test them.');
        }

        $options = get_option(ARALCO_SLUG . '_options');
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $options[ARALCO_SLUG . '_field_api_location'] .
                                        'api/Product/Updated?from=' . $start_time);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Return instead of printing
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Authorization: Basic ' . $options[ARALCO_SLUG . '_field_api_token']
        )); // Basic Auth
        $data = curl_exec($curl); // Get cURL result body
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE); // Get status code
        curl_close($curl); // Close the cURL handler

        if($http_code == 200){
            return json_decode($data, true); // Retrieval Successful.
        }

        $message = "Unknown Error";
        if(isset($data)){
            if(strpos($data, '{') !== false){
                $data = json_decode($data, true);
                $message = $data['message'] . ' ';
                if(isset($data['exceptionMessage'])){
                    $message .= $data['exceptionMessage'];
                }
            }else{
                $message = $data;
            }
        }

        return new WP_Error(
            ARALCO_SLUG . '_get_product_error',
            __('Product Fetch Failed', ARALCO_SLUG) . ' (' . $http_code . '): ' . __($message, ARALCO_SLUG)
        );
    }

    /**
     * Gets all the images associated with a product
     *
     * @param int $product_id the product to get the images for
     * @return Aralco_Image[] an array of AralcoImage objects
     */
    static function getImagesForProduct($product_id) {
        $options = get_option(ARALCO_SLUG . '_options');

        $images = array();
        foreach (array(0 => 'GetImage', 1 => 'GetWebImage') as $key=>$item){
            for($i = 0; $i < 10; $i++){
                if($key == 1 && $i < 1) $i = 1;

                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, $options[ARALCO_SLUG . '_field_api_location'] .
                                                'api/Product/' . $item . '/?id=' . $product_id . '&position=' . $i);
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Return instead of printing
                curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                    'Authorization: Basic ' . $options[ARALCO_SLUG . '_field_api_token']
                )); // Basic Auth
                $data = curl_exec($curl); // Get cURL result body
                $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE); // Get status code
                $mime_type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); // Get image type
                curl_close($curl); // Close the cURL handler

                if($http_code == 200){
                    array_push($images, new Aralco_Image($data, $mime_type));
                } else {
                    break;
                }
            }
        }

        return $images;
    }

    /**
     * Gets all the departments
     *
     * @return array|WP_Error The departments or WP_Error on failure
     */
    static function getDepartments(){
        $options = get_option(ARALCO_SLUG . '_options');

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $options[ARALCO_SLUG . '_field_api_location'] .
                                        'api/Department/Get');
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Return instead of printing
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Authorization: Basic ' . $options[ARALCO_SLUG . '_field_api_token']
        )); // Basic Auth
        $data = curl_exec($curl); // Get cURL result body
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE); // Get status code
        curl_close($curl); // Close the cURL handler

        if($http_code == 200){
            return json_decode($data, true);
        }

        $message = "Unknown Error";
        if(isset($data)){
            if(strpos($data, '{') !== false){
                $data = json_decode($data, true);
                $message = $data['message'] . ' ';
                if(isset($data['exceptionMessage'])){
                    $message .= $data['exceptionMessage'];
                }
            }else{
                $message = $data;
            }
        }

        return new WP_Error(
            ARALCO_SLUG . '_get_department_error',
            __('Department Fetch Failed', ARALCO_SLUG) . ' (' . $http_code . '): ' . __($message, ARALCO_SLUG)
        );
    }

    /**
     * Gets the images associated with a department
     *
     * @param int $product_id the product to get the images for
     * @return Aralco_Image|null the AralcoImage object containing the image or null if no image exists.
     */
    static function getImageForDepartment($product_id) {
        $options = get_option(ARALCO_SLUG . '_options');

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $options[ARALCO_SLUG . '_field_api_location'] .
                                        'api/Department/GetImage/?id=' . $product_id);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Return instead of printing
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Authorization: Basic ' . $options[ARALCO_SLUG . '_field_api_token']
        )); // Basic Auth
        $data = curl_exec($curl); // Get cURL result body
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE); // Get status code
        $mime_type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); // Get image type
        curl_close($curl); // Close the cURL handler

        if($http_code == 200){
            return new Aralco_Image($data, $mime_type);
        }

        return null;
    }

    /**
     * Get the time and timezone data from the server
     *
     * @return array|WP_Error The timezone data or WP_Error on failure
     */
    static function getServerTime(){
        $options = get_option(ARALCO_SLUG . '_options');

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $options[ARALCO_SLUG . '_field_api_location'] . 'api/TimeZone');
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Return instead of printing
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Authorization: Basic ' . $options[ARALCO_SLUG . '_field_api_token']
        )); // Basic Auth
        $data = curl_exec($curl); // Get cURL result body
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE); // Get status code
        curl_close($curl); // Close the cURL handler

        if($http_code == 200){
            return json_decode($data, true);
        }

        $message = "Unknown Error";
        if(isset($data)){
            if(strpos($data, '{') !== false){
                $data = json_decode($data, true);
                $message = $data['message'] . ' ';
                if(isset($data['exceptionMessage'])){
                    $message .= $data['exceptionMessage'];
                }
            }else{
                $message = $data;
            }
        }

        return new WP_Error(
            ARALCO_SLUG . '_get_department_error',
            __('Server Time Fetch Failed', ARALCO_SLUG) . ' (' . $http_code . '): ' . __($message, ARALCO_SLUG)
        );
    }
}
