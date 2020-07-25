<?php

defined( 'ABSPATH' ) or die(); // Prevents direct access to file.

class Aralco_Util {
    /**
     * Checks if a file already exists in the media library
     *
     * @param $filename
     * @return int
     */
    static function does_file_exists($filename) {
        global $wpdb;

        return intval( $wpdb->get_var( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value LIKE '%/$filename'" ) );
    }

    /**
     * Deletes all attachments associated with a post
     *
     * @param int $post_id the post to delete the attachments for
     */
    static function delete_all_attachments_for_post($post_id) {
        $attachments = get_posts(array(
            'post_type' => 'attachment',
            'posts_per_page' => -1,
            'post_parent' => $post_id
        ));

        if ($attachments) {
            foreach ($attachments as $attachment) {
                wp_delete_attachment($attachment->ID, true);
            }
        }
    }

    /**
     * Returns a id safe string.
     * safe strings are trimmed, all lowercase, non-alphanumeric characters removed, one or more whitespace characters are replaced with a single dash.
     *
     * @param string $str
     * @return string
     */
    static function sanitize_name($str) {
        return preg_replace(
            "/[^a-z0-9\-]/",
            '',
            preg_replace(
                "/\s+/",
                '-',
                strtolower(
                    trim($str)
                )
            )
        );
    }

    /**
     * Convert a flat relational array to a nested tree array
     *
     * @param array $flat the flat array to convert
     * @param string $idField the id to check the children against
     * @param string $parentIdField the parent id to find
     * @param string $childNodesField the field to stuff child nodes into in the parent
     * @return array the tree array
     */
    static function convertToTree(array $flat, $idField = 'id', $parentIdField = 'parent', $childNodesField = 'children') {
        $indexed = array();
        // first pass - get the array indexed by the primary id
        foreach ($flat as $row) {
            $indexed[$row[$idField]] = $row;
            $indexed[$row[$idField]][$childNodesField] = array();
        }

        //second pass
        $to_return = array();
        foreach ($indexed as $id => $row) {
            if($row[$parentIdField] !== null) {
                $indexed[$row[$parentIdField]][$childNodesField][$id] =& $indexed[$id];
            } else {
                $to_return[] =& $indexed[$row[$idField]];
            }
        }

        return $to_return;
    }

}
