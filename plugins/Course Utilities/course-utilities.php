<?php
/*
 * Plugin Name: Course Utilities
 * Description: Provides reusable utility functions for other course-related plugins.
 * Version: 1.0
 * Author: Carlos Murillo
 * Author URI: https://lucumaagency.com/
 * License: GPL-2.0+
 */

// Helper: Convert an image URL to an attachment ID
function get_attachment_id_from_url($image_url) {
    global $wpdb;
    $attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid='%s';", $image_url));
    return !empty($attachment) ? $attachment[0] : 0;
}
?>
