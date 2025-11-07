## 📦 Wix to WooCommerce Product Migration Plugin

Migrate your products from a **Wix product export CSV** file directly into **WooCommerce**, complete with support for simple products, variable products, images, and product attributes. This plugin features a batch processing system with pause and resume functionality to handle large migrations without hitting server limits.

---

## ✨ Features

- **CSV Upload & Parsing:** Upload your standard Wix product export CSV file directly from the WordPress admin.
- **Batch Processing:** Imports products in small batches (`5` by default) to prevent server timeouts and memory exhaustion.
- **Pause/Resume Migration:** Start, pause, and resume the migration process at any time.
- **Simple & Variable Products:** Automatically handles both **Simple Products** and **Variable Products** based on the CSV data.
- **Image Import:** Downloads and attaches product images (featured image and gallery) from Wix URLs to the WooCommerce product and variations.
- **Product Attributes:** Automatically creates WooCommerce product attributes and terms for variable product options.
- **Progress Tracking:** Real-time progress bar and statistics (Processed, Failed, Images Imported).
- **Error Logging:** Stores and displays a log of recent migration failures.
- **Easy Reset:** Option to completely reset the migration state and delete the uploaded CSV.

---

## 🛠️ Installation

### 1. Uploading the Plugin

1.  **Download** the plugin files (or clone the repository).
2.  **Zip** the `wix-woo-migrate` folder.
3.  Go to your WordPress **Dashboard** -> **Plugins** -> **Add New**.
4.  Click **Upload Plugin** and select the zipped file.
5.  Click **Install Now**.
6.  Click **Activate Plugin**.

### 2. File Structure

Ensure your plugin directory contains the following files:

```
wix-woo-migrate/
├── assets/
│ ├── style.css # Styles for the admin page
│ └── script.js # JavaScript for AJAX and UI interaction
└── wix-woo-migrate.php # The main plugin file
```

The `style.css` and `script.js` files are automatically generated and placed in the `assets` folder upon plugin activation by the `wix_woo_migration_create_assets` function.

---

## 🚀 How to Use

The migration tool is located under the WordPress **Tools** menu.

### 1. Access the Migration Tool

Navigate to **Tools** -> **Wix Migration** in your WordPress dashboard.

### 2. Prepare Your Wix CSV

Ensure you have your **Wix product export CSV** file. The plugin is designed to work with the standard Wix format:

- **Product rows** must have `fieldType` set to `"Product"`.
- **Variant rows** must have `fieldType` set to `"Variant"`.
- Product images are expected in the `productImageUrl` column, separated by semicolons (`;`).
- Product options (for variations) are read from `productOptionName1` through `productOptionName6`.

### 3. Upload the CSV

1.  In the **Upload Wix Product CSV** section, click **Choose File**.
2.  Select your Wix product export `.csv` file.
3.  Click the **Upload CSV** button.

The plugin will upload and parse the CSV, calculating the total number of products found. A success message will appear, and the page will refresh, moving you to the migration control view.

### 4. Start the Migration

1.  After a successful upload, the **Migration Progress** section will update with the total product count.
2.  Click the **Start Migration** button to begin the batch processing.

The plugin will use AJAX calls to process products in batches of 5 (default setting), updating the progress bar and counts in real-time.

### 5. Control the Process

- **Pause Migration:** Click **Pause Migration** at any time to temporarily stop the process. This saves the current state (index, processed count, errors), allowing you to resume later.
- **Resume Migration:** If the migration is paused, the button will change to **Resume Migration**. Click it to continue where you left off.
- **Reset Migration:** Click **Reset Migration** to delete the progress data and the uploaded CSV file. **This does NOT delete any products already created in WooCommerce.**

### 6. Monitor Progress and Errors

- **Migration Progress:** Tracks **Total Products**, **Processed**, **Failed**, and **Images Imported**.
- **Progress Bar:** Shows the percentage of products processed (successful + failed).
- **Migration Errors:** Any products that fail to import will be logged in the **Migration Errors** section, showing the product name and the error message.

---

## ⚙️ Configuration

The default batch size can be adjusted within the `wix-woo-migrate.php` file by changing the private variable `$batch_size` in the `Wix_WooCommerce_Migration` class:

```php
// wix-woo-migrate.php

class Wix_WooCommerce_Migration
{
    private $option_name = 'wix_woo_migration_state';
    private $batch_size = 5; // <-- Change this value for a different batch size
    // ...
}
```

**Note:** Lowering the batch size is recommended for servers with low PHP memory or execution limits. Increasing it can speed up the process on more powerful servers, but carries a higher risk of timeouts.

---

## 👨‍💻 Development

The plugin uses standard WordPress AJAX actions for all backend tasks:

| Action Hook                       | PHP Method            | Description                                                                           |
| :-------------------------------- | :-------------------- | :------------------------------------------------------------------------------------ |
| `wp_ajax_wix_woo_upload_csv`      | `handle_csv_upload()` | Handles file upload, parses the CSV into product groups, and saves the initial state. |
| `wp_ajax_wix_woo_start_migration` | `start_migration()`   | Changes the migration status to 'active' to begin batch processing.                   |
| `wp_ajax_wix_woo_process_batch`   | `process_batch()`     | Imports a batch of products (calls `import_wix_product`) and updates the state.       |
| `wp_ajax_wix_woo_stop_migration`  | `stop_migration()`    | Changes the migration status to 'paused'.                                             |
| `wp_ajax_wix_woo_reset_migration` | `reset_migration()`   | Deletes the CSV file and the migration state option.                                  |

### Core Logic

The core import logic is:

- **`parse_wix_csv()`**: Reads the uploaded CSV, detects the delimiter (tab or comma), groups product and variant rows by `handleId`, and returns an array of product groups.
- **`import_wix_product()`**: Determines if a product is Simple or Variable based on the presence of variants.
  - **Simple Product**: Creates a `WC_Product_Simple` object, sets properties (name, price, SKU, etc.), and calls `import_wix_images()`.
  - **Variable Product**: Calls `create_variable_product()`.
- **`create_variable_product()`**: Creates a `WC_Product_Variable`, extracts attributes, calls `create_woo_attributes()`, and then `create_wix_variations()`.
- **`import_wix_images()`**: Downloads images from `https://static.wixstatic.com/media/[filename]`, sideloads them to the WordPress Media Library, and sets the featured image and product gallery/variation image.

Built with PHP and WooCommerce.
