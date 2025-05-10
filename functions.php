<?php


// // Add product thumbnail to order details table in My Account
add_filter( 'woocommerce_order_item_name', 'add_thumbnail_to_order_details_table', 20, 3 );
function add_thumbnail_to_order_details_table( $item_name, $item, $is_visible ) {
    // Target only the view order pages in My Account
    if ( is_wc_endpoint_url( 'view-order' ) ) {
        $product = $item->get_product(); // Get the WC_Product object
        if ( $product && $product->get_image_id() > 0 ) {
            $thumbnail = $product->get_image( array( 50, 50 ) ); // Get thumbnail (50x50 pixels)
            $item_name = '<div class="item-thumbnail" style="float:left; margin-right:10px;">' . $thumbnail . '</div>' . $item_name;
        }
    }
    return $item_name;
}



// Incluir el endpoint personalizado
require_once get_stylesheet_directory() . '/api/course-endpoint.php';

// Helper: Convertir una URL de imagen a un ID de imagen
function get_attachment_id_from_url($image_url) {
    global $wpdb;
    $attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid='%s';", $image_url));
    $attachment_id = !empty($attachment) ? $attachment[0] : 0;
    error_log("Convertido URL {$image_url} a ID de imagen: {$attachment_id}");
    return $attachment_id;
}

// Shortcode para renderizar la imagen del instructor
add_shortcode('instructor_photo', function ($atts) {
    $atts = shortcode_atts(array(
        'post_id' => get_the_ID(), // Obtiene el ID del post actual por defecto
    ), $atts, 'instructor_photo');

    $post_id = $atts['post_id'];

    // Obtener el campo ACF de tipo Link
    $instructor_photo_link = get_field('field_6818dc2febac', $post_id); // course_instructor_photo (Link)

    if (!$instructor_photo_link || !isset($instructor_photo_link['url']) || empty($instructor_photo_link['url'])) {
        error_log("No se encontró URL para instructor_photo en post ID {$post_id}");
        return '';
    }

    // Renderizar la imagen
    return '<img src="' . esc_url($instructor_photo_link['url']) . '" alt="Instructor Photo" class="instructor-image" />';
});

/**
 * 1. Configuración inicial y obtención de datos del JSON desde el endpoint personalizado
 */
function get_course_json_data($course_id) {
    error_log("Intentando obtener JSON para stm-courses ID: {$course_id} desde custom/v1/courses");
    $response = wp_remote_get("https://arcana.pruebalucuma.site/wp-json/custom/v1/courses/{$course_id}", [
        'timeout' => 10,
        'sslverify' => false,
    ]);
    
    if (is_wp_error($response)) {
        error_log("Error en wp_remote_get para ID {$course_id}: " . $response->get_error_message());
        return false;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        error_log("Código de respuesta no es 200 para ID {$course_id}: {$response_code}");
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $json = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Error al decodificar JSON para ID {$course_id}: " . json_last_error_msg() . " - Body: {$body}");
        return false;
    }

    if (!$json || !is_array($json)) {
        error_log("JSON inválido o no es un array para ID {$course_id}: " . print_r($json, true));
        return false;
    }

    // Mapear datos del endpoint custom/v1/courses
    $mapped_json = [
        'title' => isset($json['title']) ? sanitize_text_field($json['title']) : '',
        'content' => isset($json['content']) ? strip_shortcodes(wp_kses_post($json['content'])) : '', // Limpia shortcodes
        'permalink' => isset($json['permalink']) ? esc_url($json['permalink']) : '',
        'price' => isset($json['price']) ? floatval($json['price']) : 0,
        'instructor' => isset($json['instructor']) ? sanitize_text_field($json['instructor']) : '',
        'instructor_photo' => isset($json['instructor_photo']) ? esc_url($json['instructor_photo']) : '',
        'categories' => isset($json['categories']) && is_array($json['categories']) ? array_map('sanitize_text_field', $json['categories']) : [],
        'students' => isset($json['students']) ? absint($json['students']) : 0,
        'views' => isset($json['views']) ? absint($json['views']) : 0,
        'faqs' => isset($json['faqs']) && is_array($json['faqs']) ? array_map(function($faq) {
            return [
                'question' => isset($faq['question']) ? sanitize_text_field($faq['question']) : '',
                'answer' => isset($faq['answer']) ? wp_kses_post($faq['answer']) : '',
            ];
        }, $json['faqs']) : [],
        'background_image' => 0, // Ahora será un ID de imagen
    ];

    error_log("Contenido extraído para ID {$course_id}: " . print_r($mapped_json['content'], true));
    error_log("JSON procesado correctamente desde custom/v1/courses para ID {$course_id}: " . print_r($mapped_json, true));
    return $mapped_json;
}

