<?php
/*
 * Plugin Name: Course Management
 * Description: Manages the creation and updating of course pages and associated products.
 * Version: 1.0
 * Author: Carlos Murillo
 * Author URI: https://lucumaagency.com/
 * License: GPL-2.0+
 */

// Define default image ID for fallback
define('DEFAULT_IMAGE_ID', 123); // Replace with actual attachment ID

// Include the custom endpoint and utilities
require_once get_stylesheet_directory() . '/api/course-endpoint.php';
require_once plugin_dir_path(__FILE__) . 'course-utilities.php';

/**
 * 1. Initial setup and retrieval of JSON data from the custom endpoint
 */
function get_course_json_data($course_id) {
    $response = wp_remote_get(
        home_url("/wp-json/custom/v1/courses/{$course_id}"),
        [
            'timeout' => 10,
            'sslverify' => false,
        ]
    );
    
    if (is_wp_error($response)) {
        return false;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $json = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return false;
    }

    if (!$json || !is_array($json)) {
        return false;
    }

    // Map data from the custom/v1/courses endpoint
    return [
        'title' => isset($json['title']) ? sanitize_text_field($json['title']) : '',
        'content' => isset($json['content']) ? strip_shortcodes(wp_kses_post($json['content'])) : '',
        'permalink' => isset($json['permalink']) ? esc_url($json['permalink']) : '',
        'price' => isset($json['price']) ? floatval($json['price']) : 0,
        'instructor' => isset($json['instructor']) ? sanitize_text_field($json['instructor']) : '',
        'categories' => isset($json['categories']) && is_array($json['categories']) ? array_map('sanitize_text_field', $json['categories']) : [],
        'students' => isset($json['students']) ? absint($json['students']) : 0,
        'views' => isset($json['views']) ? absint($json['views']) : 0,
        'faqs' => isset($json['faqs']) && is_array($json['faqs']) ? array_map(function($faq) {
            return [
                'question' => isset($faq['question']) ? sanitize_text_field($faq['question']) : '',
                'answer' => isset($faq['answer']) ? wp_kses_post($faq['answer']) : '',
            ];
        }, $json['faqs']) : [],
        'background_image' => 0,
    ];
}

/**
 * 2. Retrieve the background image ID from the ACF field or use the featured image
 */
function get_background_image_from_course_page($course_page_id, $stm_course_id) {
    // Attempt to get the ACF field course_background_image (now as Image ID)
    $background_image_id = get_cached_acf_field('field_6819abc58a2b', $course_page_id);
    if ($background_image_id && is_numeric($background_image_id)) {
        return $background_image_id;
    }

    // If the current value is a URL (migration case), convert to ID
    $raw_value = get_post_meta($course_page_id, 'course_background_image', true);
    if ($raw_value && filter_var($raw_value, FILTER_VALIDATE_URL)) {
        $background_image_id = get_attachment_id_from_url($raw_value);
        if ($background_image_id) {
            update_field('field_6819abc58a2b', $background_image_id, $course_page_id);
            return $background_image_id;
        }
    }

    // Use the featured image from stm-courses as fallback
    $thumbnail_id = get_post_thumbnail_id($stm_course_id);
    if ($thumbnail_id) {
        $background_image_id = $thumbnail_id;
    } else {
        $background_image_id = DEFAULT_IMAGE_ID;
    }

    update_field('field_6819abc58a2b', $background_image_id, $course_page_id);
    return $background_image_id;
}

/**
 * 3. Create or update a course page and associated products
 */
