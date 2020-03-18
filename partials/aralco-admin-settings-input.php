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
    <input id="<?php echo esc_attr($args['label_for']); ?>" type="<?php echo esc_attr($args['type']); ?>"
           placeholder="<?php echo esc_attr($args['placeholder']); ?>"
           name="<?php echo ARALCO_SLUG ?>_options[<?php echo esc_attr($args['label_for']); ?>]"
           value="<?php echo (isset($options[$args['label_for']])) ? esc_attr($options[$args['label_for']]) : ''; ?>"
           <?php echo (isset($args['step'])) ? 'step="' . $args['step'] . '"' : '' ?>
           <?php echo (isset($args['min'])) ? 'min="' . $args['min'] . '"' : '' ?>
           <?php echo (isset($args['max'])) ? 'max="' . $args['max'] . '"' : '' ?>  />
    <?php if(isset($args['description'])){?>
        <p class="description">
            <?php esc_html_e($args['description'], ARALCO_SLUG); ?>
        </p>
    <?php
    }
}

/**
 * The markup for how selects are drawn in the admin options page
 *
 * @param array $options the array containing the aralco plugin options
 * @param array $args the arguments for how to construct this input
 */
function aralco_admin_settings_select($options, $args){
    $errors = get_settings_errors($args['label_for']);
    if(!empty($errors)) {
        foreach($errors as $index => $error){
            ?>
            <p style="color:#ff0000;"><?php print_r($error['message'])?></p>
        <?php }
    } ?>
    <select id="<?php echo esc_attr($args['label_for']); ?>"
           name="<?php echo ARALCO_SLUG ?>_options[<?php echo esc_attr($args['label_for']); ?>]"
    ><?php
        foreach($args['options'] as $label => $value){
            $selected = (isset($options[$args['label_for']]) && $options[$args['label_for']] == $value) ?
                ' selected="selected"' : '';
            ?><option value="<?php echo $value ?>"<?php echo $selected ?>><?php echo $label ?></option><?php
        }
        ?></select>
    <?php if(isset($args['description'])){?>
        <p class="description">
            <?php esc_html_e($args['description'], ARALCO_SLUG); ?>
        </p>
        <?php
    }
}

/**
 * The markup for how checkboxes are drawn in the admin options page
 *
 * @param array $options the array containing the aralco plugin options
 * @param array $args the arguments for how to construct this input
 */
function aralco_admin_settings_checkbox($options, $args){
    $errors = get_settings_errors($args['label_for']);
    if(!empty($errors)) {
        foreach($errors as $index => $error){
            ?>
            <p style="color:#ff0000;"><?php print_r($error['message'])?></p>
        <?php }
    } ?>
    <input id="<?php echo esc_attr($args['label_for']); ?>" type="checkbox"
           name="<?php echo ARALCO_SLUG ?>_options[<?php echo esc_attr($args['label_for']); ?>]"
           <?php echo (isset($options[$args['label_for']]) && $options[$args['label_for']] == true) ? 'checked="checked"' : ''; ?> value="1" />
    <?php if(isset($args['description'])){?>
        <p class="description">
            <?php esc_html_e($args['description'], ARALCO_SLUG); ?>
        </p>
        <?php
    }
}