/**
 * 2. Obtener el ID de la imagen de fondo desde el campo ACF del post course o desde /wp/v2/pages
 */
function get_background_image_from_course_page($course_page_id, $course_title, $instructor_photo) {
    error_log("Intentando obtener background_image para course ID: {$course_page_id}");

    // Obtener el valor directamente desde la base de datos para verificar
    $raw_value = get_post_meta($course_page_id, 'course_background_image', true);
    error_log("Valor raw de course_background_image desde get_post_meta para course ID {$course_page_id}: {$raw_value}");

    // Intentar obtener el campo ACF course_background_image (ahora como Image ID)
    $background_image_id = get_field('field_6819abc58a2b', $course_page_id); // Field key para course_background_image
    if ($background_image_id && is_numeric($background_image_id)) {
        error_log("Background_image ID encontrado en el campo ACF para course ID {$course_page_id}: {$background_image_id}");
        return $background_image_id;
    }

    // Si el valor actual es una URL (caso de migración), convertir a ID
    if ($raw_value && filter_var($raw_value, FILTER_VALIDATE_URL)) {
        $background_image_id = get_attachment_id_from_url($raw_value);
        if ($background_image_id) {
            error_log("URL migrada a ID de imagen para course ID {$course_page_id}: {$background_image_id}");
            update_field('field_6819abc58a2b', $background_image_id, $course_page_id);
            return $background_image_id;
        }
    }

    error_log("No se encontró background_image en el campo ACF para course ID {$course_page_id}. Buscando en /wp/v2/pages...");

    // Si no está en el campo ACF, buscar en /wp/v2/pages
    $response = wp_remote_get("https://arcana.pruebalucuma.site/wp-json/wp/v2/pages?per_page=100", [
        'timeout' => 10,
        'sslverify' => false,
    ]);

    if (is_wp_error($response)) {
        error_log("Error en wp_remote_get para /wp/v2/pages: " . $response->get_error_message());
        return 0;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        error_log("Código de respuesta no es 200 para /wp/v2/pages: {$response_code}");
        return 0;
    }

    $body = wp_remote_retrieve_body($response);
    $pages = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Error al decodificar JSON para /wp/v2/pages: " . json_last_error_msg() . " - Body: {$body}");
        return 0;
    }

    if (!$pages || !is_array($pages)) {
        error_log("JSON inválido o no es un array para /wp/v2/pages: " . print_r($pages, true));
        return 0;
    }

    // Buscar una página cuyo título coincida con el curso
    $background_image_id = 0;
    foreach ($pages as $page) {
        $page_title = isset($page['title']['rendered']) ? $page['title']['rendered'] : '';
        if (strtolower($page_title) === strtolower($course_title)) {
            $content = isset($page['content']['rendered']) ? $page['content']['rendered'] : '';
            if ($content) {
                $pattern = '/background-image:\s*url\("([^"]+)"\)/i';
                if (preg_match($pattern, $content, $matches)) {
                    $image_url = esc_url($matches[1]);
                    $background_image_id = get_attachment_id_from_url($image_url);
                    if ($background_image_id) {
                        error_log("Background_image ID encontrado en /wp/v2/pages (ID: {$page['id']}): {$background_image_id} (URL: {$image_url})");
                        update_field('field_6819abc58a2b', $background_image_id, $course_page_id);
                        break;
                    }
                }
            }
        }
    }

    if (!$background_image_id) {
        error_log("No se encontró background_image en /wp/v2/pages para el curso: {$course_title}. Usando instructor_photo como fallback.");
        $background_image_id = get_attachment_id_from_url($instructor_photo);
        if ($background_image_id) {
            update_field('field_6819abc58a2b', $background_image_id, $course_page_id);
        } else {
            error_log("No se pudo obtener un ID de imagen para instructor_photo: {$instructor_photo}. No se establecerá ningún valor por defecto.");
            return 0; // No establecer 0 en el campo, dejarlo vacío
        }
    }

    return $background_image_id;
}

