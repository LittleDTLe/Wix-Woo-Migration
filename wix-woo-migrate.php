<?php
/**
 * Plugin Name: Wix to WooCommerce Product Migration
 * Description: Migrate products from Wix CSV to WooCommerce with batch processing and pause/resume functionality
 * Version: 1.0.0
 * Author: Panagiotis Drougas
 * Author URI: https://github.com/LittleDTLe
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wix-woo-migration
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Wix_WooCommerce_Migration
{

    private $option_name = 'wix_woo_migration_state';
    private $batch_size = 5;

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_wix_woo_upload_csv', array($this, 'handle_csv_upload'));
        add_action('wp_ajax_wix_woo_start_migration', array($this, 'start_migration'));
        add_action('wp_ajax_wix_woo_process_batch', array($this, 'process_batch'));
        add_action('wp_ajax_wix_woo_stop_migration', array($this, 'stop_migration'));
        add_action('wp_ajax_wix_woo_reset_migration', array($this, 'reset_migration'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_action_links'));
    }

    public function add_admin_menu()
    {
        add_management_page(
            __('Wix to WooCommerce Migration', 'wix-woo-migration'),
            __('Wix Migration', 'wix-woo-migration'),
            'manage_options',
            'wix-woo-migration',
            array($this, 'render_admin_page')
        );
    }

    public function add_action_links($links)
    {
        $migration_link = '<a href="' . admin_url('tools.php?page=wix-woo-migration') . '">' . __('Migrate Products', 'wix-woo-migration') . '</a>';
        array_unshift($links, $migration_link);
        return $links;
    }

    public function enqueue_scripts($hook)
    {
        if ($hook !== 'tools_page_wix-woo-migration') {
            return;
        }

        wp_enqueue_style('wix-woo-migration-css', plugin_dir_url(__FILE__) . 'assets/style.css', array(), '1.0.0');
        wp_enqueue_script('wix-woo-migration-js', plugin_dir_url(__FILE__) . 'assets/script.js', array('jquery'), '1.0.0', true);

        wp_localize_script('wix-woo-migration-js', 'wixWooMigration', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wix_woo_migration_nonce')
        ));
    }

    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $migration_state = get_option($this->option_name, array());
        $is_active = isset($migration_state['status']) && $migration_state['status'] === 'active';
        $is_paused = isset($migration_state['status']) && $migration_state['status'] === 'paused';

        ?>
        <div class="wrap wix-woo-migration-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="wix-woo-migration-container">

                <?php if (!$is_active && !$is_paused): ?>
                    <div class="wix-woo-section">
                        <h2><?php _e('Upload Wix Product CSV', 'wix-woo-migration'); ?></h2>
                        <p><?php _e('Upload your Wix product export CSV file. The plugin will automatically import simple and variable products with images and attributes.', 'wix-woo-migration'); ?>
                        </p>

                        <div class="csv-format-info">
                            <h3><?php _e('Wix CSV Format:', 'wix-woo-migration'); ?></h3>
                            <p><?php _e('This plugin works with the standard Wix product export CSV format, which includes:', 'wix-woo-migration'); ?>
                            </p>
                            <ul>
                                <li><strong>Product rows:</strong> Main product information (fieldType = "Product")</li>
                                <li><strong>Variant rows:</strong> Product variations (fieldType = "Variant")</li>
                                <li><strong>Images:</strong> Semicolon-separated image filenames in productImageUrl column</li>
                                <li><strong>Attributes:</strong> Up to 6 product options (productOptionName1-6)</li>
                            </ul>
                        </div>

                        <form id="wix-csv-upload-form" enctype="multipart/form-data">
                            <input type="file" id="wix-csv-file" name="wix_csv_file" accept=".csv" required>
                            <button type="submit"
                                class="button button-primary"><?php _e('Upload CSV', 'wix-woo-migration'); ?></button>
                        </form>

                        <div id="upload-message" class="notice" style="display:none;"></div>
                    </div>
                <?php endif; ?>

                <div class="wix-woo-section">
                    <h2><?php _e('Migration Progress', 'wix-woo-migration'); ?></h2>

                    <div id="migration-info">
                        <?php if (isset($migration_state['total_products'])): ?>
                            <p>
                                <?php _e('Total Products:', 'wix-woo-migration'); ?>
                                <strong><?php echo esc_html($migration_state['total_products']); ?></strong>
                            </p>
                            <p>
                                <?php _e('Processed:', 'wix-woo-migration'); ?>
                                <strong id="processed-count"><?php echo esc_html($migration_state['processed']); ?></strong>
                            </p>
                            <p>
                                <?php _e('Failed:', 'wix-woo-migration'); ?>
                                <strong id="failed-count"><?php echo esc_html($migration_state['failed']); ?></strong>
                            </p>
                            <p>
                                <?php _e('Images Imported:', 'wix-woo-migration'); ?>
                                <strong id="images-count"><?php echo esc_html($migration_state['images_imported'] ?? 0); ?></strong>
                            </p>
                        <?php else: ?>
                            <p><?php _e('No migration in progress. Please upload a CSV file to begin.', 'wix-woo-migration'); ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="progress-bar-container">
                        <div id="progress-bar" class="progress-bar">
                            <div id="progress-fill" class="progress-fill"
                                style="width: <?php echo isset($migration_state['total_products']) && $migration_state['total_products'] > 0 ? (($migration_state['processed'] + $migration_state['failed']) / $migration_state['total_products'] * 100) : 0; ?>%">
                            </div>
                        </div>
                        <div id="progress-text" class="progress-text">
                            <?php
                            if (isset($migration_state['total_products']) && $migration_state['total_products'] > 0) {
                                echo esc_html(round(($migration_state['processed'] + $migration_state['failed']) / $migration_state['total_products'] * 100, 1)) . '%';
                            } else {
                                echo '0%';
                            }
                            ?>
                        </div>
                    </div>

                    <div class="migration-controls">
                        <?php if (isset($migration_state['csv_path']) && !$is_active): ?>
                            <button id="start-migration-btn" class="button button-primary">
                                <?php echo $is_paused ? __('Resume Migration', 'wix-woo-migration') : __('Start Migration', 'wix-woo-migration'); ?>
                            </button>
                        <?php endif; ?>

                        <?php if ($is_active): ?>
                            <button id="stop-migration-btn" class="button button-secondary">
                                <?php _e('Pause Migration', 'wix-woo-migration'); ?>
                            </button>
                        <?php endif; ?>

                        <?php if (isset($migration_state['csv_path'])): ?>
                            <button id="reset-migration-btn" class="button button-link-delete">
                                <?php _e('Reset Migration', 'wix-woo-migration'); ?>
                            </button>
                        <?php endif; ?>
                    </div>

                    <div id="migration-message" class="notice" style="display:none;"></div>
                </div>

                <?php if (isset($migration_state['errors']) && !empty($migration_state['errors'])): ?>
                    <div class="wix-woo-section">
                        <h2><?php _e('Migration Errors', 'wix-woo-migration'); ?></h2>
                        <div class="error-log">
                            <?php foreach (array_slice($migration_state['errors'], -20) as $error): ?>
                                <p><?php echo esc_html($error); ?></p>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>
        <?php
    }

    public function handle_csv_upload()
    {
        check_ajax_referer('wix_woo_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access', 'wix-woo-migration')));
        }

        if (!isset($_FILES['wix_csv_file'])) {
            wp_send_json_error(array('message' => __('No file uploaded', 'wix-woo-migration')));
        }

        $file = $_FILES['wix_csv_file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(array('message' => __('File upload error: ' . $file['error'], 'wix-woo-migration')));
        }

        $file_type = wp_check_filetype($file['name']);
        if ($file_type['ext'] !== 'csv') {
            wp_send_json_error(array('message' => __('Only CSV files are allowed', 'wix-woo-migration')));
        }

        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/wix-migration/';

        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
        }

        $target_file = $target_dir . 'wix-products-' . time() . '.csv';

        if (!move_uploaded_file($file['tmp_name'], $target_file)) {
            wp_send_json_error(array('message' => __('Failed to save uploaded file', 'wix-woo-migration')));
        }

        // Parse CSV to get product groups
        try {
            $product_groups = $this->parse_wix_csv($target_file);

            if (empty($product_groups)) {
                wp_send_json_error(array('message' => __('No products found in CSV. Please check the file format.', 'wix-woo-migration')));
            }

            update_option($this->option_name, array(
                'csv_path' => $target_file,
                'total_products' => count($product_groups),
                'processed' => 0,
                'failed' => 0,
                'current_index' => 0,
                'product_groups' => $product_groups,
                'status' => 'ready',
                'errors' => array(),
                'images_imported' => 0
            ));

            wp_send_json_success(array(
                'message' => sprintf(__('CSV uploaded successfully. Found %d products.', 'wix-woo-migration'), count($product_groups)),
                'total_products' => count($product_groups)
            ));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => __('Error parsing CSV: ', 'wix-woo-migration') . $e->getMessage()));
        }
    }

    private function parse_wix_csv($file_path)
    {
        if (!file_exists($file_path)) {
            throw new Exception('CSV file not found');
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            throw new Exception('Cannot open CSV file');
        }

        // Try to detect the delimiter (tab or comma)
        $first_line = fgets($handle);
        rewind($handle);

        $delimiter = "\t"; // Default to tab
        if (strpos($first_line, "\t") === false && strpos($first_line, ",") !== false) {
            $delimiter = ",";
        }

        // Read headers
        $headers = fgetcsv($handle, 0, $delimiter);

        if (!$headers || empty($headers)) {
            fclose($handle);
            throw new Exception('Invalid CSV headers');
        }

        // Clean up headers (remove BOM and trim)
        $headers = array_map(function ($header) {
            return trim($header, " \t\n\r\0\x0B\xEF\xBB\xBF");
        }, $headers);

        $product_groups = array();
        $current_handle = null;
        $line_number = 1;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line_number++;

            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            // Ensure row has same number of columns as headers
            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), '');
            } elseif (count($row) > count($headers)) {
                $row = array_slice($row, 0, count($headers));
            }

            $data = array_combine($headers, $row);

            if (!$data) {
                continue;
            }

            // Check if this is a Product or Variant
            if (isset($data['fieldType'])) {
                if ($data['fieldType'] === 'Product') {
                    $current_handle = $data['handleId'];
                    $product_groups[$current_handle] = array(
                        'product' => $data,
                        'variants' => array()
                    );
                } elseif ($data['fieldType'] === 'Variant' && $current_handle && isset($product_groups[$current_handle])) {
                    $product_groups[$current_handle]['variants'][] = $data;
                }
            }
        }

        fclose($handle);

        // Return only the values (remove keys)
        return array_values($product_groups);
    }

    public function start_migration()
    {
        check_ajax_referer('wix_woo_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access', 'wix-woo-migration')));
        }

        $migration_state = get_option($this->option_name, array());

        if (!isset($migration_state['csv_path'])) {
            wp_send_json_error(array('message' => __('No CSV file found', 'wix-woo-migration')));
        }

        $migration_state['status'] = 'active';
        update_option($this->option_name, $migration_state);

        wp_send_json_success(array('message' => __('Migration started', 'wix-woo-migration')));
    }

    public function process_batch()
    {
        check_ajax_referer('wix_woo_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access', 'wix-woo-migration')));
        }

        $migration_state = get_option($this->option_name, array());

        if ($migration_state['status'] !== 'active') {
            wp_send_json_error(array('message' => __('Migration is not active', 'wix-woo-migration')));
        }

        $product_groups = $migration_state['product_groups'];
        $current_index = $migration_state['current_index'];

        $processed_in_batch = 0;
        $batch_results = array('success' => 0, 'failed' => 0, 'images' => 0);

        while ($processed_in_batch < $this->batch_size && $current_index < count($product_groups)) {
            $product_group = $product_groups[$current_index];

            $result = $this->import_wix_product($product_group);

            if ($result['success']) {
                $batch_results['success']++;
                $batch_results['images'] += $result['images_imported'];
            } else {
                $batch_results['failed']++;
                $migration_state['errors'][] = sprintf(
                    __('Failed to import product: %s - %s', 'wix-woo-migration'),
                    $product_group['product']['name'] ?? 'Unknown',
                    $result['error'] ?? 'Unknown error'
                );
            }

            $processed_in_batch++;
            $current_index++;
        }

        $migration_state['processed'] += $batch_results['success'];
        $migration_state['failed'] += $batch_results['failed'];
        $migration_state['images_imported'] = ($migration_state['images_imported'] ?? 0) + $batch_results['images'];
        $migration_state['current_index'] = $current_index;

        $is_complete = $current_index >= count($product_groups);

        if ($is_complete) {
            $migration_state['status'] = 'completed';
        }

        update_option($this->option_name, $migration_state);

        wp_send_json_success(array(
            'processed' => $migration_state['processed'],
            'failed' => $migration_state['failed'],
            'total' => $migration_state['total_products'],
            'images_imported' => $migration_state['images_imported'],
            'is_complete' => $is_complete,
            'percentage' => round(($migration_state['processed'] + $migration_state['failed']) / $migration_state['total_products'] * 100, 1)
        ));
    }

    public function stop_migration()
    {
        check_ajax_referer('wix_woo_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access', 'wix-woo-migration')));
        }

        $migration_state = get_option($this->option_name, array());
        $migration_state['status'] = 'paused';
        update_option($this->option_name, $migration_state);

        wp_send_json_success(array('message' => __('Migration paused', 'wix-woo-migration')));
    }

    public function reset_migration()
    {
        check_ajax_referer('wix_woo_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access', 'wix-woo-migration')));
        }

        $migration_state = get_option($this->option_name, array());

        if (isset($migration_state['csv_path']) && file_exists($migration_state['csv_path'])) {
            unlink($migration_state['csv_path']);
        }

        delete_option($this->option_name);

        wp_send_json_success(array('message' => __('Migration reset successfully', 'wix-woo-migration')));
    }

    private function import_wix_product($product_group)
    {
        if (!class_exists('WC_Product_Simple')) {
            return array('success' => false, 'error' => 'WooCommerce not active');
        }

        try {
            $product_data = $product_group['product'];
            $variants = $product_group['variants'];
            $images_imported = 0;

            // Determine if this is a variable product
            $has_variants = !empty($variants);

            if ($has_variants) {
                $result = $this->create_variable_product($product_data, $variants);
                return $result;
            } else {
                $product = new WC_Product_Simple();

                // Set product name
                if (!empty($product_data['name'])) {
                    $product->set_name(sanitize_text_field($product_data['name']));
                }

                // Set description
                if (!empty($product_data['description'])) {
                    $product->set_description(wp_kses_post($product_data['description']));
                }

                // Set price
                if (!empty($product_data['price'])) {
                    $product->set_regular_price(floatval($product_data['price']));
                }

                // Set SKU
                if (!empty($product_data['sku'])) {
                    $product->set_sku(sanitize_text_field($product_data['sku']));
                }

                // Set stock
                if (isset($product_data['inventory'])) {
                    if (strtolower($product_data['inventory']) === 'instock') {
                        $product->set_stock_status('instock');
                    } else {
                        $product->set_stock_status('outofstock');
                    }
                }

                // Set weight
                if (!empty($product_data['weight'])) {
                    $product->set_weight(sanitize_text_field($product_data['weight']));
                }

                // Set visibility
                if (isset($product_data['visible'])) {
                    $visible = strtolower($product_data['visible']) === 'true';
                    $product->set_catalog_visibility($visible ? 'visible' : 'hidden');
                }

                $product->save();

                // Import images
                if (!empty($product_data['productImageUrl'])) {
                    $images_imported = $this->import_wix_images($product->get_id(), $product_data['productImageUrl']);
                }

                // Set collection as category
                if (!empty($product_data['collection'])) {
                    $this->set_product_category($product->get_id(), $product_data['collection']);
                }

                return array('success' => true, 'images_imported' => $images_imported);
            }

        } catch (Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    private function create_variable_product($product_data, $variants)
    {
        if (!class_exists('WC_Product_Variable')) {
            return array('success' => false, 'error' => 'WooCommerce Variable Products not available');
        }

        try {
            $images_imported = 0;

            // Create variable product
            $product = new WC_Product_Variable();

            if (!empty($product_data['name'])) {
                $product->set_name(sanitize_text_field($product_data['name']));
            }

            if (!empty($product_data['description'])) {
                $product->set_description(wp_kses_post($product_data['description']));
            }

            if (!empty($product_data['sku'])) {
                $product->set_sku(sanitize_text_field($product_data['sku']));
            }

            $product->save();
            $product_id = $product->get_id();

            // Import main product images
            if (!empty($product_data['productImageUrl'])) {
                $images_imported = $this->import_wix_images($product_id, $product_data['productImageUrl']);
            }

            // Set collection as category
            if (!empty($product_data['collection'])) {
                $this->set_product_category($product_id, $product_data['collection']);
            }

            // Extract attributes from first variant
            $attributes = $this->extract_wix_attributes($product_data, $variants);

            // Create and set attributes
            if (!empty($attributes)) {
                $this->create_woo_attributes($product, $attributes);
            }

            // Create variations
            $variation_images = $this->create_wix_variations($product_id, $variants, $attributes);
            $images_imported += $variation_images;

            // Sync the variable product
            WC_Product_Variable::sync($product_id);

            return array('success' => true, 'images_imported' => $images_imported);

        } catch (Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    private function extract_wix_attributes($product_data, $variants)
    {
        $attributes = array();

        // Check for up to 6 product options
        for ($i = 1; $i <= 6; $i++) {
            $option_name = 'productOptionName' . $i;

            if (!empty($product_data[$option_name])) {
                $attr_name = sanitize_text_field($product_data[$option_name]);
                $attributes[$attr_name] = array();

                // Collect unique values from variants
                foreach ($variants as $variant) {
                    $option_value_key = 'productOptionName' . $i;
                    if (!empty($variant[$option_value_key])) {
                        $value = sanitize_text_field($variant[$option_value_key]);
                        if (!in_array($value, $attributes[$attr_name])) {
                            $attributes[$attr_name][] = $value;
                        }
                    }
                }
            }
        }

        return $attributes;
    }

    private function create_woo_attributes($product, $attributes)
    {
        $product_attributes = array();

        foreach ($attributes as $attr_name => $attr_values) {
            $taxonomy_name = wc_sanitize_taxonomy_name($attr_name);
            $attribute_taxonomy = 'pa_' . $taxonomy_name;

            // Register attribute if it doesn't exist
            if (!taxonomy_exists($attribute_taxonomy)) {
                $this->register_product_attribute($attr_name, $taxonomy_name);
            }

            // Create terms
            $term_ids = array();
            foreach ($attr_values as $value) {
                $term = term_exists($value, $attribute_taxonomy);
                if (!$term) {
                    $term = wp_insert_term($value, $attribute_taxonomy);
                }
                if (!is_wp_error($term)) {
                    $term_ids[] = $term['term_id'];
                }
            }

            // Set product terms
            wp_set_object_terms($product->get_id(), $term_ids, $attribute_taxonomy);

            // Create attribute object
            $attribute = new WC_Product_Attribute();
            $attribute->set_id(wc_attribute_taxonomy_id_by_name($attribute_taxonomy));
            $attribute->set_name($attribute_taxonomy);
            $attribute->set_options($term_ids);
            $attribute->set_visible(true);
            $attribute->set_variation(true);

            $product_attributes[] = $attribute;
        }

        $product->set_attributes($product_attributes);
        $product->save();
    }

    private function register_product_attribute($name, $slug)
    {
        $attribute_id = wc_create_attribute(array(
            'name' => $name,
            'slug' => $slug,
            'type' => 'select',
            'order_by' => 'menu_order',
            'has_archives' => false,
        ));

        if (!is_wp_error($attribute_id)) {
            register_taxonomy(
                'pa_' . $slug,
                array('product'),
                array(
                    'labels' => array('name' => $name),
                    'hierarchical' => false,
                    'show_ui' => true,
                    'query_var' => true,
                    'rewrite' => false,
                    'show_in_nav_menus' => false,
                )
            );
        }
    }

    private function create_wix_variations($product_id, $variants, $attributes)
    {
        $total_images = 0;

        foreach ($variants as $variant_data) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);

            // Map attributes to variation
            $variation_attributes = array();
            for ($i = 1; $i <= 6; $i++) {
                $option_name_key = 'productOptionName' . $i;

                if (!empty($variant_data[$option_name_key])) {
                    // Find the matching attribute name from parent product
                    foreach ($attributes as $attr_name => $attr_values) {
                        if (in_array($variant_data[$option_name_key], $attr_values)) {
                            $taxonomy_name = 'pa_' . wc_sanitize_taxonomy_name($attr_name);
                            $term = get_term_by('name', $variant_data[$option_name_key], $taxonomy_name);
                            if ($term) {
                                $variation_attributes[$taxonomy_name] = $term->slug;
                            }
                            break;
                        }
                    }
                }
            }

            $variation->set_attributes($variation_attributes);

            // Set variation price
            if (!empty($variant_data['price'])) {
                $variation->set_regular_price(floatval($variant_data['price']));
            }

            // Set variation SKU
            if (!empty($variant_data['sku'])) {
                $variation->set_sku(sanitize_text_field($variant_data['sku']));
            }

            // Set stock status
            if (isset($variant_data['inventory'])) {
                if (strtolower($variant_data['inventory']) === 'instock') {
                    $variation->set_stock_status('instock');
                } else {
                    $variation->set_stock_status('outofstock');
                }
            }

            // Set weight
            if (!empty($variant_data['weight'])) {
                $variation->set_weight(sanitize_text_field($variant_data['weight']));
            }

            $variation->save();

            // Import variation image
            if (!empty($variant_data['productImageUrl'])) {
                $image_imported = $this->import_wix_images($variation->get_id(), $variant_data['productImageUrl'], true);
                $total_images += $image_imported;
            }
        }

        return $total_images;
    }

    private function import_wix_images($product_id, $images_string, $is_variation = false)
    {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // Wix uses semicolons to separate images
        $images = array_map('trim', explode(';', $images_string));
        $image_ids = array();
        $imported_count = 0;

        foreach ($images as $index => $image) {
            if (empty($image))
                continue;

            // Build Wix image URL
            $image_url = 'https://static.wixstatic.com/media/' . $image;

            // Download image
            $tmp = download_url($image_url);

            if (is_wp_error($tmp)) {
                continue;
            }

            // Get file extension
            $file_array = array(
                'name' => basename($image),
                'tmp_name' => $tmp
            );

            // Upload to media library
            $attachment_id = media_handle_sideload($file_array, $product_id);

            if (is_wp_error($attachment_id)) {
                @unlink($tmp);
                continue;
            }

            $image_ids[] = $attachment_id;
            $imported_count++;

            // Set as featured image (first image only)
            if ($index === 0) {
                if ($is_variation) {
                    update_post_meta($product_id, '_thumbnail_id', $attachment_id);
                } else {
                    set_post_thumbnail($product_id, $attachment_id);
                }
            }
        }

        // Set gallery images for main product
        if (!$is_variation && count($image_ids) > 1) {
            $gallery_ids = array_slice($image_ids, 1);
            update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
        }

        return $imported_count;
    }

    private function set_product_category($product_id, $category_name)
    {
        $category_name = sanitize_text_field($category_name);

        // Check if category exists
        $term = term_exists($category_name, 'product_cat');

        if (!$term) {
            // Create the category
            $term = wp_insert_term($category_name, 'product_cat');
        }

        if (!is_wp_error($term)) {
            wp_set_object_terms($product_id, $term['term_id'], 'product_cat');
        }
    }
}

// Initialize the plugin
new Wix_WooCommerce_Migration();

// Create assets on activation
function wix_woo_migration_create_assets()
{
    $plugin_dir = plugin_dir_path(__FILE__);
    $assets_dir = $plugin_dir . 'assets/';

    if (!file_exists($assets_dir)) {
        wp_mkdir_p($assets_dir);
    }

    // CSS
    $css = '.wix-woo-migration-wrap {
    max-width: 1200px;
}

.wix-woo-migration-container {
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    margin-top: 20px;
}

.wix-woo-section {
    padding: 20px;
    border-bottom: 1px solid #f0f0f1;
}

.wix-woo-section:last-child {
    border-bottom: none;
}

.wix-woo-section h2 {
    margin-top: 0;
}

.csv-format-info {
    background: #f0f6fc;
    border-left: 4px solid #2271b1;
    padding: 15px;
    margin: 15px 0;
}

.csv-format-info h3 {
    margin-top: 0;
}

.csv-format-info ul {
    margin: 10px 0;
}

.csv-format-info li {
    margin: 8px 0;
}

#wix-csv-upload-form {
    margin: 20px 0;
}

#wix-csv-file {
    margin-right: 10px;
}

.progress-bar-container {
    margin: 20px 0;
}

.progress-bar {
    width: 100%;
    height: 30px;
    background: #f0f0f1;
    border-radius: 4px;
    overflow: hidden;
    position: relative;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #2271b1, #135e96);
    transition: width 0.3s ease;
}

.progress-text {
    text-align: center;
    margin-top: 10px;
    font-weight: 600;
    font-size: 16px;
}

.migration-controls {
    margin: 20px 0;
}

.migration-controls button {
    margin-right: 10px;
}

#migration-info p {
    margin: 10px 0;
}

.error-log {
    background: #f0f0f1;
    padding: 15px;
    border-radius: 4px;
    max-height: 300px;
    overflow-y: auto;
}

.error-log p {
    margin: 5px 0;
    font-size: 13px;
    color: #d63638;
}

.notice {
    padding: 12px;
    margin: 15px 0;
    border-left: 4px solid;
}

.notice-success {
    background: #edfaef;
    border-color: #00a32a;
}

.notice-error {
    background: #fcf0f1;
    border-color: #d63638;
}

.notice-info {
    background: #f0f6fc;
    border-color: #2271b1;
}';

    file_put_contents($assets_dir . 'style.css', $css);

    // JavaScript
    $js = 'jQuery(document).ready(function($) {
    let migrationInterval = null;
    
    // CSV Upload
    $("#wix-csv-upload-form").on("submit", function(e) {
        e.preventDefault();
        
        const formData = new FormData();
        const fileInput = $("#wix-csv-file")[0];
        
        if (!fileInput.files.length) {
            showMessage("upload-message", "error", "Please select a file");
            return;
        }
        
        // Show loading message
        showMessage("upload-message", "info", "Uploading and parsing CSV file...");
        
        formData.append("wix_csv_file", fileInput.files[0]);
        formData.append("action", "wix_woo_upload_csv");
        formData.append("nonce", wixWooMigration.nonce);
        
        $.ajax({
            url: wixWooMigration.ajax_url,
            type: "POST",
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                console.log("Upload response:", response);
                if (response.success) {
                    showMessage("upload-message", "success", response.data.message);
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showMessage("upload-message", "error", response.data.message || "Upload failed");
                }
            },
            error: function(xhr, status, error) {
                console.error("Upload error:", xhr, status, error);
                showMessage("upload-message", "error", "Upload failed: " + error);
            }
        });
    });
    
    // Start/Resume Migration
    $("#start-migration-btn").on("click", function() {
        $.post(wixWooMigration.ajax_url, {
            action: "wix_woo_start_migration",
            nonce: wixWooMigration.nonce
        }, function(response) {
            if (response.success) {
                startMigrationProcess();
            } else {
                showMessage("migration-message", "error", response.data.message);
            }
        });
    });
    
    // Stop Migration
    $("#stop-migration-btn").on("click", function() {
        $.post(wixWooMigration.ajax_url, {
            action: "wix_woo_stop_migration",
            nonce: wixWooMigration.nonce
        }, function(response) {
            if (response.success) {
                stopMigrationProcess();
                showMessage("migration-message", "info", response.data.message);
            }
        });
    });
    
    // Reset Migration
    $("#reset-migration-btn").on("click", function() {
        if (!confirm("Are you sure you want to reset the migration? This will delete all progress.")) {
            return;
        }
        
        $.post(wixWooMigration.ajax_url, {
            action: "wix_woo_reset_migration",
            nonce: wixWooMigration.nonce
        }, function(response) {
            if (response.success) {
                location.reload();
            }
        });
    });
    
    function startMigrationProcess() {
        $("#start-migration-btn").hide();
        $("#stop-migration-btn").show();
        
        migrationInterval = setInterval(function() {
            processBatch();
        }, 1000);
    }
    
    function stopMigrationProcess() {
        if (migrationInterval) {
            clearInterval(migrationInterval);
            migrationInterval = null;
        }
        
        $("#stop-migration-btn").hide();
        $("#start-migration-btn").show().text("Resume Migration");
    }
    
    function processBatch() {
        $.post(wixWooMigration.ajax_url, {
            action: "wix_woo_process_batch",
            nonce: wixWooMigration.nonce
        }, function(response) {
            if (response.success) {
                updateProgress(response.data);
                
                if (response.data.is_complete) {
                    stopMigrationProcess();
                    showMessage("migration-message", "success", "Migration completed successfully!");
                    $("#start-migration-btn").hide();
                    $("#stop-migration-btn").hide();
                }
            } else {
                stopMigrationProcess();
                showMessage("migration-message", "error", response.data.message);
            }
        }).fail(function(xhr, status, error) {
            console.error("Batch processing error:", xhr, status, error);
            stopMigrationProcess();
            showMessage("migration-message", "error", "Batch processing failed: " + error);
        });
    }
    
    function updateProgress(data) {
        $("#processed-count").text(data.processed);
        $("#failed-count").text(data.failed);
        $("#images-count").text(data.images_imported);
        $("#progress-fill").css("width", data.percentage + "%");
        $("#progress-text").text(data.percentage + "%");
    }
    
    function showMessage(elementId, type, message) {
        const $msg = $("#" + elementId);
        $msg.removeClass("notice-success notice-error notice-info")
            .addClass("notice-" + type)
            .html("<p>" + message + "</p>")
            .show();
        
        if (type !== "info") {
            setTimeout(function() {
                $msg.fadeOut();
            }, 5000);
        }
    }
});';

    file_put_contents($assets_dir . 'script.js', $js);
}

register_activation_hook(__FILE__, 'wix_woo_migration_create_assets');