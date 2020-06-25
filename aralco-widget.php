<?php
class List_Groupings_For_Department_Widget extends WP_Widget{

    function __construct(){
        parent::__construct(
            'list-groupings-for-department',  // Base ID
            'List Groupings for Department',   // Name
            ['description' => __( 'Lists all the groupings for the current department filter.' , ARALCO_SLUG )]
        );
        add_action('widgets_init', function(){
            register_widget( 'List_Groupings_For_Department_Widget');
        });
    }

    public $args = [
        'before_title'  => '<h4 class="widget-title">',
        'after_title'   => '</h4>',
        'before_widget' => '<div class="widget-wrap">',
        'after_widget'  => '</div></div>'
    ];

    public function widget($args, $instance){
        require_once 'partials/aralco-admin-settings-input.php';
        if(!wp_script_is('select2')){
            wp_register_script( 'select2', WC()->plugin_url() . '/assets/js/select2/select2.full.min.js', array( 'jquery' ), '4.0.3' );
            wp_enqueue_style( 'select2');
        }
        if(!wp_script_is('selectWoo')){
            wp_register_script( 'selectWoo', WC()->plugin_url() . '/assets/js/selectWoo/selectWoo.full.min.js', array( 'jquery' ), '1.0.6' );
            wp_enqueue_script( 'selectWoo');
        }
        if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            $filters = array('product_cat');
            if(is_product_category()) {
                global $wp_query;
                $cat = $wp_query->get_queried_object();
            } else if(isset($_GET['product_cat'])){
                $cat = get_term_by('slug', sanitize_text_field($_GET['product_cat']), 'product_cat');
            }
            if (isset($cat) && $cat instanceof WP_Term) {
                $temp_filters = get_term_meta($cat->term_id, 'aralco_filters',true);
                if(is_array($temp_filters)) {
                    foreach($temp_filters as $temp_filter){
                        array_push($filters, 'grouping-' . Aralco_Util::sanitize_name($temp_filter));
                    }
                }
            }

            $title = (isset($instance['title']) && !empty($instance['title']))? $instance['title'] :
                __('Product Search', ARALCO_SLUG);
            $subtitle = (isset($instance['subtitle']) && !empty($instance['subtitle']))? $instance['subtitle'] :
                __('Product Filters', ARALCO_SLUG);
            global $wp;
            echo $args['before_widget'] . $args['before_title'] . apply_filters('widget_title', $title) .
                $args['after_title'] . '<div class="list-groupings-for-department-widget">' .
                '<form id="product-filter-form" class="woocommerce-widget-layered-nav-dropdown" method="get" action="' .
                home_url() . '">' .
                '<input type="hidden" name="post_type" value="product">' .
                '<input type="hidden" name="type_aws" value="true">';
            $min_price = '';
            $max_price = '';
            $s = '';
            if(isset($_GET['min_price']) && is_numeric($_GET['min_price'])){
                $min_price = intval($_GET['min_price']);
                if($min_price <= 0) $min_price = '';
            }
            if(isset($_GET['max_price']) && is_numeric($_GET['max_price'])){
                $max_price = intval($_GET['max_price']);
                if($max_price <= 0) $max_price = '';
            }
            if(isset($_GET['s']) && !empty($_GET['s'])) {
                $s = sanitize_text_field($_GET['s']);
            }
            echo '<p class="form-row wps-drop">
<label for="s">Keyword</label>
<input type="text" id="s" name="s" value="' . $s . '" placeholder="Search&hellip;" style="width:100%">
</p>
<p class="flex-filter-buttons mobile-only">
<button class="button reset">Clear</button>
<button class="button" type="submit">Show Results</button>
</p>' .
                $args['before_title'] . apply_filters('widget_subtitle', $subtitle) . $args['after_title'] .
'<p class="form-row wps-drop">
<label for="min_price, max_price">Price</label>
<div class="flex-filter-buttons">
<input type="number" id="min_price" name="min_price" value="' . $min_price . '" placeholder="Min" style="width:45%">
<div style="width: 10%; font-size: 2em; text-align: center">-</div>
<input type="number" id="max_price" name="max_price" value="' . $max_price . '" placeholder="Max" style="width:45%">
</div>
</p>';

            foreach($filters as $filter){
                if($filter != 'product_cat'){
                    $attr_filter = wc_attribute_taxonomy_name($filter);
                    $filter_name = 'filter_' . $filter;
                } else {
                    $attr_filter = $filter;
                    $filter_name = $filter;
                }
                /**
                 * @var $the_taxonomy WP_Taxonomy
                 */
                $the_taxonomy = get_taxonomy($attr_filter);
                $the_terms = get_terms(array(
                    'taxonomy' => $attr_filter
                    /*, 'hide_empty' => false*/
                ));
                $options = array();
                if ($the_taxonomy instanceof WP_Taxonomy && !($the_terms instanceof WP_Error)) {
                    $options[''] = __('Any', ARALCO_SLUG);
                    foreach($the_terms as $the_term) {
                        $options[$the_term->slug] = $the_term->name;
                    }
                    if($options > 1) {
                        $value = array();
                        if (isset($_GET[$filter_name]) && $filter != 'product_cat'){
                            foreach($options as $slug => $name) {
                                $get_array = explode(',', $_GET[$filter_name]);
                                if(in_array($slug, $get_array)){
                                    array_push($value, $slug);
                                }
                            }
                        } else if(isset($cat) && $cat instanceof WP_Term && $filter == 'product_cat') {
                            $value = $cat->slug;
                        } else if($filter == 'product_cat') {
                            $value = '';
                        }

                        $classes = array('wps-drop', 'js-use-select2');
                        $multiple = false;
                        if($filter != 'product_cat'){
                            array_push($classes, 'attr_filter');
                            $multiple = true;
                            echo '<input type="hidden" name="query_type' . substr($filter_name, 6) . '" value="or">';
                        }

                        aralco_form_field($filter_name, array(
                            'type' => 'select',
                            'class' => $classes,
                            'label' => $the_taxonomy->label,
                            'options' => $options,
                            'multiple' => $multiple,
                        ), $value);
                    }
                }
            }
            echo '<div class="flex-filter-buttons">
<button class="button reset">Clear</button>
<button class="button" type="submit">Show Results</button>
</div></form></div>' . $args['after_widget'];
            wc_enqueue_js('
$(".js-use-select2 select").select2({
    placeholder: "Any",
    width: "100%"
});
$(".button.reset").on("click", function(e){
    e.preventDefault();
    $("#s").val(null);
    $(".js-use-select2 select").val(null).trigger("change");
});
$(document).on("change.select2", "#product_cat", function() {
    $(".attr_filter, .please-wait").remove();
    if($(this).val().length <= 0 || $(this).val().indexOf("department") < 0) return;
    console.log("test");
    $("#product_cat_field").after("<p class=\'please-wait\' style=\'font-size:2em;\'>Please Wait...</p>");
    $.get("' . get_rest_url() . /** @lang JavaScript */'aralco-wc/v1/widget/filters/" + $(this).val(), function(data, status) {
    $(".please-wait").remove();
    if(status === "success"){
        let fields = "";
        for(let fieldId in data){
            if(data.hasOwnProperty(fieldId)){
                fields += "<p id=\'" + fieldId + "_field\' class=\'form-row wps-drop js-use-select2 attr_filter\' data-priority=\'\'>" +
                "<label for=\'" + fieldId + "\' class=\'\'>" + data[fieldId].label + "</label><span class=\'woocommerce-input-wrapper\'>" +
                "<select name=\'" + fieldId + "\' id=\'" + fieldId + "\' class=\'select \' data-allow_clear=\'true\' data-placeholder=\'Any\' multiple>";
                for (let optionId in data[fieldId].options){
                    if(data[fieldId].options.hasOwnProperty(optionId)){
                        fields += "<option value=\'" + optionId + "\'>" + data[fieldId].options[optionId] + "</option>";
                    }
                }
                fields += "</select></span></p>";
            }
        }
        if(fields.length > 0){
            $("#product_cat_field").after(fields);
            $(".js-use-select2.attr_filter select").select2({
                placeholder: "Any",
                width: "100%"
            });
        }
    } else {
        console.error(data);
        $("#product_cat_field").after("<p class=\'please-wait\' style=\'color:#f00;\'>An error has occurred!</p>");
    }
    })
});
$("#product-filter-form").on("submit", function() {
    // This section converts the multi selects to a comma delimited input
    $(this).find(".attr_filter select[name]").each(function() {
        if(Array.isArray($(this).val()) && $(this).val().length > 0){
            $("<input>").attr("type", "hidden")
            .attr("name", $(this).attr("name"))
            .attr("value", $(this).val().join(","))
            .appendTo("#product-filter-form");
            $(this).prop("name", "");
        }
    });
    
    // This section prevents blanks fields from being submitted
    $(this).find("input[name], select[name]")
    .filter(function() {
        return !this.value;
    })
    .prop("name", "");
});
if (($(document.body).hasClass("search") || $(document.body).hasClass("archive")) && $(window).width() < 768) {
    window.scroll({top: $("#primary").offset().top - 50});
}');
        } else {
            $subtitle = (isset($instance['subtitle']) && !empty($instance['subtitle']))? $instance['subtitle'] :
                __('Product Filters', ARALCO_SLUG);
            echo $args['before_widget'] . $args['before_title'] . apply_filters('widget_subtitle', $subtitle) . $args['after_title'] .
                '<div class="list-groupings-for-department-widget">WooCommerce is required for this widget to function</div>' .
                $args['after_widget'];
        }
    }

    public function form($instance) {
        $title = !empty($instance['title'])? $instance['title'] : esc_html__('', ARALCO_SLUG);
        $subtitle = !empty($instance['subtitle'])? $instance['subtitle'] : esc_html__('', ARALCO_SLUG);
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php echo esc_html__('Search Title:', ARALCO_SLUG); ?>
            </label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                   name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text"
                   value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('subtitle')); ?>">
                <?php echo esc_html__('Filter Title:', ARALCO_SLUG); ?>
            </label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('subtitle')); ?>"
                   name="<?php echo esc_attr($this->get_field_name('subtitle')); ?>" type="text"
                   value="<?php echo esc_attr($subtitle); ?>">
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = [];
        $instance['title'] = (!empty($new_instance['title']))? strip_tags($new_instance['title']) : '';
        $instance['subtitle'] = (!empty($new_instance['subtitle']))? strip_tags($new_instance['subtitle']) : '';
        return $instance;
    }
}

new List_Groupings_For_Department_Widget();