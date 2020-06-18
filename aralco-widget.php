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
        if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            if(is_product_category()) {
                global $wp_query;
                /**
                 * @noinspection PhpUndefinedMethodInspection
                 * @var $cat WP_Term
                 */
                $cat = $wp_query->get_queried_object();
                $filters = array();
                $temp_filters = get_term_meta($cat->term_id, 'aralco_filters',true);
                if(is_array($temp_filters)) {
                    foreach($temp_filters as $temp_filter){
                        array_push($filters, 'grouping-' . Aralco_Util::sanitize_name($temp_filter));
                    }
                }
                if(count($filters) > 0){
                    $title = (isset($instance['title']) && !empty($instance['title']))? $instance['title'] :
                        __('Product Filters', ARALCO_SLUG);
                    global $wp;
                    echo $args['before_widget'] . $args['before_title'] . apply_filters('widget_title', $title) .
                        $args['after_title'] . '<div class="list-groupings-for-department-widget">' .
                        '<form class="woocommerce-widget-layered-nav-dropdown" method="get" action="' .
                        home_url(add_query_arg(array(), $wp->request)) . '">';
                    if(isset($_GET['min_price']) && is_numeric($_GET['min_price'])){
                        echo '<input type="hidden" name="min_price" value="' . intval($_GET['min_price']) . '">';
                    }
                    if(isset($_GET['max_price']) && is_numeric($_GET['max_price'])){
                        echo '<input type="hidden" name="max_price" value="' . intval($_GET['max_price']) . '">';
                    }
                    foreach($filters as $filter){
                        /**
                         * @var $the_taxonomy WP_Taxonomy
                         */
                        $the_taxonomy = get_taxonomy(wc_attribute_taxonomy_name($filter));
                        $the_terms = get_terms(array(
                            'taxonomy' => wc_attribute_taxonomy_name($filter)
                            /*, 'hide_empty' => false*/
                        ));
                        $options = array();
                        if ($the_taxonomy instanceof WP_Taxonomy && !($the_terms instanceof WP_Error) &&
                            $the_taxonomy->public && $the_taxonomy->publicly_queryable) {
                            $options[''] = __('Select an option...', ARALCO_SLUG);
                            foreach($the_terms as $the_term) {
                                $options[$the_term->slug] = $the_term->name;
                            }
                        }
                        if($options > 1) {
                            $value = '';
                            if (isset($_GET['filter_' . $filter])){
                                foreach($options as $slug => $name) {
                                    if($_GET['filter_' . $filter] === $slug){
                                        $value = $slug;
                                        break;
                                    }
                                }
                            }

                            aralco_form_field('filter_' . $filter, array(
                                'type' => 'select',
                                'class' => array('wps-drop'),
                                'label' => $the_taxonomy->label,
                                'options' => $options
                            ), $value);
                        }
                    }
                    echo '<button class="button" type="submit">Filter</button></form></div>' . $args['after_widget'];
                }
            }
        } else {
            $title = (isset($instance['title']) && !empty($instance['title']))? $instance['title'] :
                __('Product Filters', ARALCO_SLUG);
            echo $args['before_widget'] . $args['before_title'] . apply_filters('widget_title', $title) . $args['after_title'] .
                '<div class="list-groupings-for-department-widget">WooCommerce is required for this widget to function</div>' .
                $args['after_widget'];
        }
    }

    public function form($instance) {
        $title = !empty($instance['title'])? $instance['title'] : esc_html__('', ARALCO_SLUG);
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php echo esc_html__('Title:', ARALCO_SLUG); ?>
            </label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                   name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text"
                   value="<?php echo esc_attr($title); ?>">
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = [];
        $instance['title'] = (!empty($new_instance['title']))? strip_tags($new_instance['title']) : '';
        return $instance;
    }
}

new List_Groupings_For_Department_Widget();