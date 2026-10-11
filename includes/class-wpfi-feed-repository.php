<?php
if (!defined('ABSPATH')) {
    exit;
}

final class WPFI_Feed_Repository {
    const OPTION = 'wpfi_feeds_v3';

    public function all() {
        $value = get_option(self::OPTION, []);
        return is_array($value) ? array_values($value) : [];
    }

    public function get($id) {
        foreach ($this->all() as $feed) {
            if (($feed['id'] ?? '') === $id) {
                return $feed;
            }
        }
        return null;
    }

    public function save(array $feed) {
        $feeds = $this->all();
        $feed['id'] = $feed['id'] ?: wp_generate_uuid4();
        $found = false;

        foreach ($feeds as $index => $existing) {
            if (($existing['id'] ?? '') === $feed['id']) {
                $feeds[$index] = $feed;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $feeds[] = $feed;
        }

        update_option(self::OPTION, array_values($feeds), false);
        return $feed;
    }

    public function delete($id) {
        $feeds = array_values(array_filter($this->all(), static function ($feed) use ($id) {
            return ($feed['id'] ?? '') !== $id;
        }));

        update_option(self::OPTION, $feeds, false);
        return true;
    }

    public function defaults() {
        return [
            'id' => '',
            'name' => '',
            'slug' => '',
            'url' => '',
            'format' => 'xml',
            'enabled' => 1,
            'frequency' => 'daily',
            'product_xpath' => '/products/product',
            'variation_xpath' => '',
            'identifier_field' => 'sku',
            'variation_identifier_field' => 'sku',
            'skip_zero_stock' => 1,
            'zero_stock_field' => 'stock_quantity',
            'map' => [
                'name' => 'name',
                'sku' => 'sku',
                'description' => 'description',
                'short_description' => 'short_description',
                'price' => 'price',
                'sale_price' => 'sale_price',
                'stock_quantity' => 'stock',
                'stock_status' => 'stock_status',
                'image' => 'image_url',
                'category' => 'category',
            ],
            'attributes' => [],
            'auth' => [
                'type' => 'none',
                'api_key' => '',
                'api_key_name' => 'X-API-Key',
                'username' => '',
                'password' => '',
                'token' => '',
                'header_name' => '',
                'header_value' => '',
                'query_params' => [],
                'path_params' => [],
                'path_template' => '',
            ],
            'csv' => [
                'delimiter' => ',',
                'enclosure' => '"',
                'has_header' => 1,
                'encoding' => 'UTF-8',
            ],
        ];
    }

    public function pinnacle_preset() {
        $defaults = $this->defaults();

        return array_merge($defaults, [
            'name' => 'Pinnacle Products',
            'slug' => 'pinnacle',
            'url' => 'https://www.pinnacle.co.za/pinnacle/productfeed/xml/',
            'format' => 'xml',
            'enabled' => 1,
            'frequency' => 'daily',
            'product_xpath' => '/products/product',
            'identifier_field' => 'sku',
            'skip_zero_stock' => 1,
            'map' => [
                'name' => 'ProdName',
                'sku' => 'StockCode',
                'description' => 'TopCat',
                'price' => 'ProdPriceExclVAT',
                'sale_price' => 'ProdPriceExclVAT',
                'stock_quantity' => 'ProdQty',
                'stock_status' => 'stock_status',
                'image' => 'ProdImg',
                'category' => 'category_tree',
            ],
            'auth' => [
                'type' => 'custom',
                'api_key' => '',
                'api_key_name' => 'X-API-Key',
                'username' => '',
                'password' => '',
                'token' => '',
                'header_name' => '',
                'header_value' => '',
                'query_params' => [],
                'path_params' => [
                    'id' => '11305',
                    'uid' => 'bf672543-bc4c-40a9-a8c6-0ac6259bb4de',
                ],
                'path_template' => '',
            ],
        ]);
    }
}
