<?php
/**
 * Plugin Name: Aralco WooCommerce Connector
 * Plugin URI: https://github.com/sonicer105/aralcowoocon
 * Description: WooCommerce Connector for Aralco POS Systems.
 * Version: 1.26.1
 * Author: Elias Turner, Aralco
 * Author URI: https://aralco.com
 * Requires at least: 5.0
 * Tested up to: 5.7.2
 * Text Domain: aralco_woocommerce_connector
 * Domain Path: /languages/
 * WC requires at least: 4.0
 * WC tested up to: 5.3.0
 *
 * @package Aralco_WooCommerce_Connector
 * @version 1.26.1
 */

defined( 'ABSPATH' ) or die(); // Prevents direct access to file.

define('ARALCO_SLUG', 'aralco_woocommerce_connector');

//add_filter( 'jetpack_development_mode', '__return_true' ); //TODO: Remove when done

$current_aralco_user = array();
$aralco_groups = array();

require_once 'aralco-util.php';
require_once 'aralco-admin-settings-input-validation.php';
require_once 'aralco-connection-helper.php';
require_once 'aralco-processing-helper.php';
require_once 'aralco-shipping-methods.php';

/**
 * Class Aralco_WooCommerce_Connector
 *
 * Main class in the plugin. All the core logic is contained here.
 */
class Aralco_WooCommerce_Connector {
    public static $loggingEnabled = null;

