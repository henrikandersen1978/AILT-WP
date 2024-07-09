<?php


namespace Ailt;

class ApiController
{
    public function __construct()
    {
        add_action('template_redirect', [$this, 'webhook']);
        add_action('template_redirect', [$this, 'categories']);
        add_action('template_redirect', [$this, 'authors']);
    }

    public function categories()
    {
        if (strpos($_SERVER['REQUEST_URI'], '/ailt/categories') === false) {
            return;
        }

        $categories = get_categories([
            'hide_empty' => false,
        ]);

        $categories = array_map(function ($category) {
            return [
                'id' => $category->term_id,
                'name' => $category->name,
            ];
        }, $categories);

        status_header(200);
        echo json_encode($categories);
        die;
    }

    public function authors()
    {
        if (strpos($_SERVER['REQUEST_URI'], '/ailt/authors') === false) {
            return;
        }

        $authors = get_users([
            'capability__in' => 'publish_posts'
        ]);

        $authors = array_map(function ($author) {
            return [
                'id' => $author->ID,
                'name' => $author->display_name,
            ];
        }, $authors);

        status_header(200);
        echo json_encode($authors);
        die;
    }

    public function webhook()
    {
        if (strpos($_SERVER['REQUEST_URI'], '/ailt/webhook') === false) {
            return;
        }


        $json = file_get_contents('php://input');
        $data = json_decode($json);

        if (empty($data->nonce_callback_url) || empty($data->article_id) || !$this->validate_nonce_with_server($data->nonce_callback_url, $data->article_id)) {
            wp_send_json_error('Invalid request', 400);
            return;
        }

        global $wpdb;
        $post_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'ailt_id' AND meta_value = %s", $data->article_id));

        if ($post_id) {
            wp_update_post([
                'ID' => $post_id,
                'post_title' => $data->title,
                'post_content' => $data->content,
                'post_date' => $data->publish_at ? date('Y-m-d H:i:s', strtotime($data->publish_at)) : date('Y-m-d H:i:s'),
                'post_author' => $data->author_id,
            ]);
        } else {
            $post_id = wp_insert_post([
                'post_author' => $data->author_id,
                'post_title' => $data->title,
                'post_status' => 'publish',
                'post_content' => $data->content,
                'post_type' => 'post',
                'post_date' => $data->publish_at ? date('Y-m-d H:i:s', strtotime($data->publish_at)) : date('Y-m-d H:i:s'),
            ]);
            add_post_meta($post_id, 'ailt_id', $data->article_id);
        }

        if (!empty($data->featured_image)) {
            $attachment_id = $this->download_image($data->featured_image->url, $post_id);
            set_post_thumbnail($post_id, $attachment_id);
        }

        $content = $this->downloadImages($data->content, $post_id);
        wp_update_post([
            'ID' => $post_id,
            'post_content' => $content
        ]);

        $category_id = $data->category_id;
        $category = get_category($category_id);
        if ($category) {
            wp_set_post_categories($post_id, [$category_id]);
        }

        // if the site has Yoast SEO installed
        if (function_exists('wpseo_replace_vars')) {
            $post = get_post($post_id);
            $seo_title = wpseo_replace_vars($data->title, $post);
            $seo_description = wpseo_replace_vars($data->meta_description, $post);
            update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $seo_description);
        }

        status_header(201);
        echo json_encode([
            'status' => 'success',
            'post_id' => $post_id,
            'url' => get_permalink($post_id),
        ]);
        die;
    }

    private function downloadImages($content, $post_id)
    {
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $content);
        $doc->encoding = "UTF-8";
        $images = $doc->getElementsByTagName("img");

        foreach ($images as $image) {
            $src = $image->getAttribute("src");
            if (strpos($src, "http") === 0) {
                $attachment_id = $this->download_image($src, $post_id);
                $image->setAttribute(
                    "src",
                    wp_get_attachment_url($attachment_id)
                );
            }
        }
        $content = $doc->saveHTML($doc->getElementsByTagName("body")->item(0));
        $content = str_replace("<body>", "", $content);
        $content = str_replace("</body>", "", $content);

        return $content;
    }

    public function download_image($src, $post_id)
    {
        $image_content = file_get_contents($src);
        $image_md5 = md5($image_content);
        $upload_dir = wp_upload_dir();
        $title = get_post_field("post_title", $post_id);

        // Check if the image already exists in the database
        global $wpdb;
        $existing_attachment_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_image_md5' AND meta_value = %s",
                $image_md5
            )
        );

        if ($existing_attachment_id) {
            return $existing_attachment_id;
        }

        $nice_filename = sanitize_title($title);
        $extension = pathinfo($src, PATHINFO_EXTENSION);
        $extension = explode("?", $extension)[0];
        $nice_filename = $nice_filename . "." . $extension;
        $nice_filename = sanitize_file_name($nice_filename);
        $nice_filename = wp_unique_filename($upload_dir["path"], $nice_filename);
        $image_path = $upload_dir["path"] . "/" . $nice_filename;

        file_put_contents($image_path, $image_content);

        $wp_filetype = wp_check_filetype(basename($image_path), null);
        $attachment = [
            "post_mime_type" => $wp_filetype["type"],
            "post_title" => sanitize_file_name(basename($image_path)),
            "post_content" => "",
            "post_status" => "inherit"
        ];
        $attach_id = wp_insert_attachment($attachment, $image_path, $post_id);
        require_once ABSPATH . "wp-admin/includes/image.php";
        $attach_data = wp_generate_attachment_metadata($attach_id, $image_path);
        wp_update_attachment_metadata($attach_id, $attach_data);
        add_post_meta($attach_id, "_image_md5", $image_md5);

        return $attach_id;
    }

    private static function validate_nonce_with_server($nonce_callback_url, $article_id)
    {
        $response = wp_remote_post($nonce_callback_url, [
            'body' => json_encode([
                'article_id' => $article_id,
            ]),
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return isset($data['valid']) && $data['valid'] === true;
    }
}
