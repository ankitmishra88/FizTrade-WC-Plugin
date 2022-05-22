<?php
/**
 * Plugin Name:       Fiztrade Integration With WooCommerce
 * Plugin URI:        https://buykellygold.com
 * Description:       Handle the basics with this plugin.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Ankit Mishra
 * Author URI:        https://buykellygold.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fiztrade-integration
 * Domain Path:       /languages
 */

defined('ABSPATH') || die('No Script Kiddies Please');

defined('FIZTRADE_PLUGIN_DIR') || define('FIZTRADE_PLUGIN_DIR', dirname(__FILE__) );
defined('FIZTRADE_PLUGIN_URL') || define('FIZTRADE_PLUGIN_URL', plugin_dir_url(__FILE__) );

class FizTradeIntegration{
	
	public function __construct() {
		require_once(FIZTRADE_PLUGIN_DIR.'/classes/class-fzt-api.php');
		require_once(FIZTRADE_PLUGIN_DIR.'/classes/class-fzt-wc.php');
		add_action('admin_menu', [$this,'menu' ]);
		$this->wc = new FZT_WC();
	}
	
	function menu(){
		add_menu_page(
			'FIZTRADE SETTINGS',
			'FIZTRADE',
			'manage_options',
			'fiztrade-settings',
			[$this,'fiztrade_settings'],
			'',
			2
		);
	}
	
	function fiztrade_settings(){
		include_once(FIZTRADE_PLUGIN_DIR.'/admin/settings/settings.php');	
	}
}

add_action('plugins_loaded', 'fzt_init_trade');

function fzt_init_trade() {
	if( class_exists( 'WooCommerce' ) ) {
		global $fiztrade_wc;

		$fiztrade_wc = new FizTradeIntegration();
		
	}
}

function fiztrade_wc(){
	return $GLOBALS['fiztrade_wc'];
}

