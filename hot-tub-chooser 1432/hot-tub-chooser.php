<?php
/*
Plugin Name: Hot Tub Chooser
Description: Provides a stylish shortcode and REST API to filter and display hot tubs by attributes.
Version: 1.4.32
Author: A[lee]X Development 
*/

if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', function() {
    require_once __DIR__ . '/inc/class-hot-tub-chooser.php';
    if (is_admin()) {
        require_once __DIR__ . '/inc/class-hot-tub-chooser-admin.php';
    }
    require_once __DIR__ . '/inc/class-hot-tub-chooser-analytics.php';
    Hot_Tub_Chooser::init();
});
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_script(
        'htc-analytics',
        plugins_url('assets/hot-tub-chooser-analytics.js', __FILE__),
        [],
        '1.0',
        true
    );
    // Make sure ajaxurl is available for frontend
    wp_localize_script('htc-analytics', 'ajaxurl', admin_url('admin-ajax.php'));
});
// Enqueue the analytics script and pass ajaxurl properly
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_script(
        'htc-analytics',
        plugins_url('assets/hot-tub-chooser-analytics.js', __FILE__),
        [],
        '1.0',
        true
    );
    wp_localize_script('htc-analytics', 'htc_ajax_object', array(
        'ajaxurl' => admin_url('admin-ajax.php')
    ));
});

// --- Analytics Logging Endpoint ---
add_action('wp_ajax_htc_log_event', 'htc_log_analytics_event');
add_action('wp_ajax_nopriv_htc_log_event', 'htc_log_analytics_event');

function htc_log_analytics_event() {
    global $wpdb;

    $table = $wpdb->prefix . 'htc_analytics';

    $event_type = isset($_POST['event_type']) ? sanitize_text_field($_POST['event_type']) : '';
    $seats = isset($_POST['seats']) ? sanitize_text_field($_POST['seats']) : '';
    $power = isset($_POST['power']) ? sanitize_text_field($_POST['power']) : '';
    $lounger = isset($_POST['lounger']) ? sanitize_text_field($_POST['lounger']) : '';
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $device = isset($_POST['device']) ? sanitize_text_field($_POST['device']) : '';
    $referrer = isset($_POST['referrer']) ? esc_url_raw($_POST['referrer']) : '';

    $wpdb->insert(
        $table,
        [
            'event_type' => $event_type,
            'seats' => $seats,
            'power' => $power,
            'lounger' => $lounger,
            'product_id' => $product_id,
            'device' => $device,
            'referrer' => $referrer,
            'created_at' => current_time('mysql'),
        ]
    );

    wp_send_json_success();
}

// --- Create Table on Activation ---
register_activation_hook(__FILE__, 'htc_create_analytics_table');

function htc_create_analytics_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'htc_analytics';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      event_type varchar(100) NOT NULL,
      seats varchar(10) DEFAULT '' NOT NULL,
      power varchar(10) DEFAULT '' NOT NULL,
      lounger varchar(10) DEFAULT '' NOT NULL,
      product_id bigint(20) DEFAULT 0 NOT NULL,
      device varchar(20) DEFAULT '' NOT NULL,
      referrer text,
      created_at datetime NOT NULL,
      PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}