<?php
require_once 'aralco-util.php';

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
        add_action('wp_enqueue_scripts', function(){
            wp_enqueue_style('dashicons');
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
//            $subtitle = (isset($instance['subtitle']) && !empty($instance['subtitle']))? $instance['subtitle'] :
//                __('Advanced Search', ARALCO_SLUG);
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
<label for="s" class="screen-reader-text">Keyword</label>
<span class="group">
<span class="input">
    <input type="text" id="s" name="s" value="' . $s . '" placeholder="Product Name, Code, Text Search&hellip;" style="width:100%">
    <button id="clear-search-field" type="button" style="display: none;">
        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
        <span class="screen-reader-text">Clear Field</span>
    </button>
</span>
<button type="submit">
    <span class="dashicons dashicons-search" aria-hidden="true"></span>
    <span class="screen-reader-text">Show Results</span>
</button>
</span>
<div class="flex-filter-buttons mobile-only" aria-hidden="true">
<button class="button reset">Clear</button>
<button class="button" type="submit">Show Results</button>
</div>
</p>'; //<span class="gamma widget-title widget-subtitle">' . apply_filters('widget_subtitle', $subtitle) . '</span>';

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
//                    'hide_empty' => false,
                    'taxonomy' => $attr_filter
                ));
                $options = array();
                if ($the_taxonomy instanceof WP_Taxonomy && !($the_terms instanceof WP_Error)) {
                    foreach($the_terms as $the_term) {
                        array_push($options, array(
                            'id' => $the_term->term_id,
                            'parent' => (is_numeric($the_term->parent) && $the_term->parent > 0) ? $the_term->parent : null,
                            'slug' => $the_term->slug,
                            'text' => $the_term->name
                        ));
                    }
                    if($filter != 'product_cat') {
                        usort($options, function($a, $b) {
                            return strcmp($a['slug'], $b['slug']);
                        });
                    } else {
                        usort($options, function($a, $b) {
                            return strcmp($a['text'], $b['text']);
                        });
                        $options = array_values(Aralco_Util::convertToTree($options));
                    }
                    array_unshift($options, array(
                        'id' => '',
                        'parent' => '',
                        'slug' => '',
                        'text' => __('Any', ARALCO_SLUG)
                    ));
                    $options = array_values($options);

                    if(count($options) > 0) {
                        $value = array();
                        if (isset($_GET[$filter_name]) && $filter != 'product_cat'){
                            foreach($options as $i => $option) {
                                $get_array = explode(',', $_GET[$filter_name]);
                                if(in_array($option['slug'], $get_array)){
                                    array_push($value, $option['slug']);
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
                            'placeholder' => 'Any'
                        ), $value);
                    }
                }
            }
            echo '<p class="form-row wps-drop">
<label for="min_price, max_price">Price</label>
<span class="flex-filter-buttons">
<input type="number" id="min_price" name="min_price" value="' . $min_price . '" placeholder="Min" style="width:45%">
<span style="width: 10%; font-size: 2em; text-align: center; font-weight: bold;">-</span>
<input type="number" id="max_price" name="max_price" value="' . $max_price . '" placeholder="Max" style="width:45%">
</span>
</p>
<div class="flex-filter-buttons">
<button class="button reset">Clear</button>
<button class="button" type="submit">Show Results</button>
</div></form></div>' . $args['after_widget'];
            /** @noinspection JSJQueryEfficiency */
            wc_enqueue_js('
function select2TemplateResult (data) {
    if (!data.element) return data.text;
    let indent = (($(data.element)[0].className.split("-")[1]) - 1) + "em";
    return $("<span></span>").css("padding-left", indent).text(data.text);
}
$("#s, #min_price, #max_price").on("keydown", function(e){
    if (13 === e.which) {
        e.preventDefault();
        $("#product-filter-form").submit();
    }
});
$(".js-use-select2 select").select2({
    templateResult: select2TemplateResult,
    placeholder: "Any",
    width: "100%"
});
$(".button.reset").on("click", function(e){
    e.preventDefault();
    $("#s, #min_price, #max_price").val(null);
    $(".js-use-select2 select").val(null).trigger("change");
    $("#clear-search-field").hide();
});
$("#s").on("input", function(){
    if($(this).val()) {
        $("#clear-search-field").show();
    } else {
        $("#clear-search-field").hide();
    }
});
$("#clear-search-field").on("click", function(e){
    e.preventDefault();
    $("#s").val(null);
    $(this).hide();
});
if($("#s").val()){
    $("#clear-search-field").show();
}
$(document).on("change.select2", "#product_cat", function() {
    $(".attr_filter, .please-wait").remove();
    if($(this).val().length <= 0 || $(this).val().indexOf("department") < 0) return;
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
                let keys = Object.keys(data[fieldId].options).sort();
                for (let i in keys){
                    if(data[fieldId].options.hasOwnProperty(keys[i])){
                        fields += "<option value=\'" + keys[i] + "\'>" + data[fieldId].options[keys[i]] + "</option>";
                    }
                }
                fields += "</select></span></p>";
            }
        }
        if(fields.length > 0){
            $("#product_cat_field").after(fields);
            $(".js-use-select2.attr_filter select").select2({
                templateResult: select2TemplateResult,
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
        echo '<style>
.widget.widget_list-groupings-for-department input,
.widget.widget_list-groupings-for-department .select2-selection__rendered,
.widget.widget_list-groupings-for-department .select2-selection__choice {
    font-weight: bold;
}
.widget.widget_list-groupings-for-department p {
    margin-bottom: 0.75rem;
}
.widget.widget_list-groupings-for-department .group {
    display: flex;
    flex-direction: row;
}
.widget.widget_list-groupings-for-department .group .input {
    flex-grow: 1;
    position: relative;
}
#clear-search-field {
    background: transparent;
    position: absolute;
    right: 0;
    top: 0;
}
#clear-search-field:hover, #clear-search-field:active {
    background: rgba(0,0,0, 0.1);
}
.widget.widget_list-groupings-for-department .group button {
    flex-grow: 0;
    padding: 0.6180469716em 0.6180469716em;
}
@media screen and (min-width: 768px) {
    .mobile-only {
        display: none;
    }
}
@media screen and (max-width: 767px) {
    .gamma.widget-title {
        text-align: center;
    }
}
.gamma.widget-subtitle {
    border: none;
    padding-bottom: 0;
    margin-bottom: 0.5em;
}
</style>';
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