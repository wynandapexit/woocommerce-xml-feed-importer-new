<?php
if (!defined('ABSPATH')) {
    exit;
}

final class WPFI_Importer {
    private $logger;

    public function __construct($logger) {
        $this->logger = $logger;
    }

    public function import(array $feed) {
        $format = $feed['format'] ?? 'xml';
        if ($format === 'csv') {
            return $this->import_csv($feed);
        }

        return $this->import_xml($feed);
    }

    private function request($feed) {
        $url = $this->authenticated_url($feed);
        $response = wp_remote_get($url, [
            'timeout' => 120,
            'redirection' => 3,
            'user-agent' => 'WPFI/' . WPFI_VERSION,
            'headers' => $this->auth_headers($feed),
        ]);

        if (is_wp_error($response)) {
            $this->logger->write($feed, 'error', 'Request failed: ' . $response->get_error_message());
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            $this->logger->write($feed, 'error', 'Request failed with HTTP ' . $status);
            return false;
        }

        return wp_remote_retrieve_body($response);
    }

    private function import_csv($feed) {
        $body = $this->request($feed);
        if ($body === false) {
            return false;
        }

        $cfg = wp_parse_args($feed['csv'] ?? [], [
            'delimiter' => ',',
            'enclosure' => '"',
            'has_header' => 1,
            'encoding' => 'UTF-8',
        ]);

        $rows = $this->csv_rows($body, $cfg);
        if (!$rows) {
            $this->logger->write($feed, 'error', 'No CSV rows returned.');
            return false;
        }

        $saved = 0;
        foreach ($rows as $row) {
            $values = [];
            foreach ($row as $key => $value) {
                $values[$key] = trim((string) $value);
            }

            if (!$this->save_product($feed, $values)) {
                $this->logger->write($feed, 'warning', 'Skipped invalid row.');
                continue;
            }

            $saved++;
        }

        $this->logger->write($feed, 'info', 'Imported ' . $saved . ' products.');
        return true;
    }

    private function import_xml($feed) {
        $body = $this->request($feed);
        if ($body === false) {
            return false;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml === false) {
            $this->logger->write($feed, 'error', 'Unable to parse XML feed.');
            return false;
        }

        $root = $feed['product_xpath'] ?? '/products/product';
        $nodes = $xml->xpath($root);
        if (!$nodes) {
            $this->logger->write($feed, 'error', 'No product nodes matched XPath: ' . $root);
            return false;
        }

        $saved = 0;
        foreach ($nodes as $node) {
            $values = $this->values($node, $feed['map'] ?? [], $feed);
            $field = $feed['identifier_field'] ?? 'sku';
            $id = trim((string) ($values[$field] ?? ($values['sku'] ?? '')));

            if ($id === '') {
                $this->logger->write($feed, 'warning', 'Skipped product without identifier: ' . $field);
                continue;
            }

            if ($this->save_product($feed, $values, $node)) {
                $saved++;
            }
        }

        $this->logger->write($feed, 'info', 'Imported ' . $saved . ' products.');
        return true;
    }

    private function save_product($feed, $values, $node = null) {
        $field = $feed['identifier_field'] ?? 'sku';
        $id = trim((string) ($values[$field] ?? ($values['sku'] ?? '')));
        if ($id === '') {
            $this->logger->write($feed, 'warning', 'Missing product identifier for feed: ' . print_r($values, true));
            return false;
        }

        $product_id = $this->find_product_by_identifier($feed, $id);

        $data = [
            'name' => $values['name'] ?? $id,
            'sku' => $id,
            'description' => $values['description'] ?? '',
        ];

        if (isset($values['short_description'])) {
            $data['short_description'] = $values['short_description'];
        }
        if (isset($values['price'])) {
            $data['price'] = $values['price'];
        }
        if (isset($values['sale_price'])) {
            $data['sale_price'] = $values['sale_price'];
        }
        if (isset($values['stock_quantity'])) {
            $data['stock_quantity'] = $values['stock_quantity'];
        }
        if (isset($values['stock_status'])) {
            $data['stock_status'] = $values['stock_status'];
        }
        if (isset($values['category'])) {
            $data['category'] = $values['category'];
        }
        if (isset($values['image'])) {
            $data['image'] = $values['image'];
        }

        if ($product_id) {
            $this->update_product($product_id, $data);
            $this->save_identifier_meta($feed, $id, $product_id);
            return true;
        }

        $product_id = $this->create_product($data);
        if ($product_id) {
            $this->save_identifier_meta($feed, $id, $product_id);
            return true;
        }

        return false;
    }

    private function values($node, $map, $feed = []) {
        $values = [];

        foreach ((array) $map as $key => $selector) {
            $values[$key] = $this->value($node, $selector);
        }

        if (isset($feed['calculations']['sale_price']) && isset($values['sale_price'])) {
            $formula = $feed['calculations']['sale_price'];
            $values['sale_price'] = $this->calculate_value($values, $formula);
        }

        return $values;
    }

