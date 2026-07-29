<?php
/**
 * Plugin Name: Cansoft Form Submissions Send
 * Plugin URI: https://cansoft.ca
 * Description: Captures form submissions (Elementor, CF7/Flamingo, WPForms) and categorizes legitimate vs spam queries for Cansoft PM Monthly Reports.
 * Version: 1.0.0
 * Author: Fazle Bari
 * Author URI: https://github.com/fazlebarisn
 * Text Domain: cansoft-form-submissions-send
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

define('CANSOFT_FORM_SEND_VERSION', '1.0.0');
define('CANSOFT_FORM_SEND_PATH', plugin_dir_path(__FILE__));
define('CANSOFT_FORM_SEND_URL', plugin_dir_url(__FILE__));

require_once CANSOFT_FORM_SEND_PATH . 'includes/class-cansoft-form-submissions-sender.php';

function run_cansoft_form_submissions_sender() {
    $plugin = new Cansoft_Form_Submissions_Sender();
    $plugin->init();
}
run_cansoft_form_submissions_sender();
