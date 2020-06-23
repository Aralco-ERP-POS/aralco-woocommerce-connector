<?php

defined( 'ABSPATH' ) or die(); // Prevents direct access to file.

/**
 * Provide a admin area view for the plugin
 *
 * This file is used to markup the admin-facing aspects of the plugin.
 */

?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js" integrity="sha256-KM512VNnjElC30ehFwehXjx1YCHPiQkOPmqnrWtpccM=" crossorigin="anonymous"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.css" integrity="sha256-rByPlHULObEjJ6XQxW/flG2r+22R5dKiAoef+aXWfik=" crossorigin="anonymous" />
<style>
    .<?php echo ARALCO_SLUG ?>_row input {
        min-width: 300px;
    }
    @media (min-width: 768px) {
        .aralco-columns {
            display: flex;
            justify-content: space-between;
        }
        .aralco-columns > * {
            width: 32%;
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
    <div class="settings accordion">
        <h2>Hide/Show</h2>
        <form action="options.php" method="post">
            <?php
            settings_fields(ARALCO_SLUG);
            do_settings_sections(ARALCO_SLUG);
            submit_button('Save Settings');
            echo '<pre style="border: 1px solid #000; padding: 1em">' .
                 print_r(get_option(ARALCO_SLUG . '_options'), true) . '</pre>';
            echo '<pre style="border: 1px solid #000; padding: 1em">' .
                json_encode(Aralco_Processing_Helper::process_order(72955, true), JSON_PRETTY_PRINT) . '</pre>';
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
    <h1>Tools</h1>
    <div class="aralco-columns form-requires-load-blur">
    <form action="admin.php?page=aralco_woocommerce_connector_settings" method="post">
        <h2>Test the Connection</h2>
        <p>Remember to save you settings before testing the connection. Selecting "Test Connection" uses the saved configuration.</p>
        <input type="hidden" name="test-connection" value="1">
        <?php submit_button('Test Connection'); ?>
    </form>
    <form action="admin.php?page=aralco_woocommerce_connector_settings" method="post">
        <h2>Sync Now</h2>
        <p>Manually sync data from Aralco. Running this will get all products in the last hour or since last sync, whichever is greater.</p>
        <input type="hidden" name="sync-now" value="1">
        <label><input type="checkbox" name="sync-departments">Departments</label>
        <label><input type="checkbox" name="sync-groupings">Groupings</label>
        <label><input type="checkbox" name="sync-grids">Grids</label>
        <label><input type="checkbox" name="sync-products" checked="checked">Products</label>
        <label><input type="checkbox" name="sync-stock" checked="checked">Stock</label>
        <?php submit_button('Sync Now'); ?>
    </form>
    <form action="admin.php?page=aralco_woocommerce_connector_settings" method="post">
        <h2>Re-Sync</h2>
        <p>Manually sync data from Aralco. This will ignore the last sync time and pull everything. Only do this if the data in WooCommerce becomes de-synced with what's in Aralco. This operation can take over an hour.</p>
        <input type="hidden" name="force-sync-now" value="1">
        <label><input type="checkbox" name="sync-departments">Departments</label>
        <label><input type="checkbox" name="sync-groupings">Groupings</label>
        <label><input type="checkbox" name="sync-grids">Grids</label>
        <label><input type="checkbox" name="sync-products" checked="checked">Products</label>
        <label><input type="checkbox" name="sync-stock" checked="checked">Stock</label>
        <?php submit_button('Force Sync Now'); ?>
    </form>
    </div>
    <div>
        <p style="color: #ff0000; text-align: center">If you get a critical error while syncing, you may need to adjust the server's php timeout or memory limit. Contact Aralco if you need assistance with that.</p>
        <h3 class="last-run-stats-title">Last Run Stats</h3>
        <div class="last-run-stats">
            <span title="Last Completion Date">
                <span class="dashicons dashicons-plugins-checked" aria-hidden="true"></span>
                <span class="screen-reader-text">Last Completion Date:</span>
                <?php
                    $last_sync = get_option(ARALCO_SLUG . '_last_sync');
                    $total_records = 0;
                    $total_run_tiume = 0;
                    echo $last_sync !== false ? $last_sync . ' UTC' : '(never run)'
                    ?>
            </span> <span title="Departments">
                <span class="dashicons dashicons-building" aria-hidden="true"></span>
                <span class="screen-reader-text">Departments:</span>
                <?php
                    $time_taken = get_option(ARALCO_SLUG . '_last_sync_duration_departments');
                    $count = get_option(ARALCO_SLUG . '_last_sync_department_count');
                    if ($time_taken > 0) $total_run_tiume += $time_taken;
                    if ($count > 0) $total_records += $count;
                    echo ($count !== false ? $count : '0') . ' (' .
                        ($time_taken !== false ? $time_taken : '0') . 's)'
                    ?>
            </span> <span title="Grids">
                <span class="dashicons dashicons-grid-view" aria-hidden="true"></span>
                <span class="screen-reader-text">Grids:</span>
                <?php
                $time_taken = get_option(ARALCO_SLUG . '_last_sync_duration_grids');
                $count = get_option(ARALCO_SLUG . '_last_sync_grid_count');
                if ($time_taken > 0) $total_run_tiume += $time_taken;
                if ($count > 0) $total_records += $count;
                echo ($count !== false ? $count : '0') . ' (' .
                    ($time_taken !== false ? $time_taken : '0') . 's)'
                ?>
            </span> <span title="Groupings">
                <span class="dashicons dashicons-index-card" aria-hidden="true"></span>
                <span class="screen-reader-text">Groupings:</span>
                <?php
                    $time_taken = get_option(ARALCO_SLUG . '_last_sync_duration_groupings');
                    $count = get_option(ARALCO_SLUG . '_last_sync_grouping_count');
                    if ($time_taken > 0) $total_run_tiume += $time_taken;
                    if ($count > 0) $total_records += $count;
                    echo ($count !== false ? $count : '0') . ' (' .
                        ($time_taken !== false ? $time_taken : '0') . 's)'
                    ?>
            </span> <span title="Products">
                <span class="dashicons dashicons-cart" aria-hidden="true"></span>
                <span class="screen-reader-text">Products:</span>
                <?php
                    $time_taken = get_option(ARALCO_SLUG . '_last_sync_duration_products');
                    $count = get_option(ARALCO_SLUG . '_last_sync_product_count');
                    if ($time_taken > 0) $total_run_tiume += $time_taken;
                    if ($count > 0) $total_records += $count;
                    echo ($count !== false ? $count : '0') . ' (' .
                        ($time_taken !== false ? $time_taken : '0') . 's)'
                    ?>
            </span> <span title="Stock">
                <span class="dashicons dashicons-archive" aria-hidden="true"></span>
                <span class="screen-reader-text">Stock:</span>
                <?php
                    $time_taken = get_option(ARALCO_SLUG . '_last_sync_duration_stock');
                    $count = get_option(ARALCO_SLUG . '_last_sync_stock_count');
                    if ($time_taken > 0) $total_run_tiume += $time_taken;
                    if ($count > 0) $total_records += $count;
                    echo ($count !== false ? $count : '0') . ' (' .
                        ($time_taken !== false ? $time_taken : '0') . 's)'
                    ?>
            </span> <span title="Total Entries Updated">
                <span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
                <span class="screen-reader-text">Total Entries Updated:</span>
                <?php
                    echo $total_records . ' (' . $total_run_tiume . 's)'
                    ?>
            </span>
        </div>
    </div>
    <hr>
    <div style="text-align: center;">Questions? Comments? Find a problem? <a href="https://aralco.com/services/support/" target="_blank" rel="noopener,noreferrer">Contact Aralco.</a></div>
</div>
<script>
    jQuery(document).ready(function ($) {
        $('body').append('<div class="load-blur" style="display: none;"><div><p aria-hidden="true"><span class="dashicons dashicons-download blink"></span></p><h1>Please Wait...</h1></div></div>');
        $('.form-requires-load-blur .button').on('click', function () {
            $('.load-blur').show();
        })
    })
</script>