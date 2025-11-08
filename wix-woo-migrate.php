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
    private $settings_name = 'wix_woo_migration_settings';
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
        add_action('wp_ajax_wix_woo_save_settings', array($this, 'save_settings'));
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

        wp_enqueue_style('wix-woo-migration-css', plugin_dir_url(__FILE__) . 'assets/style.css', array(), '1.0.1');
        wp_enqueue_script('wix-woo-migration-js', plugin_dir_url(__FILE__) . 'assets/script.js', array('jquery'), '1.0.1', true);

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
        $settings = get_option($this->settings_name, array('import_categories' => true));
        $is_active = isset($migration_state['status']) && $migration_state['status'] === 'active';
        $is_paused = isset($migration_state['status']) && $migration_state['status'] === 'paused';

        ?>
        <div class="wrap wix-woo-migration-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="wix-woo-migration-container">

                <?php if (!$is_active && !$is_paused): ?>
                    <div class="wix-woo-section">
                        <h2><?php _e('Migration Settings', 'wix-woo-migration'); ?></h2>
                        <form id="wix-migration-settings-form">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label
                                            for="import_categories"><?php _e('Import Categories', 'wix-woo-migration'); ?></label>
                                    </th>
                                    <td>
                                        <label>
                                            <input type="checkbox" id="import_categories" name="import_categories" value="1" <?php checked(!empty($settings['import_categories']), true); ?>>
                                            <?php _e('Import Wix collections as WooCommerce categories', 'wix-woo-migration'); ?>
                                        </label>
                                    </td>
                                </tr>
                            </table>
                            <button type="submit"
                                class="button button-primary"><?php _e('Save Settings', 'wix-woo-migration'); ?></button>
                        </form>
                        <div id="settings-message" class="notice" style="display:none;"></div>
                    </div>

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
                                <?php _e('Skipped (Duplicates):', 'wix-woo-migration'); ?>
                                <strong id="skipped-count"><?php echo esc_html($migration_state['skipped'] ?? 0); ?></strong>
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
                                style="width: <?php echo isset($migration_state['total_products']) && $migration_state['total_products'] > 0 ? (($migration_state['processed'] + $migration_state['failed'] + ($migration_state['skipped'] ?? 0)) / $migration_state['total_products'] * 100) : 0; ?>%">
                            </div>
                        </div>
                        <div id="progress-text" class="progress-text">
                            <?php
                            if (isset($migration_state['total_products']) && $migration_state['total_products'] > 0) {
                                echo esc_html(round(($migration_state['processed'] + $migration_state['failed'] + ($migration_state['skipped'] ?? 0)) / $migration_state['total_products'] * 100, 1)) . '%';
                            } else {
                                echo '0%';
                            }
                            ?>
                        </div>
                    </div>

                    <div class="migration-controls">
                        <?php if (isset($migration_state['csv_path']) && $migration_state['status'] !== 'completed'): ?>
                            <?php if (!$is_active): ?>
                                <button id="start-migration-btn" class="button button-primary">
                                    <?php echo $is_paused ? __('Resume Migration', 'wix-woo-migration') : __('Start Migration', 'wix-woo-migration'); ?>
                                </button>
                            <?php endif; ?>

                            <?php if ($is_active): ?>
                                <button id="stop-migration-btn" class="button button-secondary">
                                    <?php _e('Pause Migration', 'wix-woo-migration'); ?>
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if (isset($migration_state['csv_path'])): ?>
                            <button id="reset-migration-btn" class="button button-link-delete">
                                <?php _e('Reset Migration', 'wix-woo-migration'); ?>
                            </button>

                            <?php if (!empty($migration_state['failed_products'])): ?>
                                <a href="<?php echo admin_url('tools.php?page=wix-woo-migration&export_failed=1'); ?>" class="button">
                                    <?php _e('Export Failed Products', 'wix-woo-migration'); ?>
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <div id="migration-message" class="notice" style="display:none;"></div>
                </div>

                <?php if (isset($migration_state['failed_products']) && !empty($migration_state['failed_products'])): ?>
                    <div class="wix-woo-section">
                        <h2><?php _e('Failed Products', 'wix-woo-migration'); ?></h2>
                        <p><?php _e('The following products failed to import:', 'wix-woo-migration'); ?></p>
                        <div class="error-log">
                            <?php foreach ($migration_state['failed_products'] as $failed): ?>
                                <p><strong><?php echo esc_html($failed['name']); ?></strong> (SKU:
                                    <?php echo esc_html($failed['sku'] ?? 'N/A'); ?>)<br>
                                    <span style="color: #d63638;"><?php echo esc_html($failed['error']); ?></span>
                                </p>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (isset($migration_state['errors']) && !empty($migration_state['errors'])): ?>
                    <div class="wix-woo-section">
                        <h2><?php _e('Migration Log', 'wix-woo-migration'); ?></h2>
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

        // Handle export failed products
        if (isset($_GET['export_failed']) && !empty($migration_state['failed_products'])) {
            $this->export_failed_products($migration_state['failed_products']);
        }
    }

    private function export_failed_products($failed_products)
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=failed-products-' . date('Y-m-d-His') . '.csv');

        $output = fopen('php://output', 'w');
        fputcsv($output, array('Product Name', 'SKU', 'Handle ID', 'Error Message'));

        foreach ($failed_products as $product) {
            fputcsv($output, array(
                $product['name'],
                $product['sku'] ?? 'N/A',
                $product['handle_id'] ?? 'N/A',
                $product['error']
            ));
        }

        fclose($output);
        exit;
    }

    public function save_settings()
    {
        check_ajax_referer('wix_woo_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access', 'wix-woo-migration')));
        }

        $import_categories = isset($_POST['import_categories']) && $_POST['import_categories'] === '1';

        update_option($this->settings_name, array(
            'import_categories' => $import_categories
        ));

        wp_send_json_success(array('message' => __('Settings saved successfully', 'wix-woo-migration')));
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
                'skipped' => 0,
                'current_index' => 0,
                'product_groups' => $product_groups,
                'status' => 'ready',
                'errors' => array(),
                'failed_products' => array(),
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

        $delimiter = "\t";
        if (strpos($first_line, "\t") === false && strpos($first_line, ",") !== false) {
            $delimiter = ",";
        }

        $headers = fgetcsv($handle, 0, $delimiter);

        if (!$headers || empty($headers)) {
            fclose($handle);
            throw new Exception('Invalid CSV headers');
        }

        $headers = array_map(function ($header) {
            return trim($header, " \t\n\r\0\x0B\xEF\xBB\xBF");
        }, $headers);

        $product_groups = array();
        $current_handle = null;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (empty(array_filter($row))) {
                continue;
            }

            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), '');
            } elseif (count($row) > count($headers)) {
                $row = array_slice($row, 0, count($headers));
            }

            $data = array_combine($headers, $row);

            if (!$data) {
                continue;
            }

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
        $batch_results = array('success' => 0, 'failed' => 0, 'skipped' => 0, 'images' => 0);

        while ($processed_in_batch < $this->batch_size && $current_index < count($product_groups)) {
            $product_group = $product_groups[$current_index];

            $result = $this->import_wix_product($product_group);

            if ($result['success']) {
                $batch_results['success']++;
                $batch_results['images'] += $result['images_imported'];
            } elseif ($result['skipped']) {
                $batch_results['skipped']++;
                $migration_state['errors'][] = sprintf(
                    __('Skipped duplicate: %s (SKU: %s)', 'wix-woo-migration'),
                    $product_group['product']['name'] ?? 'Unknown',
                    $product_group['product']['sku'] ?? 'N/A'
                );
            } else {
                $batch_results['failed']++;
                $migration_state['failed_products'][] = array(
                    'name' => $product_group['product']['name'] ?? 'Unknown',
                    'sku' => $product_group['product']['sku'] ?? '',
                    'handle_id' => $product_group['product']['handleId'] ?? '',
                    'error' => $result['error'] ?? 'Unknown error'
                );
                $migration_state['errors'][] = sprintf(
                    __('Failed: %s - %s', 'wix-woo-migration'),
                    $product_group['product']['name'] ?? 'Unknown',
                    $result['error'] ?? 'Unknown error'
                );
            }

            $processed_in_batch++;
            $current_index++;
        }

        $migration_state['processed'] += $batch_results['success'];
        $migration_state['failed'] += $batch_results['failed'];
        $migration_state['skipped'] = ($migration_state['skipped'] ?? 0) + $batch_results['skipped'];
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
            'skipped' => $migration_state['skipped'],
            'total' => $migration_state['total_products'],
            'images_imported' => $migration_state['images_imported'],
            'is_complete' => $is_complete,
            'percentage' => round(($migration_state['processed'] + $migration_state['failed'] + $migration_state['skipped']) / $migration_state['total_products'] * 100, 1)
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
            return array('success' => false, 'skipped' => false, 'error' => 'WooCommerce not active');
        }

        try {
            $product_data = $product_group['product'];
            $variants = $product_group['variants'];

            // Check for duplicates by SKU or name
            $sku = !empty($product_data['sku']) ? sanitize_text_field($product_data['sku']) : '';
            $name = !empty($product_data['name']) ? sanitize_text_field($product_data['name']) : '';

            if (!empty($sku)) {
                $existing_id = wc_get_product_id_by_sku($sku);
                if ($existing_id) {
                    return array('success' => false, 'skipped' => true, 'error' => 'Duplicate SKU');
                }
            }

            // Check by name if no SKU
            if (empty($sku) && !empty($name)) {
                $existing = get_page_by_title($name, OBJECT, 'product');
                if ($existing) {
                    return array('success' => false, 'skipped' => true, 'error' => 'Duplicate name');
                }
            }

            $images_imported = 0;
            $has_variants = !empty($variants);

            if ($has_variants) {
                $result = $this->create_variable_product($product_data, $variants);
                return $result;
            } else {
                $product = new WC_Product_Simple();

                if (!empty($product_data['name'])) {
                    $product->set_name($name);
                }

                if (!empty($product_data['description'])) {
                    $product->set_description(wp_kses_post($product_data['description']));
                }

                if (!empty($product_data['price'])) {
                    $product->set_regular_price(floatval($product_data['price']));
                }

                if (!empty($sku)) {
                    $product->set_sku($sku);
                }

                if (isset($product_data['inventory'])) {
                    if (strtolower($product_data['inventory']) === 'instock') {
                        $product->set_stock_status('instock');
                    } else {
                        $product->set_stock_status('outofstock');
                    }
                }

                if (!empty($product_data['weight'])) {
                    $product->set_weight(sanitize_text_field($product_data['weight']));
                }

                if (isset($product_data['visible'])) {
                    $visible = strtolower($product_data['visible']) === 'true';
                    $product->set_catalog_visibility($visible ? 'visible' : 'hidden');
                }

                $product->save();

                if (!empty($product_data['productImageUrl'])) {
                    $images_imported = $this->import_wix_images($product->get_id(), $product_data['productImageUrl']);
                }

                $settings = get_option($this->settings_name, array('import_categories' => true));
                if (!empty($settings['import_categories']) && !empty($product_data['collection'])) {
                    $this->set_product_category($product->get_id(), $product_data['collection']);
                }

                return array('success' => true, 'skipped' => false, 'images_imported' => $images_imported);
            }

        } catch (Exception $e) {
            return array('success' => false, 'skipped' => false, 'error' => $e->getMessage());
        }
    }

    private function create_variable_product($product_data, $variants)
    {
        if (!class_exists('WC_Product_Variable')) {
            return array('success' => false, 'skipped' => false, 'error' => 'WooCommerce Variable Products not available');
        }

        try {
            $images_imported = 0;

            // Extract attributes FIRST before creating product
            $attributes = $this->extract_wix_attributes($product_data, $variants);

            // Debug log
            error_log('Extracted attributes for ' . $product_data['name'] . ': ' . print_r($attributes, true));

            if (empty($attributes)) {
                return array('success' => false, 'skipped' => false, 'error' => 'No attributes found for variable product');
            }

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

            if (!empty($product_data['productImageUrl'])) {
                $images_imported = $this->import_wix_images($product_id, $product_data['productImageUrl']);
            }

            $settings = get_option($this->settings_name, array('import_categories' => true));
            if (!empty($settings['import_categories']) && !empty($product_data['collection'])) {
                $this->set_product_category($product_id, $product_data['collection']);
            }

            // Create global attributes and terms BEFORE creating variations
            $this->create_woo_attributes($product, $attributes);

            // Force reload product to get fresh attribute data
            $product = wc_get_product($product_id);

            // Create variations with proper attribute mapping
            $variation_images = $this->create_wix_variations($product_id, $variants, $attributes);
            $images_imported += $variation_images;

            // Sync the variable product
            WC_Product_Variable::sync($product_id);

            // Save again after sync
            $product->save();

            return array('success' => true, 'skipped' => false, 'images_imported' => $images_imported);

        } catch (Exception $e) {
            error_log('Variable product creation error: ' . $e->getMessage());
            return array('success' => false, 'skipped' => false, 'error' => $e->getMessage());
        }
    }

    private function extract_wix_attributes($product_data, $variants)
    {
        $attributes = array();

        // Check for up to 6 product options from the PARENT product row
        for ($i = 1; $i <= 6; $i++) {
            $option_name_key = 'productOptionName' . $i;
            $option_type_key = 'productOptionType' . $i;

            // Get the attribute name from the parent product
            if (!empty($product_data[$option_name_key])) {
                $attr_name = sanitize_text_field($product_data[$option_name_key]);
                $attributes[$attr_name] = array();

                // Collect unique values from ALL variants for this attribute position
                foreach ($variants as $variant) {
                    // In variant rows, the value is in the SAME column (productOptionName1, etc.)
                    if (!empty($variant[$option_name_key])) {
                        $value = sanitize_text_field($variant[$option_name_key]);
                        if (!in_array($value, $attributes[$attr_name])) {
                            $attributes[$attr_name][] = $value;
                        }
                    }
                }

                // If no values found in variants, remove this attribute
                if (empty($attributes[$attr_name])) {
                    unset($attributes[$attr_name]);
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

            error_log('Creating attribute: ' . $attr_name . ' with taxonomy: ' . $attribute_taxonomy);
            error_log('Values: ' . print_r($attr_values, true));

            // Check if attribute exists globally
            $attribute_id = wc_attribute_taxonomy_id_by_name($attribute_taxonomy);

            if (!$attribute_id) {
                // Create global attribute
                error_log('Attribute does not exist, creating...');
                $attribute_id = $this->register_product_attribute($attr_name, $taxonomy_name);
                error_log('Created attribute with ID: ' . $attribute_id);
            } else {
                error_log('Attribute already exists with ID: ' . $attribute_id);
            }

            if (!$attribute_id) {
                error_log('ERROR: Failed to get or create attribute ID for: ' . $attr_name);
                continue;
            }

            // Ensure taxonomy is registered
            if (!taxonomy_exists($attribute_taxonomy)) {
                error_log('Taxonomy does not exist, registering: ' . $attribute_taxonomy);
                register_taxonomy(
                    $attribute_taxonomy,
                    array('product', 'product_variation'),
                    array(
                        'labels' => array(
                            'name' => $attr_name,
                            'singular_name' => $attr_name,
                            'menu_name' => $attr_name,
                        ),
                        'hierarchical' => false,
                        'show_ui' => true,
                        'show_in_menu' => true,
                        'show_in_nav_menus' => true,
                        'query_var' => true,
                        'rewrite' => array('slug' => $taxonomy_name),
                        'show_admin_column' => true,
                        'show_in_rest' => true,
                        'public' => true,
                    )
                );
            }

            // Create terms globally in the taxonomy
            $term_ids = array();
            foreach ($attr_values as $value) {
                // Check if term exists
                $term = get_term_by('name', $value, $attribute_taxonomy);

                if (!$term) {
                    error_log('Creating term: ' . $value . ' in taxonomy: ' . $attribute_taxonomy);
                    // Create the term globally
                    $term_result = wp_insert_term($value, $attribute_taxonomy);

                    if (is_wp_error($term_result)) {
                        error_log('ERROR creating term: ' . $term_result->get_error_message());
                        // Try to get it anyway in case it exists
                        $term = get_term_by('name', $value, $attribute_taxonomy);
                        if ($term) {
                            $term_ids[] = $term->term_id;
                        }
                    } else {
                        error_log('Successfully created term with ID: ' . $term_result['term_id']);
                        $term_ids[] = $term_result['term_id'];
                    }
                } else {
                    error_log('Term already exists: ' . $value . ' with ID: ' . $term->term_id);
                    $term_ids[] = $term->term_id;
                }
            }

            error_log('Final term IDs for attribute: ' . print_r($term_ids, true));

            // Assign terms to product
            if (!empty($term_ids)) {
                $result = wp_set_object_terms($product->get_id(), $term_ids, $attribute_taxonomy, false);
                if (is_wp_error($result)) {
                    error_log('ERROR assigning terms to product: ' . $result->get_error_message());
                } else {
                    error_log('Successfully assigned terms to product');
                }
            }

            // Create product attribute
            $attribute = new WC_Product_Attribute();
            $attribute->set_id($attribute_id);
            $attribute->set_name($attribute_taxonomy);
            $attribute->set_options($term_ids);
            $attribute->set_visible(true);
            $attribute->set_variation(true);

            $product_attributes[] = $attribute;
            error_log('Added attribute to product_attributes array');
        }

        error_log('Setting ' . count($product_attributes) . ' attributes on product');
        $product->set_attributes($product_attributes);
        $product->save();
        error_log('Product saved with attributes');
    }

    private function register_product_attribute($name, $slug)
    {
        global $wpdb;

        // Check if attribute already exists
        $existing_id = wc_attribute_taxonomy_id_by_name('pa_' . $slug);
        if ($existing_id) {
            return $existing_id;
        }

        // Create the attribute
        $attribute_id = wc_create_attribute(array(
            'name' => $name,
            'slug' => $slug,
            'type' => 'select',
            'order_by' => 'menu_order',
            'has_archives' => false,
        ));

        if (is_wp_error($attribute_id)) {
            error_log('Failed to create attribute: ' . $name . ' - ' . $attribute_id->get_error_message());
            return false;
        }

        // Register the taxonomy immediately
        $attribute_taxonomy = 'pa_' . $slug;

        register_taxonomy(
            $attribute_taxonomy,
            array('product', 'product_variation'),
            array(
                'labels' => array(
                    'name' => $name,
                    'singular_name' => $name,
                    'menu_name' => $name,
                ),
                'hierarchical' => false,
                'show_ui' => true,
                'show_in_menu' => true,
                'show_in_nav_menus' => true,
                'query_var' => true,
                'rewrite' => array('slug' => $slug),
                'show_admin_column' => true,
                'show_in_rest' => true,
                'public' => true,
            )
        );

        // Clear any cached taxonomy data
        delete_transient('wc_attribute_taxonomies');
        WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');

        return $attribute_id;
    }

    private function create_wix_variations($product_id, $variants, $attributes)
    {
        $total_images = 0;

        foreach ($variants as $variant_data) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);

            $variation_attributes = array();

            // Map variant values to global taxonomy terms
            for ($i = 1; $i <= 6; $i++) {
                $option_name_key = 'productOptionName' . $i;

                if (!empty($variant_data[$option_name_key])) {
                    $variant_value = sanitize_text_field($variant_data[$option_name_key]);

                    // Find which attribute this belongs to
                    foreach ($attributes as $attr_name => $attr_values) {
                        if (in_array($variant_value, $attr_values)) {
                            $taxonomy_name = 'pa_' . wc_sanitize_taxonomy_name($attr_name);

                            // Get the term from global taxonomy
                            $term = get_term_by('name', $variant_value, $taxonomy_name);

                            if (!$term) {
                                // If term doesn't exist, create it globally
                                $term_result = wp_insert_term($variant_value, $taxonomy_name);
                                if (!is_wp_error($term_result)) {
                                    $term = get_term($term_result['term_id'], $taxonomy_name);
                                }
                            }

                            if ($term && !is_wp_error($term)) {
                                // Use the term slug for variation attribute
                                $variation_attributes[$taxonomy_name] = $term->slug;
                            }
                            break;
                        }
                    }
                }
            }

            $variation->set_attributes($variation_attributes);

            if (!empty($variant_data['price'])) {
                $variation->set_regular_price(floatval($variant_data['price']));
            }

            if (!empty($variant_data['sku'])) {
                $variation->set_sku(sanitize_text_field($variant_data['sku']));
            }

            if (isset($variant_data['inventory'])) {
                if (strtolower($variant_data['inventory']) === 'instock') {
                    $variation->set_stock_status('instock');
                } else {
                    $variation->set_stock_status('outofstock');
                }
            }

            if (!empty($variant_data['weight'])) {
                $variation->set_weight(sanitize_text_field($variant_data['weight']));
            }

            $variation->save();

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

        $images = array_map('trim', explode(';', $images_string));
        $image_ids = array();
        $imported_count = 0;

        foreach ($images as $index => $image) {
            if (empty($image))
                continue;

            $image_url = 'https://static.wixstatic.com/media/' . $image;

            $tmp = download_url($image_url, 30);

            if (is_wp_error($tmp)) {
                continue;
            }

            $file_array = array(
                'name' => basename($image),
                'tmp_name' => $tmp
            );

            $attachment_id = media_handle_sideload($file_array, $product_id);

            if (is_wp_error($attachment_id)) {
                @unlink($tmp);
                continue;
            }

            $image_ids[] = $attachment_id;
            $imported_count++;

            if ($index === 0) {
                if ($is_variation) {
                    update_post_meta($product_id, '_thumbnail_id', $attachment_id);
                } else {
                    set_post_thumbnail($product_id, $attachment_id);
                }
            }
        }

        if (!$is_variation && count($image_ids) > 1) {
            $gallery_ids = array_slice($image_ids, 1);
            update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
        }

        return $imported_count;
    }

    private function set_product_category($product_id, $category_name)
    {
        $category_name = sanitize_text_field($category_name);

        $term = term_exists($category_name, 'product_cat');

        if (!$term) {
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

.form-table th {
    padding: 20px 10px 20px 0;
}

.form-table td {
    padding: 15px 10px;
}

#wix-csv-upload-form,
#wix-migration-settings-form {
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

.migration-controls button,
.migration-controls a {
    margin-right: 10px;
    margin-bottom: 10px;
}

#migration-info p {
    margin: 10px 0;
}

.error-log {
    background: #f0f0f1;
    padding: 15px;
    border-radius: 4px;
    max-height: 400px;
    overflow-y: auto;
}

.error-log p {
    margin: 5px 0;
    font-size: 13px;
    padding: 8px;
    border-bottom: 1px solid #ddd;
}

.error-log p:last-child {
    border-bottom: none;
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
    
    // Initialize button states on page load
    var isActive = $("#stop-migration-btn").length > 0;
    var isPaused = $("#start-migration-btn").text().indexOf("Resume") !== -1;
    
    if (isActive) {
        // If migration is active, show stop button
        $("#start-migration-btn").hide();
        $("#stop-migration-btn").show();
    } else if (isPaused) {
        // If paused, show resume button
        $("#start-migration-btn").show().text("Resume Migration");
        $("#stop-migration-btn").hide();
    }
    
    // Settings Form
    $("#wix-migration-settings-form").on("submit", function(e) {
        e.preventDefault();
        
        const importCategories = $("#import_categories").is(":checked") ? "1" : "0";
        
        $.post(wixWooMigration.ajax_url, {
            action: "wix_woo_save_settings",
            nonce: wixWooMigration.nonce,
            import_categories: importCategories
        }, function(response) {
            if (response.success) {
                showMessage("settings-message", "success", response.data.message);
            } else {
                showMessage("settings-message", "error", response.data.message);
            }
        });
    });
    
    // CSV Upload
    $("#wix-csv-upload-form").on("submit", function(e) {
        e.preventDefault();
        
        const formData = new FormData();
        const fileInput = $("#wix-csv-file")[0];
        
        if (!fileInput.files.length) {
            showMessage("upload-message", "error", "Please select a file");
            return;
        }
        
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
        $(this).prop("disabled", true).text("Starting...");
        
        $.post(wixWooMigration.ajax_url, {
            action: "wix_woo_start_migration",
            nonce: wixWooMigration.nonce
        }, function(response) {
            if (response.success) {
                startMigrationProcess();
            } else {
                $("#start-migration-btn").prop("disabled", false).text("Start Migration");
                showMessage("migration-message", "error", response.data.message);
            }
        });
    });
    
    // Stop Migration
    $("#stop-migration-btn").on("click", function() {
        $(this).prop("disabled", true).text("Pausing...");
        
        $.post(wixWooMigration.ajax_url, {
            action: "wix_woo_stop_migration",
            nonce: wixWooMigration.nonce
        }, function(response) {
            if (response.success) {
                stopMigrationProcess();
                showMessage("migration-message", "info", response.data.message);
                setTimeout(function() {
                    location.reload();
                }, 1000);
            } else {
                $("#stop-migration-btn").prop("disabled", false).text("Pause Migration");
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
        $("#stop-migration-btn").show().prop("disabled", false);
        $("#reset-migration-btn").show();
        
        migrationInterval = setInterval(function() {
            processBatch();
        }, 2000); // Increased interval to reduce load
    }
    
    function stopMigrationProcess() {
        if (migrationInterval) {
            clearInterval(migrationInterval);
            migrationInterval = null;
        }
        
        $("#stop-migration-btn").hide();
        $("#start-migration-btn").show().prop("disabled", false).text("Resume Migration");
        $("#reset-migration-btn").show();
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
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
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
        $("#skipped-count").text(data.skipped);
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