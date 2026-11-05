<?php
/**
 * Plugin URI: https://github.com/digicyfr/digicyfr-image-alt-use-audit
 * Plugin Name: Digicyfr Image Alt Use Audit
 * Description: A private, bounded inventory of saved image uses and their alt attributes.
 * Version: 0.1.0
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Author: Digicyfr
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: digicyfr-image-alt-use-audit
 * Domain Path: /languages
 * Update URI: https://digicyfr.com/plugins/digicyfr-image-alt-use-audit/
 *
 * @package Digicyfr_Image_Alt_Use_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DIGICYFR_IMAGE_ALT_USE_AUDIT_VERSION', '0.1.0' );
define( 'DIGICYFR_IMAGE_ALT_USE_AUDIT_PATH', plugin_dir_path( __FILE__ ) );
define( 'DIGICYFR_IMAGE_ALT_USE_AUDIT_URL', plugin_dir_url( __FILE__ ) );

require_once DIGICYFR_IMAGE_ALT_USE_AUDIT_PATH . 'includes/class-diau-storage.php';
require_once DIGICYFR_IMAGE_ALT_USE_AUDIT_PATH . 'includes/class-diau-scanner.php';
require_once DIGICYFR_IMAGE_ALT_USE_AUDIT_PATH . 'includes/class-diau-run.php';
require_once DIGICYFR_IMAGE_ALT_USE_AUDIT_PATH . 'includes/class-diau-admin.php';

register_activation_hook( __FILE__, array( 'DIAU_Storage', 'install' ) );
DIAU_Admin::boot();