/**
 * 3. Crear o actualizar una página de tipo 'course' y productos asociados
 */
function create_or_update_course_page($stm_course_id, $json) {
    error_log("Creando/actualizando página para stm-courses ID: {$stm_course_id}");
    
    // Verificar si ya existe una página 'course' asociada
    $existing_course_id = get_post_meta($stm_course_id, 'related_course_id', true);
    $course_page_id = $existing_course_id ? $existing_course_id : 0;

    // Extraer el slug del permalink
    $slug = isset($json['permalink']) ? basename($json['permalink']) : '';
    $slug = sanitize_title($slug);

    // Configurar los datos de la nueva página
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
        $error_message = is_wp_error($course_page_id) ? $course_page_id->get_error_message() : 'Error desconocido';
        error_log("Error al crear/actualizar página course para stm-courses ID {$stm_course_id}: {$error_message}");
        return false;
    }

    // Verificar si ACF está disponible
    if (!function_exists('acf')) {
        error_log("ACF no está disponible para course ID {$course_page_id}");
        return false;
    }

    // Inyectar la imagen destacada del stm-courses al course
    $stm_course = get_post($stm_course_id);
    if ($stm_course && $stm_course->post_type === 'stm-courses') {
        $thumbnail_id = get_post_thumbnail_id($stm_course_id);
        if ($thumbnail_id) {
            $result = set_post_thumbnail($course_page_id, $thumbnail_id);
            if ($result) {
                error_log("[create_or_update_course_page] Imagen destacada (thumbnail_id {$thumbnail_id}) asignada correctamente al course_page_id {$course_page_id}");
            } else {
                error_log("[create_or_update_course_page] Error: No se pudo asignar la imagen destacada al course_page_id {$course_page_id}");
            }
        } else {
            error_log("[create_or_update_course_page] No se encontró imagen destacada para stm_course_id {$stm_course_id}");
        }
    }

    // Crear productos de WooCommerce
    // Verificar si WooCommerce está activo
    if (!class_exists('WooCommerce')) {
        error_log("WooCommerce no está activo. No se pueden crear productos para course ID {$course_page_id}");
    } else {
        $course_title = isset($json['title']) ? $json['title'] : "Course {$stm_course_id}";
        
        // Producto 1: Nombre del curso
        $course_product = [
            'post_title' => $course_title,
            'post_type' => 'product',
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ];
        $course_product_id = wp_insert_post($course_product, true);
        if ($course_product_id && !is_wp_error($course_product_id)) {
            // Establecer tipo de producto como simple
            wp_set_object_terms($course_product_id, 'simple', 'product_type');
            update_post_meta($course_product_id, '_visibility', 'visible');
            update_post_meta($course_product_id, '_stock_status', 'instock');
            update_post_meta($course_product_id, '_price', $json['price'] ?? 0);
            update_post_meta($course_product_id, '_regular_price', $json['price'] ?? 0);
            $course_product_link = "/?add-to-cart={$course_product_id}&quantity=1";
            error_log("Producto creado para course ID {$course_page_id}: {$course_title} (ID: {$course_product_id}, Link: {$course_product_link})");
            
            // Almacenar el enlace en el campo ACF
            $result = update_field('field_681e16f6a4555', $course_product_link, $course_page_id); // Reemplaza con tu clave real
            if ($result) {
                error_log("Enlace del producto del curso almacenado en ACF para course ID {$course_page_id}: {$course_product_link}");
            } else {
                error_log("Error al almacenar el enlace del producto del curso en ACF para course ID {$course_page_id}");
            }
        } else {
            $error_message = is_wp_error($course_product_id) ? $course_product_id->get_error_message() : 'Error desconocido';
            error_log("Error al crear producto para course ID {$course_page_id}: {$error_message}");
        }

        // Producto 2: Webinar - Nombre del curso
        $webinar_product = [
            'post_title' => "Webinar - {$course_title}",
            'post_type' => 'product',
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ];
        $webinar_product_id = wp_insert_post($webinar_product, true);
        if ($webinar_product_id && !is_wp_error($webinar_product_id)) {
            // Establecer tipo de producto como simple
            wp_set_object_terms($webinar_product_id, 'simple', 'product_type');
            update_post_meta($webinar_product_id, '_visibility', 'visible');
            update_post_meta($webinar_product_id, '_stock_status', 'instock');
            update_post_meta($webinar_product_id, '_price', $json['price'] ?? 0);
            update_post_meta($webinar_product_id, '_regular_price', $json['price'] ?? 0);
            $webinar_product_link = "/?add-to-cart={$webinar_product_id}&quantity=1";
            error_log("Producto webinar creado para course ID {$course_page_id}: Webinar - {$course_title} (ID: {$webinar_product_id}, Link: {$webinar_product_link})");
            
            // Almacenar el enlace en el campo ACF
            $result = update_field('field_681e16fea4556', $webinar_product_link, $course_page_id); // Reemplaza con tu clave real
            if ($result) {
                error_log("Enlace del producto webinar almacenado en ACF para course ID {$course_page_id}: {$webinar_product_link}");
            } else {
                error_log("Error al almacenar el enlace del producto webinar en ACF para course ID {$course_page_id}");
            }
        } else {
            $error_message = is_wp_error($webinar_product_id) ? $webinar_product_id->get_error_message() : 'Error desconocido';
            error_log("Error al crear producto webinar para course ID {$course_page_id}: {$error_message}");
        }
    }

    // Obtener el ID de la imagen de fondo desde el campo ACF o /wp/v2/pages
    $json['background_image'] = get_background_image_from_course_page($course_page_id, $json['title'], $json['instructor_photo']);

    // Ajustar formatos para otros campos
    $instructor_photo_link = [
        'url' => $json['instructor_photo'],
        'title' => 'Instructor Photo',
        'target' => '',
    ];

    // Convertir valores numéricos a strings donde sea necesario
    $json['views'] = strval($json['views']);
    $json['students'] = strval($json['students']);
    $json['price'] = strval($json['price']);

    // Guardar datos en ACF usando field keys
    $acf_updates = [
        'field_6819ab78a29a' => $json['title'],
        'field_6819abc58a2b' => $json['background_image'],
        'field_123456789' => $json['content'],
        'field_6818da1febaa' => $json['price'],
        'field_6818db1febab' => $json['instructor'],
        'field_6818dc2febac' => $instructor_photo_link,
        'field_6818dcdfefbad' => $json['categories'],
        'field_6818dd7febae' => $json['students'],
        'field_6818de1febaf' => $json['views'],
        'field_6818d5cfea7' => $json['faqs'],
    ];

    foreach ($acf_updates as $field => $value) {
        $result = update_field($field, $value, $course_page_id);
        if ($result === false) {
            error_log("Error al actualizar campo ACF {$field} para course ID {$course_page_id}: Valor intentado: " . print_r($value, true));
        } else {
            error_log("Actualizando campo ACF {$field} para course ID {$course_page_id}: Éxito - Valor: " . print_r($value, true));
            if ($field === 'field_6819abc58a2b' && function_exists('wp_set_post_terms')) {
                wp_update_post([
                    'ID' => $course_page_id,
                    'post_modified' => current_time('mysql'),
                    'post_modified_gmt' => current_time('mysql', 1),
                ]);
                error_log("Forzado actualización de post {$course_page_id} para reflejar cambio en background_image");
            }
        }
    }

    error_log("Página course ID {$course_page_id} creada/actualizada para stm-courses ID {$stm_course_id}");
    return $course_page_id;
}

