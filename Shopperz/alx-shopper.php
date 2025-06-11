<?php
/*
 * Plugin Name: Oasis ODL Finder
 * Plugin URI: https://www.oasis-odl.co.uk
 * Description: A customizable product search plugin for WooCommerce, featuring user-friendly front-end dropdowns and analytics capabilities.
 * Version: 2
 * Author: Lee Hopewell
 * Author URI: www.leehopewell.co.uk
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'ALX_SHOPPER_VERSION', '1.0' );
define( 'ALX_SHOPPER_DIR', plugin_dir_path( __FILE__ ) );
define( 'ALX_SHOPPER_URL', plugin_dir_url( __FILE__ ) );

// Include necessary files
require_once ALX_SHOPPER_DIR . 'includes/class-alx-shopper-frontend.php';
require_once ALX_SHOPPER_DIR . 'includes/class-alx-shopper-analytics.php';
require_once ALX_SHOPPER_DIR . 'includes/class-alx-shopper-search.php';
new Alx_Shopper_Search();

add_action('wp_ajax_alxshopper_log_event', 'alxshopper_log_event');
add_action('wp_ajax_nopriv_alxshopper_log_event', 'alxshopper_log_event');

// --- Main and Email Analytics Table Creation ---
function alxshopper_create_analytics_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'alxshopper_analytics';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        event_type VARCHAR(32) NOT NULL,
        event_data LONGTEXT,
        user_ip VARCHAR(64),
        user_location VARCHAR(255),
        referrer VARCHAR(255),
        device VARCHAR(128),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
function alxshopper_create_email_analytics_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'alxshopper_email_analytics';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        email_address VARCHAR(255) NOT NULL,
        filter_title VARCHAR(255),
        search_query TEXT,
        quickviews TEXT,
        product_views TEXT,
        user_ip VARCHAR(64),
        user_location VARCHAR(255),
        consent_for_marketing TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'alxshopper_create_analytics_table');
register_activation_hook(__FILE__, 'alxshopper_create_email_analytics_table');

// --- Export Page ---
function shopperz_analytics_export_page() {
    if (isset($_GET['download_csv'])) {
        if (ob_get_length()) ob_end_clean();

        global $wpdb;
        $table = $wpdb->prefix . 'alxshopper_analytics';

        // Date range filter
        $where = [];
        $params = [];
        if (!empty($_GET['from_date'])) {
            $where[] = "created_at >= %s";
            $params[] = $_GET['from_date'] . " 00:00:00";
        }
        if (!empty($_GET['to_date'])) {
            $where[] = "created_at <= %s";
            $params[] = $_GET['to_date'] . " 23:59:59";
        }
        $where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

        // Get ALL events in range
        $sql = "SELECT event_type, event_data, user_ip, user_location, referrer, device, created_at FROM $table $where_sql ORDER BY created_at DESC";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        // For mapping filter_id to human name
        $filter_titles = [];
        $filters = get_posts([
            'post_type' => 'alx_shopper_filter',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);
        foreach ($filters as $filter) {
            $filter_titles[$filter->post_name] = $filter->post_title;
        }

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="alxshopper-analytics.csv"');
        $out = fopen('php://output', 'w');

        // CSV header (to match JS)
        $header = ['Date','Action','Product','Price','Query','Filters','IP','Location','Referrer','Device'];
        fputcsv($out, $header);

        foreach ($rows as $row) {
            $data = $row['event_data'];
            // Try to unserialize or decode JSON
            if (is_string($data) && strpos($data, '{') === 0) {
                $data = json_decode($data, true);
            } elseif (is_string($data) && strpos($data, 'a:') === 0) {
                $data = @unserialize($data);
            }
            if (!is_array($data)) $data = [];

            $actionLabels = [
                'product_view' => 'Product Card Click',
                'view_product_btn' => 'Product Card Click',
                'quick_view' => 'Quick View Button',
                'modal_view_product' => 'Modal View Product',
                'add_to_cart' => 'Add to Cart',
                'search' => 'Search'
            ];
            $action = isset($actionLabels[$row['event_type']]) ? $actionLabels[$row['event_type']] : ucfirst(str_replace('_', ' ', $row['event_type']));

            $product = isset($data['title']) ? $data['title'] : (isset($data['product_id']) ? $data['product_id'] : '');
            $price = isset($data['price']) ? $data['price'] : '';
            $query = isset($data['query']) ? $data['query'] : (isset($data['search_terms']) ? $data['search_terms'] : '');

            $filters_col = '';
            if (!empty($data['filters']) && is_array($data['filters'])) {
                $filters = [];
                if (!empty($data['filters']['alx_filter_id'])) {
                    $filter_id = $data['filters']['alx_filter_id'];
                    $filter_name = isset($filter_titles[$filter_id]) ? $filter_titles[$filter_id] : $filter_id;
                    $filters[] = "Filter id: $filter_name";
                }
                foreach ($data['filters'] as $k => $v) {
                    if (preg_match('/^alx_dropdown_(\d+)$/', $k, $m)) {
                        $idx = $m[1];
                        $label = isset($data['filters']["alx_dropdown_{$idx}_label"]) ? $data['filters']["alx_dropdown_{$idx}_label"] : '';
                        if ($v !== 'any' && $v !== '') {
                            $valDisplay = (is_string($v) && !is_numeric($v))
                                ? ucwords(str_replace(['-', '_'], ' ', $v))
                                : $v;
                            $filters[] = ($label ? "$label: " : '') . $valDisplay;
                        }
                    }
                }
                $filters_col = implode("\n", $filters);
            }

            $ip = isset($row['user_ip']) ? $row['user_ip'] : '';
            $location = isset($row['user_location']) ? $row['user_location'] : '';
            $referrer = isset($row['referrer']) ? $row['referrer'] : '';
            $device = isset($row['device']) ? $row['device'] : '';
            $datetime = isset($row['created_at']) ? $row['created_at'] : '';

            fputcsv($out, [
                $datetime,
                $action,
                $product,
                $price,
                $query,
                $filters_col,
                $ip,
                $location,
                $referrer,
                $device
            ]);
        }
        fclose($out);
        exit;
    }
    // --- Email Analytics Export ---
    if (isset($_GET['download_email_csv'])) {
        if (ob_get_length()) ob_end_clean();

        global $wpdb;
        $table = $wpdb->prefix . 'alxshopper_email_analytics';

        $where = [];
        $params = [];
        if (!empty($_GET['from_date'])) {
            $where[] = "created_at >= %s";
            $params[] = $_GET['from_date'] . " 00:00:00";
        }
        if (!empty($_GET['to_date'])) {
            $where[] = "created_at <= %s";
            $params[] = $_GET['to_date'] . " 23:59:59";
        }
        $where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

        $sql = "SELECT * FROM $table $where_sql ORDER BY created_at DESC";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="alxshopper-email-analytics.csv"');
        $out = fopen('php://output', 'w');
        $header = ['Date','Email','Filter Title','Search Query','Quickviews','Product Views','IP','Location','Marketing Consent'];
        fputcsv($out, $header);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['created_at'],
                $row['email_address'],
                $row['filter_title'],
                $row['search_query'],
                $row['quickviews'],
                $row['product_views'],
                $row['user_ip'],
                $row['user_location'],
                $row['consent_for_marketing'] ? 'Yes' : 'No'
            ]);
        }
        fclose($out);
        exit;
    }
    ?>
    <div class="wrap">
        <h1>ODL Analytics Export</h1>
        <form method="get" action="">
            <input type="hidden" name="page" value="shopperz-analytics-export" />
            <input type="hidden" name="download_csv" value="1" />
            <label for="from_date">From:</label>
            <input type="date" id="from_date" name="from_date" value="<?php echo esc_attr($_GET['from_date'] ?? ''); ?>" />
            <label for="to_date">To:</label>
            <input type="date" id="to_date" name="to_date" value="<?php echo esc_attr($_GET['to_date'] ?? ''); ?>" />
            <button type="submit" class="button button-primary">Download Analytics CSV</button>
        </form>
        <form method="get" action="" style="margin-top:20px;">
            <input type="hidden" name="page" value="shopperz-analytics-export" />
            <input type="hidden" name="download_email_csv" value="1" />
            <label for="from_date_email">From:</label>
            <input type="date" id="from_date_email" name="from_date" value="<?php echo esc_attr($_GET['from_date'] ?? ''); ?>" />
            <label for="to_date_email">To:</label>
            <input type="date" id="to_date_email" name="to_date" value="<?php echo esc_attr($_GET['to_date'] ?? ''); ?>" />
            <button type="submit" class="button button-primary">Download Email Analytics CSV</button>
        </form>
    </div>
    <?php
}

add_action('admin_menu', 'alx_shopper_add_admin_menu');
function alx_shopper_add_admin_menu() {
    add_menu_page(
        'Oasis ODL Finder',
        'Oasis ODL Finder',
        'manage_options',
        'alx-shopper',
        'alx_shopper_dashboard_page',
        'data:image/svg+xml;base64,' . base64_encode('
            <svg width="20" height="20" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                <path d="M2 10 Q5 2 10 10 T18 10 Q15 18 10 10 T2 10 Z" fill="#FF9800"/>
            </svg>
        '),
        2
    );
    add_submenu_page(
        'alx-shopper',
        'Analytics',
        'Analytics',
        'manage_options',
        'alxshopper-analytics',
        'alxshopper_analytics_page'
    );
    add_submenu_page(
        'alx-shopper',
        'Analytics Export',
        'Analytics Export',
        'manage_options',
        'shopperz-analytics-export',
        'shopperz_analytics_export_page'
    );
    add_submenu_page(
        'alx-shopper',
        'Email Analytics',
        'Email Analytics',
        'manage_options',
        'alxshopper-email-analytics',
        'alxshopper_email_analytics_page'
    );
}

function alx_shopper_dashboard_page() {
    echo '<div class="wrap"><h1><a href="https://www.oasis-odl.co.uk" target="_blank" style="text-decoration:none;">Oasis ODL Finder</a> Dashboard</h1><p>Welcome to the Oasis ODL Finder plugin!</p></div>';
}

// Settings page callback
function alx_shopper_settings_page() {
    // Get all options
    $num = intval(get_option('alx_shopper_num_dropdowns', 2));
    $titles = get_option('alx_shopper_dropdown_titles', []);
    $mapping = get_option('alx_shopper_dropdown_attributes', []);
    $values = get_option('alx_shopper_dropdown_values', []);
    $orders = get_option('alx_shopper_dropdown_value_order', []);
    $attribute_taxonomies = function_exists('wc_get_attribute_taxonomies') ? wc_get_attribute_taxonomies() : [];

    ?>
    <div class="wrap">
        <h1>A[LEE]X Shopper Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('alx_shopper_settings_group'); ?>
            <table class="form-table">
                
                <tr>
                    <th scope="row">Product Categories for Search</th>
                    <td>
                        <?php alx_shopper_categories_callback(); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Number of Dropdowns (2-5)</th>
                    <td>
                        <input type="number" min="2" max="5" name="alx_shopper_num_dropdowns" value="<?php echo esc_attr($num); ?>" />
                        <p class="description">Change this to instantly update the number of dropdowns below.</p>
                    </td>
                </tr>
            </table>
            <hr>
            <h2>Dropdown Filters</h2>
            <?php
            // Always render 5, hide extra with JS
            for ($i = 0; $i < 5; $i++) {
                $row_style = ($i >= $num) ? 'display:none;' : '';
                echo '<div class="alx-dynamic-row" data-index="'.$i.'" style="margin-bottom:30px;'.$row_style.'">';
                echo '<h3>Dropdown '.($i+1).'</h3>';

                // Title
                $val = isset($titles[$i]) ? esc_attr($titles[$i]) : '';
                echo '<label>Title: </label>';
                echo '<input type="text" name="alx_shopper_dropdown_titles['.$i.']" value="'.$val.'" placeholder="Dropdown '.($i+1).' Title" style="width:250px;" /><br><br>';

                // Attribute
                echo '<label>Attribute: </label>';
                echo '<select class="alx-dropdown-attribute" name="alx_shopper_dropdown_attributes['.$i.']">';
                echo '<option value="">-- Select Attribute --</option>';
                foreach ($attribute_taxonomies as $tax) {
                    $attr_name = wc_attribute_taxonomy_name($tax->attribute_name);
                    $selected = (isset($mapping[$i]) && $mapping[$i] === $attr_name) ? 'selected' : '';
                    echo "<option value='{$attr_name}' {$selected}>".esc_html($tax->attribute_label)."</option>";
                }
                echo '</select><br><br>';

                // Values
                echo '<label>Values:</label><br>';
                $attr = isset($mapping[$i]) ? $mapping[$i] : '';
                $selected_values = isset($values[$i]) ? (array)$values[$i] : [];
                if ($attr) {
                    $terms = get_terms([
                        'taxonomy' => $attr,
                        'hide_empty' => false,
                    ]);
                    if (!empty($terms) && !is_wp_error($terms)) {
                        echo '<select class="alx-dropdown-values" name="alx_shopper_dropdown_values['.$i.'][]" multiple style="min-width:250px; height:100px;">';
                        foreach ($terms as $term) {
                            $sel = in_array($term->term_id, $selected_values) ? 'selected' : '';
                            echo "<option value='{$term->term_id}' {$sel}>{$term->name}</option>";
                        }
                        echo '</select>';
                    } else {
                        echo '<em>No terms found for this attribute.</em>';
                    }
                } else {
                    echo '<em>No attribute selected.</em>';
                }
                echo '<br><br>';

                // Order (drag-and-drop)
                $orders_for_this = isset($orders[$i]) ? (array)$orders[$i] : $selected_values;
                if ($attr && !empty($selected_values)) {
                    $terms = get_terms([
                        'taxonomy' => $attr,
                        'include' => $selected_values,
                        'hide_empty' => false,
                    ]);
                    // Order terms as per $orders_for_this
                    $ordered_terms = [];
                    foreach ($orders_for_this as $term_id) {
                        foreach ($terms as $term) {
                            if ($term->term_id == $term_id) {
                                $ordered_terms[] = $term;
                            }
                        }
                    }
                    // Add missing terms (in case of new selections)
                    foreach ($terms as $term) {
                        if (!in_array($term, $ordered_terms)) {
                            $ordered_terms[] = $term;
                        }
                    }
                    echo '<label>Order (drag to reorder):</label><br>';
                    echo '<ul class="alx-sortable" data-index="'.$i.'" style="margin-bottom:10px; background:#f9f9f9; padding:10px; min-width:250px;">';
                    // "Any" option as a draggable item
                    $any_selected = (isset($orders_for_this[0]) && $orders_for_this[0] === 'any') ? 'checked' : '';
                    echo '<li class="alx-sortable-any"><label><input type="checkbox" name="alx_orders['.$i.'][]" value="any" '.$any_selected.'> Any</label></li>';
                    foreach ($ordered_terms as $term) {
                        echo '<li class="alx-sortable-item" data-term="'.$term->term_id.'">'.esc_html($term->name);
                        echo '<input type="hidden" name="alx_orders['.$i.'][]" value="'.$term->term_id.'">';
                        echo '</li>';
                    }
                    echo '</ul>';
                } else {
                    echo '<em>No values selected.</em>';
                }
                echo '<br><hr>';
                echo '</div>';
            }
            ?>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', 'alx_shopper_register_settings');
function alx_shopper_register_settings() {
    register_setting('alx_shopper_settings_group', 'alx_shopper_num_dropdowns');
    register_setting('alx_shopper_settings_group', 'alx_shopper_categories');
    register_setting('alx_shopper_settings_group', 'alx_shopper_dropdown_titles');
    register_setting('alx_shopper_settings_group', 'alx_shopper_dropdown_attributes');
    register_setting('alx_shopper_settings_group', 'alx_shopper_dropdown_values');
    register_setting('alx_shopper_settings_group', 'alx_shopper_dropdown_value_order');
    

    add_settings_section('alx_shopper_main_section', 'Main Settings', null, 'alx-shopper-settings');

      add_settings_field(
        'alx_shopper_enable_email_results',
        'Enable Email Results Option',
        function() {
            $enabled = get_option('alx_shopper_enable_email_results', false);
            echo '<input type="checkbox" name="alx_shopper_enable_email_results" value="1" '.checked($enabled, 1, false).' /> Allow users to email results to themselves';
        },
        'alx-shopper-settings',
        'alx_shopper_main_section'
    );
    add_settings_field(
        'alx_shopper_num_dropdowns',
        'Number of Dropdowns (2-5)',
        'alx_shopper_num_dropdowns_callback',
        'alx-shopper-settings',
        'alx_shopper_main_section'
    );

    add_settings_field(
        'alx_shopper_categories',
        'Product Categories for Search',
        'alx_shopper_categories_callback',
        'alx-shopper-settings',
        'alx_shopper_main_section'
    );

    add_settings_field(
        'alx_shopper_dropdown_titles',
        'Dropdown Titles',
        'alx_shopper_dropdown_titles_callback',
        'alx-shopper-settings',
        'alx_shopper_main_section'
    );

    add_settings_field(
        'alx_shopper_dropdown_attributes',
        'Dropdown Attribute Mapping',
        'alx_shopper_dropdown_attributes_callback',
        'alx-shopper-settings',
        'alx_shopper_main_section'
    );

    add_settings_field(
        'alx_shopper_dropdown_values',
        'Dropdown Value Selection',
        'alx_shopper_dropdown_values_callback',
        'alx-shopper-settings',
        'alx_shopper_main_section'
    );

    add_settings_field(
        'alx_shopper_dropdown_value_order',
        'Dropdown Value Order',
        'alx_shopper_dropdown_value_order_callback',
        'alx-shopper-settings',
        'alx_shopper_main_section'
    );

  
}

function alx_shopper_num_dropdowns_callback() {
    $value = get_option('alx_shopper_num_dropdowns', 2);
    echo '<input type="number" min="2" max="5" name="alx_shopper_num_dropdowns" value="' . esc_attr($value) . '" />';
}

function alx_shopper_categories_callback() {
    $selected = (array) get_option('alx_shopper_categories', []);
    $categories = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
    ]);
    if (empty($categories) || is_wp_error($categories)) {
        echo 'No product categories found.';
        return;
    }
    echo '<select name="alx_shopper_categories[]" multiple style="min-width:250px; height:100px;">';
    foreach ($categories as $cat) {
        $selected_attr = in_array($cat->term_id, $selected) ? 'selected' : '';
        echo "<option value='{$cat->term_id}' {$selected_attr}>{$cat->name}</option>";
    }
    echo '</select>';
    echo '<br><small>Hold Cmd (Mac) or Ctrl (Windows) to select multiple categories.</small>';
}

function alx_shopper_dropdown_titles_callback() {
    $titles = get_option('alx_shopper_dropdown_titles', []);
    $num = intval(get_option('alx_shopper_num_dropdowns', 2));
    for ($i = 0; $i < $num; $i++) {
        $val = isset($titles[$i]) ? esc_attr($titles[$i]) : '';
        echo '<input type="text" name="alx_shopper_dropdown_titles['.$i.']" value="'.$val.'" placeholder="Dropdown '.($i+1).' Title" style="margin-bottom:5px; width:250px;" /><br />';
    }
    echo '<p class="description">Set a title for each dropdown (shown on the front end).</p>';
}

function alx_shopper_dropdown_attributes_callback() {
    $mapping = get_option('alx_shopper_dropdown_attributes', []);
    $num = intval(get_option('alx_shopper_num_dropdowns', 2));
    if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
        echo 'WooCommerce is required for this feature.';
        return;
    }
    $attribute_taxonomies = wc_get_attribute_taxonomies();
    if ( empty( $attribute_taxonomies ) ) {
        echo 'No global attributes found.';
        return;
    }
    // Build attribute options
    $options = [];
    foreach ( $attribute_taxonomies as $tax ) {
        $attr_name = wc_attribute_taxonomy_name( $tax->attribute_name );
        $label = esc_html( $tax->attribute_label );
        $options[$attr_name] = $label;
    }
    // Render a select for each dropdown
    for ($i = 0; $i < $num; $i++) {
        $selected = isset($mapping[$i]) ? esc_attr($mapping[$i]) : '';
        echo '<label>Dropdown '.($i+1).': </label>';
        echo '<select name="alx_shopper_dropdown_attributes['.$i.']" style="min-width:200px;">';
        echo '<option value="">-- Select Attribute --</option>';
        foreach ($options as $attr_name => $label) {
            $sel = ($selected === $attr_name) ? 'selected' : '';
            echo "<option value='{$attr_name}' {$sel}>{$label}</option>";
        }
        echo '</select><br />';
    }
    echo '<p class="description">Assign a WooCommerce attribute to each dropdown filter.</p>';
}

function alx_shopper_dropdown_values_callback() {
    $mapping = get_option('alx_shopper_dropdown_attributes', []);
    $values = get_option('alx_shopper_dropdown_values', []);
    $num = intval(get_option('alx_shopper_num_dropdowns', 2));

    for ($i = 0; $i < $num; $i++) {
        $attr = isset($mapping[$i]) ? $mapping[$i] : '';
        if (!$attr) {
            echo '<p>Dropdown '.($i+1).': <em>No attribute selected.</em></p>';
            continue;
        }
        $taxonomy = $attr;
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
        ]);
        echo '<strong>Dropdown '.($i+1).' Values:</strong><br>';
        if (empty($terms) || is_wp_error($terms)) {
            echo '<em>No terms found for this attribute.</em><br>';
            continue;
        }
        $selected = isset($values[$i]) ? (array)$values[$i] : [];
        echo '<select name="alx_shopper_dropdown_values['.$i.'][]" multiple style="min-width:250px; height:100px;">';
        foreach ($terms as $term) {
            $sel = in_array($term->term_id, $selected) ? 'selected' : '';
            echo "<option value='{$term->term_id}' {$sel}>{$term->name}</option>";
        }
        echo '</select><br><br>';
    }
    echo '<p class="description">Select which values will be available in each dropdown. Hold Ctrl (Windows) or Cmd (Mac) to select multiple.</p>';
}

function alx_shopper_dropdown_value_order_callback() {
    $mapping = get_option('alx_shopper_dropdown_attributes', []);
    $values = get_option('alx_shopper_dropdown_values', []);
    $orders = get_option('alx_shopper_dropdown_value_order', []);
    $num = intval(get_option('alx_shopper_num_dropdowns', 2));

    for ($i = 0; $i < $num; $i++) {
        $attr = isset($mapping[$i]) ? $mapping[$i] : '';
        if (!$attr) {
            echo '<p>Dropdown '.($i+1).': <em>No attribute selected.</em></p>';
            continue;
        }
        $selected = isset($values[$i]) ? (array)$values[$i] : [];
        if (empty($selected)) {
            echo '<p>Dropdown '.($i+1).': <em>No values selected.</em></p>';
            continue;
        }
        // Get term objects for selected values
        $terms = get_terms([
            'taxonomy' => $attr,
            'include' => $selected,
            'hide_empty' => false,
        ]);
        // Use saved order or default to selected order
        $order = isset($orders[$i]) ? (array) $orders[$i] : $selected;
        // Build ordered list of terms
        $ordered_terms = [];
        foreach ($order as $term_id) {
            foreach ($terms as $term) {
                if ($term->term_id == $term_id) {
                    $ordered_terms[] = $term;
                }
            }
        }
        // Add any missing terms (in case of new selections)
        foreach ($terms as $term) {
            if (!in_array($term, $ordered_terms)) {
                $ordered_terms[] = $term;
            }
        }
        echo '<label>Dropdown '.($i+1).' Value Order:</label><br>';
        echo '<ul class="alx-sortable" data-input="alx_shopper_dropdown_value_order['.$i.']">';
        // "Any" option
        $any_selected = (isset($order[0]) && $order[0] === 'any') ? 'checked' : '';
        echo '<li class="alx-sortable-any"><label><input type="checkbox" name="alx_shopper_dropdown_value_order['.$i.'][]" value="any" '.$any_selected.'> Any</label></li>';
        foreach ($ordered_terms as $term) {
            echo '<li class="alx-sortable-item" data-term="'.$term->term_id.'">'.esc_html($term->name);
            echo '<input type="hidden" name="alx_shopper_dropdown_value_order['.$i.'][]" value="'.$term->term_id.'">';
            echo '</li>';
        }
        echo '</ul><br>';
    }
    echo '<p class="description">Drag to reorder values. "Any" will allow users to search for any value in this dropdown.</p>';
}

add_action('admin_enqueue_scripts', function() {
    wp_enqueue_script('jquery-ui-sortable');
    wp_enqueue_script('alx-shopper-admin', ALX_SHOPPER_URL . 'assets/js/alx-shopper-admin.js', ['jquery', 'jquery-ui-sortable'], ALX_SHOPPER_VERSION, true);
    wp_enqueue_style('alx-shopper-admin', ALX_SHOPPER_URL . 'assets/css/alx-shopper-admin.css', [], ALX_SHOPPER_VERSION);
});



// Enqueue frontend scripts and styles
add_action('wp_enqueue_scripts', function() {
    global $alx_shopper_current_filter_config;
    $enable_email_results = false;
    if (isset($alx_shopper_current_filter_config['enable_email_results'])) {
        $enable_email_results = (bool) $alx_shopper_current_filter_config['enable_email_results'];
    } else {
        $enable_email_results = (bool) get_option('alx_shopper_enable_email_results', false);
    }
    wp_enqueue_script(
        'alx-shopper-frontend',
        ALX_SHOPPER_URL . 'assets/js/shopper-script.js',
        ['jquery'],
        ALX_SHOPPER_VERSION,
        true
    );
    wp_localize_script('alx-shopper-frontend', 'alxShopperAjax', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'enable_email_results' => $enable_email_results,
    ]);
    
wp_enqueue_script(
        'alx-shopper-modal',
        ALX_SHOPPER_URL . 'assets/js/alx-shopper-modal.js'
);
       
   
    wp_enqueue_style(
        'alx-shopper-style',
        ALX_SHOPPER_URL . 'assets/css/shopper-style.css',
        [],
        ALX_SHOPPER_VERSION
    );
    wp_enqueue_style(
        'alx-shopper-results-card',
        ALX_SHOPPER_URL . 'assets/css/shopper-results-card.css',
        [],
        ALX_SHOPPER_VERSION
    );
});

add_action('wp_ajax_alx_get_attribute_terms', function() {
    $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field($_POST['taxonomy']) : '';
    $index = isset($_POST['index']) ? intval($_POST['index']) : 0;

    if (!$taxonomy) {
        echo '<em>No attribute selected.</em>';
        wp_die();
    }

    $terms = get_terms([
        'taxonomy' => $taxonomy,
        'hide_empty' => false,
    ]);

    if (empty($terms) || is_wp_error($terms)) {
        echo '<em>No terms found for this attribute.</em>';
        wp_die();
    }

    // Output the sortable list for drag-and-drop ordering
    echo '<ul class="alx-sortable" data-input="alx_shopper_dropdown_value_order['.$index.']">';
    // "Any" option
    echo '<li class="alx-sortable-any"><label><input type="checkbox" name="alx_shopper_dropdown_value_order['.$index.'][]" value="any"> Any</label></li>';
    foreach ($terms as $term) {
        echo '<li class="alx-sortable-item" data-term="'.$term->term_id.'">'.esc_html($term->name);
        echo '<input type="hidden" name="alx_shopper_dropdown_value_order['.$index.'][]" value="'.$term->term_id.'">';
        echo '</li>';
    }
    echo '</ul>';

    wp_die();
});

add_action('wp_ajax_alx_shopper_filter', 'alx_shopper_filter_ajax');
add_action('wp_ajax_nopriv_alx_shopper_filter', 'alx_shopper_filter_ajax');

function alx_shopper_filter_ajax() {
    $filter_id = isset($_POST['alx_filter_id']) ? sanitize_key($_POST['alx_filter_id']) : 'default';
    $config = alx_shopper_get_filter_config($filter_id);

    // Fallback to global config if CPT config not found
    if (!$config) {
        $num = intval(get_option('alx_shopper_num_dropdowns', 2));
        $mapping = get_option('alx_shopper_dropdown_attributes', []);
        $categories = (array) get_option('alx_shopper_categories', []);
    } else {
        $num = isset($config['num']) ? intval($config['num']) : 2;
        $mapping = isset($config['mapping']) ? $config['mapping'] : [];
        $categories = isset($config['categories']) ? (array)$config['categories'] : [];
    }

    $tax_query = [];
    if (!empty($categories)) {
        $tax_query[] = [
            'taxonomy' => 'product_cat',
            'field'    => 'term_id',
            'terms'    => $categories,
        ];
    }
    for ($i = 0; $i < $num; $i++) {
        $attr = isset($mapping[$i]) ? $mapping[$i] : '';
        $val = isset($_POST["alx_dropdown_$i"]) ? $_POST["alx_dropdown_$i"] : '';
        if ($attr !== '' && $val !== '' && $val !== 'any') {
            $tax_query[] = [
                'taxonomy' => $attr,
                'field'    => 'term_id',
                'terms'    => [ intval($val) ],
            ];
        }
    }

    $args = [
        'post_type'      => 'product',
        'posts_per_page' => 12,
        'tax_query'      => $tax_query,
    ];

    $products = new WP_Query($args);

    ob_start();
    // Pass $products to the template
    include ALX_SHOPPER_DIR . 'templates/shopper-results.php';
    $html = ob_get_clean();

    echo $html;
    wp_die();
}

function alx_shopper_shortcode($atts = []) {
    $atts = shortcode_atts(['id' => 'default'], $atts);
    $filter_id = sanitize_key($atts['id']);
    global $alx_shopper_current_filter_id, $alx_shopper_current_filter_config;
    $alx_shopper_current_filter_id = $filter_id;
    $alx_shopper_current_filter_config = alx_shopper_get_filter_config($filter_id);
    ob_start();
    include ALX_SHOPPER_DIR . 'templates/shopper-main.php';
    return ob_get_clean();
}
add_shortcode('alx_shopper', 'alx_shopper_shortcode');

add_action('wp_ajax_alx_quick_view', 'alx_quick_view_callback');
add_action('wp_ajax_nopriv_alx_quick_view', 'alx_quick_view_callback');

function alx_quick_view_callback() {
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    if (!$product_id) {
        echo 'Product not found.';
        wp_die();
    }
    $product = wc_get_product($product_id);
    if (!$product) {
        echo 'Product not found.';
        wp_die();
    }
    $GLOBALS['product'] = $product;
    $GLOBALS['product_id'] = $product_id;
    include ALX_SHOPPER_DIR . 'templates/shopper-modal.php';
    wp_die();
}

add_action('admin_init', function() {
    if (
        isset($_POST['option_page']) &&
        $_POST['option_page'] === 'alx_shopper_settings_group' &&
        isset($_POST['submit'])
    ) {
        if (!isset($_POST['alx_shopper_enable_email_results'])) {
            update_option('alx_shopper_enable_email_results', 0);
        }
    }
});

add_action('wp_ajax_alx_shopper_send_results_email', 'alx_shopper_send_results_email');
add_action('wp_ajax_nopriv_alx_shopper_send_results_email', 'alx_shopper_send_results_email');
function alx_shopper_send_results_email() {
    error_log('DEBUG: alx_shopper_send_results_email called');
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    if (!is_email($email)) {
        wp_send_json_error(['message' => 'Invalid email address.']);
    }

    $filter_id = isset($_POST['alx_filter_id']) ? sanitize_key($_POST['alx_filter_id']) : 'default';
    $config = alx_shopper_get_filter_config($filter_id);

    // Get results from AJAX POST data
    $results = [];
    if (!empty($_POST['results'])) {
        $results = json_decode(stripslashes($_POST['results']), true);
        if (!is_array($results)) $results = [];
    }

    // --- NEW: Get analytics fields from POST ---
    $filters = isset($_POST['filters']) ? json_decode(stripslashes($_POST['filters']), true) : [];
    $search_query = isset($_POST['search_query']) ? sanitize_text_field($_POST['search_query']) : '';
    $quick_views = isset($_POST['quick_views']) ? implode(',', json_decode(stripslashes($_POST['quick_views']), true) ?: []) : '';
    $product_views = isset($_POST['product_views']) ? implode(',', json_decode(stripslashes($_POST['product_views']), true) ?: []) : '';
    $marketing_consent = isset($_POST['marketing_consent']) && $_POST['marketing_consent'] ? 'Yes' : 'No';
    $user_ip = $_SERVER['REMOTE_ADDR'];
    $user_location = isset($_POST['user_location']) ? sanitize_text_field($_POST['user_location']) : '';
    $referrer = isset($_POST['referrer']) ? sanitize_text_field($_POST['referrer']) : '';
    $device = isset($_POST['device']) ? sanitize_text_field($_POST['device']) : '';
    $datetime = current_time('mysql');

    // --- LOG THE EMAIL EVENT ---
    if (function_exists('alxshopper_log_event')) {
        alxshopper_log_event([
            'event_type' => 'email_results',
            'event_data' => [
                'email' => $email,
                'filters' => $filters,
                'search_query' => $search_query,
                'quick_views' => $quick_views,
                'product_views' => $product_views,
                'marketing_consent' => $marketing_consent,
            ],
            'user_ip' => $user_ip,
            'user_location' => $user_location,
            'referrer' => $referrer,
            'device' => $device,
            'created_at' => $datetime,
        ]);
    }

    // --- (existing email sending code below) ---
    $body = '<html><body style="font-family:Arial,sans-serif;background:#f7f7f7;padding:20px;">';
    if (!empty($results)) {
        $body .= '<h2 style="color:#2196F3;">Your Matches</h2><div style="display:flex;flex-wrap:wrap;">';
        foreach ($results as $product) {
            $body .= '<div style="border:1px solid #eee;border-radius:8px;padding:16px;margin:8px;width:220px;box-sizing:border-box;display:inline-block;vertical-align:top;text-align:center;background:#fff;">';
            if (!empty($product['permalink'])) {
                $body .= '<a href="' . esc_url($product['permalink']) . '" style="text-decoration:none;color:#222;">';
            }
            if (!empty($product['image'])) {
                $body .= '<img src="' . esc_url($product['image']) . '" alt="' . esc_attr($product['title']) . '" style="max-width:100%;height:auto;border-radius:4px;"><br>';
            }
            $body .= '<strong style="font-size:1.1em;">' . esc_html($product['title']) . '</strong>';
            if (!empty($product['permalink'])) $body .= '</a>';
            if (!empty($product['price_html'])) {
                $body .= '<div style="color:#2196F3;font-weight:bold;margin:8px 0;">' . $product['price_html'] . '</div>';
            }
            if (!empty($product['explanation'])) {
                $body .= '<div style="margin-top:8px;font-size:0.95em;color:#2196F3;">' . esc_html($product['explanation']) . '</div>';
            }
            if (!empty($product['permalink'])) {
                $body .= '<a href="' . esc_url($product['permalink']) . '" style="display:inline-block;margin-top:12px;padding:8px 18px;background:#2196F3;color:#fff;text-decoration:none;border-radius:4px;font-weight:bold;font-size:1em;">View Product</a>';
            }
            $body .= '</div>';
        }
        $body .= '</div>';
    } else {
        $body .= '<h2 style="color:#2196F3;">Your Matches</h2><p>No products found.</p>';
    }
    $body .= '<p style="margin-top:30px;font-size:0.95em;color:#888;">Sent from ' . esc_html(get_bloginfo('name')) . '</p>';
    $body .= '</body></html>';

    $subject = 'Your Product Matches from ' . get_bloginfo('name');
    $headers = ['Content-Type: text/html; charset=UTF-8'];

    $sent = wp_mail($email, $subject, $body, $headers);

    // --- EMAIL ANALYTICS LOGGING (always log, even if email fails) ---
    global $wpdb;
    $table = $wpdb->prefix . 'alxshopper_email_analytics';
    $user_ip = $_SERVER['REMOTE_ADDR'];
    $user_location = isset($_POST['user_location']) ? sanitize_text_field($_POST['user_location']) : '';
    $filter_title = '';
    if (!empty($config['titles']) && is_array($config['titles'])) {
        $filter_title = implode(', ', array_map('sanitize_text_field', $config['titles']));
    }
    $search_query = isset($_POST['search_query']) ? sanitize_textarea_field($_POST['search_query']) : '';
    $consent = isset($_POST['marketing_consent']) && $_POST['marketing_consent'] ? 1 : 0;

    error_log('DEBUG: email to insert: ' . $email); // <--- ADD THIS LINE

    error_log('EMAIL ANALYTICS INSERT: ' . print_r([
        'email_address' => $email,
        'filter_title' => $filter_title,
        'search_query' => $search_query,
        'quickviews' => $quick_views,
        'product_views' => $product_views,
        'user_ip' => $user_ip,
        'user_location' => $user_location,
        'consent_for_marketing' => $consent,
        'created_at' => current_time('mysql')
    ], true));
    
$test = $wpdb->insert($table, [
    'email_address' => 'test@example.com',
    'filter_title' => 'Test Filter',
    'search_query' => 'Test Query',
    'quickviews' => '1,2,3',
    'product_views' => '4,5,6',
    'user_ip' => '127.0.0.1',
    'user_location' => 'Test Location',
    'consent_for_marketing' => 1,
    'created_at' => current_time('mysql')
]);
error_log('Manual test insert result: ' . var_export($test, true));
error_log('Manual test MySQL error: ' . $wpdb->last_error);
    $wpdb->insert($table, [
        'email_address' => $email,
        'filter_title' => $filter_title,
        'search_query' => $search_query,
        'quickviews' => $quick_views,
        'product_views' => $product_views,
        'user_ip' => $user_ip,
        'user_location' => $user_location,
        'consent_for_marketing' => $consent,
        'created_at' => current_time('mysql')
    ]);
    error_log('MySQL error: ' . $wpdb->last_error);

    if ($sent) {
        wp_send_json_success();
    } else {
        wp_send_json_error(['message' => 'Could not send email.']);
    }
}

// 1. Register the custom post type for filters as a submenu under the main dashboard menu
add_action('init', function() {
    register_post_type('alx_shopper_filter', [
        'label' => 'Shopper Filters',
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => 'alx-shopper', // <-- This makes it a submenu
        'supports' => ['title'],
    ]);
});

// 2. Add meta boxes for filter settings
add_action('add_meta_boxes', function() {
    add_meta_box(
        'alx_shopper_filter_settings',
        'Filter Settings',
        'alx_shopper_filter_settings_metabox',
        'alx_shopper_filter',
        'normal',
        'default'
    );
});

function alx_shopper_filter_settings_metabox($post) {
    $num = get_post_meta($post->ID, '_alx_num', true) ?: 2;
    $mapping = get_post_meta($post->ID, '_alx_mapping', true) ?: [];
    $categories = get_post_meta($post->ID, '_alx_categories', true) ?: [];
    $titles = get_post_meta($post->ID, '_alx_titles', true) ?: [];
    $values = get_post_meta($post->ID, '_alx_values', true) ?: [];
    $orders = get_post_meta($post->ID, '_alx_orders', true) ?: [];
    $attribute_taxonomies = function_exists('wc_get_attribute_taxonomies') ? wc_get_attribute_taxonomies() : [];

    wp_nonce_field('alx_shopper_filter_save', 'alx_shopper_filter_nonce');

    // --- Add this block at the top of the metabox ---
    $shortcode = '[alx_shopper id="' . esc_attr($post->post_name) . '"]';
    echo '<div style="margin-bottom:15px;"><strong>Shortcode:</strong> ';
    echo '<input type="text" readonly value="' . esc_attr($shortcode) . '" style="width:300px;" onclick="this.select();" />';
    echo '<br><small>Copy and paste this shortcode to use this filter on any page.</small></div>';
    // --- End block ---

    // FIXED: Output the checkbox in PHP, not mixed PHP/HTML
    $enable_email_results = get_post_meta($post->ID, '_alx_enable_email_results', true);
    echo '<p>
        <label>
            <input type="checkbox" name="alx_enable_email_results" value="1" ' . checked($enable_email_results, 1, false) . ' />
            Allow users to email results to themselves
        </label>
    </p>';
    ?>
    <p>
        <label>Number of Dropdowns (2-5):</label>
        <input type="number" name="alx_num" min="2" max="5" value="<?php echo esc_attr($num); ?>">
    </p>
    <p>
        <label>Categories:</label><br>
        <?php
        $all_cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        echo '<select name="alx_categories[]" multiple style="min-width:250px; height:100px;">';
        foreach ($all_cats as $cat) {
            $selected = in_array($cat->term_id, (array)$categories) ? 'selected' : '';
            echo "<option value='{$cat->term_id}' $selected>{$cat->name}</option>";
        }
        echo '</select>';
        ?>
        <br><small>Hold Cmd (Mac) or Ctrl (Windows) to select multiple categories.</small>
    </p>
    <hr>
    <?php
    for ($i = 0; $i < $num; $i++) {
        $title = isset($titles[$i]) ? esc_attr($titles[$i]) : '';
        $selected_attr = isset($mapping[$i]) ? $mapping[$i] : '';
        echo "<h4>Dropdown " . ($i+1) . "</h4>";
        echo "<label>Title: <input type='text' name='alx_titles[$i]' value='$title'></label><br>";
        echo "<label>Attribute: <select name='alx_mapping[$i]'>";
        echo "<option value=''>-- Select Attribute --</option>";
        foreach ($attribute_taxonomies as $tax) {
            $attr_name = wc_attribute_taxonomy_name($tax->attribute_name);
            $sel = ($selected_attr === $attr_name) ? 'selected' : '';
            echo "<option value='$attr_name' $sel>{$tax->attribute_label}</option>";
        }
        echo "</select></label><br><br>";

        // Values
        $attr = isset($mapping[$i]) ? $mapping[$i] : '';
        $selected_values = isset($values[$i]) ? (array)$values[$i] : [];
        if ($attr) {
            $terms = get_terms([
                'taxonomy' => $attr,
                'hide_empty' => false,
            ]);
            if (!empty($terms) && !is_wp_error($terms)) {
                echo '<label>Values:</label><br>';
                echo '<select name="alx_values['.$i.'][]" multiple style="min-width:250px; height:100px;">';
                foreach ($terms as $term) {
                    $sel = in_array($term->term_id, $selected_values) ? 'selected' : '';
                    echo "<option value='{$term->term_id}' {$sel}>{$term->name}</option>";
                }
                echo '</select><br><br>';
            }
        }
        // Order
        $orders_for_this = isset($orders[$i]) ? (array)$orders[$i] : $selected_values;
        if ($attr && !empty($selected_values)) {
            $terms = get_terms([
                'taxonomy' => $attr,
                'include' => $selected_values,
                'hide_empty' => false,
            ]);
            // Order terms as per $orders_for_this
            $ordered_terms = [];
            foreach ($orders_for_this as $term_id) {
                foreach ($terms as $term) {
                    if ($term->term_id == $term_id) {
                        $ordered_terms[] = $term;
                    }
                }
            }
            // Add missing terms (in case of new selections)
            foreach ($terms as $term) {
                if (!in_array($term, $ordered_terms)) {
                    $ordered_terms[] = $term;
                }
            }
            echo '<label>Order (drag to reorder):</label><br>';
            echo '<ul class="alx-sortable" data-index="'.$i.'" style="margin-bottom:10px; background:#f9f9f9; padding:10px; min-width:250px;">';
            // "Any" option as a draggable item
            $any_selected = (isset($orders_for_this[0]) && $orders_for_this[0] === 'any') ? 'checked' : '';
            echo '<li class="alx-sortable-any"><label><input type="checkbox" name="alx_orders['.$i.'][]" value="any" '.$any_selected.'> Any</label></li>';
            foreach ($ordered_terms as $term) {
                echo '<li class="alx-sortable-item" data-term="'.$term->term_id.'">'.esc_html($term->name);
                echo '<input type="hidden" name="alx_orders['.$i.'][]" value="'.$term->term_id.'">';
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '<hr>';
    }

    ?>
    <button type="button" class="button button-primary" id="alx-save-refresh"><?php esc_html_e('Save & Refresh', 'alx-shopper'); ?></button>
    <span id="alx-save-refresh-status" style="margin-left:10px;"></span>
    <script>
    jQuery(document).ready(function($) {
        $('#alx-save-refresh').on('click', function() {
            var $btn = $(this);
            var $form = $btn.closest('form');
            var formData = $form.serialize();
            $btn.prop('disabled', true);
            $('#alx-save-refresh-status').text('Saving...');
            $.post(ajaxurl, formData + '&action=alx_shopper_save_filter_ajax', function(response) {
                if (response.success) {
                    $('#alx-save-refresh-status').text('Saved! Refreshing...');
                    setTimeout(function() { location.reload(); }, 800);
                } else {
                    $('#alx-save-refresh-status').text('Error: ' + (response.data && response.data.message ? response.data.message : 'Unknown error'));
                    $btn.prop('disabled', false);
                }
            });
        });
    });
    </script>
    <?php
}

// 3. Save meta box data
add_action('save_post_alx_shopper_filter', function($post_id) {
    if (!isset($_POST['alx_shopper_filter_nonce']) || !wp_verify_nonce($_POST['alx_shopper_filter_nonce'], 'alx_shopper_filter_save')) return;
    update_post_meta($post_id, '_alx_num', intval($_POST['alx_num']));
    update_post_meta($post_id, '_alx_mapping', array_map('sanitize_text_field', $_POST['alx_mapping'] ?? []));
    update_post_meta($post_id, '_alx_categories', array_map('intval', $_POST['alx_categories'] ?? []));
    update_post_meta($post_id, '_alx_titles', array_map('sanitize_text_field', $_POST['alx_titles'] ?? []));
    update_post_meta($post_id, '_alx_values', $_POST['alx_values'] ?? []);
    update_post_meta($post_id, '_alx_orders', $_POST['alx_orders'] ?? []);
    // FIX: Save the email results checkbox
    update_post_meta($post_id, '_alx_enable_email_results', isset($_POST['alx_enable_email_results']) ? 1 : 0);
});

// 4. Update your shortcode and AJAX/email handlers to load config from the CPT
function alx_shopper_get_filter_config($filter_id) {
    $post = get_page_by_path($filter_id, OBJECT, 'alx_shopper_filter');
    if (!$post) return false;
    return [
        'num' => get_post_meta($post->ID, '_alx_num', true) ?: 2,
        'mapping' => get_post_meta($post->ID, '_alx_mapping', true) ?: [],
        'categories' => get_post_meta($post->ID, '_alx_categories', true) ?: [],
        'titles' => get_post_meta($post->ID, '_alx_titles', true) ?: [],
        'values' => get_post_meta($post->ID, '_alx_values', true) ?: [],
        'orders' => get_post_meta($post->ID, '_alx_orders', true) ?: [],
        'enable_email_results' => get_post_meta($post->ID, '_alx_enable_email_results', true) ? true : false, // <-- Add this line
    ];
}

// Example usage in AJAX/email handler:
$filter_id = isset($_POST['alx_filter_id']) ? sanitize_key($_POST['alx_filter_id']) : 'default';
$config = alx_shopper_get_filter_config($filter_id);
// Fallback to default config if needed

add_action('wp_ajax_alx_shopper_save_filter_ajax', function() {
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }
    $post_id = isset($_POST['post_ID']) ? intval($_POST['post_ID']) : 0;
    if (!$post_id) {
        wp_send_json_error(['message' => 'Invalid post ID']);
    }
    if (!isset($_POST['alx_shopper_filter_nonce']) || !wp_verify_nonce($_POST['alx_shopper_filter_nonce'], 'alx_shopper_filter_save')) {
        wp_send_json_error(['message' => 'Nonce check failed']);
    }
    update_post_meta($post_id, '_alx_num', intval($_POST['alx_num']));
    update_post_meta($post_id, '_alx_mapping', array_map('sanitize_text_field', $_POST['alx_mapping'] ?? []));
    update_post_meta($post_id, '_alx_categories', array_map('intval', $_POST['alx_categories'] ?? []));
    update_post_meta($post_id, '_alx_titles', array_map('sanitize_text_field', $_POST['alx_titles'] ?? []));
    update_post_meta($post_id, '_alx_values', $_POST['alx_values'] ?? []);
    update_post_meta($post_id, '_alx_orders', $_POST['alx_orders'] ?? []);
    // FIX: Save the email results checkbox
    update_post_meta($post_id, '_alx_enable_email_results', isset($_POST['alx_enable_email_results']) ? 1 : 0);
    wp_send_json_success();
});

// --- Email Analytics Page ---
function alxshopper_email_analytics_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'alxshopper_email_analytics';
    $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC LIMIT 100", ARRAY_A);
    echo '<div class="wrap"><h1>Email Analytics</h1>';
    if (empty($rows)) {
        echo '<p>No email analytics data found.</p></div>';
        return;
    }
    echo '<table class="widefat"><thead><tr>';
    echo '<th>Date</th><th>Email</th><th>Filter Title</th><th>Search Query</th><th>Quickviews</th><th>Product Views</th><th>IP</th><th>Location</th><th>Marketing Consent</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td>' . esc_html($row['created_at']) . '</td>';
        echo '<td>' . esc_html($row['email_address']) . '</td>';
        echo '<td>' . esc_html($row['filter_title']) . '</td>';
        echo '<td>' . esc_html($row['search_query']) . '</td>';
        echo '<td>' . esc_html($row['quickviews']) . '</td>';
        echo '<td>' . esc_html($row['product_views']) . '</td>';
        echo '<td>' . esc_html($row['user_ip']) . '</td>';
        echo '<td>' . esc_html($row['user_location']) . '</td>';
        echo '<td>' . ($row['consent_for_marketing'] ? 'Yes' : 'No') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

