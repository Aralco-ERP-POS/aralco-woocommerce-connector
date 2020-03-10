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
}
