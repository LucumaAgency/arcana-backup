<?php
// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Custom Endpoint
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/courses/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => function ($request) {
            $course_id = $request['id'];
            $course = get_post($course_id);

            if (!$course || $course->post_type !== 'stm-courses') {
                return new WP_Error('no_course', 'Curso no encontrado', ['status' => 404]);
            }

            // Obtener el contenido sin procesar shortcodes
            $content = wp_kses_post($course->post_content); // Limpia HTML pero no procesa shortcodes

            // Obtener datos adicionales del curso
            $price = get_post_meta($course_id, 'price', true) ?: '739.97';
            $instructor = '';
            $instructor_photo = '';

            // Obtener el ID del instructor desde el autor del post
            $instructor_id = $course->post_author;
            error_log('Course ID: ' . $course_id . ' | Instructor ID (post_author): ' . $instructor_id);

            // Si se obtiene un instructor_id válido, obtener nombre y foto
            if ($instructor_id && is_numeric($instructor_id)) {
                $user = get_userdata($instructor_id);
                if ($user) {
                    $instructor = $user->display_name;

                    // Construir la URL de la foto del instructor con extensión .jpg
                    $instructor_photo_url = "https://arcana.pruebalucuma.site/wp-content/uploads/stm_lms_avatars/stm_lms_avatar{$instructor_id}.jpg";
                    error_log('Instructor photo URL constructed: ' . $instructor_photo_url);

                    $instructor_photo_id = get_attachment_id_from_url($instructor_photo_url); // Usar la función de functions.php
                    if ($instructor_photo_id) {
                        $instructor_photo = $instructor_photo_id;
                        error_log('ID de la imagen: ' . $instructor_photo);
                    } else {
                        $instructor_photo = $instructor_photo_url;
                        error_log('No se encontró ID de imagen, usando URL: ' . $instructor_photo_url);
                    }
                }
            }

            // Respaldo: Extraer foto del HTML si no se encontró
            if (empty($instructor_photo)) {
                if (preg_match('/class="masterstudy-single-course-instructor__avatar[^"]*".*?src=["\'](.*?)["\']/', $content, $match_photo)) {
                    $instructor_photo = trim($match_photo[1]);
                    error_log('Avatar extracted from HTML: ' . $instructor_photo);
                }
            }

            // Respaldo: Extraer nombre del instructor del HTML
            if (empty($instructor) && !empty($content)) {
                if (preg_match('/class="masterstudy-single-course-instructor__name[^"]*"\s*href="[^"]*"\s*[^>]*>(.*?)</s', $content, $match)) {
                    $instructor = trim(strip_tags($match[1]));
                }
            }

            // Obtener FAQs
            $faqs = get_post_meta($course_id, 'faq', true);
            $faq_items = [];
            if (!empty($faqs)) {
                $faqs = maybe_unserialize($faqs);
                if (is_array($faqs)) {
                    foreach ($faqs as $faq) {
                        if (isset($faq['question']) && isset($faq['answer'])) {
                            $faq_items[] = [
                                'question' => esc_html($faq['question']),
                                'answer' => esc_html($faq['answer']),
                            ];
                        }
                    }
                }
            }

            // Obtener categorías
            $categories = wp_get_post_terms($course_id, 'stm_lms_course_taxonomy', ['fields' => 'names']);

            // Respuesta
            return apply_filters('arcana_course_sync_api_response', [
                'id' => $course->ID,
                'title' => $course->post_title,
                'content' => $content,
                'permalink' => get_permalink($course->ID),
                'price' => $price,
                'instructor' => $instructor,
                'instructor_photo' => $instructor_photo,
                'categories' => $categories,
                'students' => get_post_meta($course_id, 'current_students', true) ?: '0',
                'views' => get_post_meta($course_id, 'views', true) ?: '0',
                'faqs' => $faq_items,
            ], $course_id, $request);
        },
        'permission_callback' => '__return_true',
    ]);
});
