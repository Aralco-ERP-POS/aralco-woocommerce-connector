<?php

add_action('rest_api_init', 'aralco_register_rest_routs');

function aralco_register_rest_routs() {
    register_rest_route(
        'aralco-wc/v1',
        '/widget/filters/(?P<department>[a-z0-9\-]+)',
        array(
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => 'aralco_get_filters_for_department'
        )
    );
}

function aralco_get_filters_for_department($data) {
    $term = get_term_by('slug', $data['department'], 'product_cat');
    if ($term instanceof WP_Error) return $term;
    if ($term instanceof WP_Term) {

        $filters = array();
        $temp_filters = get_term_meta($term->term_id, 'aralco_filters', true);
        if (is_array($temp_filters)) {
            foreach ($temp_filters as $temp_filter) {
                array_push($filters, 'grouping-' . Aralco_Util::sanitize_name($temp_filter));
            }
        }

        $return = array();
        foreach ($filters as $filter) {
            /**
             * @var $the_taxonomy WP_Taxonomy
             */
            $the_taxonomy = get_taxonomy(wc_attribute_taxonomy_name($filter));
            $the_terms = get_terms(array(
                'taxonomy' => wc_attribute_taxonomy_name($filter)
                /*, 'hide_empty' => false*/
            ));

            $options = array();
            if ($the_taxonomy instanceof WP_Taxonomy && !($the_terms instanceof WP_Error)) {
                $options[''] = __('Any', ARALCO_SLUG);
                foreach ($the_terms as $the_term) {
                    $options[$the_term->slug] = $the_term->name;
                }
                if ($options > 1) {
                    $return['filter_' . $filter] = array(
                        'label' => $the_taxonomy->label,
                        'options' => $options
                    );
                }
            }
        }
        return $return;
    }
    return array();
}