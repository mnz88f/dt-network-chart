<?php
/**
 * Plugin Name: Disciple.Tools - Network Chart
 * Description: Adds a metrics page that graphs post-to-post connections (vis-network), with a dropdown to pick the connection type.
 * Text Domain: dt-network-chart
 * Version: 0.1
 * Requires at least: 5.6
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'after_setup_theme', function () {
    if ( ! class_exists( 'Disciple_Tools' ) ) {
        return;
    }
    if ( ! defined( 'DT_FUNCTIONS_READY' ) ) {
        require_once get_template_directory() . '/dt-core/global-functions.php';
    }
    if ( strpos( dt_get_url_path(), 'metrics' ) !== false ) {
        require_once plugin_dir_path( __FILE__ ) . 'charts/network-chart.php';
        new DT_Network_Chart();
    }
}, 20 );
