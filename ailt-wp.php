<?php

/*
Plugin Name: Ailt WP Plugin
Plugin URI:
Description:
Version: 1.13.0
Author: Kristian Primdal
Author URI: https://speedly.dk
Text Domain: ailt-wp
Domain Path: /languages
*/

if (! defined('PILANTO_TEXT_SNIPPETS_DIR')) {
    define('PILANTO_TEXT_SNIPPETS_DIR', __DIR__);
}

if (! defined('PILANTO_TEXT_SNIPPETS_URL')) {
    define('PILANTO_TEXT_SNIPPETS_URL', plugin_dir_url(__FILE__));
}

require_once 'autoload.php';
require_once 'libraries/action-scheduler/action-scheduler.php';

new Ailt\Ailt();

require_once 'plugin-update-checker/plugin-update-checker.php';
$myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
    'https://github.com/henrikandersen1978/AILT-WP',
    __FILE__
);

//Optional: Set the branch that contains the stable release.
$myUpdateChecker->setBranch('main');