function create_or_update_course_page($stm_course_id, $json) {
    // Check if a course page is already associated
    $existing_course_id = get_post_meta($stm_course_id, 'related_course_id', true);
    $course_page_id = $existing_course_id ? $existing_course_id : 0;

    // Extract slug from permalink
    $slug = isset($json['permalink']) ? basename($json['permalink']) : '';
    $slug = sanitize_title($slug);

    // Set up data for the new page
    $course_page_data = [
        'post_title' => isset($json['title']) ? $json['title'] : "Course {$stm_course_id}",
        'post_name' => $slug,
        'post_type' => 'course',
        'post_status' => 'publish',
        'post_author' => get_current_user_id(),
    ];

    if ($course_page_id) {
        $course_page_data['ID'] = $course_page_id;
        $course_page_id = wp_update_post($course_page_data, true);
    } else {
        $course_page_id = wp_insert_post($course_page_data, true);
        if ($course_page_id && !is_wp_error($course_page_id)) {
            update_post_meta($stm_course_id, 'related_course_id', $course_page_id);
        }
    }

    if (!$course_page_id || is_wp_error($course_page_id)) {
        return false;
    }

    // Check if ACF is available
    if (!function_exists('acf')) {
        return false;
    }

    // Inject featured image from stm-courses to course
    $stm_course = get_post($stm_course_id);
    if ($stm_course && $stm_course->post_type === 'stm-courses') {
        $thumbnail_id = get_post_thumbnail_id($stm_course_id);
        if ($thumbnail_id) {
            set_post_thumbnail($course_page_id, $thumbnail_id);
        } else {
            set_post_thumbnail($course_page_id, DEFAULT_IMAGE_ID);
        }
    }

    // Create WooCommerce products
    if (class_exists('WooCommerce')) {
        $course_title = isset($json['title']) ? $json['title'] : "Course {$stm_course_id}";
        
        // Product 1: Course name
        $course_product = [
            'post_title' => $course_title,
            'post_type' => 'product',
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ];
        $course_product_id = wp_insert_post($course_product, true);
        if ($course_product_id && !is_wp_error($course_product_id)) {
            wp_set_object_terms($course_product_id, 'simple', 'product_type');
            update_post_meta($course_product_id, '_visibility', 'visible');
            update_post_meta($course_product_id, '_stock_status', 'instock');
            update_post_meta($course_product_id, '_price', $json['price'] ?? 0);
            update_post_meta($course_product_id, '_regular_price', $json['price'] ?? 0);
            $course_product_link = home_url("/?add-to-cart={$course_product_id}&quantity=1");
            update_field('field_681e16f6a4555', $course_product_link, $course_page_id);
        }

        // Product 2: Webinar - Course name
        $webinar_product = [
            'post_title' => "Webinar - {$course_title}",
            'post_type' => 'product',
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ];
        $webinar_product_id = wp_insert_post($webinar_product, true);
        if ($webinar_product_id && !is_wp_error($webinar_product_id)) {
            wp_set_object_terms($webinar_product_id, 'simple', 'product_type');
            update_post_meta($webinar_product_id, '_visibility', 'visible');
            update_post_meta($webinar_product_id, '_stock_status', 'instock');
            update_post_meta($webinar_product_id, '_price', $json['price'] ?? 0);
            update_post_meta($webinar_product_id, '_regular_price', $json['price'] ?? 0);
            $webinar_product_link = home_url("/?add-to-cart={$webinar_product_id}&quantity=1");
            update_field('field_681e16fea4556', $webinar_product_link, $course_page_id);
        }
    }

    // Retrieve background image ID from ACF or featured image
    $json['background_image'] = get_background_image_from_course_page($course_page_id, $stm_course_id);

    // Convert numeric values to strings where needed
    $json['views'] = strval($json['views']);
    $json['students'] = strval($json['students']);
    $json['price'] = strval($json['price']);

    // Save data to ACF using field keys
    $acf_updates = [
        'field_6819ab78a29a' => $json['title'],
        'field_6819abc58a2b' => $json['background_image'],
        'field_123456789' => $json['content'],
        'field_6818da1febaa' => $json['price'],
        'field_6818db1febab' => $json['instructor'],
        'field_6818dcdfefbad' => $json['categories'],
        'field_6818dd7febae' => $json['students'],
        'field_6818de1febaf' => $json['views'],
        'field_6818d5cfea7' => $json['faqs'],
    ];

    foreach ($acf_updates as $field => $value) {
        $result = update_field($field, $value, $course_page_id);
        if ($result === false) {
            continue;
        }
        if ($field === 'field_6819abc58a2b' && function_exists('wp_set_post_terms')) {
            wp_update_post([
                'ID' => $course_page_id,
                'post_modified' => current_time('mysql'),
                'post_modified_gmt' => current_time('mysql', 1),
            ]);
        }
    }

    return $course_page_id;
}