/**
 * 4. Creación inicial de páginas para todos los stm-courses existentes
 */
function create_initial_course_pages() {
    if (!current_user_can('manage_options')) {
        error_log("Usuario no tiene permisos para ejecutar create_initial_course_pages");
        wp_die('No tienes permisos para realizar esta acción.');
    }

    error_log("Iniciando creación inicial de páginas course");

    $args = [
        'post_type' => 'stm-courses',
        'posts_per_page' => -1,
        'post_status' => 'publish',
    ];
    $stm_courses = get_posts($args);

    if (empty($stm_courses)) {
        error_log("No se encontraron stm-courses para procesar");
        wp_die('No se encontraron cursos para procesar.');
    }

    error_log("Se encontraron " . count($stm_courses) . " stm-courses para procesar");

    foreach ($stm_courses as $stm_course) {
        error_log("Procesando stm-courses ID: {$stm_course->ID}");
        $json = get_course_json_data($stm_course->ID);
        if ($json) {
            create_or_update_course_page($stm_course->ID, $json);
        } else {
            error_log("No se pudo obtener JSON para stm-courses ID: {$stm_course->ID}");
        }
    }

    wp_redirect(admin_url('edit.php?post_type=course&message=pages_created'));
    exit;
}
add_action('wp_ajax_create_initial_course_pages', 'create_initial_course_pages');

