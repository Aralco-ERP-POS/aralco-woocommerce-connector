<?php

defined( 'ABSPATH' ) or die(); // Prevents direct access to file.
require_once(ABSPATH.'wp-admin/includes/plugin.php');

/**
 * Provide a admin area view for the plugin
 *
 * This file is used to markup the admin-facing aspects of the plugin.
 */

?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js" integrity="sha256-KM512VNnjElC30ehFwehXjx1YCHPiQkOPmqnrWtpccM=" crossorigin="anonymous"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.css" integrity="sha256-rByPlHULObEjJ6XQxW/flG2r+22R5dKiAoef+aXWfik=" crossorigin="anonymous" />
<style>
    pre {
        border: 1px solid #000;
        padding: 1em
    }
    .<?php echo ARALCO_SLUG ?>_row input {
        min-width: 300px;
    }
    @media (min-width: 768px) {
        .aralco-columns {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
        }
        .aralco-columns > * {
            flex-basis: 32%;
            width: 32%;
        }
        .aralco-columns h2 {
            text-align: center;
        }
    }
    .aralco-columns label {
        display: block;
        padding: 0.25em;
    }
    .settings.accordion {
        margin: 1em 0;
    }
    .settings.accordion h2 {
        font-size: 1.35em;
    }
    ::placeholder {
        color: #ccc;
    }
    .last-run-stats-title, .last-run-stats {
        text-align: center;
    }
    .last-run-stats > span {
        display: inline-block;
        padding: 1em;
    }
    .load-blur {
        z-index: 9999;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        position: fixed;
        top: 0;
        bottom: 0;
        left: 0;
        right: 0;
        background: rgba(0,0,0,0.25);
    }
    .load-blur > div{
        min-width: 200px;
        text-align: center;
        padding: 1em;
        border-radius: 1em;
        background: #fff;
    }
    .load-blur.has-progress > div,
    .load-blur.has-question > div{
        min-width: 300px;
    }
    .load-blur .dashicons{
        height: 60px;
        width: 60px;
    }
    .load-blur .dashicons:before{
        font-size: 60px;
    }
    .load-blur .dashicons.blink:before{
        transition: 1s color;
        animation: blink 1s ease-in-out infinite;
        -webkit-animation: blink 1s ease-in-out infinite;
    }
    .load-blur .loading-bar {
        position: relative;
        width: 100%;
        background-color: grey;
    }
    .load-blur #aralco-progress-text {
        position: absolute;
        color: #fff;
        text-align: center;
        line-height: 28px;
        top:1px;
        bottom:1px;
        left:1px;
        right:1px;
    }
    .load-blur #aralco-progress {
        width: 1%;
        height: 30px;
        background-color: #00AADC;
    }
    @keyframes blink {
        0% {
            color: #000;
        }
        50% {
            color: #fff;
        }
        100% {
            color: #000;
        }
    }
    @-webkit-keyframes blink {
        0% {
            color: #000;
        }
        50% {
            color: #fff;
        }
        100% {
            color: #000;
        }
    }
