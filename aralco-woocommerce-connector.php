<?php
/**
 * @package Aralco_WooCommerce_Connector
 * @version 1.0.0
 */
/*
Plugin Name: Aralco WooCommerce Connector
Plugin URI: https://aralco.com
Description: WooCommerce Connector for Aralco POS Systems.
Author: Aralco Retail Systems (Elias Turner)
Version: 1.0.0
Author URI: http://aralco.com/
*/

defined( 'ABSPATH' ) or die(); // Prevents direct access to file.

define('ARALCO_SLUG', 'aralco_woocommerce_connector');

add_filter( 'jetpack_development_mode', '__return_true' ); //TODO: Remove when done

require_once "aralco-util.php";
require_once "aralco-admin-settings-input-validation.php";
require_once "aralco-connection-helper.php";
require_once "aralco-processing-helper.php";

class Aralco_WooCommerce_Connector {

    public function __construct(){
        // Check if WooCommerce is active
        if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            // register our settings_init to the admin_init action hook
            add_action( 'admin_init', array($this, 'settings_init'));
            // register our options_page to the admin_menu action hook
            add_action( 'admin_menu', array($this, 'options_page'));
            add_action( 'storefront_credit_links_output', array($this, 'do_footer'), 25);
        } else {
            // Show admin notice that WooCommerce needs to be active.
            add_action('admin_notices', array($this, 'plugin_not_available'));
        }
    }

    function plugin_not_available() {
        $lang   = '';
        if ( 'en_' !== substr( get_user_locale(), 0, 3 ) ) {
            $lang = ' lang="en_CA"';
        }

        printf(
            '<div class="error notice is-dismissible notice-info">
<p><span dir="ltr"%s>%s</span></p>
</div>',
            $lang,
            wptexturize(__(
                'WooCommerce is not active. Please install and activate WooCommerce to use the Aralco WooCommerce Connector.',
                ARALCO_SLUG
            ))
        );
    }

    /**
     * custom option and settings
     */
    public function settings_init() {
        register_setting(
                ARALCO_SLUG,
                ARALCO_SLUG . '_options',
                'aralco_validate_config'
        );

        add_settings_section(
            ARALCO_SLUG . '_global_section',
            __('General Settings', ARALCO_SLUG),
            array($this, 'global_section_cb'),
            ARALCO_SLUG
        );

        add_settings_field(
            ARALCO_SLUG . '_field_api_location',
            __('API Location', ARALCO_SLUG),
            array($this, 'field_input'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_global_section',
            [
                'type' => 'text',
                'label_for' => ARALCO_SLUG . '_field_api_location',
                'class' => ARALCO_SLUG . '_row',
                'placeholder' => 'http://localhost:1234/',
                'description' => 'Enter the web address of your Aralco Ecommerce API. Please include the http:// and trailing slash'
            ]
        );

        add_settings_field(
            ARALCO_SLUG . '_field_api_token',
            __('API Token', ARALCO_SLUG),
            array($this, 'field_input'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_global_section',
            [
                'type' => 'text',
                'label_for' => ARALCO_SLUG . '_field_api_token',
                'class' => ARALCO_SLUG . '_row',
                'placeholder' => '1a2b3v4d5e6f7g8h9i0j1k2l3m4n5o6p7q8r9s0t',
                'description' => 'Enter the secret barer token for your Aralco Ecommerce API'
            ]
        );

//        add_settings_field(
//            ARALCO_SLUG . '_field_update_interval',
//            __('Update Interval', ARALCO_SLUG),
//            array($this, 'field_input'),
//            ARALCO_SLUG,
//            ARALCO_SLUG . '_global_section',
//            [
//                'type' => 'number',
//                'label_for' => ARALCO_SLUG . '_field_update_interval',
//                'class' => ARALCO_SLUG . '_row',
//                'placeholder' => '5',
//                'description' => 'Enter the interval in minutes to fetch the products, inventory and customers from Aralco'
//            ]
//        );
    }

    /**
     * custom option and settings:
     * callback functions
     */

    public function global_section_cb($args) {
        ?>
        <p id="<?php echo esc_attr($args['id']); ?>"><?php esc_html_e('General Settings for the Aralco WooCommerce Connector.', ARALCO_SLUG); ?></p>
        <?php
    }

    public function field_input($args) {
        $options = get_option(ARALCO_SLUG . '_options');
        require_once 'partials/aralco-admin-settings-input.php';
        aralco_admin_settings_input($options, $args);
    }

    /**
     * top level menu
     */
    public function options_page() {
        // add top level menu page
        add_menu_page(
            'Aralco WooCommerce Connector Settings',
            'Aralco Options',
            'manage_options',
            ARALCO_SLUG . '_settings',
            array($this, 'options_page_html')
        );
    }

    /**
     * top level menu:
     * callback functions
     */
    public function options_page_html() {
        // check user capabilities
        if (!current_user_can('manage_options')){
            echo "<h1>Current user cannot manage options.</h1>";
            return;
        }

        // add error/update messages

        // check if the user have submitted the settings
        // wordpress will add the "settings-updated" $_GET parameter to the url
        if (isset($_GET['settings-updated'])) {
            $has_error = false;
            foreach(get_settings_errors() as $index => $message) {
                if($message['type'] == "error" && strpos($message['setting'], ARALCO_SLUG) !== false) {
                    $has_error = true;
                }
            }
            // add settings saved message with the class of "updated"
            if (!$has_error) {
                add_settings_error(
                    ARALCO_SLUG . '_messages',
                    ARALCO_SLUG . '_message',
                    __('Settings Saved', ARALCO_SLUG),
                    'updated'
                );
            }
        }

        if (isset($_POST['test-connection'])){
            $this->test_connection();
        }

        if (isset($_POST['sync-now'])){
            $this->sync_products(true);
        }

        if (isset($_POST['force-sync-now'])){
            $this->sync_products(true, true);
        }

        // show error/update messages
        require_once 'partials/aralco-admin-settings-display.php';
    }

    public function test_connection() {
        $result = Aralco_Connection_Helper::testConnection();
        if ($result === true) {
            add_settings_error(
                ARALCO_SLUG . '_messages',
                ARALCO_SLUG . '_message',
                __('Connection successful.', ARALCO_SLUG),
                'updated'
            );
        } else if($result instanceof WP_Error) {
            add_settings_error(
                ARALCO_SLUG . '_messages',
                ARALCO_SLUG . '_messages',
                $result->get_error_message(),
                'error'
            );
        } else {
            // Shouldn't ever get here.
            add_settings_error(
                ARALCO_SLUG . '_messages',
                ARALCO_SLUG . '_messages',
                __('Something went wrong. Please contact Aralco.', ARALCO_SLUG) . ' (Code 1)',
                'error'
            );
        }
    }

    public function sync_products($force = false, $everything = false){
        $result = Aralco_Processing_Helper::sync_departments();
        if ($result === true){ // No issue? continue.
            $result = Aralco_Processing_Helper::sync_products($force, $everything);
        }
        if (is_bool($result)) {
            add_settings_error(
                ARALCO_SLUG . '_messages',
                ARALCO_SLUG . '_message',
                __('Sync successful.', ARALCO_SLUG),
                'updated'
            );
        } else if(is_array($result)) {
            $message = '';
            foreach($result as $key=>$value){
                $message .= '<br>' . $value->get_error_message();
            }
            add_settings_error(
                ARALCO_SLUG . '_messages',
                ARALCO_SLUG . '_messages',
                __('Sync completed with errors.') . $message,
                'warning'
            );
        } else if($result instanceof WP_Error) {
            add_settings_error(
                ARALCO_SLUG . '_messages',
                ARALCO_SLUG . '_messages',
                $result->get_error_message(),
                'error'
            );
        } else {
            // Shouldn't ever get here.
            add_settings_error(
                ARALCO_SLUG . '_messages',
                ARALCO_SLUG . '_messages',
                __('Something went wrong. Please contact Aralco.', ARALCO_SLUG) . ' (Code 2)' . $result,
                'error'
            );
        }
    }

    public function do_footer($content) {
        $text = __('Powered by Aralco', ARALCO_SLUG);
        $title = __('Aralco Inventory Management & POS Systems Software', ARALCO_SLUG);
        return substr($content, 0, -1) .
               '<span role="separator" aria-hidden="true"></span>' .
               '<a href="https://aralco.com/" target="_blank" rel="noopener nofollow" title="' . $title . '">' .
               $text .
               '</a>.';
    }
}

new Aralco_WooCommerce_Connector();