/**
 * 5. Actualización manual con dropdown ACF
 */
function update_course_page_on_save($post_id) {
    if (get_post_type($post_id) !== 'stm-courses' || wp_is_post_revision($post_id)) {
        return;
    }

    $update_action = get_field('update_course_page', $post_id);
    if ($update_action !== 'update') {
        return;
    }

    error_log("Actualización manual disparada para stm-courses ID: {$post_id}");
    $json = get_course_json_data($post_id);
    if ($json) {
        create_or_update_course_page($post_id, $json);
    }

    update_field('update_course_page', 'no_update', $post_id);
}
add_action('acf/save_post', 'update_course_page_on_save', 20);

/**
 * 6. Añadir botón en el admin para disparar la creación inicial
 */
function add_create_course_pages_button() {
    $screen = get_current_screen();
    if ($screen->post_type !== 'stm-courses' || $screen->base !== 'edit') {
        return;
    }

    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.wrap h1').after('<a href="#" id="create-course-pages-btn" class="page-title-action">Crear páginas Course</a>');
            $('#create-course-pages-btn').on('click', function(e) {
                e.preventDefault();
                if (confirm('¿Estás seguro de que deseas crear las páginas Course para todos los cursos?')) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'create_initial_course_pages',
                            nonce: '<?php echo wp_create_nonce('create_initial_course_pages_nonce'); ?>'
                        },
                        success: function(response) {
                            alert('Páginas creadas. Redirigiendo...');
                            window.location = '<?php echo admin_url('edit.php?post_type=course&message=pages_created'); ?>';
                        },
                        error: function(xhr, status, error) {
                            alert('Error al crear las páginas: ' . error);
                        }
                    });
                }
            });
        });
    </script>
    <?php
}
add_action('admin_footer', 'add_create_course_pages_button');

/**
 * 7. Verificar nonce para la acción AJAX
 */
function verify_create_course_pages_nonce() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'create_initial_course_pages_nonce')) {
        wp_die('Error de seguridad.');
    }
}
add_action('wp_ajax_create_initial_course_pages', 'verify_create_course_pages_nonce', 1);

// Shortcode para renderizar el contenido del curso en páginas de tipo 'course'
add_shortcode('course_content', function ($atts) {
    // Definir atributos del shortcode, con post_id opcional
    $atts = shortcode_atts(array(
        'post_id' => 0, // Por defecto 0, para usar la lógica de búsqueda
    ), $atts, 'course_content');

    $course_page_id = get_the_ID(); // ID de la página 'course' actual
    $stm_course_id = absint($atts['post_id']); // ID proporcionado manualmente, si existe

    // Si no se proporcionó post_id, buscar el stm-courses relacionado
    if (!$stm_course_id) {
        // Buscar un post stm-courses cuyo related_course_id coincida con el ID de la página actual
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
        ];
        $stm_courses = get_posts($args);

        if (empty($stm_courses)) {
            error_log("No se encontró un stm-courses relacionado con la página course ID {$course_page_id}");
            return '';
        }

        $stm_course_id = $stm_courses[0]->ID;
        error_log("Encontrado stm-courses ID {$stm_course_id} para la página course ID {$course_page_id}");
    }

    // Obtener los datos JSON del curso
    $course_data = get_course_json_data($stm_course_id);

    // Verificar si se obtuvo el contenido y es válido
    if (!$course_data || !isset($course_data['content']) || empty($course_data['content'])) {
        error_log("No se encontró contenido para el curso con ID {$stm_course_id}");
        return '';
    }

    // Renderizar el contenido, permitiendo HTML seguro
    return wp_kses_post($course_data['content']);
});

