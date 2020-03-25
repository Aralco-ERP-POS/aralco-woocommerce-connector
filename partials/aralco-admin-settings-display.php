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
    .settings.accordion {
        margin: 1em 0;
    }
    .settings.accordion h2 {
        font-size: 1.35em;
    }
</style>
<?php settings_errors(ARALCO_SLUG . '_messages'); ?>
<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    <div class="settings accordion">
        <h2>General Settings</h2>
        <form action="options.php" method="post">
            <?php
            settings_fields(ARALCO_SLUG);
            do_settings_sections(ARALCO_SLUG);
            submit_button('Save Settings');
//            echo '<pre style="border: 1px solid #000; padding: 1em">' .
//                 print_r(get_option(ARALCO_SLUG . '_options'), true) . '</pre>';
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
    <div class="aralco-columns">
    <form action="admin.php?page=aralco_woocommerce_connector_settings" method="post">
        <h2>Test the Connection</h2>
        <p>Remember to save you settings before testing the connection. Selecting "Test Connection" uses the saved configuration.</p>
        <input type="hidden" name="test-connection" value="1">
        <?php submit_button('Test Connection'); ?>
    </form>
    <form action="admin.php?page=aralco_woocommerce_connector_settings" method="post">
        <h2>Sync Now</h2>
        <p>Manually sync data from Aralco. Running this will ignore the last sync time</p>
        <h3 style="font-size: 1em;">Last Run Stats</h3>
        <ul><li>&bull; Completion time: <?php
            $last_sync = get_option(ARALCO_SLUG . '_last_sync');
            $total_records = 0;
            $total_run_tiume = 0;
            echo $last_sync !== false ? $last_sync . ' UTC' : '(never run)'
        ?></li><li>&bull; <?php
            $time_taken = get_option(ARALCO_SLUG . '_last_sync_duration_departments');
            $count = get_option(ARALCO_SLUG . '_last_sync_department_count');
            if ($time_taken > 0) $total_run_tiume += $time_taken;
            if ($count > 0) $total_records += $count;
            echo ($count !== false ? $count : '0') . ' Departments in ' .
                 ($time_taken !== false ? $time_taken : '0') . ' seconds.'
        ?></li><li>&bull; <?php
            $time_taken = get_option(ARALCO_SLUG . '_last_sync_duration_grids');
            $count = get_option(ARALCO_SLUG . '_last_sync_grid_count');
            if ($time_taken > 0) $total_run_tiume += $time_taken;
            if ($count > 0) $total_records += $count;
            echo ($count !== false ? $count : '0') . ' Grids in ' .
                 ($time_taken !== false ? $time_taken : '0') . ' seconds.'
        ?></li><li>&bull; <?php
            $time_taken = get_option(ARALCO_SLUG . '_last_sync_duration_products');
            $count = get_option(ARALCO_SLUG . '_last_sync_product_count');
            if ($time_taken > 0) $total_run_tiume += $time_taken;
            if ($count > 0) $total_records += $count;
            echo ($count !== false ? $count : '0') . ' Products in ' .
                 ($time_taken !== false ? $time_taken : '0') . ' seconds.'
        ?></li><li>&bull; <?php
            echo $total_records . ' Total entries updated in ' . $total_run_tiume . ' seconds.'
        ?></li></ul>
        <input type="hidden" name="sync-now" value="1">
        <?php submit_button('Sync Now'); ?>
    </form>
    <form action="admin.php?page=aralco_woocommerce_connector_settings" method="post">
        <h2>Re-Sync</h2>
        <p>Manually sync ALL data from Aralco. Only do this if the data in WooCommerce becomes de-synced with what's in Aralco. This operation will take a while.</p>
        <p style="color: #ff0000">If you get an timeout error, you may have to change your server's time limit for PHP execution and try again.</p>
        <input type="hidden" name="force-sync-now" value="1">
        <?php submit_button('Force Sync Now'); ?>
    </form>
    </div>
    <pre><?php /*print_r();*/ ?></pre>
</div>
