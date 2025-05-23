<?php
/*
Plugin Name: Hot Tub Finder
Description: Provides a stylish hot tub finder for customers on your website
Version: 1.3
Author: A[lee]X Development 
*/

if (!defined('ABSPATH')) exit;


add_action('plugins_loaded', function() {
    require_once __DIR__ . '/inc/class-hot-tub-finder.php';
    if (is_admin()) {
        require_once __DIR__ . '/inc/class-hot-tub-finder-admin.php';
    }
    require_once __DIR__ . '/inc/class-hot-tub-finder-analytics.php';
    Hot_Tub_Finder::init();
});
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_script(
        'htf-analytics',
        plugins_url('assets/hot-tub-finder-analytics.js', __FILE__),
        [],
        '1.0',
        true
    );
    // Make sure ajaxurl is available for frontend
    wp_localize_script('htf-analytics', 'ajaxurl', admin_url('admin-ajax.php'));
});
// Enqueue the analytics script and pass ajaxurl properly
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_script(
        'htf-analytics',
        plugins_url('assets/hot-tub-finder-analytics.js', __FILE__),
        [],
        '1.0',
        true
    );
    wp_localize_script('htf-analytics', 'htf_ajax_object', array(
        'ajaxurl' => admin_url('admin-ajax.php')
    ));
});
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_script(
        'htf-custom-analytics',
        plugins_url('assets/hot-tub-finder-custom-analytics.js', __FILE__),
        array('htf-analytics'),
        '1.0',
        true
    );
});