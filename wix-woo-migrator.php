<?php
/**
 * Plugin Name: Wix to WooCommerce Product Migrator
 * Plugin URI: https://example.com/wix-woo-migrator
 * Description: Imports products, variations, attributes, and images from a standard Wix product CSV export into WooCommerce.
 * Version: 2.1
 * Author: Panagiotis Drougas
 * Author URI: https://github.com/LittleDTLe
 * WC requires at least: 8.0
 * WC tested up to: 8.9
 * HPOS compatible: true
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


class Wix_Woo_Migrator
{

    // File structure constants
    const CSV_DELIMITER = ','; // Wix CSV typically uses a comma delimiter
    const WIX_MEDIA_BASE_URL = 'https://static.wixstatic.com/media/';
    const TOOL_SLUG = 'wix-product-import';

    // Batch processing constants
    const BATCH_SIZE = 10; // Number of products to process per request
    const STATE_OPTION_KEY = 'wix_migration_state';
    const WIX_HANDLE_META_KEY = '_wix_handle_id';

    // State properties
    private $error_messages = array();
    private $success_messages = array();
    private $is_batch_mode = false;

    public function __construct()
    {
        // Add Admin Menu item
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Handle HPOS compatibility declaration
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));

        // Add action link to plugins list page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));

        // Ensure necessary WordPress media functions are loaded
        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        if (!function_exists('media_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        if (!function_exists('wp_read_image_metadata')) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
        }
    }

    /**
     * Declares compatibility with WooCommerce High-Performance Order Storage (HPOS).
     */
    public function declare_hpos_compatibility()
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    /**
     * Adds the "Start Migration" link to the plugins list page.
     * @param array $actions Array of action links.
     * @return array Modified array of action links.
     */
    public function add_settings_link($actions)
    {
        $settings_link = '<a href="' . esc_url(admin_url('tools.php?page=' . self::TOOL_SLUG)) . '">' . __('Start Migration', 'wix-woo-migrator') . '</a>';
        array_unshift($actions, $settings_link);
        return $actions;
    }

    /**
     * Adds the admin menu item under 'Tools'.
     */
    public function add_admin_menu()
    {
        add_management_page(
            __('Wix Product Import', 'wix-woo-migrator'),
            __('Wix Product Import', 'wix-woo-migrator'),
            'manage_options',
            self::TOOL_SLUG,
            array($this, 'render_admin_page')
        );
    }

    /**
     * Renders the main admin page content.
     */
    public function render_admin_page()
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Wix to WooCommerce Product Migration Tool', 'wix-woo-migrator'); ?></h1>
            <p><?php esc_html_e('This tool processes your Wix product CSV export, correctly mapping variable products, attributes, and images to WooCommerce.', 'wix-woo-migrator'); ?>
            </p>

            <?php
            // Display messages (success or errors)
            $this->display_messages();

            // Check for Reset action
            if (isset($_GET['action']) && $_GET['action'] === 'reset_migration') {
                $this->reset_migration();
            }

            // Check if a batch process is currently running
            $state = get_option(self::STATE_OPTION_KEY, false);
            if ($state !== false && is_array($state)) {
                $this->is_batch_mode = true;
                $this->run_batch($state);
            }

            if (isset($_POST['wix_woo_migration_submit'])) {
                $this->handle_form_submission();
            } else {
                if ($this->is_batch_mode) {
                    // Display progress bar while batch is running
                    $this->render_progress_bar($state);
                } else {
                    // Display initial form
                    $this->render_form();
                }
            }
            ?>
        </div>
        <?php
    }

    /**
     * Renders the progress bar and status during batch processing.
     * @param array $state Current migration state.
     */
    private function render_progress_bar($state)
    {
        $total_products = $state['total_products'];
        $processed_index = $state['current_index'];
        $percentage = $total_products > 0 ? min(100, round(($processed_index / $total_products) * 100)) : 0;
        $processed_products_count = $state['imported_count'];

        ?>
        <h2><?php esc_html_e('Migration in Progress...', 'wix-woo-migrator'); ?></h2>
        <div style="margin: 20px 0;">
            <div style="height: 25px; background: #eee; border-radius: 5px; overflow: hidden; position: relative;">
                <div
                    style="width: <?php echo esc_attr($percentage); ?>%; height: 100%; background: #007cba; transition: width 0.3s;">
                </div>
                <div
                    style="position: absolute; width: 100%; text-align: center; line-height: 25px; font-weight: bold; color: #333;">
                    <?php echo esc_html($percentage); ?>%
                    (<?php echo esc_html($processed_index); ?>/<?php echo esc_html($total_products); ?>)
                </div>
            </div>
        </div>
        <p><strong><?php esc_html_e('Status:', 'wix-woo-migrator'); ?></strong> <?php echo sprintf(
               esc_html__('%d products successfully imported so far. Currently processing batch %d.', 'wix-woo-migrator'),
               $processed_products_count,
               ceil($processed_index / self::BATCH_SIZE)
           ); ?></p>
        <p><?php esc_html_e('Please keep this browser window open until the process is complete.', 'wix-woo-migrator'); ?></p>
        <a href="<?php echo esc_url(admin_url('tools.php?page=' . self::TOOL_SLUG . '&action=reset_migration')); ?>"
            class="button button-secondary">
            <?php esc_html_e('Stop & Reset Migration', 'wix-woo-migrator'); ?>
        </a>
        <?php
        // After processing the current batch, the run_batch function will handle redirection.
    }

    /**
     * Renders the migration upload form.
     */
    private function render_form()
    {
        ?>
        <form method="post" enctype="multipart/form-data" action="">
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row"><label
                                for="csv_file"><?php esc_html_e('Wix Product CSV File', 'wix-woo-migrator'); ?></label></th>
                        <td>
                            <input type="file" name="csv_file" id="csv_file" accept=".csv" required />
                            <p class="description">
                                <?php esc_html_e('Upload your exported catalog_products.csv file from Wix.', 'wix-woo-migrator'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Image Import', 'wix-woo-migrator'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="import_images" value="1" checked />
                                <?php esc_html_e('Attempt to import external images (Downloads images from Wix URLs to your Media Library).', 'wix-woo-migrator'); ?>
                                <p class="description">
                                    **<?php esc_html_e('Note: This greatly increases processing time and memory usage.', 'wix-woo-migrator'); ?>**
                                </p>
                            </label>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php wp_nonce_field('wix_woo_migration_nonce', 'wix_woo_migration_nonce_field'); ?>
            <input type="submit" name="wix_woo_migration_submit" class="button button-primary"
                value="<?php esc_html_e('Start Product Migration', 'wix-woo-migrator'); ?>" />
        </form>
        <?php
    }

    /**
     * Resets the migration process by deleting the state option.
     */
    private function reset_migration()
    {
        delete_option(self::STATE_OPTION_KEY);
        $this->add_success(__('Migration state successfully reset.', 'wix-woo-migrator'));
    }

    /**
     * Handles the form submission and initiates the migration.
     */
    private function handle_form_submission()
    {
        if (!current_user_can('manage_options')) {
            $this->add_error(__('You do not have sufficient permissions to perform this action.', 'wix-woo-migrator'));
            $this->render_form();
            return;
        }

        if (!isset($_POST['wix_woo_migration_nonce_field']) || !wp_verify_nonce($_POST['wix_woo_migration_nonce_field'], 'wix_woo_migration_nonce')) {
            $this->add_error(__('Security check failed. Please try again.', 'wix-woo-migrator'));
            $this->render_form();
            return;
        }

        if (empty($_FILES['csv_file']['tmp_name'])) {
            $this->add_error(__('Please select a CSV file to upload.', 'wix-woo-migrator'));
            $this->render_form();
            return;
        }

        $file_path = sanitize_text_field($_FILES['csv_file']['tmp_name']);
        $import_images = isset($_POST['import_images']);

        // This initial process only parses the CSV and saves the state.
        $this->start_batch_processing($file_path, $import_images);

        // If batch mode started, the run_batch in render_admin_page will take over.
    }

    /**
     * Displays current success and error messages.
     */
    private function display_messages()
    {
        if (!empty($this->error_messages)) {
            echo '<div class="notice notice-error is-dismissible">';
            foreach ($this->error_messages as $message) {
                echo '<p>' . wp_kses_post($message) . '</p>';
            }
            echo '</div>';
            $this->error_messages = array();
        }
        if (!empty($this->success_messages)) {
            echo '<div class="notice notice-success is-dismissible">';
            foreach ($this->success_messages as $message) {
                echo '<p>' . wp_kses_post($message) . '</p>';
            }
            echo '</div>';
            $this->success_messages = array();
        }
    }

    /**
     * Adds an error message to be displayed.
     * @param string $message The error message.
     */
    private function add_error($message)
    {
        $this->error_messages[] = $message;
    }

    /**
     * Adds a success message to be displayed.
     * @param string $message The success message.
     */
    private function add_success($message)
    {
        $this->success_messages[] = $message;
    }

    /**
     * Utility method to safely get data using the dynamic header map.
     * @param array $data The current CSV row data array.
     * @param array $header_map The mapping of header names to indices.
     * @param string $column_name The name of the column to retrieve.
     * @param mixed $default The default value if the column is not found or empty.
     * @return string|mixed The column value or the default value.
     */
    private function get_column_data($data, $header_map, $column_name, $default = '')
    {
        $index = $header_map[$column_name] ?? -1;
        return ($index !== -1 && isset($data[$index])) ? trim($data[$index]) : $default;
    }

    /**
     * Checks if a product with the given Wix handle ID already exists.
     * This is the idempotency check (v2.1).
     * @param string $handle_id The Wix handleId.
     * @return int|false Product post ID if it exists, false otherwise.
     */
    private function product_exists_by_wix_handle($handle_id)
    {
        $posts = get_posts(array(
            'post_type' => 'product',
            'meta_key' => self::WIX_HANDLE_META_KEY,
            'meta_value' => sanitize_text_field($handle_id),
            'fields' => 'ids',
            'numberposts' => 1,
            'post_status' => 'any', // Check published, draft, etc.
        ));

        if (!empty($posts)) {
            return $posts[0];
        }
        return false;
    }

    /**
     * Generates a unique SKU for a product, using the original SKU if possible,
     * or the handleId if the SKU is empty or already in use. (v2.2)
     * @param string $original_sku The SKU from the CSV.
     * @param string $handle_id The Wix handleId (used as unique fallback).
     * @return string A guaranteed unique SKU.
     */
    private function get_unique_product_sku($original_sku, $handle_id)
    {
        $sku = trim(sanitize_text_field($original_sku));

        // If the SKU is empty, use the handleId as a base
        if (empty($sku)) {
            $sku = strtoupper(substr($handle_id, 0, 15)) . '-ID'; // Shorten handleId for SKU length limit
        }

        // Check if the current SKU is already in use by another product
        $existing_id = wc_get_product_id_by_sku($sku);

        if ($existing_id > 0) {
            // SKU already exists, generate a unique one using the handleId
            $unique_sku = strtoupper(substr($handle_id, 0, 15)) . '-' . mt_rand(1000, 9999);
            // Final safety check (though highly unlikely to fail)
            if (wc_get_product_id_by_sku($unique_sku)) {
                $unique_sku .= time();
            }
            return $unique_sku;
        }

        return $sku;
    }

    /**
     * Phase 1: Reads the CSV, groups products by handleId, and saves the state.
     * @param string $file_path The temporary path to the uploaded CSV file.
     * @param bool $import_images Whether to import images.
     */
    private function start_batch_processing($file_path, $import_images)
    {
        // CRITICAL FIX (v2.3): Explicitly increase the time limit for the single, long synchronous parsing task.
        set_time_limit(300); // 5 minutes for parsing

        if (!function_exists('wc_get_product')) {
            $this->add_error(__('WooCommerce is not active. Please activate WooCommerce before running the migration.', 'wix-woo-migrator'));
            return;
        }

        $csv_file = fopen($file_path, 'r');
        if ($csv_file === false) {
            $this->add_error(__('Could not open the uploaded CSV file.', 'wix-woo-migrator'));
            return;
        }

        // Skip Byte Order Mark (BOM) if present (common issue with UTF-8 files)
        $bom = fread($csv_file, 3);
        if ($bom !== "﻿") { // UTF-8 BOM is EF BB BF
            rewind($csv_file);
        }

        // --- 1. Read Header and Create Dynamic Map ---
        $header_data = fgetcsv($csv_file, 0, self::CSV_DELIMITER);
        if (empty($header_data)) {
            $this->add_error(__('Invalid CSV format. Cannot read header row.', 'wix-woo-migrator'));
            fclose($csv_file);
            return;
        }

        $header_map = array_flip(array_map('trim', $header_data));

        if (!isset($header_map['handleId']) || !isset($header_map['fieldType'])) {
            $this->add_error(__('Invalid CSV format. Header is missing or "handleId" column is not found. Check that the delimiter is correct.', 'wix-woo-migrator'));
            fclose($csv_file);
            return;
        }

        // --- 2. Group Product Data by handleId (The whole file parsing) ---
        $products_to_import = array();
        $row_count = 0;

        while (($data = fgetcsv($csv_file, 0, self::CSV_DELIMITER)) !== false) {
            $row_count++;

            if (count($data) < count($header_data)) {
                continue; // Skip partial or malformed lines
            }

            $handle_id = $this->get_column_data($data, $header_map, 'handleId');
            if (empty($handle_id)) {
                continue; // Skip if no handleId (e.g., blank lines)
            }

            $field_type = $this->get_column_data($data, $header_map, 'fieldType');

            if (!isset($products_to_import[$handle_id])) {
                $products_to_import[$handle_id] = array(
                    'parent' => array(),
                    'variations' => array(),
                );
            }

            if (strtolower($field_type) === 'product') {
                $products_to_import[$handle_id]['parent'] = $data;
            } elseif (strtolower($field_type) === 'variant') {
                $products_to_import[$handle_id]['variations'][] = $data;
            }
        }
        fclose($csv_file);

        if (empty($products_to_import)) {
            $this->add_error(__('No product data found in the CSV file.', 'wix-woo-migrator'));
            return;
        }

        // --- 3. Save State and Start Batching ---
        $state = array(
            'products_to_import' => $products_to_import,
            'header_map' => $header_map,
            'import_images' => $import_images,
            'total_products' => count($products_to_import),
            'current_index' => 0,
            'imported_count' => 0,
            'errors' => array(),
        );

        update_option(self::STATE_OPTION_KEY, $state, 'no'); // 'no' prevents autoloading
        $this->add_success(sprintf(__('CSV parsed successfully. Starting batch migration for %d unique products.', 'wix-woo-migrator'), count($products_to_import)));

        // Trigger the first batch immediately
        $this->redirect_to_next_batch();
    }

    /**
     * Phase 2: Processes a single batch of products and saves the new state.
     * @param array $state Current migration state.
     */
    private function run_batch($state)
    {
        $products_to_import = $state['products_to_import'];
        $total_products = $state['total_products'];
        $current_index = $state['current_index'];
        $header_map = $state['header_map'];
        $import_images = $state['import_images'];
        $imported_count = $state['imported_count'];
        $errors = $state['errors'];

        // Get the keys for the products array (handleIds)
        $product_keys = array_keys($products_to_import);

        // Determine the start and end of this batch
        $start = $current_index;
        $end = min($total_products, $start + self::BATCH_SIZE);

        // Process the batch
        for ($i = $start; $i < $end; $i++) {
            $handle_id = $product_keys[$i];
            $product_data = $products_to_import[$handle_id];

            // --- IDEMPOTENCY CHECK (v2.1) ---
            if ($this->product_exists_by_wix_handle($handle_id)) {
                // Product already exists, but we MUST advance the current_index counter.
                $current_index++;
                continue; // Skip already imported product
            }

            if (empty($product_data['parent'])) {
                $errors[] = sprintf(__('Skipping product with handleId "%s" as the main product row is missing.', 'wix-woo-migrator'), esc_html($handle_id));
                $current_index++; // CRITICAL FIX (v2.4): Must advance index even if skipped
                continue;
            }

            // Determine if the product is simple or variable
            if (empty($product_data['variations'])) {
                $product_post_id = $this->create_simple_product($product_data['parent'], $import_images, $header_map);
            } else {
                $product_post_id = $this->create_variable_product($product_data, $import_images, $header_map);
            }

            // CRITICAL FIX (v2.4): Must advance index for the product being processed
            $current_index++;

            if (!is_wp_error($product_post_id) && $product_post_id > 0) {
                $imported_count++;
            } elseif (is_wp_error($product_post_id)) {
                $errors[] = sprintf(__('Failed to import product with handleId "%s". Error: %s', 'wix-woo-migrator'), esc_html($handle_id), esc_html($product_post_id->get_error_message()));
            }
        }

        // Update the state object
        $new_state = array(
            'products_to_import' => $products_to_import,
            'header_map' => $header_map,
            'import_images' => $import_images,
            'total_products' => $total_products,
            'current_index' => $current_index, // Updated index is now set correctly
            'imported_count' => $imported_count,
            'errors' => array_merge($state['errors'], $errors), // Collect errors from previous batches
        );

        if ($current_index < $total_products) {
            // More batches remain
            update_option(self::STATE_OPTION_KEY, $new_state, 'no');
            $this->redirect_to_next_batch();
        } else {
            // Migration finished
            delete_option(self::STATE_OPTION_KEY);

            if (!empty($new_state['errors'])) {
                $error_message = sprintf(__('Migration finished with %d errors. Total products imported: %d/%d.', 'wix-woo-migrator'), count($new_state['errors']), $imported_count, $total_products);
                $this->add_error($error_message);
                $this->add_error('<h3>' . __('Detailed Errors:', 'wix-woo-migrator') . '</h3>' . '<ul><li>' . implode('</li><li>', array_unique($new_state['errors'])) . '</li></ul>');
            } else {
                $this->add_success(sprintf(__('Migration Complete! Successfully imported %d unique products.', 'wix-woo-migrator'), $imported_count));
            }
        }
    }

    /**
     * Redirects the user to the next batch processing page via JavaScript.
     */
    private function redirect_to_next_batch()
    {
        $redirect_url = admin_url('tools.php?page=' . self::TOOL_SLUG);
        ?>
        <script type="text/javascript">
            // Use a short timeout to allow the browser to update the display
            setTimeout(function () {
                window.location.href = '<?php echo esc_url_raw($redirect_url); ?>';
            }, 200); // 200ms delay before triggering next batch
        </script>
        <?php
    }

    /**
     * Creates a simple WooCommerce product.
     * @param array $row The Wix CSV row data.
     * @param bool $import_images Whether to import images.
     * @param array $header_map Dynamic header map.
     * @return int|WP_Error The ID of the new product or WP_Error on failure.
     */
    private function create_simple_product($row, $import_images, $header_map)
    {
        $name = $this->get_column_data($row, $header_map, 'name');
        $handle_id = $this->get_column_data($row, $header_map, 'handleId');
        $original_sku = $this->get_column_data($row, $header_map, 'sku');
        $unique_sku = $this->get_unique_product_sku($original_sku, $handle_id);

        $product_post_id = wp_insert_post(array(
            'post_title' => sanitize_text_field($name),
            'post_content' => wp_kses_post($this->get_column_data($row, $header_map, 'description')),
            'post_status' => 'publish',
            'post_type' => 'product',
        ), true);

        if (is_wp_error($product_post_id)) {
            return $product_post_id;
        }

        // Set the product type to 'simple' (safest way for a new post)
        wp_set_object_terms($product_post_id, 'simple', 'product_type');

        $product = wc_get_product($product_post_id);
        if (!$product) {
            return new WP_Error('product_init_fail', __('Failed to initialize WooCommerce product object.', 'wix-woo-migrator'));
        }

        // Set common product data
        $this->set_common_product_data($product, $row, $header_map);

        // Simple product-specific metadata
        $product->set_regular_price($this->format_numeric_field($this->get_column_data($row, $header_map, 'price')));
        // CRITICAL FIX (v2.2): Use the guaranteed unique SKU
        $product->set_sku($unique_sku);

        $product->save();

        // Set HandleId (for idempotency check on future runs)
        $product->update_meta_data(self::WIX_HANDLE_META_KEY, sanitize_text_field($handle_id));
        $product->save_meta_data();


        if ($import_images) {
            $this->set_product_images($product_post_id, $this->get_column_data($row, $header_map, 'productImageUrl'));
        }

        return $product_post_id;
    }

    /**
     * Creates a variable WooCommerce product and its variations.
     * @param array $product_data Grouped parent and variation rows.
     * @param bool $import_images Whether to import images.
     * @param array $header_map Dynamic header map.
     * @return int|WP_Error The ID of the new product or WP_Error on failure.
     */
    private function create_variable_product($product_data, $import_images, $header_map)
    {
        $parent_row = $product_data['parent'];
        $variations_rows = $product_data['variations'];
        $name = $this->get_column_data($parent_row, $header_map, 'name');
        $handle_id = $this->get_column_data($parent_row, $header_map, 'handleId');

        // --- 1. Create Parent Product Post ---
        $product_post_id = wp_insert_post(array(
            'post_title' => sanitize_text_field($name),
            'post_content' => wp_kses_post($this->get_column_data($parent_row, $header_map, 'description')),
            'post_status' => 'publish',
            'post_type' => 'product',
        ), true);

        if (is_wp_error($product_post_id)) {
            return $product_post_id;
        }

        // CRITICAL FIX (v1.9): Explicitly set product type to 'variable' using taxonomy
        wp_set_object_terms($product_post_id, 'variable', 'product_type');

        $product = wc_get_product($product_post_id);
        if (!$product) {
            return new WP_Error('product_init_fail', __('Failed to initialize WooCommerce product object.', 'wix-woo-migrator'));
        }

        $this->set_common_product_data($product, $parent_row, $header_map);

        // Set HandleId (for idempotency check on future runs)
        $product->update_meta_data(self::WIX_HANDLE_META_KEY, sanitize_text_field($handle_id));
        $product->save_meta_data();

        // --- 2. Extract and Register Attributes on Parent ---
        $attributes_to_register = array();
        $product_attributes = array(); // WC_Product_Attribute array

        // Loop through all variation rows to find all possible attributes and values
        foreach ($variations_rows as $row) {
            // Check for up to 6 possible product option columns in Wix CSV
            for ($i = 1; $i <= 6; $i++) {
                $attr_name_key = 'productOptionName' . $i;
                $attr_desc_key = 'productOptionDescription' . $i;

                $attr_name = sanitize_text_field($this->get_column_data($row, $header_map, $attr_name_key));
                $attr_value = sanitize_text_field($this->get_column_data($row, $header_map, $attr_desc_key));

                // Crucial Fix (v1.8): Ensure both name and value are present and not empty
                if (!empty($attr_name) && !empty($attr_value)) {
                    $attr_name_clean = trim($attr_name);
                    $attr_value_clean = trim($attr_value);

                    // Initialize the attribute if not seen before
                    if (!isset($attributes_to_register[$attr_name_clean])) {
                        $attributes_to_register[$attr_name_clean] = array();
                    }
                    // Add the value to the list for this attribute (ensuring unique values)
                    $attributes_to_register[$attr_name_clean][$attr_value_clean] = $attr_value_clean;
                }
            }
        }

        if (empty($attributes_to_register)) {
            $this->add_error(sprintf(__('Could not find any attributes for variable product "%s".', 'wix-woo-migrator'), esc_html($name)));
            // Proceed without variations, it will be an empty variable product.
        }

        $position = 0;
        foreach ($attributes_to_register as $attr_name => $attr_values) {
            // WooCommerce requires a slug format (pa_attribute_name)
            $attr_slug = 'pa_' . sanitize_title($attr_name);

            $attribute = new WC_Product_Attribute();
            $attribute->set_name($attr_name);
            // All values for this attribute
            $attribute->set_options(array_keys($attr_values));
            $attribute->set_position($position++);
            $attribute->set_visible(true); // Visible on product page
            $attribute->set_variation(true); // Used for variations

            $product_attributes[$attr_slug] = $attribute;
        }

        $product->set_attributes($product_attributes);
        $product->save(); // Save the parent product with its attributes

        // CRITICAL FIX (v2.5): Explicitly assign terms to the parent product's taxonomies.
        // This links the parent product post to the attribute values it uses, making the UI render correctly.
        foreach ($attributes_to_register as $attr_name => $attr_values) {
            $tax_name = 'pa_' . sanitize_title($attr_name);

            // Prepare slugs for wp_set_object_terms
            $term_slugs = array();
            foreach (array_keys($attr_values) as $term_name) {
                $term_slugs[] = sanitize_title($term_name);
            }

            // Use the attribute slugs to set the terms on the product post
            wp_set_object_terms($product_post_id, $term_slugs, $tax_name);
        }

        // --- 3. Create Variations ---
        $this->create_product_variations($product_post_id, $variations_rows, $import_images, $header_map);

        if ($import_images) {
            // Use the parent's product image URL field
            $this->set_product_images($product_post_id, $this->get_column_data($parent_row, $header_map, 'productImageUrl'));
        }

        return $product_post_id;
    }

    /**
     * Sets common data for both simple and variable products.
     * @param WC_Product $product The WooCommerce product object.
     * @param array $row The Wix CSV row data.
     * @param array $header_map Dynamic header map.
     */
    private function set_common_product_data($product, $row, $header_map)
    {
        // Inventory Management
        $inventory = $this->get_column_data($row, $header_map, 'inventory');
        if (!empty($inventory)) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity((int) $inventory);
            $product->set_stock_status((int) $inventory > 0 ? 'instock' : 'outofstock');
        } else {
            $product->set_manage_stock(false);
            $product->set_stock_status('instock');
        }

        // Weight
        $weight = $this->format_numeric_field($this->get_column_data($row, $header_map, 'weight'));
        if (!empty($weight)) {
            $product->set_weight($weight);
        }

        // Note: The handleId meta key update is now handled outside this function 
        // in create_simple_product and create_variable_product for better save control.
    }

    /**
     * Creates all variations for a variable product.
     * @param int $parent_id The ID of the parent product.
     * @param array $variations_rows Array of Wix CSV rows for variations.
     * @param bool $import_images Whether to import images.
     * @param array $header_map Dynamic header map.
     */
    private function create_product_variations($parent_id, $variations_rows, $import_images, $header_map)
    {
        foreach ($variations_rows as $row) {
            $variation_post_id = wp_insert_post(array(
                'post_title' => 'Variation of #' . $parent_id,
                'post_name' => 'product-' . $parent_id . '-variation-' . $this->get_column_data($row, $header_map, 'sku'),
                'post_status' => 'publish',
                'post_parent' => $parent_id,
                'post_type' => 'product_variation',
                'menu_order' => 0,
            ));

            if (is_wp_error($variation_post_id)) {
                $this->add_error(sprintf(__('Failed to create variation for product #%d. Error: %s', 'wix-woo-migrator'), $parent_id, esc_html($variation_post_id->get_error_message())));
                continue;
            }

            $variation = wc_get_product($variation_post_id);

            $original_sku = $this->get_column_data($row, $header_map, 'sku');
            $handle_id = $this->get_column_data($row, $header_map, 'handleId');
            $unique_sku = $this->get_unique_product_sku($original_sku, $handle_id);

            // Set Price and SKU
            $variation->set_regular_price($this->format_numeric_field($this->get_column_data($row, $header_map, 'price')));
            // CRITICAL FIX (v2.2): Use the guaranteed unique SKU
            $variation->set_sku($unique_sku);

            // Set Inventory
            $inventory = $this->get_column_data($row, $header_map, 'inventory');
            if (!empty($inventory)) {
                $variation->set_manage_stock(true);
                $variation->set_stock_quantity((int) $inventory);
                $variation->set_stock_status((int) $inventory > 0 ? 'instock' : 'outofstock');
            } else {
                $variation->set_manage_stock(false);
                $variation->set_stock_status('instock');
            }

            // Set Weight
            $weight = $this->format_numeric_field($this->get_column_data($row, $header_map, 'weight'));
            if (!empty($weight)) {
                $variation->set_weight($weight);
            }

            // --- Set Attributes on Variation (Crucial Step) ---
            for ($i = 1; $i <= 6; $i++) {
                $attr_name = sanitize_text_field($this->get_column_data($row, $header_map, 'productOptionName' . $i));
                $attr_value = sanitize_text_field($this->get_column_data($row, $header_map, 'productOptionDescription' . $i));

                if (!empty($attr_name) && !empty($attr_value)) {
                    $attr_slug_key = 'attribute_pa_' . sanitize_title($attr_name);
                    // Set the meta for the variation's specific attribute value
                    update_post_meta($variation_post_id, $attr_slug_key, sanitize_title($attr_value));
                }
            }

            $variation->save();

            // Set Variation Image (if specified)
            if ($import_images) {
                $image_url = $this->get_column_data($row, $header_map, 'productImageUrl');
                if (!empty($image_url)) {
                    $this->set_variation_image($variation_post_id, $image_url);
                }
            }
        }
    }

    /**
     * Downloads and attaches images to the parent product (featured and gallery).
     * @param int $product_id The ID of the product.
     * @param string $image_urls_string Semicolon-separated string of image paths/URLs.
     */
    private function set_product_images($product_id, $image_urls_string)
    {
        if (!function_exists('media_sideload_image')) {
            $this->add_error(sprintf(__('Critical error: WordPress media sideload functions are missing. Cannot import images for product #%d.', 'wix-woo-migrator'), $product_id));
            return;
        }

        // Use a comma or semicolon as a delimiter for multiple images
        $delimiter = (strpos($image_urls_string, ';') !== false) ? ';' : ',';
        $image_urls = array_map('trim', explode($delimiter, $image_urls_string));
        $image_urls = array_filter($image_urls);

        if (empty($image_urls)) {
            return;
        }

        $gallery_ids = array();
        $is_featured = true;

        foreach ($image_urls as $url) {
            // NEW FIX (v1.4): Add Wix Media Base URL if the URL is not fully qualified (missing http/https).
            $cleaned_url = trim($url);
            if (strpos($cleaned_url, 'http') !== 0) {
                $cleaned_url = self::WIX_MEDIA_BASE_URL . $cleaned_url;
            }

            $cleaned_url = esc_url_raw($cleaned_url);
            if (empty($cleaned_url)) {
                continue;
            }

            // Download the image and insert it as an attachment
            // 'id' returns the attachment ID on success, or WP_Error on failure.
            $image_id = media_sideload_image($cleaned_url, $product_id, null, 'id');

            if (is_wp_error($image_id)) {
                $this->add_error(sprintf(__('Failed to import image for product #%d from URL: %s. Error: %s', 'wix-woo-migrator'), $product_id, esc_html($cleaned_url), esc_html($image_id->get_error_message())));
                continue;
            }

            if ($is_featured) {
                // Set the first image as the Featured Image
                set_post_thumbnail($product_id, $image_id);
                $is_featured = false;
            } else {
                // Add remaining images to the gallery
                $gallery_ids[] = $image_id;
            }
        }

        // Update product gallery metadata
        if (!empty($gallery_ids)) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
        }
    }

    /**
     * Downloads and attaches a single image to a variation.
     * @param int $variation_id The ID of the variation.
     * @param string $image_url The image path/URL.
     */
    private function set_variation_image($variation_id, $image_url)
    {
        $cleaned_url = trim($image_url);
        if (strpos($cleaned_url, 'http') !== 0) {
            $cleaned_url = self::WIX_MEDIA_BASE_URL . $cleaned_url;
        }
        $cleaned_url = esc_url_raw($cleaned_url);

        if (empty($cleaned_url)) {
            return;
        }

        // Download the image and insert it as an attachment
        $image_id = media_sideload_image($cleaned_url, $variation_id, null, 'id');

        if (is_wp_error($image_id)) {
            $this->add_error(sprintf(__('Failed to import image for variation #%d from URL: %s. Error: %s', 'wix-woo-migrator'), $variation_id, esc_html($cleaned_url), esc_html($image_id->get_error_message())));
            return;
        }

        // Set the variation image
        update_post_meta($variation_id, '_thumbnail_id', $image_id);
    }

    /**
     * Formats numeric fields (price, weight) to use a dot as a decimal separator.
     * Handles European formatting where a comma is used for the decimal.
     * @param string $value The raw numeric string.
     * @return string The formatted numeric string.
     */
    private function format_numeric_field($value)
    {
        $value = trim($value);
        if (empty($value)) {
            return '';
        }

        // Remove thousand separators (dots or spaces) and replace comma decimal with dot
        $value = str_replace(array('.', ' '), '', $value);
        $value = str_replace(',', '.', $value);

        // Ensure we only have numeric characters and a single dot
        if (is_numeric($value)) {
            return (string) floatval($value);
        }

        return '';
    }

}

new Wix_Woo_Migrator();