// Shortcode para mostrar las redes sociales del instructor en páginas de tipo 'course'
add_shortcode('instructor_socials', function ($atts) {
    // Definir atributos del shortcode
    $atts = shortcode_atts(array(
        'post_id' => 0, // Por defecto 0, para usar la lógica de búsqueda
    ), $atts, 'instructor_socials');

    $course_page_id = get_the_ID(); // ID de la página 'course' actual
    error_log("[instructor_socials] Iniciando para course_page_id: {$course_page_id}, post_id proporcionado: {$atts['post_id']}");

    // Verificar que estamos en un post de tipo 'course'
    if (get_post_type($course_page_id) !== 'course') {
        error_log("[instructor_socials] Error: No es un post de tipo 'course'. Tipo actual: " . get_post_type($course_page_id));
        return '';
    }

    $stm_course_id = absint($atts['post_id']); // ID proporcionado manualmente, si existe

    // Si no se proporcionó post_id, buscar el stm-courses relacionado
    if (!$stm_course_id) {
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
        ];
        $stm_courses = get_posts($args);

        if (empty($stm_courses)) {
            error_log("[instructor_socials] Error: No se encontró un stm-courses relacionado con course_page_id: {$course_page_id}");
            return '';
        }

        $stm_course_id = $stm_courses[0]->ID;
        error_log("[instructor_socials] Encontrado stm-courses ID: {$stm_course_id} para course_page_id: {$course_page_id}");
    } else {
        error_log("[instructor_socials] Usando stm-courses ID proporcionado: {$stm_course_id}");
    }

    // Verificar si el stm-courses existe
    $stm_course = get_post($stm_course_id);
    if (!$stm_course || $stm_course->post_type !== 'stm-courses') {
        error_log("[instructor_socials] Error: El stm-courses ID {$stm_course_id} no existe o no es de tipo 'stm-courses'");
        return '';
    }

    // Obtener el ID del instructor desde metadato o post_author
    $instructor_id = get_post_meta($stm_course_id, 'instructor_id', true);
    if (!$instructor_id || !is_numeric($instructor_id)) {
        error_log("[instructor_socials] No se encontró instructor_id en metadatos para stm_course_id {$stm_course_id}. Usando post_author como respaldo.");
        $instructor_id = $stm_course->post_author;
    }

    // Verificar si el instructor_id es válido
    $user = get_user_by('ID', $instructor_id);
    if (!$user) {
        error_log("[instructor_socials] Error: No se encontró un usuario de WordPress con ID {$instructor_id} para stm_course_id {$stm_course_id}");
        return '';
    }

    error_log("[instructor_socials] Encontrado instructor_id: {$instructor_id}, display_name: {$user->display_name}, user_login: {$user->user_login}, user_nicename: {$user->user_nicename}");

    // Definir las redes sociales a buscar
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

    // Obtener los enlaces de redes sociales del usuario
    $output = '<ul class="instructor-socials">';
    $has_socials = false;

    foreach ($social_networks as $key => $social) {
        $url = get_user_meta($instructor_id, $key, true);
        error_log("[instructor_socials] Buscando {$key} para instructor_id {$instructor_id}: " . ($url ? $url : 'No encontrado'));
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

    // Si no hay redes sociales, devolver cadena vacía
    if (!$has_socials) {
        error_log("[instructor_socials] Error: No se encontraron redes sociales para instructor_id {$instructor_id}");
        return '';
    }

    // Añadir estilos básicos
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

    error_log("[instructor_socials] Salida generada para stm_course_id {$stm_course_id}");
    return $output;
});

