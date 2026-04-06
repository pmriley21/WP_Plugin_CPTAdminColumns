<?php
/**
 * Plugin Name: Post Type Admin Columns Toggle
 * Description: Add configurable admin list columns for ACF fields and taxonomies by post type.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Paul Riley
 * License: GPL-2.0-or-later
 * Text Domain: ptact
 */

if (! defined('ABSPATH')) {
    exit;
}

define('PTACT_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once PTACT_PLUGIN_DIR . 'includes/class-ptact-plugin.php';

PTACT_Plugin::instance();