/**
 * 4. Initial creation of pages for all existing stm-courses
 */
function create_initial_course_pages() {
    check_ajax_referer('create_initial_course_pages_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }

    $batch_size = 5; // Number of courses to process per batch
    $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;

    $args = [
        'post_type' => 'stm-courses',
        'posts_per_page' => $batch_size,
        'post_status' => 'publish',
        'offset' => $offset,
    ];
    $stm_courses = get_posts($args);

    if (empty($stm_courses)) {
        wp_send_json_success(['complete' => true, 'redirect' => admin_url('edit.php?post_type=course&message=pages_created')]);
    }

    $total_courses = wp_count_posts('stm-courses')->publish;
    $processed = $offset + count($stm_courses);

    foreach ($stm_courses as $stm_course) {
        $json = get_course_json_data($stm_course->ID);
        if ($json) {
            create_or_update_course_page($stm_course->ID, $json);
        }
    }

    wp_send_json_success([
        'complete' => false,
        'offset' => $offset + $batch_size,
        'progress' => min(100, round(($processed / $total_courses) * 100)),
    ]);
}
add_action('wp_ajax_create_initial_course_pages', 'create_initial_course_pages');

/**
 * 5. Manual update with ACF dropdown
 */
function update_course_page_on_save($post_id) {
    if (get_post_type($post_id) !== 'stm-courses' || wp_is_post_revision($post_id)) {
        return;
    }

    $update_action = get_cached_acf_field('update_course_page', $post_id);
    if ($update_action !== 'update') {
        return;
    }

    $json = get_course_json_data($post_id);
    if ($json) {
        create_or_update_course_page($post_id, $json);
    }

    update_field('update_course_page', 'no_update', $post_id);
}
add_action('acf/save_post', 'update_course_page_on_save', 20);

/**
 * 6. Add button in admin to trigger initial creation
 */
function add_create_course_pages_button() {
    $screen = get_current_screen();
    if ($screen->post_type !== 'stm-courses' || $screen->base !== 'edit') {
        return;
    }

    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.wrap h1').after('<a href="#" id="create-course-pages-btn" class="page-title-action">Create Course Pages</a><div id="course-creation-progress" style="margin-top:10px;display:none;">Processing: <span id="progress-percentage">0%</span></div>');

            $('#create-course-pages-btn').on('click', function(e) {
                e.preventDefault();
                if (confirm('Are you sure you want to create Course pages for all courses?')) {
                    $('#create-course-pages-btn').prop('disabled', true);
                    $('#course-creation-progress').show();
                    processCourseBatch(0);
                }
            });

            function processCourseBatch(offset) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'create_initial_course_pages',
                        nonce: '<?php echo wp_create_nonce('create_initial_course_pages_nonce'); ?>',
                        offset: offset
                    },
                    success: function(response) {
                        if (response.success) {
                            if (response.data.complete) {
                                $('#progress-percentage').text('100%');
                                alert('Pages created. Redirecting...');
                                window.location = response.data.redirect;
                            } else {
                                $('#progress-percentage').text(response.data.progress + '%');
                                processCourseBatch(response.data.offset);
                            }
                        } else {
                            $('#course-creation-progress').hide();
                            $('#create-course-pages-btn').prop('disabled', false);
                            alert('Error: ' + (response.data.message || 'Unknown error'));
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#course-creation-progress').hide();
                        $('#create-course-pages-btn').prop('disabled', false);
                        alert('Error creating pages: ' + error);
                    }
                });
            }
        });
    </script>
    <?php
}
add_action('admin_footer', 'add_create_course_pages_button');

/**
 * 7. Verify nonce for AJAX action
 */
function verify_create_course_pages_nonce() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'create_initial_course_pages_nonce')) {
        wp_send_json_error(['message' => 'Security error']);
    }
}
add_action('wp_ajax_create_initial_course_pages', 'verify_create_course_pages_nonce', 1);
?>