// Shortcode para mostrar la galería de imágenes del curso en páginas de tipo 'course'
add_shortcode('course_gallery', function ($atts) {
    // Definir atributos del shortcode
    $atts = shortcode_atts(array(
        'post_id' => 0, // Por defecto 0, para usar la lógica de búsqueda
    ), $atts, 'course_gallery');

    $course_page_id = get_the_ID(); // ID de la página 'course' actual
    error_log("[course_gallery] Iniciando para course_page_id: {$course_page_id}, post_id proporcionado: {$atts['post_id']}");

    // Verificar que estamos en un post de tipo 'course'
    if (get_post_type($course_page_id) !== 'course') {
        error_log("[course_gallery] Error: No es un post de tipo 'course'. Tipo actual: " . get_post_type($course_page_id));
        return '';
    }

    $stm_course_id = absint($atts['post_id']); // ID proporcionado manualmente, si existe

    // Si no se proporcionó post_id, buscar el stm-courses relacionado
    if (!$stm_course_id) {
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
        ];
        $stm_courses = get_posts($args);

        if (empty($stm_courses)) {
            error_log("[course_gallery] Error: No se encontró un stm-courses relacionado con course_page_id: {$course_page_id}");
            return '';
        }

        $stm_course_id = $stm_courses[0]->ID;
        error_log("[course_gallery] Encontrado stm-courses ID: {$stm_course_id} para course_page_id: {$course_page_id}");
    } else {
        error_log("[course_gallery] Usando stm-courses ID proporcionado: {$stm_course_id}");
    }

    // Verificar si el stm-courses existe
    $stm_course = get_post($stm_course_id);
    if (!$stm_course || $stm_course->post_type !== 'stm-courses') {
        error_log("[course_gallery] Error: El stm-courses ID {$stm_course_id} no existe o no es de tipo 'stm-courses'");
        return '';
    }

    // Verificar si ACF está activo
    if (!function_exists('get_field')) {
        error_log("[course_gallery] Error: ACF no está activo o no está instalado");
        return '';
    }

    // Verificar si el campo gallery_portfolio está definido
    $field_object = get_field_object('gallery_portfolio', $stm_course_id);
    if (!$field_object) {
        error_log("[course_gallery] Error: El campo gallery_portfolio no está definido para stm-courses o no está configurado correctamente");
        return '';
    }
    error_log("[course_gallery] Configuración del campo gallery_portfolio: " . print_r($field_object, true));

    // Obtener las imágenes de la galería desde ACF
    $gallery_images = get_field('gallery_portfolio', $stm_course_id);
    error_log("[course_gallery] Valor del campo gallery_portfolio para stm_course_id {$stm_course_id}: " . print_r($gallery_images, true));

    if (empty($gallery_images) || !is_array($gallery_images)) {
        error_log("[course_gallery] Error: No se encontraron imágenes válidas en el campo gallery_portfolio para stm_course_id {$stm_course_id}");
        return '';
    }

    // Validar que las imágenes tengan los datos necesarios
    $valid_images = 0;
    foreach ($gallery_images as $image) {
        if (isset($image['url']) && !empty($image['url'])) {
            $valid_images++;
        }
    }
    error_log("[course_gallery] Número de imágenes válidas encontradas: {$valid_images}");

    if ($valid_images === 0) {
        error_log("[course_gallery] Error: Ninguna imagen tiene una URL válida en el campo gallery_portfolio para stm_course_id {$stm_course_id}");
        return '';
    }

    // Generar el HTML de la galería
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

    // Añadir estilos básicos y un script simple para lightbox
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

    // Encolar un script simple para lightbox
    wp_enqueue_script('jquery');
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

    // Añadir estilos para el lightbox
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

    error_log("[course_gallery] Galería generada para stm_course_id {$stm_course_id} con {$valid_images} imágenes");
    return $output;
});

