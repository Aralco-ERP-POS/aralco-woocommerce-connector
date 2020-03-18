<?php
/**
 * Plugin Name: Aralco WooCommerce Connector
 * Plugin URI: https://github.com/sonicer105/aralcowoocon
 * Description: WooCommerce Connector for Aralco POS Systems.
 * Version: 1.0.0
 * Author: Elias Turner, Aralco
 * Author URI: https://aralco.com
 * Requires at least: 5.0
 * Tested up to: 5.3.2
 * Text Domain: aralco_woocommerce_connector
 * Domain Path: /languages/
 * WC requires at least: 3.9
 * WC tested up to: 3.9.2
 *
 * @package Aralco_WooCommerce_Connector
 * @version 1.1.0
 */

defined( 'ABSPATH' ) or die(); // Prevents direct access to file.

define('ARALCO_SLUG', 'aralco_woocommerce_connector');

add_filter( 'jetpack_development_mode', '__return_true' ); //TODO: Remove when done

require_once "aralco-util.php";
require_once "aralco-admin-settings-input-validation.php";
require_once "aralco-connection-helper.php";
require_once "aralco-processing-helper.php";

/**
 * Class Aralco_WooCommerce_Connector
 *
 * Main class in the plugin. All the core logic is contained here.
 */
class Aralco_WooCommerce_Connector {
    /**
     * Aralco_WooCommerce_Connector constructor.
     */
    public function __construct(){
        // register sync hook and deactivation hook
        add_action( ARALCO_SLUG . '_sync_products', array($this, 'sync_products_quite'));
        add_filter('cron_schedules', array($this, 'custom_cron_timespan'));
        register_deactivation_hook(__FILE__, array($this, 'unschedule_sync'));

        // Check if WooCommerce is active
        if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            // trigger add product sync to cron (will only do if enabled)
            $this->schedule_sync();

            // register our settings_init to the admin_init action hook
            add_action( 'admin_init', array($this, 'settings_init'));

            // register our options_page to the admin_menu action hook
            add_action( 'admin_menu', array($this, 'options_page'));
        } else {
            // Show admin notice that WooCommerce needs to be active.
            add_action('admin_notices', array($this, 'plugin_not_available'));
        }
    }

    /**
     * Callback that displays an error on every admin page informing the user WooCommerce is missing and is a dependency
     * of this plugin
     */
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
     * Callback that registers the settings and rendering callbacks used to drawing the settings.
     */
    public function settings_init() {
        register_setting(
                ARALCO_SLUG,
                ARALCO_SLUG . '_options',
                'aralco_validate_config'
        );

        add_settings_section(
            ARALCO_SLUG . '_global_section',
            /*__('General Settings', ARALCO_SLUG)*/'',
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

        add_settings_field(
            ARALCO_SLUG . '_field_sync_enabled',
            __('Sync Enabled', ARALCO_SLUG),
            array($this, 'field_checkbox'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_global_section',
            [
                'label_for' => ARALCO_SLUG . '_field_sync_enabled'
            ]
        );

        add_settings_field(
            ARALCO_SLUG . '_field_sync_interval',
            __('Sync Interval', ARALCO_SLUG),
            array($this, 'field_input'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_global_section',
            [
                'type' => 'number',
                'step' => '1',
                'min' => '1',
                'max' => '9999',
                'label_for' => ARALCO_SLUG . '_field_sync_interval',
                'class' => ARALCO_SLUG . '_row',
                'placeholder' => '5'
            ]
        );

        add_settings_field(
            ARALCO_SLUG . '_field_sync_unit',
            __('Sync Unit', ARALCO_SLUG),
            array($this, 'field_select'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_global_section',
            [
                'label_for' => ARALCO_SLUG . '_field_sync_unit',
                'class' => ARALCO_SLUG . '_row',
                'description' => 'Enter the interval and unit for how often to sync products and stock automatically. Minimum 5 minutes.',
                'options' => array(
                    'Minutes' => '1',
                    'Hours' => '60',
                    'Days' => '1440'
                )
            ]
        );
    }

    /**
     * Callback for rendering the description for the settings section
     * @param $args
     */
    public function global_section_cb($args) {
        ?>
        <p id="<?php echo esc_attr($args['id']); ?>"><?php esc_html_e('General Settings for the Aralco WooCommerce Connector.', ARALCO_SLUG); ?></p>
        <?php
    }

    /**
     * Callback for Rendering a settings input
     * @param $args array of options
     */
    public function field_input($args) {
        $options = get_option(ARALCO_SLUG . '_options');
        require_once 'partials/aralco-admin-settings-input.php';
        aralco_admin_settings_input($options, $args);
    }

    /**
     * Callback for Rendering a settings select
     * @param $args array of options
     */
    public function field_select($args) {
        $options = get_option(ARALCO_SLUG . '_options');
        require_once 'partials/aralco-admin-settings-input.php';
        aralco_admin_settings_select($options, $args);
    }

    /**
     * Callback for Rendering a settings checkbox
     * @param $args array of options
     */
    public function field_checkbox($args) {
        $options = get_option(ARALCO_SLUG . '_options');
        require_once 'partials/aralco-admin-settings-input.php';
        aralco_admin_settings_checkbox($options, $args);
    }

    /**
     * WordPress Menu renderer callback that will add our section to the side bar.
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
     * Renders the Aralco WooCommerce Connector Settings page. Will render a warning instead if user does not have the
     * 'manage_options' permission.
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

    /**
     * Method called to test the connection settings from the GUI. Adds settings errors that will be shown on the next
     * admin page.
     */
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

    /**
     * Method called to sync products from the GUI. Adds settings errors that will be shown on the next admin page.
     *
     * @param bool $force true if the sync cooldown should be ignored, otherwise false. Default is false
     * @param bool $everything true if every product from the dawn of time should be synced, or false if you just want
     * updates since last sync. Default is false
     */
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

    /**
     * Method called to sync products by WordPress cron. Unlike sync_products, this method provides no feedback and takes no options.
     */
    public function sync_products_quite() {
        try{
            $result = Aralco_Processing_Helper::sync_departments();
            if($result === true){ // No issue? continue.
                Aralco_Processing_Helper::sync_products(false, false);
            }
        } catch (Exception $e) {
            // Do nothing
        }
    }

    /**
     * Registers our custom interval with cron.
     * @param $schedules mixed (internal)
     * @return mixed (internal)
     */
    public function custom_cron_timespan($schedules) {
        $options = get_option(ARALCO_SLUG . '_options');
        if($options !== false && isset($options[ARALCO_SLUG . '_field_sync_unit']) &&
           isset($options[ARALCO_SLUG . '_field_sync_interval'])){ // If sync interval and unit are set
            $minutes = intval($options[ARALCO_SLUG . '_field_sync_unit']) * intval($options[ARALCO_SLUG . '_field_sync_interval']);
            $schedules[ARALCO_SLUG . '_sync_timespan'] = array(
                'interval' => $minutes * 60,
                'display'  => __('Every ' . $minutes . ' Minutes', ARALCO_SLUG)
            );
        }
        return $schedules;
    }

    /**
     * Registers the product sync for the scheduled time, but only if _field_sync_enabled is set to "1" and is not already scheduled
     */
    public function schedule_sync() {
        $options = get_option(ARALCO_SLUG . '_options');
        if($options !== false && isset($options[ARALCO_SLUG . '_field_sync_enabled']) &&
           $options[ARALCO_SLUG . '_field_sync_enabled'] == '1') { // If sync enabled setting exists and is enabled
            if (!wp_next_scheduled(ARALCO_SLUG . '_sync_products')){ // If sync is not scheduled
                wp_schedule_event(time(), ARALCO_SLUG . '_sync_timespan', ARALCO_SLUG . '_sync_products');
            }
        } else {
            $this->unschedule_sync();
        }
    }

    /**
     * Attempts to deregister the product sync.
     */
    public function unschedule_sync() {
        $next_timestamp = wp_next_scheduled(ARALCO_SLUG . '_sync_products');
        if ($next_timestamp){ // If sync is scheduled
            wp_unschedule_event($next_timestamp, ARALCO_SLUG . '_sync_products');
        }
    }
}

new Aralco_WooCommerce_Connector();



