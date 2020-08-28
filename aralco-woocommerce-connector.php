<?php
/**
 * Plugin Name: Aralco WooCommerce Connector
 * Plugin URI: https://github.com/sonicer105/aralcowoocon
 * Description: WooCommerce Connector for Aralco POS Systems.
 * Version: 1.14.3
 * Author: Elias Turner, Aralco
 * Author URI: https://aralco.com
 * Requires at least: 5.0
 * Tested up to: 5.4.2
 * Text Domain: aralco_woocommerce_connector
 * Domain Path: /languages/
 * WC requires at least: 4.0
 * WC tested up to: 4.2.2
 *
 * @package Aralco_WooCommerce_Connector
 * @version 1.14.4
 */

defined( 'ABSPATH' ) or die(); // Prevents direct access to file.

define('ARALCO_SLUG', 'aralco_woocommerce_connector');

//add_filter( 'jetpack_development_mode', '__return_true' ); //TODO: Remove when done

$current_aralco_user = array();
$aralco_groups = array();

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

        add_action('init', array($this, 'register_globals'));

        // Check if WooCommerce is active
        if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            // trigger add product sync to cron (will only do if enabled)
            $this->schedule_sync();

            // register our settings_init to the admin_init action hook
            add_action('admin_init', array($this, 'settings_init'));

            // register our options_page to the admin_menu action hook
            add_action('admin_menu', array($this, 'options_page'));

            // register order complete hook
            add_action('woocommerce_payment_complete', array($this, 'submit_order_to_aralco'), 10, 1);

            // register new user hook
            add_action('user_register', array($this, 'new_customer'));

            // register login hook
            add_action('wp_login', array($this, 'customer_login'));

            // register custom product taxonomy
            add_action('admin_init', array($this, 'register_product_taxonomy'));

            // register customer group price and UoM hooks
            add_filter('woocommerce_get_price_html', array($this, 'alter_price_display'), 100, 2);
            add_filter('woocommerce_cart_item_price', array($this, 'alter_cart_price_display'), 100, 2);
            add_filter('woocommerce_checkout_cart_item_quantity', array($this, 'alter_cart_quantity_display'), 100, 3);
            add_action('woocommerce_before_calculate_totals', array($this, 'alter_price_cart'), 100);
            add_action('woocommerce_format_stock_quantity', array($this, 'alter_availability_text'), 100, 2);
            add_filter('woocommerce_loop_add_to_cart_link', array($this, 'replacing_add_to_cart_button'), 100, 2);
            add_filter('woocommerce_after_quantity_input_field', array($this, 'replace_quantity_field'), 100, 2);
            add_filter('woocommerce_blocks_product_grid_item_html', array($this, 'blocks_product_grid_item_html'), 100, 3);
            add_filter('woocommerce_after_add_to_cart_button', array($this, 'add_decimal_text'), 100, 2);
            add_filter('woocommerce_add_to_cart_qty_html', array($this, 'add_to_cart_qty_html'), 100, 2);
            add_filter('woocommerce_cart_item_quantity', array($this, 'cart_item_quantity'), 100, 3);
            add_filter('woocommerce_cart_contents_count', array($this, 'cart_contents_count'), 100, 2);
            add_filter('woocommerce_widget_cart_item_quantity', array($this, 'widget_cart_item_quantity'), 100, 3);
            add_filter('woocommerce_order_item_quantity_html', array($this, 'order_item_quantity_html'), 100, 2);
            add_filter('woocommerce_email_order_item_quantity', array($this, 'order_item_quantity_html'), 100, 2);
            add_filter('woocommerce_display_item_meta', array($this, 'display_item_meta'), 100, 3);
            add_filter('woocommerce_email', array($this, 'email'), 100);

            // register aralco id field display (for admins)
            add_action('woocommerce_product_meta_start', array($this, 'display_aralco_id'), 101, 0);

            // register stock check for cart page hook
            add_action('woocommerce_before_cart', array($this, 'cart_check_product_stock'), 100, 0);

            // disable the need for unique SKUs. Required for Aralco products.
            add_filter('wc_product_has_unique_sku', '__return_false' );
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
     * Registers globals for use elsewhere
     */
    public function register_globals() {
        if(is_user_logged_in()) {
            global $current_aralco_user;
            $current_aralco_user = get_user_meta(wp_get_current_user()->ID, 'aralco_data', true);
            if(!is_array($current_aralco_user)) $current_aralco_user = array();
        }
        global $aralco_groups;
        $aralco_groups = get_option(ARALCO_SLUG . '_customer_groups', true);
        if(!is_array($aralco_groups)) $aralco_groups = array();
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
            '',
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
                'required' => 'required',
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
                'required' => 'required',
                'description' => 'Enter the secret barer token for your Aralco Ecommerce API'
            ]
        );

        add_settings_field(
            ARALCO_SLUG . '_field_allow_backorders',
            __('Allow Backorders', ARALCO_SLUG),
            array($this, 'field_checkbox'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_global_section',
            [
                'label_for' => ARALCO_SLUG . '_field_allow_backorders',
                'required' => 'required'
            ]
        );

        add_settings_field(
            ARALCO_SLUG . '_field_sync_enabled',
            __('Sync Enabled', ARALCO_SLUG),
            array($this, 'field_checkbox'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_global_section',
            [
                'label_for' => ARALCO_SLUG . '_field_sync_enabled',
                'required' => 'required'
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
                'placeholder' => '5',
                'required' => 'required'
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
                ),
                'required' => 'required'
            ]
        );

        add_settings_field(
            ARALCO_SLUG . '_field_sync_items',
            __('Sync Items', ARALCO_SLUG),
            array($this, 'field_select'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_global_section',
            [
                'label_for' => ARALCO_SLUG . '_field_sync_items',
                'class' => ARALCO_SLUG . '_row',
                'description' => 'Please select all the items you want to sync automatically from Aralco. Items not selected can be synced manually.',
                'options' => array(
                    'Departments' => 'departments',
                    'Groupings' => 'groupings',
                    'Grids' => 'grids',
                    'Products' => 'products',
                    'Stock' => 'stock',
                    'Customer Groups' => 'customer_groups',
                    'Taxes' => 'taxes'
                ),
                'multi' => true,
                'required' => 'required'
            ]
        );

        add_settings_section(
            ARALCO_SLUG . '_order_section',
            '',
            array($this, 'order_section_cb'),
            ARALCO_SLUG
        );

        add_settings_field(
            ARALCO_SLUG . '_field_order_enabled',
            __('Forward Orders to Aralco on Receipt of Payment', ARALCO_SLUG),
            array($this, 'field_checkbox'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_order_section',
            [
                'label_for' => ARALCO_SLUG . '_field_order_enabled'
            ]
        );

        add_settings_field(
            ARALCO_SLUG . '_field_order_enabled',
            __('Forward Orders', ARALCO_SLUG),
            array($this, 'field_checkbox'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_order_section',
            [
                'label_for' => ARALCO_SLUG . '_field_order_enabled',
                'required' => 'required',
                'description' => 'When checked, will forward any new orders to Aralco on Receipt of Payment'
            ]
        );

        add_settings_field(
            ARALCO_SLUG . '_field_default_order_email',
            __('Default Order Email', ARALCO_SLUG),
            array($this, 'field_input'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_order_section',
            [
                'type' => 'text',
                'label_for' => ARALCO_SLUG . '_field_default_order_email',
                'class' => ARALCO_SLUG . '_row',
                'placeholder' => 'john@example.com',
                'required' => 'required',
                'description' => 'Required for guest checkout. Please provide an email that is attached to a valid customer profile in Aralco. Not providing one will result in an error if a guest checks out.'
            ]
        );

        add_settings_field(
            ARALCO_SLUG . '_field_store_id',
            __('Aralco Store ID', ARALCO_SLUG),
            array($this, 'field_input'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_order_section',
            [
                'type' => 'number',
                'step' => '1',
                'min' => '0',
                'max' => '999999',
                'label_for' => ARALCO_SLUG . '_field_store_id',
                'class' => ARALCO_SLUG . '_row',
                'placeholder' => '1',
                'required' => 'required',
                'description' => 'The ID of the store to submit new orders to.'
            ]
        );

        add_settings_field(
            ARALCO_SLUG . '_field_tender_code',
            __('Tender Code', ARALCO_SLUG),
            array($this, 'field_input'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_order_section',
            [
                'type' => 'text',
                'label_for' => ARALCO_SLUG . '_field_tender_code',
                'class' => ARALCO_SLUG . '_row',
                'placeholder' => 'VI',
                'required' => 'required',
                'description' => 'The tender code to map ecommerce payment to.'
            ]
        );
    }

    /**
     * Callback for rendering the description for the settings section
     * @param $args
     */
    public function global_section_cb($args) {
        ?>
        <h2><?php esc_html_e('General', ARALCO_SLUG) ?></h2>
        <p id="<?php echo esc_attr($args['id']); ?>"><?php esc_html_e('General Settings for the Aralco WooCommerce Connector.', ARALCO_SLUG); ?></p>
        <?php
    }

    /**
     * Callback for rendering the description for the settings section
     * @param $args
     */
    public function order_section_cb($args) {
        ?>
        <hr>
        <h2><?php esc_html_e('Orders', ARALCO_SLUG) ?></h2>
        <p id="<?php echo esc_attr($args['id']); ?>"><?php esc_html_e('Order Settings for the Aralco WooCommerce Connector.', ARALCO_SLUG); ?></p>
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
            'Aralco WooCommerce Connector',
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

        if (isset($_POST['fix-stock-count'])){
            $this->fix_stock_count();
        }

        if (isset($_POST['sync-now'])){
            $this->sync_products();
        }

        if (isset($_POST['force-sync-now'])){
            $this->sync_products(true);
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
     * Method called to test the connection settings from the GUI. Adds settings errors that will be shown on the next
     * admin page.
     */
    public function fix_stock_count() {
        $options = get_option(ARALCO_SLUG . '_options');
        $backorder_stock_status = ($options !== false &&
            isset($options[ARALCO_SLUG . '_field_allow_backorders']) &&
            $options[ARALCO_SLUG . '_field_allow_backorders'] == '1') ?
            'onbackorder' : 'outofstock';
        $result = 0;
        global $wpdb;

        foreach (array(
            array('<=', $backorder_stock_status),
            array('>', 'instock')
        ) as $i => $operation){
            $rows = $wpdb->get_results("SELECT DISTINCT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = '_stock' AND meta_value {$operation[0]} 0", ARRAY_A);
            $ids = [];
            foreach ($rows as $row) {
                $ids[] = $row['post_id'];
            }
            $ids = implode(', ', $ids);

            $q = "UPDATE {$wpdb->prefix}postmeta SET meta_value = '{$operation[1]}' WHERE meta_key = '_stock_status' AND post_id IN ({$ids})";

            $temp_result = $wpdb->query($q);
            if ($temp_result === false) {
                $result = $temp_result;
                break;
            }
            $result += $temp_result;
        }


        if($result === false) {
            add_settings_error(
                ARALCO_SLUG . '_messages',
                ARALCO_SLUG . '_messages',
                __('Failed to update table.', ARALCO_SLUG),
                'error'
            );
        } else {
            add_settings_error(
                ARALCO_SLUG . '_messages',
                ARALCO_SLUG . '_message',
                __("Fix successful. ${result} record(s) updated.", ARALCO_SLUG),
                'updated'
            );
        }
    }

    /**
     * Method called to sync products from the GUI. Adds settings errors that will be shown on the next admin page.
     *
     * @param bool $everything true if every product from the dawn of time should be synced, or false if you just want
     * updates since last sync. Default is false
     */
    public function sync_products($everything = false){
        $what_to_sync = array(
            'departments' => isset($_POST['sync-departments']),
            'groupings' => isset($_POST['sync-groupings']),
            'grids' => isset($_POST['sync-grids']),
            'products' => isset($_POST['sync-products']),
            'stock' => isset($_POST['sync-stock']),
            'customer_groups' => isset($_POST['sync-customer-groups']),
            'taxes' => isset($_POST['sync-taxes'])
        );

        $errors = array();
        if($what_to_sync['departments']) {
            $result = Aralco_Processing_Helper::sync_departments();
            if ($result !== true) {
                array_push($errors, $result);
            }
        } else {
            update_option(ARALCO_SLUG . '_last_sync_department_count', 0);
            update_option(ARALCO_SLUG . '_last_sync_duration_departments', 0);
        }
        if($what_to_sync['groupings']) {
            $result = Aralco_Processing_Helper::sync_groupings();
            if($result !== true){
                array_push($errors, $result);
            }
        } else {
            update_option(ARALCO_SLUG . '_last_sync_grouping_count', 0);
            update_option(ARALCO_SLUG . '_last_sync_duration_groupings', 0);
        }
        if($what_to_sync['grids']) {
            $result = Aralco_Processing_Helper::sync_grids();
            if($result !== true){
                array_push($errors, $result);
            }
        } else {
            update_option(ARALCO_SLUG . '_last_sync_grid_count', 0);
            update_option(ARALCO_SLUG . '_last_sync_duration_grids', 0);
        }
        if($what_to_sync['products']) {
            $result = Aralco_Processing_Helper::sync_products($everything);
            if($result !== true){
                array_push($errors, $result);
            }
        } else {
            update_option(ARALCO_SLUG . '_last_sync_product_count', 0);
            update_option(ARALCO_SLUG . '_last_sync_duration_products', 0);
        }
        if($what_to_sync['stock']) {
            $result = Aralco_Processing_Helper::sync_stock(null, $everything);
            if($result !== true){
                array_push($errors, $result);
            }
        } else {
            update_option(ARALCO_SLUG . '_last_sync_stock_count', 0);
            update_option(ARALCO_SLUG . '_last_sync_duration_stock', 0);
        }
        if($what_to_sync['customer_groups']) {
            $result = Aralco_Processing_Helper::sync_customer_groups();
            if($result !== true){
                array_push($errors, $result);
            }
        } else {
            update_option(ARALCO_SLUG . '_last_sync_customer_groups_count', 0);
            update_option(ARALCO_SLUG . '_last_sync_duration_customer_groups', 0);
        }
        if($what_to_sync['taxes']) {
            $result = Aralco_Processing_Helper::sync_taxes();
            if($result !== true){
                array_push($errors, $result);
            }
        } else {
            update_option(ARALCO_SLUG . '_last_sync_taxes_count', 0);
            update_option(ARALCO_SLUG . '_last_sync_duration_taxes', 0);
        }
        update_option(ARALCO_SLUG . '_last_sync', date("Y-m-d\TH:i:s"));

        if (count($errors) <= 0) {
            add_settings_error(
                ARALCO_SLUG . '_messages',
                ARALCO_SLUG . '_message',
                __('Sync successful.', ARALCO_SLUG),
                'updated'
            );
            return;
        }
        foreach ($errors as $result) {
            if (is_array($result)) {
                $message = '';
                foreach ($result as $key => $value) {
                    $message .= '<br>' . $value->get_error_message();
                }
                add_settings_error(
                    ARALCO_SLUG . '_messages',
                    ARALCO_SLUG . '_messages',
                    __('Sync completed with errors.') . $message,
                    'warning'
                );
            } else if ($result instanceof WP_Error) {
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
    }

    /**
     * Method called to sync products by WordPress cron. Unlike sync_products, this method provides no feedback and takes no options.
     */
    public function sync_products_quite() {
        try{
            $options = get_option(ARALCO_SLUG . '_options');
            if(!isset($options[ARALCO_SLUG . '_field_sync_items'])){
                $options = array();
            } else {
                $options = $options[ARALCO_SLUG . '_field_sync_items'];
            }

            if(in_array('departments', $options)) {
                Aralco_Processing_Helper::sync_departments();
            } else {
                update_option(ARALCO_SLUG . '_last_sync_department_count', 0);
                update_option(ARALCO_SLUG . '_last_sync_duration_departments', 0);
            }

            if(in_array('groupings', $options)) {
                Aralco_Processing_Helper::sync_groupings();
            } else {
                update_option(ARALCO_SLUG . '_last_sync_grouping_count', 0);
                update_option(ARALCO_SLUG . '_last_sync_duration_groupings', 0);
            }

            if(in_array('grids', $options)) {
                Aralco_Processing_Helper::sync_grids();
            } else {
                update_option(ARALCO_SLUG . '_last_sync_grid_count', 0);
                update_option(ARALCO_SLUG . '_last_sync_duration_grids', 0);
            }

            if(in_array('products', $options)) {
                Aralco_Processing_Helper::sync_products();
            } else {
                update_option(ARALCO_SLUG . '_last_sync_product_count', 0);
                update_option(ARALCO_SLUG . '_last_sync_duration_products', 0);
            }

            if(in_array('stock', $options)) {
                Aralco_Processing_Helper::sync_stock();
            } else {
                update_option(ARALCO_SLUG . '_last_sync_stock_count', 0);
                update_option(ARALCO_SLUG . '_last_sync_duration_stock', 0);
            }

            if(in_array('customer_groups', $options)) {
                Aralco_Processing_Helper::sync_customer_groups();
            } else {
                update_option(ARALCO_SLUG . '_last_sync_customer_groups_count', 0);
                update_option(ARALCO_SLUG . '_last_sync_duration_customer_groups', 0);
            }

            if(in_array('taxes', $options)) {
                Aralco_Processing_Helper::sync_taxes();
            } else {
                update_option(ARALCO_SLUG . '_last_sync_taxes_count', 0);
                update_option(ARALCO_SLUG . '_last_sync_duration_taxes', 0);
            }

            update_option(ARALCO_SLUG . '_last_sync', date("Y-m-d\TH:i:s"));
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

    /**
     * Registers new user as customer in Aralco
     *
     * @param int $user_id the id of the new wordpress user
     */
    public function new_customer($user_id) {
        $id = Aralco_Processing_Helper::process_new_customer($user_id);
        if(!$id || $id instanceof WP_Error) return;
        update_user_meta($user_id, 'aralco_data', array('id' => $id));
        $this->customer_login(get_user_by('ID', $user_id)->user_login);
    }

    /**
     * Get aralco info for user that just logged in and cache it
     *
     * @param string $username the user's name
     */
    public function customer_login($username) {
        $user = get_user_by('login', $username);
        $aralco_data = get_user_meta($user->ID, 'aralco_data', true);
        if (!empty($aralco_data)) {
            // aralco id was found, pull the data.
            $data = Aralco_Connection_Helper::getCustomer('Id', $aralco_data['id']);
        } else {
            // aralco id wasn't found. Let's try pulling by email instead.
            $data = Aralco_Connection_Helper::getCustomer('UserName', $user->user_email);
        }

        if (!$data || $data instanceof WP_Error) {
            // No aralco user was found. No meta will be pulled.
            return;
        }

        unset($data['password']); // Saving this to the DB would be confusing since we don't use it.

        update_user_meta($user->ID, 'aralco_data', $data);
    }

    /**
     * @param string $price_html
     * @param WC_Product $product
     * @return string
     */
    public function alter_price_display($price_html, $product) {
        $retail_by = get_post_meta($product->get_id(), '_aralco_retail_by', true);
        $sell_by = get_post_meta($product->get_id(), '_aralco_sell_by', true);
        $unit = (is_array($retail_by) && !is_admin())? '/' . $retail_by['code'] : ((is_array($sell_by))? '/' . $sell_by['code'] : '');

        // if there is no price, there's nothing to do.
        if ($product->get_price() === '') return $price_html;

        // only modify the price on the front end. Leave the admin panel alone
        if (is_admin()) return $price_html . $unit;

        // if logged in,
        $orig_price = wc_get_price_to_display($product);
        if (is_user_logged_in()) {
            $new_price = $this::get_customer_group_price($orig_price, $product->get_id(), true);

            // check if a discount was applied. if not, nothing to do.
            if(abs($new_price - $orig_price) < 0.00001) return $price_html . $unit;

            // Update the show price
            $price_html = wc_price($new_price);
        } else if (is_array($retail_by) && is_numeric($retail_by['price'])) {
            $price_html = wc_price($retail_by['price']);
        }
        return $price_html . $unit;
    }

    /**
     * @param string $price_html
     * @param array $product
     * @return string
     */
    public function alter_cart_price_display($price_html, $product) {
        $retail_by = get_post_meta($product['product_id'], '_aralco_retail_by', true);
        $sell_by = get_post_meta($product['product_id'], '_aralco_sell_by', true);
        $unit = (is_array($retail_by) && !is_admin())? '/' . $retail_by['code'] : ((is_array($sell_by))? '/' . $sell_by['code'] : '');
        if (!empty($unit)){
            return wc_get_product($product['product_id'])->get_price_html();
        }
        return $price_html . $unit;	// change weight measurement here
    }

    /**
     * @param string $quantity_html
     * @param array $cart_item
     * @param string $cart_item_key
     * @return string
     */
    public function alter_cart_quantity_display($quantity_html, $cart_item, $cart_item_key) {
        $sell_by = get_post_meta($cart_item['product_id'], '_aralco_sell_by', true);
        $unit = (is_array($sell_by))? ' ' . $sell_by['code'] . ' ' : '';
        $decimals = (is_array($sell_by) && is_numeric($sell_by['decimals']))? $sell_by['decimals'] : 0;
        $quantity = $cart_item['quantity'];
        if ($decimals > 0) {
            $quantity = $cart_item['quantity'] / (10 ** $decimals);
        }
        return ' <strong class="product-quantity">' . sprintf('&times; %s', $quantity) . $unit . '</strong>';	// change weight measurement here
    }

    /**
     * Changes the display price based on customer group discount and UoM
     *
     * @param WC_Cart $cart
     */
    public function alter_price_cart($cart) {
        // Don't do this for ajax calls or the admin interface
        if (is_admin() && !defined('DOING_AJAX')) return;

        // Required so we do it at the right time and not more than once
        if (did_action('woocommerce_before_calculate_totals') >= 2) return;

        // Apply the discount to each item
        /** @var WC_Product[] $cart_item */
        if(is_user_logged_in()) {
            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];
                $price = $product->get_price();

                // No discount applied, so nothing to do.
                $new_price = $this::get_customer_group_price($price, $product->get_id());
                if (abs($new_price - $price) < 0.00001) continue;

                // Modify the price
                $cart_item['data']->set_price($new_price);
            }
        }

        // Once again for UoM
        /** @var WC_Product[] $cart_item */
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];

            $sell_by = get_post_meta($product->get_id(), '_aralco_sell_by', true);
            if(!is_array($sell_by)) continue;

            $unit = (is_array($sell_by) && !empty($sell_by['code']))? ' ' . $sell_by['code'] : '';
            if(empty($unit)) continue;

            $decimals = (is_array($sell_by) && is_numeric($sell_by['decimals']))? $sell_by['decimals'] : 0;
            if($decimals <= 0) continue;

            // Modify the price
            $cart_item['data']->set_price(doubleval($product->get_price()) / (10 ** $decimals));
        }
    }

    /**
     * @param int $stock_quantity Stock quantity
     * @param WC_Product $product Product instance
     * @return string
     */
    public function alter_availability_text($stock_quantity, $product) {
        $sell_by = get_post_meta($product->get_id(), '_aralco_sell_by', true);
        $unit = (is_array($sell_by) && !empty($sell_by['code']))? ' ' . $sell_by['code'] : '';
        $multi = (is_array($sell_by) && is_numeric($sell_by['multi']))? $sell_by['multi'] : 1;
        $decimals = (is_array($sell_by) && is_numeric($sell_by['decimals']))? $sell_by['decimals'] : 0;
        if($decimals > 0) {
            return round(($stock_quantity / $multi) / (10 ** $decimals), $decimals) . $unit;
        }
        return round($stock_quantity / $multi) . $unit;
    }

    /**
     * @param string $html
     * @param WC_Product $product
     * @return string
     */
    public function replacing_add_to_cart_button($html, $product) {
        $sell_by = get_post_meta($product->get_id(), '_aralco_sell_by', true);
        $is_unit = is_array($sell_by) && !empty($sell_by['code']);
        if ($is_unit) {
            $button_text = __("Select qty", "woocommerce");
            $html = '<a class="button" href="' . $product->get_permalink() . '">' . $button_text . '</a>';
        }
        return $html;
    }

    /**
     * @param string $html
     * @param object $data
     * @param WC_Product $product
     * @return string
     */
    public function blocks_product_grid_item_html($html, $data, $product) {
        $sell_by = get_post_meta($product->get_id(), '_aralco_sell_by', true);
        if(!is_array($sell_by) || empty($sell_by['code'])) return $html;

        $attributes = array(
            'aria-label'       => $product->add_to_cart_description(),
//            'data-quantity'    => '1',
//            'data-product_id'  => $product->get_id(),
//            'data-product_sku' => $product->get_sku(),
            'rel'              => 'nofollow',
            'class'            => 'wp-block-button__link add_to_cart_button',
        );

        if ($product->supports('ajax_add_to_cart')) {
            $attributes['class'] .= ' ajax_add_to_cart';
        }

        $button = sprintf(
            '<a href="%s" %s>%s</a>',
            esc_url($product->get_permalink()),
            wc_implode_html_attributes( $attributes ),
            esc_html(__('Select qty', ARALCO_SLUG)/*$product->add_to_cart_text()*/)
        );
        $button = '<div class="wp-block-button wc-block-grid__product-add-to-cart">' . $button. '</div>';

        return "<li class=\"wc-block-grid__product\">
    <a href=\"{$data->permalink}\" class=\"wc-block-grid__product-link\">
        {$data->image}
        {$data->title}
    </a>
    {$data->badge}
    {$data->price}
    {$data->rating}
    $button
</li>";
    }

    public function replace_quantity_field() {
        if (is_cart()) return;
        /** @var $product WC_Product */
        global $product;
        $sell_by = get_post_meta($product->get_id(), '_aralco_sell_by', true);
        $is_unit = is_array($sell_by) && !empty($sell_by['code']);
        if($is_unit) {
            $decimal = (!empty($sell_by['decimals']))? $sell_by['decimals'] : 0;
            if ($decimal > 0) {
                $min = number_format(1 / (10 ** $decimal), $decimal);
                $size = $decimal + 4;
                wc_enqueue_js(/** @lang JavaScript */ "$('input.qty').prop('value', '').prop('step', '${min}')
.prop('min', '${min}').prop('inputmode', 'decimal').prop('size', '${size}').css('width', '100px').attr('inputmode', 'decimal')
.prop('name', '').after('<input type=\"hidden\" class=\"true-qty\" name=\"quantity\" value=\"\">');
$('form.cart').on('submit', function() {
    if(!document.querySelector('input.qty').value) return false;
    let decVal = parseFloat(document.querySelector('input.qty').value);
    document.querySelector('input.true-qty').value = decVal * Math.pow(10, ${decimal});
})");
            }
            echo $sell_by['code'];
        }
    }

    /**
     * @param string $product_quantity
     * @param int|string $cart_item_key
     * @param array $cart_item
     * @return string
     */
    public function cart_item_quantity($product_quantity, $cart_item_key, $cart_item) {
        $sell_by = get_post_meta($cart_item['product_id'], '_aralco_sell_by', true);
        $is_unit = is_array($sell_by) && !empty($sell_by['code']);
        if($is_unit) {
            $decimal = (!empty($sell_by['decimals']))? $sell_by['decimals'] : 0;
            $code = $sell_by['code'];
            if ($decimal > 0) {
                $min = number_format(1 / (10 ** $decimal), $decimal);
                $repeated_snippet = /** @lang JavaScript */ "
$('input[name=\"cart[$cart_item_key][qty]\"]').prop('min', '$min').prop('value',
    parseInt($('input[name=\"cart[$cart_item_key][qty]\"]').prop('value')) / Math.pow(10, ${decimal})
).prop('step', '$min').prop('inputmode', 'decimal').attr('inputmode', 'decimal').addClass('item-$cart_item_key')
.prop('name', '').attr('name', '').on('keypress', function(e) {
    if(e.which == 13) {
        $(this).blur();
        $('button[name=update_cart]').click();
    }
}).after('&nbsp;$code').after('<input type=\"hidden\" name=\"cart[$cart_item_key][qty]\" value=\"\">');
$('form.woocommerce-cart-form').on('submit', function() {
    let decVal = parseFloat(document.querySelector('input.item-$cart_item_key').value);
    document.querySelector('input[name=\"cart[$cart_item_key][qty]\"]').value = decVal * Math.pow(10, ${decimal});
});";
            } else {
                $repeated_snippet = /** @lang JavaScript */ "$('input[name=\"cart[$cart_item_key][qty]\"]').after('&nbsp;$code')";
            }
            wc_enqueue_js(/** @lang JavaScript */ "$repeated_snippet
$(document.body).on('updated_wc_div', function() {
$repeated_snippet
})");
        }
        return $product_quantity;
    }

    public function add_to_cart_qty_html($amount_html) {
        return '';
    }

    public function add_decimal_text() {
        /** @var $product WC_Product */
        global $product;
        $sell_by = get_post_meta($product->get_id(), '_aralco_sell_by', true);
        $is_unit = is_array($sell_by) && !empty($sell_by['code']);
        if($is_unit) {
            $decimal = (!empty($sell_by['decimals']))? $sell_by['decimals'] : 0;
            if($decimal > 0) {
                echo "<div>Up to ${decimal} decimal places.</div>";
            }
        }
    }

    /**
     * @param int $quantity
     * @return int
     */
    public function cart_contents_count($quantity){
        global $woocommerce;
        $items = $woocommerce->cart->get_cart();
        $count = 0;
        foreach ($items as $item){
            $sell_by = get_post_meta($item['product_id'], '_aralco_sell_by', true);
            $is_unit = is_array($sell_by) && !empty($sell_by['code']);
            $count += ($is_unit)? 1 : $item['quantity'];
        }
        return $count;
    }

    /**
     * @param string $quantity_html
     * @param array $cart_item
     * @param string|int $cart_item_key
     * @return string
     */
    public function widget_cart_item_quantity($quantity_html, $cart_item, $cart_item_key){
        $sell_by = get_post_meta($cart_item['product_id'], '_aralco_sell_by', true);
        $unit = (is_array($sell_by) && !empty($sell_by['code']))? ' ' . $sell_by['code'] : '';
        $decimals = (is_array($sell_by) && is_numeric($sell_by['decimals']))? $sell_by['decimals'] : 0;
        $quantity = ($decimals > 0)? $cart_item['quantity'] / (10 ** $decimals) : $cart_item['quantity'];
        $product_price = apply_filters('woocommerce_cart_item_price', WC()->cart->get_product_price(wc_get_product($cart_item['product_id'])), $cart_item, $cart_item_key);
        return '<span class="quantity">' . sprintf( '%s &times; %s', $quantity . $unit, $product_price ) . '</span>';
    }

    /**
     * @param string $html
     * @param WC_Order_Item_Product $item
     * @return string
     */
    public function order_item_quantity_html($html, $item) {
        $sell_by = get_post_meta($item->get_product_id(), '_aralco_sell_by', true);
        if (!is_array($sell_by)) return $html;

        $unit = (!empty($sell_by['code']))? ' ' . $sell_by['code'] : '';
        if (empty($sell_by['code'])) return $html;

        $decimals = (is_array($sell_by) && is_numeric($sell_by['decimals']))? $sell_by['decimals'] : 0;
        if ($decimals <= 0) return $html . $unit;

        $order = $item->get_order();
        $refunded_qty = $order->get_qty_refunded_for_item($item->get_id());
        $qty = $item->get_quantity() / (10 ** $decimals);

        if ($refunded_qty) {
            $refunded_qty = $refunded_qty / (10 ** $decimals);
            $qty_display = '<del>' . esc_html($qty) . '</del> <ins>' . esc_html($qty - ($refunded_qty * -1)) . '</ins>';
        } else {
            $qty_display = esc_html($qty);
        }

        if(strpos($html, 'product-quantity') !== false) {
            return ' <strong class="product-quantity">' . sprintf( '&times;&nbsp;%s', $qty_display ) . '</strong>' . $unit;
        }

        return $qty_display . $unit;
    }

    /**
     * Source copied from wc-template-functions.php function wc_display_item_meta
     * @see ../woocommerce/includes/wc-template-functions.php
     *
     * @param string $old_html
     * @param WC_Order_Item $item
     * @param array $args
     * @return string
     */
    public function display_item_meta($old_html, $item, $args) {
        $strings = array();
        $html = '';

        foreach ($item->get_formatted_meta_data() as $meta_id => $meta) {
            if ($meta->key == 'Backordered') {
                $sell_by = get_post_meta($item->get_product_id(), '_aralco_sell_by', true);
                $unit = (is_array($sell_by) && !empty($sell_by['code']))? ' ' . $sell_by['code'] : '';
                $decimals = (is_array($sell_by) && is_numeric($sell_by['decimals']))? $sell_by['decimals'] : 0;
                if ($decimals > 0) {
                    $meta->display_value = round($meta->value / (10 ** $decimals), $decimals) . $unit;
                }
            }
            $value = $args['autop']? wp_kses_post($meta->display_value) : wp_kses_post(make_clickable(trim($meta->display_value)));
            $strings[] = $args['label_before'] . wp_kses_post($meta->display_key) . $args['label_after'] . $value;
        }

        if ($strings) {
            $html = $args['before'] . implode($args['separator'], $strings) . $args['after'];
        }

        return $html;
    }

    /**
     * Displays aralco ID for admins
     */
    public function display_aralco_id() {
        if(current_user_can('administrator')) {
            $id = get_post_meta(get_the_ID(), '_aralco_id', true);
            if ($id == false) {
                $id = get_post_meta(wp_get_post_parent_id(get_the_ID()), '_aralco_id', true);
            }
            if ($id == false) {
                $id = 'Unknown';
            }
            echo '<span class="aralco_id_wrapper">Aralco ID: <span class="aralco_id">' . $id . '</span></span>';
        }
    }

    /**
     * @param WC_Email $email_class
     */
    public function email($email_class) {
        remove_action('woocommerce_low_stock_notification', array($email_class, 'low_stock'));
        remove_action('woocommerce_product_on_backorder_notification', array($email_class, 'backorder'));
        remove_action('woocommerce_no_stock_notification', array($email_class, 'no_stock'));
    }

    /**
     * Takes the normal price and returns the discounted price.
     *
     * @param float $normal_price
     * @param int $product_id
     * @param bool $is_retail_by_price
     * @return float
     */
    private function get_customer_group_price($normal_price, $product_id, $is_retail_by_price = false) {
        global $current_aralco_user;

        $group_prices = get_post_meta($product_id, '_group_prices', true);
        if (!is_array($group_prices)){
            $group_prices = get_post_meta(wc_get_product($product_id)->get_parent_id(), '_group_prices', true);
        }
        if (is_array($group_prices) && count($group_prices) > 0) {
            $group_price = array_values(array_filter($group_prices, function($item) use ($current_aralco_user) {
                return $item['CustomerGroupID'] === $current_aralco_user['customerGroupID'];
            }));
            if (count($group_price) > 0 && is_numeric($group_price[0]['Price']) && $group_price[0]['Price'] > 0){
                $normal_price = $group_price[0]['Price'];

                $sell_or_retail_by = get_post_meta($product_id, '_aralco_sell_by', true);
                if ($is_retail_by_price) {
                    $temp = get_post_meta($product_id, '_aralco_retail_by', true);
                    if(is_array($temp)) $sell_or_retail_by = $temp;
                    unset($temp);
                }
                if (!is_array($sell_or_retail_by)) return $normal_price;

                $multi = (is_numeric($sell_or_retail_by['multi']))? $sell_or_retail_by['multi'] : 1;
                if ($multi > 1) return $normal_price * $multi;
            }
        }

//        if (isset($current_aralco_user['customerGroupID']) && count($aralco_groups) > 0){
//            $new = array_values(array_filter($aralco_groups, function($item) use ($current_aralco_user) {
//                return $item['customerGroupID'] === $current_aralco_user['customerGroupID'];
//            }));
//            if (count($new) > 0 && is_numeric($new[0]['discountPercent']) && $new[0]['discountPercent'] > 0){
//                return round($normal_price * (1.0 - (floatval($new[0]['discountPercent']) / 100)), 2);
//            }
//        }

        return $normal_price;
    }

    public function cart_check_product_stock() {

        $products_to_update = [];
        $store_id = get_option(ARALCO_SLUG . '_options')[ARALCO_SLUG . '_field_store_id'];

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

        if(count($products_to_update) <= 0) return;

        $result = Aralco_Processing_Helper::sync_stock($products_to_update);

        if ($result instanceof WP_Error) {
            wc_add_notice($result->get_error_message(), 'error');
        }
    }

    /**
     * Catches completed orders and pushes them back to Aralco
     *
     * @param $order_id
     */
    public function submit_order_to_aralco($order_id) {
        Aralco_Processing_Helper::process_order($order_id);
    }

    /**
     * Registers the built in product taxonomy for tracking if a product is new, on special, or on clearance
     */
    public function register_product_taxonomy() {
        $taxonomy = wc_attribute_taxonomy_name('aralco-flags');

        // Create the Taxonomy
        if(!taxonomy_exists($taxonomy)){
            $id = wc_create_attribute(array(
                'name' => 'Aralco Flags',
                'slug' => $taxonomy,
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => true
            ));
            if ($id instanceof WP_Error) return;
        }

        // Will be true only immediately after the taxonomy was created. Will be false on next page load.
        if(!taxonomy_exists($taxonomy)) return;

        $terms = array('New', 'Special', 'Clearance', 'Catalogue Only');
        foreach($terms as $index => $value) {
            $slug = sprintf('%s-val-%s', $taxonomy, Aralco_Util::sanitize_name($value));
            $existing = get_term_by('slug', $slug, $taxonomy);
            if ($existing == false){
                $result = wp_insert_term($value, $taxonomy, array('slug' => $slug));
                if($result instanceof WP_Error) continue;

                $id = $result['term_id'];
                delete_term_meta($id, 'order');
                delete_term_meta($id, 'order_' . $taxonomy);
                add_term_meta($id, 'order', $index);
                add_term_meta($id, 'order_' . $taxonomy, $index);
            }
        }
    }
}

require_once 'aralco-widget.php';
require_once 'aralco-rest.php';

new Aralco_WooCommerce_Connector();
