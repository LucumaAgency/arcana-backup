<?php
/*
 * Plugin Name: Course Shortcodes
 * Description: Provides shortcodes to display course-related content.
 * Version: 1.0
 * Author: Carlos Murillo
 * Author URI: https://lucumaagency.com/
 * License: GPL-2.0+
 */

// Define default instructor photo URL
define('DEFAULT_INSTRUCTOR_PHOTO_URL', 'https://secure.gravatar.com/avatar/960ae940db3ec6809086442871c87a389e05b3da89bc95b29d6202c14b036c2b?s=200&d=mm&r=g');
define('DEFAULT_IMAGE_ID', 123); // Replace with actual attachment ID

// Include utilities if available
$utilities_path = WP_PLUGIN_DIR . '/course-utilities/course-utilities.php';
if (file_exists($utilities_path)) {
    require_once $utilities_path;
} else {
    // Log error and prevent fatal error
    error_log('Course Utilities plugin not found at ' . $utilities_path);
    add_action('admin_notices', function() {
        ?>
        <div class="notice notice-error">
            <p><strong>Course Shortcodes Error:</strong> The Course Utilities plugin is required but was not found. Please ensure it is installed and activated.</p>
        </div>
        <?php
    });
}

// Shortcode to render the instructor's photo
add_shortcode('instructor_photo', function ($atts) {
    $atts = shortcode_atts([
        'post_id' => get_the_ID(),
    ], $atts, 'instructor_photo');

    $post_id = $atts['post_id'];

    $instructor_photo_link = function_exists('get_cached_acf_field') ? get_cached_acf_field('field_6818dc2febac', $post_id) : get_field('field_6818dc2febac', $post_id);
    $photo_url = ($instructor_photo_link && isset($instructor_photo_link['url']) && !empty($instructor_photo_link['url'])) 
        ? esc_url($instructor_photo_link['url']) 
        : DEFAULT_INSTRUCTOR_PHOTO_URL;

    return '<img src="' . $photo_url . '" alt="Instructor Photo" class="instructor-image" />';
});

// Shortcode to render course content on 'course' type pages
add_shortcode('course_content', function ($atts) {
    $atts = shortcode_atts([
        'post_id' => 0,
    ], $atts, 'course_content');

    $course_page_id = get_the_ID();
    $stm_course_id = absint($atts['post_id']) ?: (function_exists('get_related_stm_course_id') ? get_related_stm_course_id($course_page_id) : 0);

    if (!$stm_course_id) {
        return '';
    }

    $course_data = get_course_json_data($stm_course_id);
    if (!$course_data || !isset($course_data['content']) || empty($course_data['content'])) {
        return '';
    }

    return wp_kses_post($course_data['content']);
});

// Shortcode to display instructor's social media links on 'course' type pages
add_shortcode('instructor_socials', function ($atts) {
    $atts = shortcode_atts([
        'post_id' => 0,
    ], $atts, 'instructor_socials');

    $course_page_id = get_the_ID();
    if (get_post_type($course_page_id) !== 'course') {
        return '';
    }

    $stm_course_id = absint($atts['post_id']) ?: (function_exists('get_related_stm_course_id') ? get_related_stm_course_id($course_page_id) : 0);
    if (!$stm_course_id) {
        return '';
    }

    $stm_course = get_post($stm_course_id);
    if (!$stm_course || $stm_course->post_type !== 'stm-courses') {
        return '';
    }

    $instructor_id = get_post_meta($stm_course_id, 'instructor_id', true);
    if (!$instructor_id || !is_numeric($instructor_id)) {
        $instructor_id = $stm_course->post_author;
    }

    $user = get_user_by('ID', $instructor_id);
    if (!$user) {
        return '';
    }

    $social_networks = [
        'facebook' => [
            'icon' => 'fab fa-facebook-f',
            'label' => 'Facebook',
        ],
        'twitter' => [
            'icon' => 'fab fa-x-twitter',
            'label' => 'X',
        ],
        'instagram' => [
            'icon' => 'fab fa-instagram',
            'label' => 'Instagram',
        ],
        'linkedin' => [
            'icon' => 'fab fa-linkedin-in',
            'label' => 'LinkedIn',
        ],
    ];

    $output = '<ul class="instructor-socials">';
    $has_socials = false;

    foreach ($social_networks as $key => $social) {
        $url = get_user_meta($instructor_id, $key, true);
        if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
            $has_socials = true;
            $output .= sprintf(
                '<li><a href="%s" target="_blank" title="%s"><i class="%s"></i></a></li>',
                esc_url($url),
                esc_attr($social['label']),
                esc_attr($social['icon'])
            );
        }
    }

    $output .= '</ul>';

    if (!$has_socials) {
        return '';
    }

    $output .= '<style>
        .instructor-socials {
            list-style: none;
            padding: 0;
            display: flex;
            gap: 10px;
        }
        .instructor-socials li {
            display: inline-block;
        }
        .instructor-socials a {
            color: #333;
            font-size: 20px;
            text-decoration: none;
        }
        .instructor-socials a:hover {
            color: #0073aa;
        }
    </style>';

    return $output;
});