// Shortcode para inyectar la imagen destacada de stm-courses a su course relacionado
add_shortcode('inject_featured_image', function ($atts) {
    // Definir atributos del shortcode
    $atts = shortcode_atts(array(
        'post_id' => 0, // Por defecto 0, para usar la lógica de búsqueda
    ), $atts, 'inject_featured_image');

    $course_page_id = get_the_ID(); // ID de la página 'course' actual
    $output = '<script>';
    $output .= 'console.log("[inject_featured_image] Iniciando para course_page_id: ' . $course_page_id . ', post_id proporcionado: ' . $atts['post_id'] . '");';

    // Verificar que estamos en un post de tipo 'course'
    if (get_post_type($course_page_id) !== 'course') {
        $output .= 'console.log("[inject_featured_image] Error: No es un post de tipo \'course\'. Tipo actual: ' . get_post_type($course_page_id) . '");';
        $output .= '</script>';
        return $output;
    }

    $stm_course_id = absint($atts['post_id']); // ID proporcionado manualmente, si existe

    // Si no se proporcionó post_id, buscar el stm-courses relacionado
    if (!$stm_course_id) {
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
        ];
        $stm_courses = get_posts($args);

        if (empty($stm_courses)) {
            $output .= 'console.log("[inject_featured_image] Error: No se encontró un stm-courses relacionado con course_page_id: ' . $course_page_id . '");';
            $output .= '</script>';
            return $output;
        }

        $stm_course_id = $stm_courses[0]->ID;
        $output .= 'console.log("[inject_featured_image] Encontrado stm-courses ID: ' . $stm_course_id . ' para course_page_id: ' . $course_page_id . '");';
    } else {
        $output .= 'console.log("[inject_featured_image] Usando stm-courses ID proporcionado: ' . $stm_course_id . '");';
    }

    // Verificar si el stm-courses existe
    $stm_course = get_post($stm_course_id);
    if (!$stm_course || $stm_course->post_type !== 'stm-courses') {
        $output .= 'console.log("[inject_featured_image] Error: El stm-courses ID ' . $stm_course_id . ' no existe o no es de tipo \'stm-courses\'");';
        $output .= '</script>';
        return $output;
    }

    // Obtener la imagen destacada del stm-courses
    $thumbnail_id = get_post_thumbnail_id($stm_course_id);
    if (!$thumbnail_id) {
        $output .= 'console.log("[inject_featured_image] Error: No se encontró imagen destacada para stm_course_id ' . $stm_course_id . '");';
        $output .= '</script>';
        return $output;
    }
    $output .= 'console.log("[inject_featured_image] Imagen destacada encontrada para stm_course_id ' . $stm_course_id . ': thumbnail_id ' . $thumbnail_id . '");';

    // Asignar la imagen destacada al course
    $result = set_post_thumbnail($course_page_id, $thumbnail_id);
    if ($result) {
        $output .= 'console.log("[inject_featured_image] Imagen destacada (thumbnail_id ' . $thumbnail_id . ') asignada correctamente al course_page_id ' . $course_page_id . '");';
    } else {
        $output .= 'console.log("[inject_featured_image] Error: No se pudo asignar la imagen destacada al course_page_id ' . $course_page_id . '");';
    }

    $output .= 'console.log("[inject_featured_image] Proceso completado");';
    $output .= '</script>';

    return $output;
});

// Añadir estilo background-image a la clase .selling-page-bkg en el frontend
add_action('wp_footer', function() {
    // Solo ejecutar en páginas de tipo course
    if (!is_singular('course')) {
        return;
    }

    $course_page_id = get_the_ID();
    error_log("[wp_footer] Verificando course_page_id: {$course_page_id}");

    // Obtener la imagen destacada del course
    $thumbnail_id = get_post_thumbnail_id($course_page_id);
    if (!$thumbnail_id) {
        error_log("[wp_footer] Error: No se encontró imagen destacada para course_page_id {$course_page_id}");
        return;
    }

    $thumbnail_url = wp_get_attachment_image_url($thumbnail_id, 'full');
    if (!$thumbnail_url) {
        error_log("[wp_footer] Error: No se pudo obtener la URL de la imagen destacada para thumbnail_id {$thumbnail_id}");
        return;
    }
    error_log("[wp_footer] URL de la imagen destacada encontrada: {$thumbnail_url}");

    // Inyectar el estilo en el footer
    echo '<style>
        .selling-page-bkg {
            background-image: url("' . esc_url($thumbnail_url) . '");
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }
    </style>';
    error_log("[wp_footer] Estilo background-image inyectado en .selling-page-bkg con URL: {$thumbnail_url}");
});
?>
