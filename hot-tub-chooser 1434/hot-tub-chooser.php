<?php
/*
Plugin Name: Hot Tub Chooser
Description: Provides a stylish shortcode and REST API to filter and display hot tubs by attributes.
Version: 1.4.34
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