    private function value($node, $selector) {
        if (!$selector) {
            return '';
        }

        if (strpos($selector, '/') !== false || strpos($selector, '@') !== false || strpos($selector, '[') !== false) {
            $result = $node->xpath($selector);
            return is_array($result) ? trim((string) ($result[0] ?? '')) : '';
        }

        return trim((string) $node->{$selector});
    }

    private function calculate_value($values, $formula) {
        if (empty($formula) || empty($values)) {
            return '';
        }

        $result = trim((string) $formula);

        foreach ($values as $field => $value) {
            if (is_numeric($value)) {
                $result = str_replace((string) $field, (float) $value, $result);
            }
        }

        $result = preg_replace('/[^0-9\+\-\*\/\.\(\)\s]/', '', $result);
        if ($result === '') {
            return '';
        }

        try {
            $calc = eval('return ' . preg_replace('/\s+/', '', $result) . ';');
            return is_numeric($calc) ? (string) $calc : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    private function apply_values($product, $values, $id) {
        $product->set_name($values['name'] ?? $id);
        $product->set_sku($id);

        if (isset($values['description'])) {
            $product->set_description(wp_kses_post($values['description']));
        }
        if (isset($values['short_description'])) {
            $product->set_short_description(wp_kses_post($values['short_description']));
        }
        if (isset($values['price'])) {
            $product->set_regular_price((string) $values['price']);
        }
        if (isset($values['sale_price'])) {
            $product->set_sale_price((string) $values['sale_price']);
        }
        if (isset($values['stock_quantity'])) {
            $product->set_stock_quantity((string) $values['stock_quantity']);
        }
        if (isset($values['stock_status'])) {
            $product->set_stock_status((string) $values['stock_status']);
        }
        if (isset($values['category'])) {
            $product->set_category_ids($this->ensure_categories($values['category']));
        }
        if (isset($values['image']) && !empty($values['image'])) {
            $this->image($product->get_id(), $values['image']);
        }
    }

    private function find_meta_product($field_name, $value) {
        $posts = get_posts([
            'post_type' => 'product',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'meta_key' => '_' . sanitize_key((string) $field_name),
            'meta_value' => $value,
            'fields' => 'ids',
        ]);

        return !empty($posts) ? (int) $posts[0] : 0;
    }

    private function taxonomy($name) {
        $taxonomy = 'pa_' . sanitize_title($name);
        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, 'product', ['public' => false, 'show_ui' => false, 'rewrite' => false]);
        }
        return $taxonomy;
    }

    private function apply_attributes($id, $name, $attributes) {
        foreach ((array) $attributes as $attribute_name => $selector) {
            $value = $this->value($name, $selector);
            if ($value !== '') {
                wp_set_object_terms($id, array_filter(array_map('trim', explode('|', $value))), $this->taxonomy($attribute_name));
            }
        }
    }

    private function apply_csv_attributes($id, $values, $attributes) {
        foreach ((array) $attributes as $attribute_name => $column) {
            $value = $values[$column] ?? '';
            if ($value !== '') {
                wp_set_object_terms($id, array_filter(array_map('trim', explode('|', $value))), $this->taxonomy($attribute_name));
            }
        }
    }

