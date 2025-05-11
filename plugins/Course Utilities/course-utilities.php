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
    $attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid='%s' LIMIT 1;", $image_url));
    return !empty($attachment) ? $attachment[0] : 0;
}

// Helper: Get the stm-courses ID related to a course page ID
function get_related_stm_course_id($course_page_id) {
    $cache_key = 'stm_course_id_' . $course_page_id;
    $stm_course_id = wp_cache_get($cache_key, 'course_utilities');
    if (false !== $stm_course_id) {
        return $stm_course_id;
    }

    $args = [
        'post_type' => 'stm-courses',
        'posts_per_page' => 1,
        'post_status' => 'publish',
        'meta_query' => [
            [
                'key' => 'related_course_id',
                'value' => $course_page_id,
                'compare' => '=',
            ],
        ],
        'fields' => 'ids',
    ];
    $stm_courses = get_posts($args);

    $stm_course_id = !empty($stm_courses) ? $stm_courses[0] : 0;
    wp_cache_set($cache_key, $stm_course_id, 'course_utilities', HOUR_IN_SECONDS);

    return $stm_course_id;
}

// Helper: Get cached ACF field value
function get_cached_acf_field($field_key, $post_id) {
    $cache_key = "acf_{$field_key}_{$post_id}";
    $value = wp_cache_get($cache_key, 'acf_fields');
    if (false === $value) {
        $value = get_field($field_key, $post_id);
        wp_cache_set($cache_key, $value, 'acf_fields', HOUR_IN_SECONDS);
    }
    return $value;
}
?>