// Shortcode to display the course image gallery on 'course' type pages
add_shortcode('course_gallery', function ($atts) {
    $atts = shortcode_atts([
        'post_id' => 0,
    ], $atts, 'course_gallery');

    $course_page_id = get_the_ID();
    if (get_post_type($course_page_id) !== 'course') {
        return '';
    }

    $stm_course_id = absint($atts['post_id']) ?: (function_exists('get_related_stm_course_id') ? get_related_stm_course_id($course_page_id) : 0);
    if (!$stm_course_id) {
        return '';
    }

    $stm_course = get_post($stm_course_id);
    if (!$stm_course || $stm_course->post_type !== 'stm-courses') {
        return '';
    }

    if (!function_exists('get_field')) {
        return '';
    }

    $field_object = function_exists('get_cached_acf_field') ? get_cached_acf_field('gallery_portfolio', $stm_course_id) : get_field('gallery_portfolio', $stm_course_id);
    if (!$field_object) {
        return '';
    }

    $gallery_images = function_exists('get_cached_acf_field') ? get_cached_acf_field('gallery_portfolio', $stm_course_id) : get_field('gallery_portfolio', $stm_course_id);
    if (empty($gallery_images) || !is_array($gallery_images)) {
        $gallery_images = [
            [
                'url' => wp_get_attachment_url(DEFAULT_IMAGE_ID),
                'sizes' => ['thumbnail' => wp_get_attachment_url(DEFAULT_IMAGE_ID)],
                'alt' => 'Default Gallery Image',
                'caption' => 'Default Image'
            ]
        ];
    }

    $output = '<div class="course-gallery">';
    foreach ($gallery_images as $image) {
        $full_url = isset($image['url']) ? esc_url($image['url']) : '';
        $thumbnail_url = isset($image['sizes']['thumbnail']) ? esc_url($image['sizes']['thumbnail']) : $full_url;
        $alt = isset($image['alt']) ? esc_attr($image['alt']) : '';
        $caption = isset($image['caption']) ? esc_attr($image['caption']) : '';

        if ($full_url) {
            $output .= sprintf(
                '<div class="gallery-item">' .
                '<a href="%s" class="gallery-lightbox" title="%s">' .
                '<img src="%s" alt="%s" class="gallery-thumbnail" />' .
                '</a>' .
                '%s' .
                '</div>',
                $full_url,
                $caption,
                $thumbnail_url,
                $alt,
                $caption ? '<p class="gallery-caption">' . $caption . '</p>' : ''
            );
        }
    }
    $output .= '</div>';

    $output .= '<style>
        .course-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin: 20px 0;
        }
        .gallery-item {
            position: relative;
        }
        .gallery-thumbnail {
            width: 100%;
            height: auto;
            object-fit: cover;
            border-radius: 5px;
        }
        .gallery-caption {
            text-align: center;
            font-size: 14px;
            margin: 5px 0 0;
            color: #333;
        }
        .gallery-lightbox {
            display: block;
        }
    </style>';

    if (!wp_script_is('jquery', 'enqueued')) {
        wp_enqueue_script('jquery');
    }
    $output .= '<script>
        jQuery(document).ready(function($) {
            $(".gallery-lightbox").on("click", function(e) {
                e.preventDefault();
                var imgSrc = $(this).attr("href");
                var caption = $(this).attr("title");
                var lightbox = $("<div class=\"lightbox\"><img src=\"" + imgSrc + "\" /><p>" + caption + "</p><span class=\"close-lightbox\">×</span></div>");
                $("body").append(lightbox);
                lightbox.fadeIn();
                lightbox.find(".close-lightbox, img").on("click", function() {
                    lightbox.fadeOut(function() { $(this).remove(); });
                });
            });
        });
    </script>';

    $output .= '<style>
        .lightbox {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .lightbox img {
            max-width: 90%;
            max-height: 80%;
            border-radius: 5px;
        }
        .lightbox p {
            color: white;
            text-align: center;
            margin-top: 10px;
        }
        .lightbox .close-lightbox {
            position: absolute;
            top: 20px;
            right: 20px;
            color: white;
            font-size: 30px;
            cursor: pointer;
        }
    </style>';

    return $output;
});

// Shortcode to inject the featured image from stm-courses to its related course
add_shortcode('inject_featured_image', function ($atts) {
    $atts = shortcode_atts([
        'post_id' => 0,
    ], $atts, 'inject_featured_image');

    $course_page_id = get_the_ID();
    if (get_post_type($course_page_id) !== 'course') {
        return '';
    }

    $stm_course_id = absint($atts['post_id']) ?: (function_exists('get_related_stm_course_id') ? get_related_stm_course_id($course_page_id) : 0);
    if (!$stm_course_id) {
        return '';
    }

    $stm_course = get_post($stm_course_id);
    if (!$stm_course || $stm_course->post_type !== 'stm-courses') {
        return '';
    }

    $thumbnail_id = get_post_thumbnail_id($stm_course_id);
    if (!$thumbnail_id) {
        $thumbnail_id = DEFAULT_IMAGE_ID;
    }

    set_post_thumbnail($course_page_id, $thumbnail_id);
    return '';
});
?>
