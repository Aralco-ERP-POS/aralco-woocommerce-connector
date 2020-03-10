<?php

defined( 'ABSPATH' ) or die(); // Prevents direct access to file.

/**
 * The markup for how inputs are drawn in the admin options page
 *
 * @param array $options the array containing the aralco plugin options
 * @param array $args the arguments for how to construct this input
 */
function aralco_admin_settings_input($options, $args){
    $errors = get_settings_errors($args['label_for']);
    if(!empty($errors)) {
        foreach($errors as $index => $error){
    ?>
    <p style="color:#ff0000;"><?php print_r($error['message'])?></p>
    <?php }
    } ?>
    <input id="<?php echo esc_attr($args['label_for']); ?>" type="text"
           placeholder="<?php echo esc_attr($args['placeholder']); ?>"
           name="<?php echo ARALCO_SLUG ?>_options[<?php echo esc_attr($args['label_for']); ?>]"
           value="<?php echo (isset($options[$args['label_for']])) ? $options[$args['label_for']] : ''; ?>"
    />
    <?php if(isset($args['description'])){?>
        <p class="description">
            <?php esc_html_e($args['description'], ARALCO_SLUG); ?>
        </p>
    <?php
    }
}
