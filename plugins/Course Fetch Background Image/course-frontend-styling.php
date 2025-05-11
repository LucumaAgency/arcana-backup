<?php
/*
 * Plugin Name: Course Fetch Background Image
 * Description: Dynamically fetches and applies the featured image as a background for course pages.
 * Version: 1.0
 * Author: Carlos Murillo
 * Author URI: https://lucumaagency.com/
 * License: GPL-2.0+
 */

// Add background-image style to the .selling-page-bkg class in the frontend
add_action('wp_footer', function() {
    if (!is_singular('course')) {
        return;
    }

    $course_page_id = get_the_ID();
    $thumbnail_id = get_post_thumbnail_id($course_page_id);
    if (!$thumbnail_id) {
        return;
    }

    $thumbnail_url = wp_get_attachment_image_url($thumbnail_id, 'full');
    if (!$thumbnail_url) {
        return;
    }

    echo '<style>
        .selling-page-bkg {
            background-image: url("' . esc_url($thumbnail_url) . '");
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }
    </style>';
});
?>