    /**
     * Aralco_WooCommerce_Connector constructor.
     */
    public function __construct(){

        // register sync hook and deactivation hook
        add_filter('cron_schedules', array($this, 'custom_cron_timespan'));
        add_action( ARALCO_SLUG . '_sync_products', array($this, 'sync_products_quite'));
        register_activation_hook(__FILE__, array($this, 'plugin_deactivation_hook'));
        register_deactivation_hook(__FILE__, array($this, 'plugin_deactivation_hook'));

        add_action('init', array($this, 'register_globals'));

        // Check if WooCommerce is active
        if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            // trigger add product sync to cron (will only do if enabled)
            $this->schedule_sync();

            add_action('woocommerce_email_classes', array($this, 'register_email_classes'));

            // register our settings_init to the admin_init action hook
            add_action('admin_init', array($this, 'settings_init'));

            // register our options_page to the admin_menu action hook
            add_action('admin_menu', array($this, 'options_page'));

            // register order hooks
            add_action('woocommerce_after_order_notes', array($this, 'reference_number_checkout_field'));
            add_action('woocommerce_checkout_process', array($this, 'reference_number_checkout_field_process'));
            add_action('woocommerce_checkout_update_order_meta', array($this, 'reference_number_checkout_field_update_order_meta'));
            add_action('woocommerce_payment_complete', array($this, 'submit_order_to_aralco'), 10, 1);
            add_action('woocommerce_order_details_before_order_table', array($this, 'show_reference_number'), 10, 1);
            add_action('woocommerce_email_order_details', array($this, 'show_reference_number_email'), 10, 4);
            add_filter('woocommerce_endpoint_order-pay_title', array($this, 'endpoint_order_pay_title'), 10, 2);
            add_filter('woocommerce_endpoint_order-received_title', array($this, 'endpoint_order_received_title'), 10, 2);
            add_filter('woocommerce_endpoint_orders_title', array($this, 'endpoint_orders_title'), 10, 2);
            add_filter('woocommerce_endpoint_view-order_title', array($this, 'endpoint_view_order_title'), 10, 2);
            add_filter('woocommerce_account_menu_items', array($this, 'account_menu_items'), 10, 2);
            add_filter('woocommerce_my_account_my_orders_columns', array($this, 'my_account_my_orders_columns'), 10, 1);
            add_filter('woocommerce_order_button_text', array($this, 'order_button_text'), 10, 1);
            add_filter('woocommerce_checkout_fields', array($this, 'checkout_fields'), 10, 1);
            add_filter('woocommerce_thankyou_order_received_text', array($this, 'thankyou_order_received_text'), 10, 2);

            add_filter('woocommerce_available_payment_gateways', array($this, 'quote_all_payment_gateway_disable'));
            add_action('woocommerce_thankyou', array($this, 'quote_update_order_status_pending'));
            add_filter('woocommerce_cart_needs_payment', array($this, 'quote_cart_needs_payment'), 10, 2);
            add_filter('woocommerce_order_needs_payment', array($this, 'quote_order_needs_payment'), 10, 3);

            // register new user hook
            add_action('user_register', array($this, 'new_customer'));

            // register login hook
            add_action('wp_login', array($this, 'customer_login'));
            add_action('aralco_refresh_user_data', array($this, 'customer_login'));
            add_action('wp_loaded', array($this, 'intercept_lost_password_form'), 30);

            // register login redirect hook
            add_action('template_redirect', array($this, 'require_customer_login'));

            // register customer update hook
            add_action('wp_login', array($this, 'trigger_update_customer_info'));
            add_action('woocommerce_update_customer', array($this, 'update_customer_info'));

            // register custom product taxonomy
            add_action('admin_init', array($this, 'register_aralco_flags_taxonomy'));
            add_action('admin_init', array($this, 'register_supplier_taxonomy'));

            // register template overrides
            add_filter('woocommerce_locate_template', array($this, 'woocommerce_locate_template'), 10, 3);

            // register template scripts
            add_action('wp_enqueue_scripts', array($this, 'enqueue_cart_checkout_scripts'));

            // register customer group price and UoM hooks
            add_filter('woocommerce_get_price_html', array($this, 'alter_price_display'), 100, 2);
            add_filter('woocommerce_cart_item_price', array($this, 'alter_cart_price_display'), 100, 2);
            add_filter('woocommerce_cart_item_subtotal', array($this, 'alter_cart_item_subtotal_display'), 100, 3);
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
//            add_filter('woocommerce_email', array($this, 'email'), 100);

            // register aralco id field display (for admins)
            add_action('woocommerce_product_meta_start', array($this, 'display_aralco_id'), 101, 0);

            // register stock check for cart page hook
            add_action('woocommerce_before_cart', array($this, 'cart_check_product_stock'), 100, 0);

            // register tax handler
            add_action('woocommerce_cart_totals_get_item_tax_rates', array($this, 'calculate_custom_tax_totals'), 10, 3);

            // register points updates
            add_action('woocommerce_before_cart', array($this, 'update_points'), 100, 0);
            add_filter('woocommerce_calculated_total', array($this, 'apply_points_discount'), 10, 2);
            add_action('woocommerce_payment_complete', array($this, 'complete_order_points'), 10, 1);
            add_action('woocommerce_cart_totals_before_order_total', array($this, 'show_points_block'));
            add_action('woocommerce_review_order_before_order_total', array($this, 'show_points_block'));
            add_filter('woocommerce_get_order_item_totals', array($this, 'points_get_order_item_totals'), 10 , 3);
            add_action('woocommerce_admin_order_totals_after_total', array($this, 'admin_points_display'), 1 , 1);

            // Register itemized notes hooks

            add_action('woocommerce_after_cart_item_name', array($this, 'add_notes_input_after_cart_item_name'), 10, 2);
            add_action('wp_enqueue_scripts', array($this, 'itemized_notes_enqueue_scripts'));
            add_action('wp_ajax_aralco_update_cart_item_notes', array($this, 'save_item_note_to_cart'));
            add_action('woocommerce_checkout_create_order_line_item', array($this, 'add_item_notes_meta_cart_item'), 10, 4);
            add_filter('woocommerce_get_item_data', array($this, 'add_notes_to_item_data'), 10, 2);

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
        self::log_error('Plugin was loaded while WooCommerce was unavailable!');
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
     * Register email class overrides
     */
    public function register_email_classes($email_classes) {
        $email_classes['WC_Email_New_Order']                 = require __DIR__ . '/woocommerce/emails/class-wc-email-new-order.php';
        $email_classes['WC_Email_Cancelled_Order']           = require __DIR__ . '/woocommerce/emails/class-wc-email-cancelled-order.php';
        $email_classes['WC_Email_Failed_Order']              = require __DIR__ . '/woocommerce/emails/class-wc-email-failed-order.php';
        $email_classes['WC_Email_Customer_On_Hold_Order']    = require __DIR__ . '/woocommerce/emails/class-wc-email-customer-on-hold-order.php';
        $email_classes['WC_Email_Customer_Processing_Order'] = require __DIR__ . '/woocommerce/emails/class-wc-email-customer-processing-order.php';
        $email_classes['WC_Email_Customer_Completed_Order']  = require __DIR__ . '/woocommerce/emails/class-wc-email-customer-completed-order.php';
        $email_classes['WC_Email_Customer_Refunded_Order']   = require __DIR__ . '/woocommerce/emails/class-wc-email-customer-refunded-order.php';
//        $email_classes['WC_Email_Customer_Invoice']          = require __DIR__ . '/woocommerce/emails/class-wc-email-customer-invoice.php';
        $email_classes['WC_Email_Customer_Note']             = require __DIR__ . '/woocommerce/emails/class-wc-email-customer-note.php';

        return $email_classes;
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
            ARALCO_SLUG . '_field_enable_logging',
            __('Enable Logging', ARALCO_SLUG),
            array($this, 'field_checkbox'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_global_section',
            [
                'label_for' => ARALCO_SLUG . '_field_enable_logging',
                'required' => 'required'
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
            ARALCO_SLUG . '_field_login_required',
            __('Require login to view store', ARALCO_SLUG),
            array($this, 'field_checkbox'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_global_section',
            [
                'label_for' => ARALCO_SLUG . '_field_login_required',
                'required' => 'required'
            ]
        );

        add_settings_field(
            ARALCO_SLUG . '_field_enable_points',
            __('Enable Points', ARALCO_SLUG),
            array($this, 'field_checkbox'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_global_section',
            [
                'label_for' => ARALCO_SLUG . '_field_enable_points',
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
            ARALCO_SLUG . '_field_sync_chunking',
            __('Sync Chunking', ARALCO_SLUG),
            array($this, 'field_input'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_global_section',
            [
                'type' => 'number',
                'step' => '1',
                'min' => '1',
                'max' => '100000',
                'label_for' => ARALCO_SLUG . '_field_sync_chunking',
                'description' => 'Used to determine the amount of items to process per chunk when doing a manual sync. 20 is the recommended but lower it if you have timeout issues. Raising it provides no benefit. A lower value can cause longer syncs.',
                'class' => ARALCO_SLUG . '_row',
                'placeholder' => '20',
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
                    'Suppliers' => 'suppliers',
                    'Products' => 'products',
                    'Stock' => 'stock',
                    'Customer Groups' => 'customer_groups',
                    'Taxes' => 'taxes',
                    'Stores' => 'stores'
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
            ARALCO_SLUG . '_field_order_is_quote',
            __('Submit as Quote', ARALCO_SLUG),
            array($this, 'field_checkbox'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_order_section',
            [
                'label_for' => ARALCO_SLUG . '_field_order_is_quote',
                'required' => 'required',
                'description' => 'When checked, any new orders will be sent to Aralco as a Quote instead of an Order'
            ]
        );

        add_settings_field(
            ARALCO_SLUG . '_field_order_quote_text',
            __('Replace Word "Order"', ARALCO_SLUG),
            array($this, 'field_checkbox'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_order_section',
            [
                'label_for' => ARALCO_SLUG . '_field_order_quote_text',
                'required' => 'required',
                'description' => 'When checked, any instances of the word order are replaced with quote'
            ]
        );

        add_settings_field(
            ARALCO_SLUG . '_field_order_itemized_notes',
            __('Allow Per-item Notes', ARALCO_SLUG),
            array($this, 'field_checkbox'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_order_section',
            [
                'label_for' => ARALCO_SLUG . '_field_order_itemized_notes',
                'required' => 'required',
                'description' => 'When checked, customers can supply notes on a per-item basis'
            ]
        );

        add_settings_field(
            ARALCO_SLUG . '_field_reference_number_enabled',
            __('Ref Field Enabled', ARALCO_SLUG),
            array($this, 'field_checkbox'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_order_section',
            [
                'label_for' => ARALCO_SLUG . '_field_reference_number_enabled',
                'required' => 'required',
                'description' => 'When checked, the reference number input field is shown to the customer when checking out.'
            ]
        );

        add_settings_field(
            ARALCO_SLUG . '_field_reference_number_required',
            __('Ref Field Required', ARALCO_SLUG),
            array($this, 'field_checkbox'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_order_section',
            [
                'label_for' => ARALCO_SLUG . '_field_reference_number_required',
                'required' => 'required',
                'description' => 'When checked, the reference number input field is required to be filled to check out.'
            ]
        );

        add_settings_field(
            ARALCO_SLUG . '_field_reference_number_label',
            __('Ref Number Label', ARALCO_SLUG),
            array($this, 'field_input'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_order_section',
            [
                'type' => 'text',
                'label_for' => ARALCO_SLUG . '_field_reference_number_label',
                'class' => ARALCO_SLUG . '_row',
                'placeholder' => 'Claim #',
                'description' => 'What the reference number field label should be. Leave blank to use \'Reference #\'.'
            ]
        );

//        add_settings_field(
//            ARALCO_SLUG . '_field_default_order_email',
//            __('Default Order Email', ARALCO_SLUG),
//            array($this, 'field_input'),
//            ARALCO_SLUG,
//            ARALCO_SLUG . '_order_section',
//            [
//                'type' => 'text',
//                'label_for' => ARALCO_SLUG . '_field_default_order_email',
//                'class' => ARALCO_SLUG . '_row',
//                'placeholder' => 'john@example.com',
//                'required' => 'required',
//                'description' => 'Required for guest checkout. Please provide an email that is attached to a valid customer profile in Aralco. Not providing one will result in an error if a guest checks out.'
//            ]
//        );

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
            ARALCO_SLUG . '_field_store_id_stock_from',
            __('Stock From Store', ARALCO_SLUG),
            array($this, 'field_input'),
            ARALCO_SLUG,
            ARALCO_SLUG . '_order_section',
            [
                'type' => 'number',
                'step' => '1',
                'min' => '0',
                'max' => '999999',
                'label_for' => ARALCO_SLUG . '_field_store_id_stock_from',
                'class' => ARALCO_SLUG . '_row',
                'placeholder' => '1',
                'required' => 'required',
                'description' => 'The ID of the store to sync stock from.'
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
                    self::log_error(wp_get_current_user()->display_name . ' failed to update plugin settings!', $message);
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
                self::log_info(wp_get_current_user()->display_name . ' updated plugin settings', null, true);
            }
        } else if (isset($_POST['test-connection'])){
            self::log_info(wp_get_current_user()->display_name . ' used tool Test Connection');
            $this->test_connection();
        } else if (isset($_POST['fix-stock-count'])){
            self::log_info(wp_get_current_user()->display_name . ' used tool Fix Stock Count');
            $this->fix_stock_count();
        } else if (isset($_POST['remove-old-fields'])){
            self::log_info(wp_get_current_user()->display_name . ' used tool Remove Old Fields');
            $this->remove_old_fields();
        } else {
            self::log_info(wp_get_current_user()->display_name . ' accessed plugin admin page');
        }

        // show error/update messages
        require_once 'partials/aralco-admin-settings-display.php';
    }

    public function plugin_path() {
        // gets the absolute path to this plugin directory
        return untrailingslashit(plugin_dir_path(__FILE__));
    }

    public function woocommerce_locate_template($template, $template_name, $template_path) {
        global $woocommerce;

        $_template = $template;

        if (!$template_path) $template_path = $woocommerce->template_url;

        $plugin_path = $this->plugin_path() . '/woocommerce/';

        // Look within passed path within the theme - this is priority
        $template = locate_template(
            array(
                $template_path . $template_name,
                $template_name
            )
        );

        // Modification: Get the template from this plugin, if it exists
        if (!$template && file_exists($plugin_path . $template_name))
            $template = $plugin_path . $template_name;

        // Use default template
        if (!$template)
            $template = $_template;

        // Return what we found
        return $template;
    }

    public function enqueue_cart_checkout_scripts() {
        if(is_checkout() || is_cart()) {
            if (!wp_script_is('select2')) {
                wp_register_script('select2', WC()->plugin_url() . '/assets/js/select2/select2.full.min.js', array('jquery'), '4.0.3');
                wp_enqueue_style('select2');
            }
            if(!wp_script_is('selectWoo')){
                wp_register_script( 'selectWoo', WC()->plugin_url() . '/assets/js/selectWoo/selectWoo.full.min.js', array( 'jquery' ), '1.0.6' );
                wp_enqueue_script( 'selectWoo');
            }
            if(!wp_script_is('store-select2')){
                wp_register_script( 'store-select2', plugin_dir_url(__FILE__) . '/assets/js/store-select2.js', array( 'jquery' ), '1.0.1' );
                wp_enqueue_script( 'store-select2');
            }
            if(!wp_script_is('aralco-points')){
                wp_register_script( 'aralco-points', plugin_dir_url(__FILE__) . '/assets/js/points.js', array( 'jquery' ), '1.0.0' );
                wp_enqueue_script( 'aralco-points');
            }
        }
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
            self::log_info('Connection test successful');
        } else if($result instanceof WP_Error) {
            add_settings_error(
                ARALCO_SLUG . '_messages',
                ARALCO_SLUG . '_messages',
                $result->get_error_message(),
                'error'
            );
            self::log_error('Connection test failed!', $result);
        } else {
            // Shouldn't ever get here.
            add_settings_error(
                ARALCO_SLUG . '_messages',
                ARALCO_SLUG . '_messages',
                __('Something went wrong. Please contact Aralco.', ARALCO_SLUG) . ' (Code 1)',
                'error'
            );
            self::log_error('Connection test failed, but not WP_Error?', $result);
        }
    }

    /**
     * Used to fix the stock status to match the stock count
     */
    public function fix_stock_count() {
        $options = get_option(ARALCO_SLUG . '_options');
        $backorder_stock_status = ($options !== false &&
            isset($options[ARALCO_SLUG . '_field_allow_backorders']) &&
            $options[ARALCO_SLUG . '_field_allow_backorders'] == '1') ?
            'onbackorder' : 'outofstock';
        $result = 0;
        global $wpdb;

        // Add Meta
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1
        );
        $loop = new WP_Query( $args );
        foreach($loop->posts as $post) {
            $stock = get_post_meta($post->ID, '_stock', true);
            if (!$stock) {
                update_post_meta($post->ID, '_stock', 0);
            }
            update_post_meta($post->ID, '_manage_stock', 'yes');
        }

        foreach (array(
            array('<=', $backorder_stock_status),
            array('>', 'instock')
        ) as $i => $operation){
            $rows = $wpdb->get_results("SELECT DISTINCT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = '_stock' AND meta_value {$operation[0]} 0", ARRAY_A);
            $ids = [];
            foreach ($rows as $row) {
                $ids[] = $row['post_id'];
            }

            if(count($rows) <= 0) continue; // Nothing to do.

            $ids = implode(', ', $ids);

            $q = "UPDATE {$wpdb->prefix}postmeta SET meta_value = '{$operation[1]}' WHERE meta_key = '_stock_status' AND post_id IN ({$ids})";

//            add_settings_error(
//                ARALCO_SLUG . '_messages',
//                ARALCO_SLUG . '_messages_2',
//                "SELECT DISTINCT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = '_stock' AND meta_value {$operation[0]} 0",//print_r($rows, true),
//                'error'
//            );

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
     * Use to remove all legacy entries in wp_options table
     */
    public function remove_old_fields() {
        global $wpdb;
        /** @noinspection SqlResolve */
        $wpdb->query("DELETE FROM {$wpdb->prefix}options WHERE option_name like '" . ARALCO_SLUG . "_last_sync_%'" );
        return;
    }

    /**
     * Method called to sync products by WordPress cron. Unlike sync_products, this method provides no feedback and takes no options.
     */
    public function sync_products_quite() {
        self::log_info('CRON started automatic sync!');
        try{
            $options = get_option(ARALCO_SLUG . '_options');
            if(!isset($options[ARALCO_SLUG . '_field_sync_items'])){
                $options = array();
            } else {
                $options = $options[ARALCO_SLUG . '_field_sync_items'];
            }

            if(in_array('departments', $options)) Aralco_Processing_Helper::sync_departments();
            if(in_array('groupings', $options)) Aralco_Processing_Helper::sync_groupings();
            if(in_array('grids', $options)) Aralco_Processing_Helper::sync_grids();
            if(in_array('suppliers', $options)) Aralco_Processing_Helper::sync_suppliers();
            if(in_array('products', $options)) Aralco_Processing_Helper::sync_products();
            if(in_array('stock', $options)) Aralco_Processing_Helper::sync_stock();
            if(in_array('customer_groups', $options)) Aralco_Processing_Helper::sync_customer_groups();
            if(in_array('taxes', $options)) Aralco_Processing_Helper::sync_taxes();
            if(in_array('stores', $options)) Aralco_Processing_Helper::sync_stores();

            update_option(ARALCO_SLUG . '_last_sync', date("Y-m-d\TH:i:s"));
        } catch (Exception $e) {
            self::log_error('Uncaught error thrown while performing automatic sync!', $e);
        }
        self::log_info('CRON finished automatic sync!');
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
            $schedules = wp_get_schedules();
            if($schedules[ARALCO_SLUG . '_sync_timespan'] && !wp_next_scheduled(ARALCO_SLUG . '_sync_products')){
                wp_schedule_event(time() + $schedules[ARALCO_SLUG . '_sync_timespan']['interval'], ARALCO_SLUG . '_sync_timespan', ARALCO_SLUG . '_sync_products');
            }
        } else {
            $this->unschedule_sync();
        }
    }

    /**
     * Plugin Activation Hook
     */
    public function plugin_activation_hook() {
        self::log_warning('Plugin activated!', null, true);
    }

    /**
     * Plugin Activation Hook
     */
    public function plugin_deactivation_hook() {
        self::log_warning('Plugin deactivated!', null, true);
        $this->unschedule_sync();
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

        if($data) {
            self::log_info($username . ' logged in. Found saved Aralco ID ' . $data['id']);
        }

        try {
            if (!$data || $data instanceof WP_Error) {
                // Must be a legacy customer. Create or link it.
                $this->new_customer($user->ID);
                $aralco_data = get_user_meta($user->ID, 'aralco_data', true);
                $data = Aralco_Connection_Helper::getCustomer('Id', $aralco_data['id']);
                if (isset($data['id'])){
                    self::log_info($username . ' logged in. Couldn\'t find in aralco so it was created! Aralco ID ' . $data['id']);
                }
            }

            if (!$data || $data instanceof WP_Error) {
                // if it still doesn't exist, give up
                self::log_error($username . ' logged in. Couldn\'t find or create a customer in Aralco!', $data);
                return;
            }

        } catch (Exception $e) {
            self::log_error($username . ' logged in. Unhandled exception was thrown during Aralco ID matching!', $e);
            return;
        }

        unset($data['password']); // Saving this to the DB would be confusing since we don't use it.

        update_user_meta($user->ID, 'aralco_data', $data);
    }

    /**
     * Intermediary call to update customer profile on login
     *
     * @param $username string
     */
    public function trigger_update_customer_info($username) {
        $user = get_user_by('login', $username);
        try {
            $this->update_customer_info($user);
        } catch (Exception $e) {
            // Do Nothing
        }
    }

    /**
     * Update customer profile in Aralco
     *
     * @param $user WP_User
     */
    public function update_customer_info($user) {
        $aralco_data = get_user_meta($user->ID, "aralco_data", true);
        if(!!$aralco_data && isset($aralco_data['id'])) {
            $result = Aralco_Processing_Helper::process_customer_update($user->ID, $aralco_data['id']);
            if($result instanceof WP_Error || $result !== true) {
                self::log_error($user->user_login . ' updated their profile. Failed to update in Aralco.', $result);
                return;
            }
            self::log_info($user->user_login . ' updated their profile. Update in Aralco.');
        }
    }

    public function intercept_lost_password_form() {
        if (isset($_POST['wc_reset_password'], $_POST['user_login'])) {

            $errors = wc_get_notices('error');

            if(count($errors) == 0) return;

            $success = $this->import_user_from_aralco();

            // If successful, redirect to my account with query arg set.
            if ($success) {
                wp_safe_redirect(add_query_arg('reset-link-sent', 'true', wc_get_account_endpoint_url('lost-password')));
                exit;
            }
        }
    }

    public function import_user_from_aralco() {
        $data = Aralco_Connection_Helper::getCustomer('UserName', $_POST['user_login']);

        if (!$data || $data instanceof WP_Error) return false;

        $user_data = array(
            'first_name' => $data['name'],
            'last_name' => $data['surname']
        );

        $username = wc_create_new_customer_username($_POST['user_login'], $user_data);
        $password = wp_generate_password();

        $user_id = wc_create_new_customer($_POST['user_login'], $username, $password, $user_data);
        if ($user_id instanceof WP_Error) {
            self::log_error($_POST['user_login'] . ' requested a password reset, but they don\'t exist in WordPress. They do exist in Aralco but something went wrong when importing!', $user_id);
            return false;
        }

        self::log_info($_POST['user_login'] . ' requested a password reset, but they don\'t exist in WordPress. They do exist in Aralco through! importing....');
        return WC_Shortcode_My_Account::retrieve_password();
    }

    public function require_customer_login(){
        if(is_user_logged_in()) return;
        $options = get_option(ARALCO_SLUG . '_options');
        if($options !== false && isset($options[ARALCO_SLUG . '_field_login_required']) &&
            $options[ARALCO_SLUG . '_field_login_required'] == '1') {
            global $wp;
            if(!is_page('my-account')) {
                wp_redirect(home_url('my-account/my-account'));
            } else if(!isset($_GET['password-reset']) && home_url($wp->request) . '/' != wc_lostpassword_url()) {
//                wc_add_notice(__('Have an account but don\'t know the password? Click <a href="' . wc_lostpassword_url() . '">HERE</a> to set a new one.', ARALCO_SLUG) , 'notice');
            }
        }
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
     * @param string $product_subtotal
     * @param array $product cart item
     * @param string $cart_item_key
     * @return string
     */
    public function alter_cart_item_subtotal_display($product_subtotal, $cart_item, $cart_item_key) {
        $sell_by = get_post_meta($cart_item['product_id'], '_aralco_sell_by', true);
        $decimals = (is_array($sell_by) && is_numeric($sell_by['decimals']))? $sell_by['decimals'] : 0;

        if ($decimals > 0) {
            $quantity = (did_action('aralco_changed_quantity') < 1) ?
                $cart_item['quantity'] / (10 ** $decimals) : $cart_item['quantity'];
            return wc_price(doubleval($cart_item['data']->get_price()) * $quantity);
        }

        return wc_price($cart_item['line_subtotal']);
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
        do_action('aralco_changed_quantity');
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
        global $post;
        if(empty($post)) return;
//        echo "<pre>" . print_r($post, true) . "</pre>";
        $sell_by = get_post_meta($post->ID, '_aralco_sell_by', true);
        $is_unit = is_array($sell_by) && !empty($sell_by['code']);
        if($is_unit) {
            $decimal = (!empty($sell_by['decimals']))? $sell_by['decimals'] : 0;
            if ($decimal > 0) {
                $min = number_format(1 / (10 ** $decimal), $decimal);
                $size = $decimal + 4;
                wc_enqueue_js(/** @lang JavaScript */ "$(function(){ if(window.ranQtySwitch) return; $('form.cart input.qty').prop('value', '').prop('step', '${min}')
.prop('min', '${min}').prop('inputmode', 'decimal').prop('size', '${size}').css('width', '100px').attr('inputmode', 'decimal')
.prop('name', '').after('<input type=\"hidden\" class=\"true-qty\" name=\"quantity\" value=\"\">');
$('form.cart').on('submit', function() {
    if(!document.querySelector('form.cart input.qty').value) return false;
    let decVal = parseFloat(document.querySelector('form.cart input.qty').value);
    document.querySelector('form.cart input.true-qty').value = decVal * Math.pow(10, ${decimal})
});
if(!$('form.cart input.qty').val()) $('form.cart input.qty').val('1');
window.ranQtySwitch = true;
});");
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
if(!$('.woocommerce-cart-form input[name=\"cart[$cart_item_key][qty]\"]').data('processed')){
    $('.woocommerce-cart-form input[name=\"cart[$cart_item_key][qty]\"]')
        .hide()
        .data('processed', true)
        .after(
            $('.woocommerce-cart-form input[name=\"cart[$cart_item_key][qty]\"]')
            .clone(true)
            .off()
            .show()
            .prop('min', '$min')
            .prop('step', '$min')
            .prop('name', '')
            .prop('id', '')
            .val(parseFloat($('.woocommerce-cart-form input[name=\"cart[$cart_item_key][qty]\"]').val() / Math.pow(10, ${decimal})))
            .prop('value', parseFloat($('.woocommerce-cart-form input[name=\"cart[$cart_item_key][qty]\"]').val() / Math.pow(10, ${decimal})))
            .on('change', function (e){
                let multiple = Math.pow(10, ${decimal});
                let val = Math.round($(this).val() * multiple) / multiple;
                $(this).val(val);
                $(this).prop('value', val);
                $('.woocommerce-cart-form input[name=\"cart[$cart_item_key][qty]\"]').val(Math.round(val * multiple));
            })
        )
        .removeClass('qty');
}
";
            } else {
                $repeated_snippet = /** @lang JavaScript */ "$('.woocommerce-cart-form input[name=\"cart[$cart_item_key][qty]\"]').after('&nbsp;$code')";
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
        $store_id = get_option(ARALCO_SLUG . '_options')[ARALCO_SLUG . '_field_store_id_stock_from'];

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
            self::log_error("Attempted to update some product as they are in someone's cart and they are checking out, but something went wrong!", [$products_to_update, $result]);
            return;
        }
        self::log_info("Updated stock for products as it's in someone's cart and they are checking out.", $products_to_update);
    }

    public function update_points() {
        Aralco_Processing_Helper::update_points_exchange();
    }

    public function apply_points_discount($total, $cart) {
        $options = get_option(ARALCO_SLUG . '_options');
        $points_enabled = isset($options[ARALCO_SLUG . '_field_enable_points']) && $options[ARALCO_SLUG . '_field_enable_points'] == '1';
        if(!$points_enabled) return $total;

        $points_cache = get_user_meta(get_current_user_id(),'points_cache', true);
        if(isset($points_cache) && isset($points_cache['apply_to_order'])) {
            if($points_cache['apply_to_order'] > $total) {
                $points_cache = get_user_meta(get_current_user_id(),'points_cache', true);
                unset($points_cache["apply_to_order"]);
                update_user_meta(get_current_user_id(),'points_cache', $points_cache);
                wc_add_notice('Your points were removed from the cart because they exceeded the cart total.', 'notice');
            } else {
                return round($total - $points_cache['apply_to_order'], $cart->dp);
            }
        }
        return $total;
    }

    public function complete_order_points($order_id) {
        $options = get_option(ARALCO_SLUG . '_options');
        $points_enabled = isset($options[ARALCO_SLUG . '_field_enable_points']) && $options[ARALCO_SLUG . '_field_enable_points'] == '1';
        if($points_enabled && is_user_logged_in()){
            $points_cache = get_user_meta(get_current_user_id(),'points_cache', true);
            $points_multiplier = get_option(ARALCO_SLUG . '_points_exchange', 0);
            if(isset($points_cache) && isset($points_cache["apply_to_order"])) {
                $order = wc_get_order($order_id);
                $order->add_meta_data('aralco_points_value', $points_cache["apply_to_order"], true);
                $order->add_meta_data('aralco_points_exchange', $points_multiplier, true);
                $order->save();

                $points = $points_cache["apply_to_order"] / $points_multiplier;
                $aralco_user_data = get_user_meta(get_current_user_id(), 'aralco_data', true);
                if(isset($aralco_user_data) && isset($aralco_user_data['points'])) {
                    $aralco_user_data['points'] -= $points;
                    update_user_meta(get_current_user_id(), 'aralco_data', $aralco_user_data);
                }
            }
        }

        delete_user_meta(get_current_user_id(),'points_cache');
    }

    /* @var $order WC_Order */
    public function points_get_order_item_totals($total_rows, $order, $tax_display) {
        $points_value = $order->get_meta('aralco_points_value', 'true', 'edit');
        if(empty($points_value)) return $total_rows;

        $new_total_rows = array_slice($total_rows, 0, count($total_rows) - 2, true) +
        array('points' => array(
            'label' => __( 'Points:', ARALCO_SLUG ),
            'value' => strip_tags(wc_price($points_value * -1)),
        )) + array_slice($total_rows, count($total_rows) - 2, count($total_rows), true);
        $new_total_rows['order_total']['label'] = __( 'Total paid:', ARALCO_SLUG );
        $new_total_rows['order_total']['value'] = strip_tags(wc_price($order->get_total('edit') - $points_value));
        return $new_total_rows;
    }

    public function admin_points_display($order_id) {
        $order = wc_get_order($order_id);
        $points_value = $order->get_meta('aralco_points_value', 'true', 'edit');
        if(empty($points_value)) return;
        ?>

        <table class="wc-order-totals">
            <tr>
                <td class="label"><?php esc_html_e('Amount Paid By Points', ARALCO_SLUG); ?>:</td>
                <td width="1%"></td>
                <td class="total">
                    <?php echo wc_price($points_value, array('currency' => $order->get_currency())); // WPCS: XSS ok. ?>
                </td>
            </tr>
        </table>

        <div class="clear"></div>

        <?php
    }

    public function show_points_block() {
        require_once 'partials/points-module.php';
    }

    public function calculate_custom_tax_totals($item_tax_rates, $item, $cart){

//        WC_Tax::get_tax_location() -> {0 = CA, 1 = ON, 2 = V7P 3R9, 3 = Vancouver}

        $tax_ids = array();
        $aralco_tax_ids = get_post_meta($item->object['product_id'], '_aralco_taxes', true);
        if(is_array($aralco_tax_ids)){
            $tax_mappings = get_option(ARALCO_SLUG . '_tax_mapping', array());
            foreach ($aralco_tax_ids as $aralco_tax_id){
                $tax_ids = array_merge($tax_ids, $tax_mappings[$aralco_tax_id]);
            }
            $tax_ids = implode(',', $tax_ids);
            global $wpdb;
            $taxes = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id IN ({$tax_ids}) ORDER BY tax_rate_priority, tax_rate_order", ARRAY_A);

//            echo '<pre>' . print_r(WC_Tax::get_tax_location(), true) . '</pre>';
//            echo '<pre>' . print_r($taxes, true) . '</pre>';

            $getTax = function($p, $state) use ($taxes) {
                foreach ($taxes as $tax){
                    if(!empty($tax['tax_rate_state']) && $tax['tax_rate_state'] == $state && $tax['tax_rate_priority'] == $p){
                        return $tax;
                    }
                }
                return null;
            };

            $location = WC_Tax::get_tax_location();
            $state = (is_array($location) && isset($location[1])) ? $location[1] : '';
            $to_return = array();
            $tax1 = $getTax(1, $state);
            $tax2 = $getTax(2, $state);
            if($tax1 !== null) {
                $to_return[$tax1['tax_rate_id']] = array(
                    'rate' => (float)$tax1['tax_rate'],
                    'label' => $tax1['tax_rate_name'],
                    'shipping' => $tax1['tax_rate_shipping'] == 1 ? 'yes' : 'no',
                    'compound' => $tax1['tax_rate_compound'] == 1 ? 'yes' : 'no',
                );
            }
            if($tax2 !== null) {
                $to_return[$tax2['tax_rate_id']] = array(
                    'rate' => (float)$tax2['tax_rate'],
                    'label' => $tax2['tax_rate_name'],
                    'shipping' => $tax2['tax_rate_shipping'] == 1 ? 'yes' : 'no',
                    'compound' => $tax2['tax_rate_compound'] == 1 ? 'yes' : 'no',
                );
            }
//            echo '<pre>' . print_r($to_return, true) . '</pre>';
            return $to_return;
        }

        if(isset($item->object['wc_gc_giftcard_to_multiple']) && count($item->object['wc_gc_giftcard_to_multiple']) > 0) {
            return $item_tax_rates;
        }

        wc_add_notice(sprintf(__('Sorry, but "%s" appears to be missing tax info. Please report this to the site administrator.', ARALCO_SLUG), $item->object['data']->get_name()), 'error');
        return $item_tax_rates;
    }

    /**
     * Add the field to the checkout
     */

    public function reference_number_checkout_field($checkout) {
        $options = get_option(ARALCO_SLUG . '_options');

        if (!isset($options[ARALCO_SLUG . '_field_reference_number_enabled']) ||
            $options[ARALCO_SLUG . '_field_reference_number_enabled'] != '1') return;

        $title = (isset($options[ARALCO_SLUG . '_field_reference_number_label'])) ?
            $options[ARALCO_SLUG . '_field_reference_number_label'] : __('Reference #', ARALCO_SLUG);

        echo '<div id="' . ARALCO_SLUG . '-reference-number-field__field-wrapper">';

        $perams = array(
            'type' => 'text',
            'class' => array('reference-number-field form-row-wide'),
            'label' => $title
        );

        if(isset($options[ARALCO_SLUG . '_field_reference_number_required']) && $options[ARALCO_SLUG . '_field_reference_number_required'] == "1"){
            $perams['required'] = true;
        }

        woocommerce_form_field(ARALCO_SLUG . '_reference_number', $perams,
            $checkout->get_value(ARALCO_SLUG . '_reference_number'));

        echo '</div>';
    }

    public function reference_number_checkout_field_process() {
        $options = get_option(ARALCO_SLUG . '_options');
        if (!isset($options[ARALCO_SLUG . '_field_reference_number_enabled']) ||
            !isset($options[ARALCO_SLUG . '_field_reference_number_required']) ||
            $options[ARALCO_SLUG . '_field_reference_number_enabled'] != '1' ||
            $options[ARALCO_SLUG . '_field_reference_number_required'] != '1') return;
        if (empty($_POST[ARALCO_SLUG . '_reference_number'])) {
            $title = (isset($options[ARALCO_SLUG . '_field_reference_number_label'])) ?
                $options[ARALCO_SLUG . '_field_reference_number_label'] : __('Reference #', ARALCO_SLUG);
            wc_add_notice($title . __(' is required.', ARALCO_SLUG), 'error');
        }
    }

    function reference_number_checkout_field_update_order_meta($order_id) {
        $options = get_option(ARALCO_SLUG . '_options');
        if (!isset($options[ARALCO_SLUG . '_field_reference_number_enabled']) ||
            $options[ARALCO_SLUG . '_field_reference_number_enabled'] != '1') return;
        if (!empty($_POST[ARALCO_SLUG . '_reference_number'])) {
            update_post_meta($order_id, ARALCO_SLUG . '_reference_number', sanitize_text_field($_POST[ARALCO_SLUG . '_reference_number']));
        }
    }

    /**
     * Quote require no payment
     */
    public function quote_all_payment_gateway_disable($available_gateways) {
        $options = get_option(ARALCO_SLUG . '_options');
        if(isset($options[ARALCO_SLUG . '_field_order_is_quote']) &&
            $options[ARALCO_SLUG . '_field_order_is_quote'] == '1') {
            return [];
        }
        return $available_gateways;
    }

    public function quote_update_order_status_pending($order_id) {
        $options = get_option(ARALCO_SLUG . '_options');
        if(isset($options[ARALCO_SLUG . '_field_order_is_quote']) &&
            $options[ARALCO_SLUG . '_field_order_is_quote'] == '1') {
            $order = new WC_Order($order_id);
            $order->update_status('processing');
        }
    }

    public function quote_cart_needs_payment($total_is_0, $cart) {
        $options = get_option(ARALCO_SLUG . '_options');
        if(isset($options[ARALCO_SLUG . '_field_order_is_quote']) &&
            $options[ARALCO_SLUG . '_field_order_is_quote'] == '1') {
            return false;
        }
        return $total_is_0;
    }

    public function quote_order_needs_payment($this_has_status_valid_order_statuses_this_get_total_0, $order, $valid_order_statuses) {
        $options = get_option(ARALCO_SLUG . '_options');
        if(isset($options[ARALCO_SLUG . '_field_order_is_quote']) &&
            $options[ARALCO_SLUG . '_field_order_is_quote'] == '1') {
            return false;
        }
        return $this_has_status_valid_order_statuses_this_get_total_0;
    }

    /**
     * Catches completed orders and pushes them back to Aralco
     *
     * @param $order_id
     */
    public function submit_order_to_aralco($order_id) {
        $result = Aralco_Processing_Helper::process_order($order_id);
        if ($result instanceof WP_Error) {
            wc_add_notice(__("Order submission to BOS failed: ", ARALCO_SLUG) . $result->get_error_message(),'error');
            self::log_error("Order submission to BOS failed for order " . $order_id, $result);
        } else {
            self::log_info("Order submitted to BOS (order " . $order_id . ")", $result);
        }
    }

    public function show_reference_number($order) {
        $options = get_option(ARALCO_SLUG . '_options');
        if(isset($options[ARALCO_SLUG . '_field_reference_number_enabled']) &&
            $options[ARALCO_SLUG . '_field_reference_number_enabled'] == '1') {
            $title = (isset($options[ARALCO_SLUG . '_field_reference_number_label'])) ?
                $options[ARALCO_SLUG . '_field_reference_number_label'] : __('Reference #', ARALCO_SLUG);
            echo '<p>' . $title . '<mark class="reference-number">' . $order->get_meta(ARALCO_SLUG . '_reference_number') . '</mark></p>';
        }
    }

    public function show_reference_number_email($order, $sent_to_admin, $plain_text, $email){
        $options = get_option(ARALCO_SLUG . '_options');
        if(isset($options[ARALCO_SLUG . '_field_reference_number_enabled']) &&
            $options[ARALCO_SLUG . '_field_reference_number_enabled'] == '1') {
            $title = (isset($options[ARALCO_SLUG . '_field_reference_number_label'])) ?
                $options[ARALCO_SLUG . '_field_reference_number_label'] : __('Reference #', ARALCO_SLUG);
            if($plain_text) {
                echo $title . $order->get_meta(ARALCO_SLUG . '_reference_number') . '
';
            } else {
                echo '<p style="font-size:24px;">' . $title . '<b>' . $order->get_meta(ARALCO_SLUG . '_reference_number') . '</b></p>';
            }
        }
    }

    public function endpoint_order_pay_title($title, $endpoint){
        $options = get_option(ARALCO_SLUG . '_options');
        if(isset($options[ARALCO_SLUG . '_field_order_quote_text']) &&
            $options[ARALCO_SLUG . '_field_order_quote_text'] == '1') {
            $title = __( 'Pay for quote', 'woocommerce' );
        }
        return $title;
    }

    public function endpoint_order_received_title($title, $endpoint){
        $options = get_option(ARALCO_SLUG . '_options');
        if(isset($options[ARALCO_SLUG . '_field_order_quote_text']) &&
            $options[ARALCO_SLUG . '_field_order_quote_text'] == '1') {
            $title = __( 'Quote created', 'woocommerce' );
        }
        return $title;
    }

    public function endpoint_orders_title($title, $endpoint){
        $options = get_option(ARALCO_SLUG . '_options');
        if(isset($options[ARALCO_SLUG . '_field_order_quote_text']) &&
            $options[ARALCO_SLUG . '_field_order_quote_text'] == '1') {
            global $wp;
            if (!empty($wp->query_vars['orders'])) {
                /* translators: %s: page */
                $title = sprintf(__('Quotes (page %d)', ARALCO_SLUG), intval($wp->query_vars['orders']));
            } else {
                $title = __('Quotes', ARALCO_SLUG);
            }
        }
        return $title;
    }

    public function endpoint_view_order_title($title, $endpoint){
        $options = get_option(ARALCO_SLUG . '_options');
        if(isset($options[ARALCO_SLUG . '_field_order_is_quote']) &&
            $options[ARALCO_SLUG . '_field_order_is_quote'] == '1') {
            global $wp;
            $order = wc_get_order($wp->query_vars['view-order']);
            /* translators: %s: order number */
            $title = ($order)? sprintf(__('Quote #%s', ARALCO_SLUG), $order->get_order_number()) : '';
        }
        return $title;
    }

    public function account_menu_items($items){
        $options = get_option(ARALCO_SLUG . '_options');
        if(isset($options[ARALCO_SLUG . '_field_order_quote_text']) &&
            $options[ARALCO_SLUG . '_field_order_quote_text'] == '1') {
            $items['orders'] = __('Quotes', ARALCO_SLUG);
        }
        return $items;
    }

    public function my_account_my_orders_columns($items){
        $options = get_option(ARALCO_SLUG . '_options');
        if(isset($options[ARALCO_SLUG . '_field_order_quote_text']) &&
            $options[ARALCO_SLUG . '_field_order_quote_text'] == '1') {
            $items['order-number'] = esc_html__('Quote', ARALCO_SLUG);
        }
        return $items;
    }

    public function order_button_text($text) {
        $options = get_option(ARALCO_SLUG . '_options');
        if (isset($options[ARALCO_SLUG . '_field_order_quote_text']) &&
            $options[ARALCO_SLUG . '_field_order_quote_text'] == '1') {
            $text = __('Create quote', ARALCO_SLUG);
        }
        return $text;
    }

    public function checkout_fields($fields) {
        $options = get_option(ARALCO_SLUG . '_options');
        if (isset($options[ARALCO_SLUG . '_field_order_quote_text']) &&
            $options[ARALCO_SLUG . '_field_order_quote_text'] == '1') {
            $fields['order']['order_comments']['label'] = __('Quote notes', ARALCO_SLUG);
            $fields['order']['order_comments']['placeholder'] = esc_attr__(
                'Notes about your quote, e.g. special notes for delivery.',
                ARALCO_SLUG);
        }
        return $fields;
    }

    public function thankyou_order_received_text($text, $order) {
        $options = get_option(ARALCO_SLUG . '_options');
        if (isset($options[ARALCO_SLUG . '_field_order_quote_text']) &&
            $options[ARALCO_SLUG . '_field_order_quote_text'] == '1') {
            $text = esc_html__( 'Thank you. Your quote has been created.', 'woocommerce' );
        }
        return $text;
    }

    /**
     * Registers the built in product taxonomy for tracking if a product is new, on special, or on clearance
     */
    public function register_aralco_flags_taxonomy() {
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
            self::log_info("Aralco Flags taxonomy created");
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
                self::log_info("Added " . $value . " to Aralco Flags taxonomy");
            }
        }
    }

    /**
     * Registers the built in product taxonomy for tracking if a product is new, on special, or on clearance
     */
    public function register_supplier_taxonomy() {
        $taxonomy = wc_attribute_taxonomy_name('suppliers');

        // Create the Taxonomy
        if(!taxonomy_exists($taxonomy)){
            wc_create_attribute(array(
                'name' => 'Suppliers',
                'slug' => $taxonomy,
                'type' => 'select',
                'order_by' => 'name',
                'has_archives' => true
            ));
            self::log_info("Suppliers taxonomy created");
        }
    }

    /* itemized notes hooks */

    /**
     * Add a text field to each cart item
     */
    public function add_notes_input_after_cart_item_name($cart_item, $cart_item_key) {
        $options = get_option(ARALCO_SLUG . '_options');
        if(!isset($options[ARALCO_SLUG . '_field_order_itemized_notes']) ||
            $options[ARALCO_SLUG . '_field_order_itemized_notes'] != '1') {
            return;
        }
        $notes = isset($cart_item['notes'])? $cart_item['notes'] : '';
        printf(
            '<div><label><input type="checkbox" class="toggle_item_note" data-toggle="#cart_notes_%s" autocomplete="off"%s> Add Note</label></div>',
            $cart_item_key,
            empty($notes) ? '' : ' checked="checked"'
        );
        printf(
            '<div><textarea class="aralco-cart-notes" id="cart_notes_%s" data-cart-id="%s" style="%s">%s</textarea></div>',
            $cart_item_key,
            $cart_item_key,
            empty($notes) ? 'display:none;' : '',
            $notes
        );
    }

    /**
     * Enqueue our JS file
     */
    public function itemized_notes_enqueue_scripts() {
        $options = get_option(ARALCO_SLUG . '_options');
        if(!isset($options[ARALCO_SLUG . '_field_order_itemized_notes']) ||
            $options[ARALCO_SLUG . '_field_order_itemized_notes'] != '1') {
            return;
        }
        wp_register_script('aralco-item-note-script', trailingslashit(plugin_dir_url(__FILE__)) . 'assets/js/item-notes-ajax.js', array('jquery-blockui'), time(), true);
        wp_localize_script(
            'aralco-item-note-script',
            'aralco_vars',
            array(
                'ajaxurl' => admin_url('admin-ajax.php')
            )
        );
        wp_enqueue_script('aralco-item-note-script');
    }

    /**
     * Update cart item notes
     */
    public function save_item_note_to_cart() {
        // Do a nonce check
        if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'woocommerce-cart')) {
            wp_send_json(array('nonce_fail' => 1));
            exit;
        }
        // Save the notes to the cart meta
        $cart = WC()->cart->cart_contents;
        $cart_id = $_POST['cart_id'];
        $notes = $_POST['notes'];
        $cart_item = $cart[$cart_id];
        $cart_item['notes'] = sanitize_text_field($notes);
        WC()->cart->cart_contents[$cart_id] = $cart_item;
        WC()->cart->set_session();
        wp_send_json(array('success' => 1));
        exit;
    }

    public function add_item_notes_meta_cart_item($item, $cart_item_key, $values, $order) {
        foreach ($item as $cart_item_key => $cart_item) {
            if (isset($cart_item['notes'])) {
                $item->add_meta_data('notes', $cart_item['notes'], true);
            }
        }
    }

    public function add_notes_to_item_data($item_data, $cart_item) {
        if (!is_cart() && !empty($cart_item['notes'])) {
            $item_data[] = array(
                'key' => 'Notes',
                'value' => $cart_item['notes']
            );
        }
        return $item_data;
    }

    /* End of itemized notes hooks */

    /**
     * Adds an entry to the plugin log
     *
     * @param string|array $entry the item to log.
     * @param null $second_entry used to add extra objects for logging.
     * @param string $level the log level for the log.
     * @param bool $override_logging_option weather or not the logging setting should be respected.
     * @return bool If writing to the log was successful.
     */
    private static function add_log($entry, $second_entry = null, $level = "INFO", $override_logging_option = false) {
        if(Aralco_WooCommerce_Connector::$loggingEnabled == null) {
            $options = get_option(ARALCO_SLUG . '_options');
            if($options[ARALCO_SLUG . '_field_enable_logging']){
                Aralco_WooCommerce_Connector::$loggingEnabled = boolval($options[ARALCO_SLUG . '_field_enable_logging']);
            } else {
                Aralco_WooCommerce_Connector::$loggingEnabled = false;
            }
        }

        if(!Aralco_WooCommerce_Connector::$loggingEnabled && !$override_logging_option) return false;

        // Get WordPress uploads directory.
        $upload_dir = wp_upload_dir();
        $upload_dir = $upload_dir['basedir'];
        // If the entry is array, json_encode.
        if (is_array($entry)) {
            $entry = json_encode($entry);
        }
        if ($second_entry instanceof WP_Error) {
            $second_entry = $second_entry->get_error_code() . ' - ' .
                $second_entry->get_error_message() . ' - ' .
                json_encode($second_entry->get_all_error_data());
        } else if ($second_entry !== null && is_array($second_entry)) {
            $second_entry = json_encode($second_entry);
        }
        // Write the log file.
        $file = $upload_dir . '/' . ARALCO_SLUG . '.log';
        $file = fopen($file, 'a');
        if(!$file) return false; //failed to get a file handle. read only wp-content?
        try {
            $time = (new DateTime('now', new DateTimeZone(wp_timezone_string())))->format('Y-m-d H:i:s');
            $bytes = fwrite($file, '[' . $time . '] [' . $level . ']: ' . $entry . "\n");
            if($second_entry !== null && is_numeric($bytes)) {
                $bytes += fwrite($file, '[' . $time . '] [' . $level . ']: ' . $second_entry . "\n");
            }
        } catch (Exception $e) {}
        fclose($file);
        return (isset($bytes)) ? $bytes > 0 : false;
    }

    /**
     * Adds an error entry to the plugin log
     *
     * @param string|array $entry the item to log.
     * @param null|string|array $second_entry additional object to log.
     * @param false $override_logging_option weather or not the logging setting should be respected.
     */
    public static function log_error($entry, $second_entry = null, $override_logging_option = false) { self::add_log($entry, $second_entry, 'ERROR', $override_logging_option); }

    /**
     * Adds an warning entry to the plugin log
     *
     * @param string|array $entry the item to log.
     * @param null|string|array $second_entry additional object to log.
     * @param false $override_logging_option weather or not the logging setting should be respected.
     */
    public static function log_warning($entry, $second_entry = null, $override_logging_option = false) { self::add_log($entry, $second_entry, 'WARN', $override_logging_option); }

    /**
     * Adds an info entry to the plugin log
     *
     * @param string|array $entry the item to log.
     * @param null|string|array $second_entry additional object to log.
     * @param false $override_logging_option weather or not the logging setting should be respected.
     */
    public static function log_info($entry, $second_entry = null, $override_logging_option = false) { self::add_log($entry, $second_entry, 'INFO', $override_logging_option); }
}

require_once 'aralco-widget.php';
require_once 'aralco-rest.php';
require_once 'aralco-payment-gateway.php';
require_once 'aralco-gift-card.php';

$ARALCO = new Aralco_WooCommerce_Connector();
