<?php

defined( 'ABSPATH' ) || exit;
define('DINKASSA_SE_API','https://www.dinkassa.se/api');
define('UTC2_OFFSET', 7200);
define('UPDATE_DELAY', 60);
if ( ! class_exists( 'WooCommerce_Background_Process' ) ) :
    class WooCommerce_Background_Process extends WP_Background_Process
    {
        protected $action = 'woocommerce_background_process';

        private bool $log_woocommerce_events;

        public function __construct()
        {
            parent::__construct();
            $this->log_woocommerce_events = get_option('log_wc_events') === 'yes';
        }

        /**
         * @var array $wc_product_id_map
         * @var int[] $crud_products
         */
        private array $wc_product_id_map; // Maps Dinkassa.se inventoryitem IDs to WooCommerce product IDs

        private array $crud_products; // Array of products (IDs) with pending CRUD operations

        /**
         * @inheritDoc
         * @throws Exception
         */
        protected function task($item)
        {
            // TODO: Implement task() method.

            // Update products and categories only if the synchronize
            // flag is true and a user is not logged in, unless WP_DEBUG
            // is true. We don't want the process and the user updating
            // the database simultaneously. The process will resume
            // updating when the user logs out.
            $do_update_products_and_categories = get_option('synchronize') === 'yes'
                                             && (get_option('user_logged_in') === 'no' || WP_DEBUG);
            if ($do_update_products_and_categories)
            {
                $this->update_wc_products_and_categories();
                sleep(UPDATE_DELAY);
                return $item;
            }
            return false;
        }

        /**
         * Sends a GET request to Dinkassa.se and returns an array of
         * inventoryitems/categories depending on the value of $controller.
         *
         * @var string $controller Dinkassa.se controller
         * @return array
         */
        private function get_json_data($controller)
        {
            $handle = null;
            $total_items_read = 0;
            $dinkassa_items = array();
            if ($controller === 'inventoryitem')
                $item_count = 200;
            else
                $item_count = 100;
            $headers = array(
                'MachineId: ' . get_option('machine_id'),
                'MachineKey: ' . get_option('machine_key'),
                'IntegratorId: ' . get_option('integrator_id'),
                'Accept: application/json'
            );
            $options = array(
                CURLOPT_CUSTOMREQUEST => "GET",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => is_ssl(),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30
            );
            $start_url = DINKASSA_SE_API . '/' . $controller . '?fetch=' . $item_count;
            $url = $start_url;
            while (true)
            {
                $handle = curl_init($url);
                if (curl_errno($handle) != 0)
                    break;
                else if (!curl_setopt_array($handle, $options))
                    break;
                else {
                    $response = curl_exec($handle);
                    if (curl_errno($handle) != 0)
                        break;
                    else {
                        $dinkassa_data = json_decode($response);
                        if (empty($dinkassa_data))
                            break;
                        else if ($dinkassa_data->{'ItemCountFetched'} == 0)
                            break;
                        else {
                            $dinkassa_items = array_merge($dinkassa_items, $dinkassa_data->{'Items'});
                            $total_items_read += $dinkassa_data->{'ItemCountFetched'};
                            if ($total_items_read >= $dinkassa_data->{'ItemCountTotal'})
                                break;
                            else {
                                $url = $start_url . '&offset=' . $total_items_read;
                                curl_close($handle);
                            }
                        }
                    }
                }
            }
            curl_close($handle);
            return $dinkassa_items;
        }

        /**
         * Returns an associative array of WooCommerce product categories indexed
         * by the Dinkassa.se Id of the category.
         *
         * @return WP_Term[] An array of WC_Term objects representing WooCommerce
         * product categories.
         */
        private function get_wc_category_list()
        {
            $category_list = array();
            $categories = get_terms(array(
                'taxonomy' => 'product_cat',
                'hide_empty' => false));
            if (! is_wp_error($categories)) {
                foreach ($categories as $category) {
                    $term_id = $category->term_id;
                    $category_id = get_term_meta($term_id, 'wh_meta_cat_id', true);
                    if (! empty($category_id))
                        $category_list[$category_id] = $category;
                }
            }
            return $category_list;
        }

        private function background_process_logger($message)
        {
            date_default_timezone_set('Europe/Stockholm');
            $date_time = date("Y-m-d H:i:s");
            $log_file = plugin_dir_path( __FILE__ ) . 'woocommerce-logger.txt';
            file_put_contents($log_file, __CLASS__ . $message . $date_time . "\r\n", FILE_APPEND);
        }

        /**
         * Initializes an associative array that maps Dinkassa.se inventoryitem IDs
         * to corresponding WooCommerce product IDs.
         */
        private function create_product_id_map()
        {
            global $wpdb;

            $meta_key = META_KEY_PREFIX . 'id';
            $rows = $wpdb->get_results("
                SELECT wc_product.product_id as product_id, post_meta.meta_value as dinkassa_id 
                FROM {$wpdb->prefix}wc_product_meta_lookup as wc_product
                JOIN {$wpdb->prefix}postmeta post_meta ON post_meta.post_id = wc_product.product_id 
                WHERE post_meta.meta_key = '{$meta_key}'
            ");
            $this->wc_product_id_map = array();
            $this->crud_products = array();
            foreach ($rows as $row)
            {
                $product_id = $row->product_id;
                $dinkassa_id = $row->dinkassa_id;
                if (empty($dinkassa_id))
                    $this->crud_products[] = $product_id;
                else {
                    $this->wc_product_id_map[$dinkassa_id] = $product_id;
                    if ($this->pending_crud_operations($product_id))
                        $this->crud_products[] = $product_id;
                }
            }
        }

        /**
         * Updates the name of an existing product category.
         *
         * @param string $old_name
         * @param string $new_name
         * @return mixed bool or WP_Error
         */
        private function wp_update_category_name($old_name, $new_name)
        {
            global $wpdb;

            // Check that the new name doesn't exist
            if( term_exists( $new_name, 'product_cat' ) )
                return new WP_Error("precondition", "New category name already exists");

            // Check that the old name exists
            if( ! term_exists( $old_name, 'product_cat' ) )
                return new WP_Error("precondition", "Old category name doesn't exist");

            $new_slug = sanitize_title( $new_name );
            return $wpdb->query("
                UPDATE {$wpdb->prefix}terms as a
                JOIN {$wpdb->prefix}term_taxonomy b ON a.term_id = b.term_id
                SET a.name = '$new_name',
                    a.slug = '$new_slug'
                WHERE b.taxonomy = 'product_cat'
                AND a.name = '$old_name'
            ");
        }

        /**
         * Creates/updates a product category and its custom fields. If the category
         * has a parent category the function will recursively create it first.
         * All WooCommerce product categories have 4 custom fields: AccountNumber,
         * OnlyAllowCategories, DefaultVatPercentage and CategoryId.
         *
         * @param string $category_id Dinkassa.se category ID
         * @param array $dinkassa_category_id_map
         * @param WP_Term[] $wc_categories
         * @return mixed array('term_id' => int, 'term_taxonomy_id' => int) or WP_Error
         */
        private function insert_wc_product_category($category_id, $dinkassa_category_id_map, $wc_categories)
        {
            if (empty($category_id))
                return array('term_id' => 0, 'term_taxonomy_id' => '');
            else {
                $category = $dinkassa_category_id_map[$category_id];
                $parent_category_id = $category['ParentCategoryId'];
                $category_name = $category['Name'];
                $parent_term = $this->insert_wc_product_category($parent_category_id, $dinkassa_category_id_map, $wc_categories);
                $category_term = term_exists($category_name, 'product_cat');
                $parent_term_id = (int)$parent_term['term_id'];
                $new_category = empty($wc_categories[$category_id]);
                $existing_category = ! empty($category_term);
                if ($existing_category)
                {
                    // Check if parent category in Dinkassa.se has changed.
                    // If it has, update parent of current category.
                    $term_id = $category_term['term_id'];
                    $term = get_term($term_id, 'product_cat');
                    if (empty($term) || is_wp_error($term))
                        return array('term_id' => 0, 'term_taxonomy_id' => '');
                    else if ($term->parent !== $parent_term_id) {
                        // Update parent category
                        $category_term = wp_update_term(
                            $term_id,
                            'product_cat',
                            array(
                                'slug' => sanitize_title($category_name),
                                'parent' => $parent_term_id
                            ));
                        if ($this->log_woocommerce_events) {
                            $this->log_category_update('category-updated', $category_name, $category_id);
                        }
                    }
                }
                else if ($new_category)
                {
                    // Create new WooCommerce product category
                    $category_term = wp_insert_term(
                        $category_name,
                        'product_cat',
                        array(
                            'slug' => sanitize_title($category_name),
                            'parent' => $parent_term_id
                        )
                    );
                    if ($this->log_woocommerce_events) {
                        $this->log_category_update('category-created', $category_name, $category_id);
                    }
                }
                else {
                    // The name of an existing category has been changed.
                    // Update the old category name.
                    $wc_category = $wc_categories[$category_id];
                    $old_category_name = $wc_category->name;
                    $result = $this->wp_update_category_name($old_category_name, $category_name);
                    if (! $result || is_wp_error($result))
                        return array('term_id' => 0, 'term_taxonomy_id' => ''); // Error
                    $category_term = term_exists($category_name, 'product_cat');
                    if ($this->log_woocommerce_events) {
                        $this->log_category_update('category-updated', $category_name, $category_id);
                    }
                }
                if (empty($category_term) || is_wp_error($category_term))
                    return array('term_id' => 0, 'term_taxonomy_id' => ''); // Error
                else {
                    $term_id = $category_term['term_id'];
                    if (!$this->is_pending_update_operation($term_id))
                    {
                        $account_number = $category['AccountNumber'];
                        $boolean_value = (bool)$category['OnlyAllowCategories'];
                        $default_vat_percentage = $category['DefaultVatPercentage'];
                        $only_allow_categories = $boolean_value ? 'on' : ''; // Checkbox value
                        $modified = false;
                        if ($this->log_woocommerce_events && ! $new_category)
                        {
                            // Check if any custom category fields have been updated in Dinkassa.se
                            $current_acc_nr = get_term_meta($term_id, 'wh_meta_account', true);
                            $current_only_cat = get_term_meta($term_id, 'wh_meta_only_cat', true);
                            $current_default_vat = get_term_meta($term_id, 'wh_meta_default_vat', true);
                            $current_datetime = get_term_meta($term_id, 'wh_meta_modified_datetime', true);
                            $modified = $account_number != $current_acc_nr
                                     || $only_allow_categories != $current_only_cat
                                     || $default_vat_percentage != $current_default_vat;
                            $wc_category_modified_timestamp = strtotime($current_datetime) + UTC2_OFFSET;
                            $dinkassa_category_modified_timestamp = strtotime($category['LastModifiedDateTime']);
                            if ($modified && $dinkassa_category_modified_timestamp >= $wc_category_modified_timestamp)
                            {
                                $this->log_category_update('category-updated', $category_name, $category_id);
                                $log_file = plugin_dir_path( __FILE__ ) . 'cat_modified.txt';
                                $data = $dinkassa_category_modified_timestamp . '    ' . $wc_category_modified_timestamp;
                                file_put_contents($log_file, $data . "\r\n", FILE_APPEND);
                            }
                        }
                        // Add/update custom category fields
                        if ($modified || $new_category) {
                            $current_datetime = date('Y-m-d H:i:s');
                            update_term_meta($term_id, 'wh_meta_account', $account_number);
                            update_term_meta($term_id, 'wh_meta_only_cat', $only_allow_categories);
                            update_term_meta($term_id, 'wh_meta_default_vat', $default_vat_percentage);
                            update_term_meta($term_id, 'wh_meta_modified_datetime', $current_datetime);
                        }
                        if ($new_category) {
                            add_term_meta($term_id, 'wh_meta_cat_id', $category_id);
                            add_term_meta($term_id, 'wh_meta_pending_crud', 0);
                        }
                    }
                    return $category_term;
                }
            }
        }

        private function log_category_update($event, $category_name, $category_id)
        {
            $category_info = array();
            $category_info['Id'] = $category_id;
            $category_info['Name'] = $category_name;
            $category_info = array('Item' => $category_info);
            woocommerce_event_logger($event, 200, $category_info, true);
        }

        /**
         * Returns true if a category is pending an update/create operation,
         * false otherwise.
         *
         * @param int $term_id
         * @return bool
         */
        private function is_pending_update_operation($term_id)
        {
            $meta_key = 'wh_meta_pending_crud';
            $pending_crud = (int)get_term_meta($term_id, $meta_key, true);
            return ($pending_crud & 0x2) != 0;
        }

        /**
         * Removes all WooCommerce product categories not found in Dinkassa categories
         * from the WP database. Checks if every category in the list of WooCommerce
         * product categories is found in the list of Dinkassa.se product categories.
         * If it isn't, then it is removed from the WP database.
         *
         * @param array $dinkassa_categories Contains all product categories in Dinkassa.se
         * @param WP_Term[] $wc_categories Contains all WooCommerce product categories.
         */
        private function delete_wc_categories($dinkassa_categories, $wc_categories)
        {
            // Remove the action that is triggered when a product is deleted. It is
            // only supposed to be executed when an administrator is deleting a
            // product manually from the product admin page.
            remove_action('pre_delete_term', 'delete_dinkassa_product_category');
            foreach ($wc_categories as $category_id => $wc_category)
            {
                if (empty($dinkassa_categories[$category_id]))
                {
                    // This category has been removed from Dinkassa.se
                    // and should also be removed from the WP database.
                    $term_id = $wc_category->term_id;
                    if ($this->log_woocommerce_events) {
                        $this->log_category_update('category-deleted', $wc_category->name, $category_id);
                    }
                    delete_term_meta($term_id, 'wh_meta_cat_id');
                    delete_term_meta($term_id, 'wh_meta_account');
                    delete_term_meta($term_id, 'wh_meta_only_cat');
                    delete_term_meta($term_id, 'wh_meta_default_vat');
                    delete_term_meta($term_id, 'wh_meta_pending_crud');
                    delete_term_meta($term_id, 'wh_meta_modified_datetime');
                    wp_delete_term($term_id, 'product_cat');
                }
            }
        }

        /**
         * Removes all WooCommerce products not found in Dinkassa.se. Any Dinkassa.se
         * product that has been deleted must also be removed from the WP database.
         *
         * @param array $wc_products
         * @return mixed
         */
        private function delete_wc_products($wc_products)
        {
            // Any products still remaining in $wc_products have been removed from
            // Dinkassa.se. These products will be removed from the WP database.

            if (count($wc_products) > 0) {
                // Remove action 'before_delete_post'. It's only supposed to be
                // triggered when products are deleted manually from product admin.

                remove_action('before_delete_post', 'delete_dinkassa_product');
                foreach ($wc_products as $key => $product_id) {
                    $wc_product = wc_get_product($product_id);
                    if (empty($wc_product)) {
                        $error_message = sprintf('No product with ID %d exists', $product_id);
                        return new WP_Error('function failed', $error_message);
                    }
                    else {
                        // Delete custom product fields before deleting product.
                        $product_info = get_product_info($product_id);
                        delete_custom_product_fields($product_id);
                        if ($wc_product->delete(true))
                        {
                            if ($this->log_woocommerce_events) {
                                woocommerce_event_logger('product-deleted', 200, $product_info, true);
                            }
                        }
                        else {
                            $error_message = sprintf("Failed to delete product with ID %d", $product_id);
                            return new WP_Error('function failed', $error_message);
                        }
                    }
                }
            }
            return true;
        }

        /**
         * Creates an associative array of CategoryId => (CategoryName, ParentCategoryId, ...)
         * from a json encoded list of categories read from DinKassa.se.
         *
         * @return array Array(CategoryId => array(Name, ParentCategoryId, OnlyAllowCategories, DefaultVatPercentage))
         */
        private function get_dinkassa_categories()
        {
            $dinkassa_category_id_map = array();
            $dinkassa_categories = $this->get_json_data('category');
            if (! empty($dinkassa_categories)) {
                foreach ($dinkassa_categories as $dinkassa_category) {
                    $category_id = $dinkassa_category->{'Id'};
                    if (!empty($category_id)) {
                        $dinkassa_category_id_map[$category_id] = array(
                            'Name' => $dinkassa_category->{'Name'},
                            'ParentCategoryId' => $dinkassa_category->{'ParentCategoryId'},
                            'AccountNumber' => $dinkassa_category->{'AccountNumber'},
                            'OnlyAllowCategories' => $dinkassa_category->{'OnlyAllowCategories'},
                            'DefaultVatPercentage' => $dinkassa_category->{'DefaultVatPercentage'},
                            'LastModifiedDateTime' => $dinkassa_category->{'LastModifiedDateTime'}
                        );
                    }
                }
            }
            return $dinkassa_category_id_map;
        }

        /**
         * @param int $category_id Term_id of main category
         * @param array $dinkassa_category_ids
         * @param array $extra_category_ids
         * @return int[] Returns an array of category term_ids
         */
        private function get_wc_category_ids($category_id, $dinkassa_category_ids, $extra_category_ids)
        {
            $category_ids = array($category_id);
            foreach ($extra_category_ids as $category_id => $category)
            {
                if (array_key_exists($category_id, $dinkassa_category_ids))
                {
                    $category_name = $category['Name'];
                    $term = term_exists($category_name, 'product_cat');
                    if (! empty($term))
                    {
                        $category_ids[] = (int)$term['term_id'];
                    }
                }
            }
            return $category_ids;
        }

        /**
         * Checks if there are any pending CRUD operations, such as updates,
         * creations, deletions or changes in stock quantity. If there are any,
         * these will be processed by sending the appropriate asynchronous
         * HTTP-requests to Dinkassa.se.
         *
         * @param int[] $wc_product_ids
         */
        private function process_pending_crud_operations($wc_product_ids)
        {
            $meta_key = META_KEY_PREFIX . 'pending_crud';
            foreach ($wc_product_ids as $wc_product_id)
            {
                /**
                 * @var WC_Product_Simple $wc_product
                 * @var WC_Deleted_Item[] $deleted_items
                 */
                $wc_product = wc_get_product($wc_product_id);
                $pending_crud_operation = (int)get_post_meta($wc_product_id, $meta_key, true);
                $pending_product_update = ($pending_crud_operation & 0x3) != 0;
                $pending_stock_quantity_update = ($pending_crud_operation & 0x4) != 0;
                if ($pending_product_update)
                    update_dinkassa_product($wc_product_id, $wc_product);
                if ($pending_stock_quantity_update)
                    $this->update_dinkassa_product_stock_quantity($wc_product_id, $wc_product);
            }
            $term_id = get_deleted_item_term_id();
            $deleted_items = get_term_meta($term_id, 'meta_deleted_item');
            foreach ($deleted_items as $item) {
                // Resend request to delete product/category
                if ($item->type == 'product') {
                    $controller = "inventoryitem";
                    $event = "product-deleted";
                }
                else {
                    $controller = "category";
                    $event = "category-deleted";
                }
                create_async_http_request(
                    "DELETE",
                    null,
                    $controller,
                    null,
                    $item->dinkassa_id,
                    0,
                    $event);
            }
            // Loop through all categories and check if there any pending
            // operations.
            $terms = get_terms(array(
                'taxonomy' => 'product_cat',
                'hide_empty' => false
            ));
            if (! is_wp_error($terms))
            {
                $meta_key = 'wh_meta_pending_crud';
                foreach ($terms as $category)
                {
                    $term_id = $category->term_id;
                    $pending_crud_operation = (int)get_term_meta($term_id, $meta_key, true);
                    if ($pending_crud_operation & 0x1)
                        create_dinkassa_product_category($term_id);
                    else if ($pending_crud_operation & 0x2)
                        update_dinkassa_product_category($term_id);
                }
            }
        }

        /**
         * Sends an asynchronous HTTP-request to change a product's stock quantity in Dinkassa.se.
         *
         * @param int $product_id
         * @param WC_Product_Simple $wc_product
         */
        private function update_dinkassa_product_stock_quantity($product_id, $wc_product)
        {
            global $wp_lock;

            $wp_lock->acquire();
            $meta_key = META_KEY_PREFIX . 'quantity_change';
            $quantity_change = (int)get_post_meta($product_id, $meta_key, true);
            $new_quantity = $wc_product->get_stock_quantity('edit');
            $current_quantity = $new_quantity + $quantity_change;
            $form_data = sprintf('quantityChange=%d&currentQuantity=%d&newQuantity=%d',
                $quantity_change,
                $current_quantity,
                $new_quantity);
            $opt_headers = array(
                'Content-Type: application/x-www-form-urlencoded',
                'Content-Length: ' . strlen($form_data),
                'Accept: application/json'
            );
            $meta_key = META_KEY_PREFIX . 'id';
            $dinkassa_id = get_post_meta($product_id, $meta_key, true);
            if (! empty($dinkassa_id)) {
                create_async_http_request(
                    "POST",
                    $form_data,
                    "inventoryitem",
                    $opt_headers,
                    $dinkassa_id,
                    $product_id,
                    "stock-quantity-updated");
            }
        }

        /**
         * Reads all products and categories from Dinkassa.se and creates/updates
         * corresponding WooCommerce products and categories and their respective
         * custom fields. Products and categories removed from Dinkassa.se will
         * also be removed from the WP database.
         * @throws Exception
         */
        private function update_wc_products_and_categories()
        {
            $this->create_product_id_map();
            $dinkassa_categories = $this->get_dinkassa_categories();
            $woocommerce_categories = $this->get_wc_category_list();

            // Remove the actions 'created_product_cat' and 'woocommerce_update_product'
            // to prevent them from being triggered. These actions are triggered auto-
            // matically when a WooCommerce product is created and updated respectively,
            // which is not what you want here. These actions are supposed to be executed
            // only when a user creates or updates a product from the WooCommerce admin page.
            remove_action('created_product_cat', 'create_dinkassa_product_category');
            remove_action('woocommerce_update_product', 'update_dinkassa_product');

            // Create and update product categories
            foreach ($dinkassa_categories as $category_id => $category)
            {
                $this->insert_wc_product_category($category_id, $dinkassa_categories, $woocommerce_categories);
            }
            $dinkassa_products = $this->get_json_data('inventoryitem');
            if (! empty($dinkassa_products)) {
                foreach ($dinkassa_products as $dinkassa_product) {
                    $id = $dinkassa_product->{'Id'};
                    $category_name = $dinkassa_product->{'CategoryName'};
                    $description = $dinkassa_product->{'Description'};
                    $bar_code = $dinkassa_product->{'BarCode'};
                    $bar_code2 = $dinkassa_product->{'BarCode2'};
                    $bar_code3 = $dinkassa_product->{'BarCode3'};
                    $bar_code4 = $dinkassa_product->{'BarCode4'};
                    $bar_code5 = $dinkassa_product->{'BarCode5'};
                    $product_code = $dinkassa_product->{'ProductCode'};
                    $external_product_code = $dinkassa_product->{'ExternalProductCode'};
                    $price_including_vat = $dinkassa_product->{'PriceIncludingVat'};
                    $pickup_price_including_vat = $dinkassa_product->{'PickupPriceIncludingVat'};
                    $vat_percentage = $dinkassa_product->{'VatPercentage'};
                    $creation_date_time = $dinkassa_product->{'CreatedDateTime'};
                    $quantity_in_stock_current = (int)$dinkassa_product->{'QuantityInStockCurrent'};
                    $extra_category_ids = $dinkassa_product->{'ExtraCategoryIds'};
                    $supplier_name = $dinkassa_product->{'SupplierName'};
                    $sorting_weight = (int)$dinkassa_product->{'SortingWeight'};
                    $last_modified_date = $dinkassa_product->{'LastModifiedDateTime'};
                    $visible_on_sales_menu = $dinkassa_product->{'VisibleOnSalesMenu'};
                    $visibility = empty($visible_on_sales_menu)? 'hidden' : 'visible';
                    $stock_status = $quantity_in_stock_current > 0 ? 'instock' : 'outofstock';
                    if (empty($vat_percentage))
                    {
                        // Set VAT % to default VAT % of product category
                        $term = term_exists($category_name, 'product_cat');
                        if (! $term)
                            $vat_percentage = 0;
                        else {
                            $term_id = $term['term_id'];
                            $vat_percentage = get_term_meta($term_id, 'wh_meta_default_vat', true);
                        }
                    }
                    $term = term_exists($category_name, 'product_cat');
                    if (empty($term))
                        continue; // Error
                    $category_id = $term['term_id'];
                    if (empty($product_code) && !empty($external_product_code))
                        $product_code = $external_product_code;
                    $custom_field_data = array(
                        'barcode' => $bar_code,
                        'barcode2' => $bar_code2,
                        'barcode3' => $bar_code3,
                        'barcode4' => $bar_code4,
                        'barcode5' => $bar_code5,
                        'productcode' => $product_code,
                        'description' => $description,
                        'pickuppriceincludingvat' => $pickup_price_including_vat,
                        'suppliername' => $supplier_name,
                        'categoryname' => $category_name,
                        'vatpercentage' => $vat_percentage,
                        'current_cat_term_id' => $category_id
                    );
                    if (empty($this->wc_product_id_map[$id])) {
                        // New product. Create and save it in the database
                        $custom_field_data['id'] = $id;
                        $custom_field_data['pending_crud'] = 0;
                        $custom_field_data['quantity_change'] = 0;
                        $wc_category_ids = $this->get_wc_category_ids($category_id, $dinkassa_categories, $extra_category_ids);
                        $wc_product = new WC_Product_Simple();
                        $wc_product->set_name($description);
                        $wc_product->set_status('publish');
                        $wc_product->set_price($price_including_vat);
                        $wc_product->set_sale_price($price_including_vat);
                        $wc_product->set_regular_price($price_including_vat);
                        $wc_product->set_description($description);
                        $wc_product->set_short_description($description);
                        $wc_product->set_featured(true);
                        $wc_product->set_menu_order($sorting_weight);
                        $wc_product->set_manage_stock(true);
                        $wc_product->set_stock_quantity($quantity_in_stock_current);
                        $wc_product->set_stock_status($stock_status);
                        $wc_product->set_reviews_allowed(true);
                        $wc_product->set_backorders('no');
                        $wc_product->set_date_created($creation_date_time);
                        $wc_product->set_category_ids($wc_category_ids);
                        $wc_product->set_date_modified($last_modified_date);
                        try {
                            $wc_product->set_catalog_visibility($visibility);
                        } catch (WC_Data_Exception $e) {
                        }
                        $product_id = $wc_product->save();
                        $this->update_custom_product_fields($product_id, $custom_field_data);
                        if ($this->log_woocommerce_events) {
                            $product_info = get_product_info($product_id);
                            woocommerce_event_logger('product-created', 200, $product_info, true);
                        }
                    }
                    else {
                        // Existing product. Update product properties and custom fields.
                        // Don't update products with pending CRUD operations, otherwise
                        // the changes will be overwritten.

                        $product_id = $this->wc_product_id_map[$id];
                        if (! $this->pending_crud_operations($product_id))
                        {
                            $wc_product = wc_get_product($product_id);
                            // Check if any product properties/custom fields have been updated in Dinkassa.se
                            $modified = $wc_product->get_description() != $description
                                     || $wc_product->get_price() != $price_including_vat
                                     || $wc_product->get_stock_quantity() != $quantity_in_stock_current
                                     || $wc_product->get_catalog_visibility() != $visibility;
                            if (! $modified)
                            {
                                foreach ($custom_field_data as $field_name => $value)
                                {
                                    $meta_key = META_KEY_PREFIX . $field_name;
                                    $field_value = get_post_meta($product_id, $meta_key, true);
                                    if ($custom_field_data[$field_name] != $field_value)
                                    {
                                        $modified = true;
                                        break;
                                    }
                                }
                            }
                            if ($this->log_woocommerce_events && $modified)
                            {
                                // Need to add offset (+2h) to the time stamp because WooCommerce
                                // doesn't set the correct time zone for datetime objects.
                                $wc_last_modified_datetime = $wc_product->get_date_modified();
                                $wc_last_modified_timestamp = $wc_last_modified_datetime->getTimestamp() + UTC2_OFFSET;
                                $dinkassa_last_modified_timestamp = strtotime($last_modified_date);
                                if ($dinkassa_last_modified_timestamp >= $wc_last_modified_timestamp) {
                                    $product_info = get_product_info($product_id);
                                    if ($wc_product->get_stock_quantity() != $quantity_in_stock_current)
                                        woocommerce_event_logger('stock-quantity-updated', 200, $product_info, true);
                                    else
                                        woocommerce_event_logger('product-updated', 200, $product_info, true);
                                }
                            }
                            if ($modified) {
                                $wc_product->set_name($description);
                                $wc_product->set_price($price_including_vat);
                                $wc_product->set_stock_status($stock_status);
                                $wc_product->set_sale_price($price_including_vat);
                                $wc_product->set_regular_price($price_including_vat);
                                $wc_product->set_description($description);
                                $wc_product->set_short_description($description);
                                $wc_product->set_stock_quantity($quantity_in_stock_current);
                                try {
                                    $wc_product->set_catalog_visibility($visibility);
                                } catch (WC_Data_Exception $e) {
                                }
                                $wc_product->save();
                                $this->update_custom_product_fields($product_id, $custom_field_data);
                            }
                        }
                        // Remove product with ID = $id from $wc_product_id_map. The products
                        // that remain after the loop is finished are products that have been
                        // deleted from Dinkassa.se. These will be deleted from the WP database.
                        unset($this->wc_product_id_map[$id]);
                    }
                }
                // Remove corresponding WooCommerce products and categories, if any,
                // that have been removed from Dinkassa.se. Process any pending CRUD
                // operations for products and categories.
                $this->delete_wc_products($this->wc_product_id_map);
                $this->delete_wc_categories($dinkassa_categories, $woocommerce_categories);
                $this->process_pending_crud_operations($this->crud_products);
            }
            return true;
        }

        /**
         * Creates/updates custom fields of a product. For new products, two
         * extra fields are added, 'pending_crud' and 'quantity_change', that
         * are used to keep track of pending CRUD operations and change in
         * stock quantity in case Dinkassa.se isn't responding or synchronization
         * is turned off.
         *
         * @param int $product_id
         * @param array $custom_product_fields An array(field_name => field_value)
         */
        private function update_custom_product_fields($product_id, $custom_product_fields)
        {
            foreach ($custom_product_fields as $field_name => $value)
            {
                $meta_key = META_KEY_PREFIX . $field_name;
                update_post_meta($product_id, $meta_key, $value);
            }
        }

        /**
         * Returns true if a product has any pending CRUD operations, false otherwise.
         *
         * @param int $product_id
         * @return bool
         */
        private function pending_crud_operations($product_id)
        {
            $meta_key = META_KEY_PREFIX . 'pending_crud';
            $pending_crud_operations = (int)get_post_meta($product_id, $meta_key, true);
            return ($pending_crud_operations & 0x7) != 0;
        }

        /**
         * Complete
         *
         * Override if applicable, but ensure that the below actions are
         * performed, or, call parent::complete().
         */
        protected function complete() {
            parent::complete();
            // Show notice to user or perform some other arbitrary task...
        }
    }
endif;