    private function image($product_id, $image_url) {
        if (!is_string($image_url) || $image_url === '') {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = attachment_url_to_postid($image_url);
        if (!$attachment_id) {
            $tmp = download_url($image_url);
            if (is_wp_error($tmp)) {
                return;
            }

            try {
                $file = wp_upload_bits(basename(parse_url($image_url, PHP_URL_PATH)), null, file_get_contents($tmp));
                if (!empty($file['error'])) {
                    @unlink($tmp);
                    return;
                }

                $attachment_id = media_sideload_image($image_url, $product_id, '', 'id');
            } catch (Throwable $e) {
                @unlink($tmp);
                return;
            }

            @unlink($tmp);
        }

        if ($attachment_id) {
            update_post_meta($product_id, '_thumbnail_id', $attachment_id);
        }
    }

    private function authenticated_url($feed) {
        $auth = $feed['auth'] ?? [];
        $url = trim((string) ($feed['url'] ?? ''));
        $params = is_array($auth['query_params'] ?? null) ? $auth['query_params'] : [];
        $path_params = is_array($auth['path_params'] ?? null) ? $auth['path_params'] : [];
        $template = trim((string) ($auth['path_template'] ?? ''));

        if ($template !== '') {
            $url = $template;
        }

        if (!empty($path_params)) {
            $url = rtrim($url, '/');
            foreach ($path_params as $key => $value) {
                $placeholder = '{' . $key . '}';
                if (strpos($url, $placeholder) !== false) {
                    $url = str_replace($placeholder, rawurlencode((string) $value), $url);
                    continue;
                }

                if (strpos($url, '/{' . $key . '}') !== false) {
                    $url = str_replace('/{' . $key . '}', '/' . rawurlencode((string) $value), $url);
                    continue;
                }

                $url .= '/' . rawurlencode((string) $key) . '/' . rawurlencode((string) $value);
            }
        }

        return !empty($params) ? add_query_arg(array_map('strval', $params), $url) : $url;
    }

    private function auth_headers($feed) {
        $auth = $feed['auth'] ?? [];
        $headers = [];
        $type = $auth['type'] ?? 'none';

        if ($type === 'api_key' && !empty($auth['api_key'])) {
            $headers[$auth['api_key_name'] ?? 'X-API-Key'] = $auth['api_key'];
        }

        if ($type === 'basic' && !empty($auth['username']) && !empty($auth['password'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode($auth['username'] . ':' . $auth['password']);
        }

        if ($type === 'bearer' && !empty($auth['token'])) {
            $headers['Authorization'] = 'Bearer ' . $auth['token'];
        }

        if ($type === 'custom' && !empty($auth['header_name']) && !empty($auth['header_value'])) {
            $headers[$auth['header_name']] = $auth['header_value'];
        }

        return $headers;
    }

    private function csv_rows($body, $cfg) {
        $encoding = strtoupper((string) ($cfg['encoding'] ?? 'UTF-8'));
        if ($encoding !== 'UTF-8' && $body !== '') {
            $body = mb_convert_encoding($body, 'UTF-8', $encoding);
        }

        $lines = preg_split('/\r\n|\r|\n/', $body);
        if (!$lines) {
            return [];
        }

        $rows = [];
        $header = null;
        foreach ($lines as $line) {
            if (trim((string) $line) === '') {
                continue;
            }

            $cols = str_getcsv($line, $cfg['delimiter'] ?? ',', $cfg['enclosure'] ?? '"');
            if ($header === null) {
                $header = $cols;
                continue;
            }

            $rows[] = array_combine($header, $cols);
        }

        return $rows;
    }

    private function create_product($data) {
        $post = [
            'post_title' => $data['name'] ?? 'Imported Product',
            'post_status' => 'publish',
            'post_type' => 'product',
        ];

        $id = wp_insert_post($post);
        if ($id && $id > 0) {
            update_post_meta($id, '_sku', sanitize_text_field((string) ($data['sku'] ?? '')));
            if (!empty($data['price'])) {
                update_post_meta($id, '_regular_price', (string) $data['price']);
            }
            if (!empty($data['sale_price'])) {
                update_post_meta($id, '_sale_price', (string) $data['sale_price']);
            }

            $product = wc_get_product($id);
            if ($product) {
                $this->apply_values($product, $data, $data['sku'] ?? '');
                $product->save();
            }

            return $id;
        }

        return false;
    }

    private function update_product($id, $data) {
        $product = wc_get_product($id);
        if (!$product) {
            return false;
        }

        $this->apply_values($product, $data, $data['sku'] ?? '');
        $product->save();
        return true;
    }

    private function find_product_by_identifier($feed, $id, $variation = false) {
        $identifier_field = $feed['identifier_field'] ?? 'sku';
        $meta_key = '_' . sanitize_key((string) $identifier_field);

        $query = new WP_Query([
            'post_type' => 'product',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => $meta_key,
                    'value' => sanitize_text_field((string) $id),
                    'compare' => '=',
                ],
            ],
            'fields' => 'ids',
        ]);

        if (!empty($query->posts)) {
            return (int) $query->posts[0];
        }

        $fallback = get_posts([
            'post_type' => 'product',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'meta_key' => '_sku',
            'meta_value' => sanitize_text_field((string) $id),
            'fields' => 'ids',
        ]);

        if (!empty($fallback)) {
            return (int) $fallback[0];
        }

        return 0;
    }

    private function save_identifier_meta($feed, $id, $product_id) {
        if (empty($product_id)) {
            return;
        }

        $field = $feed['identifier_field'] ?? 'sku';
        update_post_meta($product_id, '_' . sanitize_key((string) $field), sanitize_text_field((string) $id));
        update_post_meta($product_id, '_sku', sanitize_text_field((string) $id));
    }

    private function ensure_categories($category) {
        if (empty($category)) {
            return [];
        }

        $ids = [];
        $items = array_filter(array_map('trim', explode('|', (string) $category)));

        foreach ($items as $item) {
            $term = term_exists($item, 'product_cat');
            if (!$term) {
                $term = wp_insert_term($item, 'product_cat');
            }

            if (!is_wp_error($term) && !empty($term['term_id'])) {
                $ids[] = (int) $term['term_id'];
            }
        }

        return array_values(array_unique($ids));
    }
}
