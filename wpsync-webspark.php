<?php
/*
Plugin Name: wpsync-webspark
Description: Synchronize product database with stock levels
Version: 1.0
*/

// Register a hook to run synchronization every hour
add_action('wpsync_webspark_sync_products_once', 'wpsync_webspark_sync_products');
add_action('wpsync_webspark_sync_products_hourly', 'wpsync_webspark_sync_products');
register_activation_hook(__FILE__, 'wpsync_webspark_activate');
register_deactivation_hook(__FILE__, 'wpsync_webspark_deactivate');

// Plugin activation function
function wpsync_webspark_activate()
{
    wp_schedule_single_event(time() + 20, 'wpsync_webspark_sync_products_once'); // Process the request immediately after activation
    wp_schedule_event(time(), 'hourly', 'wpsync_webspark_sync_products_hourly'); // Run synchronization every hour
}

// Plugin deactivation function
function wpsync_webspark_deactivate()
{
    wp_clear_scheduled_hook('wpsync_webspark_sync_products'); // Clear cron request on plugin deactivation
}

// Function to synchronize the product database
function wpsync_webspark_sync_products()
{
    $api_url = 'https://wp.webspark.dev/wp-api/products';
    $response = wp_remote_get($api_url);
    $body = wp_remote_retrieve_body($response);
    $response_code = wp_remote_retrieve_response_code($response);

    while (empty($body) || $response_code !== 200) {
        $response = wp_remote_get($api_url);
        $body = wp_remote_retrieve_body($response);
        $response_code = wp_remote_retrieve_response_code($response);
    }

    // Process each product
    $body = json_decode($body, true);
    $data = $body['data'];
    $existing_products = get_existing_products();
    foreach ($data as $item) {

        $sku = sanitize_text_field($item['sku']);
        $name = sanitize_text_field($item['name']);
        $description = sanitize_text_field($item['description']);
        $price = $item['price'];
        $picture = sanitize_text_field($item['picture']);
        $in_stock = $item['in_stock'];

        $existing_product = get_product_by_sku($sku);
        if ($existing_product) {
            // Update existing product
            update_product($existing_product->get_id(), $name, $description, $price, $picture, $in_stock);
            // Remove the product from the existing products list
            unset($existing_products[$existing_product->get_id()]);
        } else {
            // Create a new product
            create_product($sku, $name, $description, $price, $picture, $in_stock);
        }
        // Delete products that are not in the API response
        foreach ($existing_products as $product_id => $product) {
            wp_delete_post($product_id, true);
        }
    }
}

// Function to get the list of existing products
function get_existing_products()
{
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
    );

    $products = get_posts($args);

    $existing_products = array();
    foreach ($products as $product) {
        $existing_products[$product->ID] = $product;
    }

    return $existing_products;
}

// Function to get a product by SKU
function get_product_by_sku($sku)
{
    $args = array(
        'post_type' => 'product',
        'meta_query' => array(
            array(
                'key' => '_sku',
                'value' => $sku,
            ),
        ),
    );

    $products = get_posts($args);

    if (!empty($products)) {
        return wc_get_product($products[0]->ID);
    }

    return false;
}

// Function to update a product
function update_product($product_id, $name, $description, $price, $picture, $in_stock)
{
    $product_data = array(
        'ID' => $product_id,
        'post_title' => $name,
        'post_content' => $description,
    );

    wp_update_post($product_data);

    update_post_meta($product_id, '_price', $price);
    update_post_meta($product_id, '_regular_price', $price);
    update_post_meta($product_id, '_stock_status', $in_stock ? 'instock' : 'outofstock');
    update_post_meta($product_id, '_stock', $in_stock ? $in_stock : '');

    // Update product image
    if ($picture) {
        $attachment_id = wpsync_webspark_upload_image($picture);

        if ($attachment_id) {
            set_post_thumbnail($product_id, $attachment_id);
        }
    }
}

// Function to create a new product
function create_product($sku, $name, $description, $price, $picture, $in_stock)
{
    $product_data = array(
        'post_title' => $name,
        'post_content' => $description,
        'post_status' => 'publish',
        'post_type' => 'product',
    );

    $product_id = wp_insert_post($product_data);

    update_post_meta($product_id, '_sku', $sku);
    update_post_meta($product_id, '_price', $price);
    update_post_meta($product_id, '_regular_price', $price);
    update_post_meta($product_id, '_stock_status', $in_stock ? 'instock' : 'outofstock');
    update_post_meta($product_id, '_stock', $in_stock ? $in_stock : '');

    // Upload and set product image
    if ($picture) {
        $attachment_id = wpsync_webspark_upload_image($picture);

        if ($attachment_id) {
            set_post_thumbnail($product_id, $attachment_id);
        }
    }
}

// Function to upload an image and return the attachment ID
function wpsync_webspark_upload_image($image_url)
{
    $upload_dir = wp_upload_dir();
    $image_data = wp_remote_get($image_url);

    if (!is_wp_error($image_data) && wp_remote_retrieve_response_code($image_data) === 200) {
        $image_name = basename($image_url);
        $image_path = $upload_dir['path'] . '/' . $image_name;

        file_put_contents($image_path, wp_remote_retrieve_body($image_data));

        $attachment = array(
            'guid' => $upload_dir['url'] . '/' . $image_name,
            'post_mime_type' => wp_remote_retrieve_header($image_data, 'content-type'),
            'post_title' => sanitize_file_name($image_name),
            'post_content' => '',
            'post_status' => 'inherit',
        );

        $attachment_id = wp_insert_attachment($attachment, $image_path);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $image_path);
        wp_update_attachment_metadata($attachment_id, $attachment_data);

        return $attachment_id;
    }

    return false;
}