</style>
<?php settings_errors(ARALCO_SLUG . '_messages'); ?>
<h1><?php echo esc_html(get_admin_page_title()); ?></h1>
<div class="wrap">
    <h1>Settings</h1>
    <div id="aralco-js-error-box"></div>
    <div class="settings accordion">
        <h2>Hide/Show</h2>
        <form action="options.php" method="post">
            <?php
            settings_fields(ARALCO_SLUG);
            do_settings_sections(ARALCO_SLUG);
            submit_button('Save Settings');
            echo '<h2>Debug</h2>';
            echo '<pre style="border: 1px solid #000; padding: 1em">' .
                print_r(get_option(ARALCO_SLUG . '_options'), true) . '</pre>';
            ?>
        </form>
    </div>
    <script>
        jQuery('.settings.accordion').accordion({
            active: <?php echo (count(get_settings_errors(ARALCO_SLUG . '_messages')) > 0 &&
                                get_settings_errors(ARALCO_SLUG . '_messages')[0]['type'] == 'error') ?
                '0' : 'false' ?>,
            animate: 200,
            collapsible: true
        })
    </script>
    <?php if (isset($_POST['get-order-json']) && isset($_POST['order-id'])) { ?>
    <h1>Order JSON</h1>
    <?php
    $order = wc_get_order($_POST['order-id']);
    $order_array = $order->get_meta('_aralco_order_array');
    $notice = '';
    if(empty($order_array)) {
        $notice = sprintf(__("Order #%s doesn't have saved aralco order data. Generating new data...", ARALCO_SLUG), $_POST['order-id']);
        $order_array = Aralco_Processing_Helper::process_order(intval($_POST['order-id']), true);
    }
    if($order_array instanceof WP_Error) {
        $to_echo = $order_array->get_error_message();
    } else {
        $to_echo = json_encode($order_array, JSON_PRETTY_PRINT);
    }
    if(!empty($notice)){ ?>
    <p><?php echo $notice ?></p>
    <?php } ?>
    <pre><?php echo $to_echo ?></pre>
    <?php } ?>
    <h1>Tools</h1>
    <div class="aralco-columns">
        <form action="admin.php?page=aralco_woocommerce_connector_settings" method="post" class="form-requires-load-blur">
            <h2>Test the Connection</h2>
            <p>Remember to save you settings before testing the connection. Selecting "Test Connection" uses the saved configuration.</p>
            <input type="hidden" name="test-connection" value="1">
            <?php submit_button('Test Connection'); ?>
        </form>
        <form action="admin.php?page=aralco_woocommerce_connector_settings" method="post" class="sync-form">
            <h2>Sync Now</h2>
            <p>Manually sync data from Aralco. Running this will get all products in the last hour or since last sync, whichever is greater.</p>
            <input type="hidden" name="sync-now" value="on">
            <label><input type="checkbox" name="sync-departments">Departments</label>
            <label><input type="checkbox" name="sync-groupings">Groupings</label>
            <label><input type="checkbox" name="sync-grids">Grids</label>
            <label><input type="checkbox" name="sync-suppliers">Suppliers</label>
            <label><input type="checkbox" name="sync-products" checked="checked">Products</label>
            <label><input type="checkbox" name="sync-stock" checked="checked">Stock</label>
            <label><input type="checkbox" name="sync-customer-groups" checked="checked">Customer Groups</label>
            <label><input type="checkbox" name="sync-taxes" checked="checked">Taxes</label>
            <label><input type="checkbox" name="sync-stores" checked="checked">Stores</label>
            <?php submit_button('Sync Now'); ?>
        </form>
        <form action="admin.php?page=aralco_woocommerce_connector_settings" method="post" class="sync-form">
            <h2>Re-Sync</h2>
            <p>Manually sync data from Aralco. This will ignore the last sync time and pull everything. Only do this if the data in WooCommerce becomes de-synced with what's in Aralco. This operation can take over an hour.</p>
            <input type="hidden" name="force-sync-now" value="on">
            <label><input type="checkbox" name="sync-departments">Departments</label>
            <label><input type="checkbox" name="sync-groupings">Groupings</label>
            <label><input type="checkbox" name="sync-grids">Grids</label>
            <label><input type="checkbox" name="sync-suppliers">Suppliers</label>
            <label><input type="checkbox" name="sync-products" checked="checked">Products</label>
            <label><input type="checkbox" name="sync-stock" checked="checked">Stock</label>
            <label><input type="checkbox" name="sync-customer-groups" checked="checked">Customer Groups</label>
            <label><input type="checkbox" name="sync-taxes" checked="checked">Taxes</label>
            <label><input type="checkbox" name="sync-stores" checked="checked">Stores</label>
            <?php submit_button('Force Sync Now'); ?>
        </form>
        <form action="admin.php?page=aralco_woocommerce_connector_settings" method="post" class="form-requires-load-blur">
            <h2>Get Order JSON (DEBUG)</h2>
            <p>Used to get the json data for a specific order. Only the last 20 are shown. Debug tool. May be removed in the future.</p>
            <input type="hidden" name="get-order-json" value="1">
            <label>Order
                <select name="order-id">
                    <?php
                    try {
                        $orders = (new WC_Order_Query(array(
                            'limit' => 20,
                            'orderby' => 'date',
                            'order' => 'DESC',
                            'return' => 'objects'
                        )))->get_orders();
                        if(is_array($orders) && count($orders) > 0) {
                            /** @var Automattic\WooCommerce\Admin\Overrides\Order $order */
                            $gc_active = is_plugin_active('woocommerce-gift-cards/woocommerce-gift-cards.php');
                            foreach ($orders as $i => $order) {
                                if (is_a($order, 'WC_Order_Refund')) {
                                    $order = wc_get_order($order->get_parent_id());
                                }
                                $id = $order->get_id();
                                $name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                                $amount = strip_tags(WC_Price(($gc_active) ? WC_GC()->order->get_order_total($order) : $order->get_total()));

                                ?>
                                <option value="<?php echo $id ?>"<?php echo ($i == 0)? ' selected="selected"' : ''; ?>>#<?php
                                    echo $id . ' - ' . $name . ', ' . $amount; ?></option>
                            <?php }
                        } else { ?>
                            <option value="-1">No Orders Found</option>
                        <?php }
                    } catch (Exception $e) { ?>
                        <option value="-1">No Orders Found</option>
                    <?php } ?>
                </select>
            </label>
            <?php submit_button('Get JSON'); ?>
        </form>
        <form action="admin.php?page=aralco_woocommerce_connector_settings" method="post" class="form-requires-load-blur">
            <h2>Fix Stock Status (DEBUG)</h2>
            <p>Used to fix stock status to reflect the actual stock count. Debug tool. May be removed in the future.</p>
            <input type="hidden" name="fix-stock-count" value="1">
            <?php submit_button('Fix Stock'); ?>
        </form>
        <form action="admin.php?page=aralco_woocommerce_connector_settings" method="post" class="form-requires-load-blur">
            <h2>Remove Old Fields (DEBUG)</h2>
            <p>Cleans up old options no longer used by this plugin. Debug tool. May be removed in the future.</p>
            <input type="hidden" name="remove-old-fields" value="1">
            <?php submit_button('Remove Old Fields'); ?>
        </form>
    </div>
    <div>
        <p style="color: #ff0000; text-align: center">If you get a critical error while syncing, you may need to adjust the server's php timeout or memory limit. Contact Aralco if you need assistance with that.</p>
        <!--<h3 class="last-run-stats-title">Last Run Stats</h3>-->
        <div class="last-run-stats">
            <span title="Last Sync">
                <span class="dashicons dashicons-plugins-checked" aria-hidden="true"></span>
                <span class="screen-reader-text">Last Sync</span>
                <?php
                    $last_sync = get_option(ARALCO_SLUG . '_last_sync');
                    if($last_sync !== false) {
                        $dt = new DateTime($last_sync, new DateTimeZone('UTC'));
                        $dt->setTimezone(new DateTimeZone(wp_timezone_string()));
                        echo $dt->format("Y-m-d\TH:i:s");
                    } else echo '(never run)';
                    ?>
            </span>
            <span title="Server Time">
                <span class="dashicons dashicons-clock" aria-hidden="true"></span>
                <span class="screen-reader-text">Server Time</span>
                <?php echo (new DateTime("now", new DateTimeZone(wp_timezone_string())))->format("Y-m-d\TH:i:s"); ?>
            </span>
            <span title="Timezone">
                <span class="dashicons dashicons-admin-site" aria-hidden="true"></span>
                <span class="screen-reader-text">Timezone</span>
                <?php echo wp_timezone_string(); ?>
            </span>
        </div>
    </div>
    <hr>
    <div style="text-align: center;">Questions? Comments? Find a problem? <a href="https://aralco.com/services/support/" target="_blank" rel="noopener,noreferrer">Contact Aralco.</a></div>
</div>
<!--suppress JSJQueryEfficiency -->
<script>
    const wpApiSettings = {
        root: "<?php echo esc_url_raw(rest_url()) ?>",
        nonce: "<?php echo wp_create_nonce('wp_rest') ?>"
    };

    jQuery(document).ready(function ($) {
        $('body').append('<div class="load-blur" style="display: none;"><div><p aria-hidden="true"><span class="dashicons dashicons-download blink"></span></p><h1>Please Wait...</h1></div></div>');
        $('.form-requires-load-blur .button').on('click', function () {
            $('.load-blur').show();
        });

        $(".sync-form").on('submit', function (e) {
            e.preventDefault();
            startSync(false, e.currentTarget)
        });

        function startSync(ignore, form){
            let rawData = $(form).serializeArray();
            let data = {};
            if(ignore) data.ignore = true;
            for (let i = 0; i < rawData.length; i++) {
                data[rawData[i].name] = "1";
            }

            $('#aralco-js-error-box').empty();
            aralcoAddProgress();

            $.ajax({
                url: wpApiSettings.root + "aralco-wc/v1/admin/new-sync",
                method: 'GET',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
                },
                data: data
            }).done(function (response) {
                if(response.resume){
                    aralcoConfirmToContinueProcessing(response, form);
                } else {
                    aralcoContinueProcessing(response);
                }
            }).fail(function (response) {
                aralcoSomethingWentWrong(response);
            });
        }

        function aralcoConfirmToContinueProcessing(data, form) {
            aralcoRemoveProgress();
            $('body').append('<div class="load-blur has-question"><div><p>Incomplete sync found. Do you want to continue to old sync or start over?</p><button id="button-old">Use Old</button><button id="button-new">Use New</button><button>Cancel</button></div></div>');
            $('.load-blur.has-question button').on('click', function () {
                $('.load-blur.has-question').remove();
            });
            $('#button-old').on('click', aralcoAddProgress).on('click', aralcoContinueProcessing.bind(null, data));
            $('#button-new').on('click', startSync.bind(null, true, form));
        }

        function aralcoContinueProcessing(data) {
            console.log(data);
            if(data.warnings && data.warnings.count > 0){
                for(let i in data.warnings){
                    aralcoAddWarning(data.warnings[i]);
                }
            }
            $('#aralco-status').text(data.statusText);
            $('#aralco-progress-text').text(data.progress + '/' + data.total);
            let barWidth = Math.round((data.progress / data.total) * 100);
            if (barWidth === 0) barWidth = 1;
            $('#aralco-progress').css('width', barWidth + '%');
            if(data.complete > 0){
                wpApiSettings.nonce = data.nonce;
                $('#aralco-js-error-box').append('<div id="setting-error-aralco_woocommerce_connector_message" class="notice notice-success settings-error"><p><strong>Sync Completed Successfully</strong></p></div>');
                setTimeout(aralcoRemoveProgress, 1000);
            } else {
                $.ajax({
                    url: wpApiSettings.root + "aralco-wc/v1/admin/continue-sync",
                    method: 'GET',
                    beforeSend: function (xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', data.nonce);
                    }
                }).done(function (response) {
                    aralcoContinueProcessing(response);
                }).fail(function (response) {
                    // aralcoSomethingWentWrong(response);
                    aralcoContinueProcessing(data);
                });
            }
        }

        function aralcoAddWarning(warning) {
            let message = (warning && warning.message) ? warning.message : 'An unknown warning occurred';
            $('#aralco-js-error-box').append('<div id="setting-error-aralco_woocommerce_connector_message" class="notice notice-warning settings-error"><p><strong>Warn:</strong> ' + message + '</p></div>');
        }

        function aralcoSomethingWentWrong(data) {
            aralcoRemoveProgress();
            let message = (data && data.responseJSON && data.responseJSON.message) ? data.responseJSON.message : 'An unknown error occurred';
            $('#aralco-js-error-box').append('<div id="setting-error-aralco_woocommerce_connector_message" class="notice notice-error settings-error"><p><strong>An error has occurred:</strong> ' + message + '</p></div>');
            console.error(data);
        }

        function aralcoAddProgress() {
            $('body').append('<div class="load-blur has-progress"><div><p aria-hidden="true"><span class="dashicons dashicons-download blink"></span></p><div class="loading-bar"><div id="aralco-progress"></div><div id="aralco-progress-text"></div></div><h1 id="aralco-status">Initializing...</h1></div></div>');
        }

        function aralcoRemoveProgress() {
            $('.load-blur.has-progress').remove();
        }
    })
</script>