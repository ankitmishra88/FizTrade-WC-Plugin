<?php
defined( 'ABSPATH' ) || die( 'No Script Kiddies Please' );

/**
 * Fiztrade main api class
 */
class FZT_API {

	/**
	 * Whether or not logging is enabled
	 *
	 * @var bool
	 */
	public static $log_enabled = false;

	/**
	 * Logger Object
	 *
	 * @var bool
	 */
	public static $log = false;

	public function __construct() {
		$settings          = get_option( 'fiztrade_settings' );
		$settings          = !is_array($settings)?array():$settings;
		$this->api_token   = $settings['fiztrade_api_token'];
		$this->mode        = isset($settings['live_mode'])?'live':'sandbox';
		SELF::$log_enabled = $this->mode == 'sandbox';

	}

	/**
	 * Returns base url
	 */
	public function get_base_url() {
		if ( 'sandbox' === $this->mode ) {
			return 'https://stage-connect.fiztrade.com';
		}

		return 'https://stage-connect.fiztrade.com';
	}

	/**
	 * Returns enpoint
	 */

	public function get_endpoint_url($endpoint,$data=array()){
		$base_url = $this->get_base_url();
		switch($endpoint){
			case 'get_products':
				return "{$base_url}/FizServices/GetProductsByMetalV2/{$this->api_token}/{$data['code']}";
			case 'get_images':
				return "{$base_url}/FizServices/GetCoinImages/{$this->api_token}/{$data['code']}";
			case 'get_prices_for_products':
				return "{$base_url}/FizServices/GetPricesForProducts/{$this->api_token}";
			case 'get_single_product_price':
				return "{$base_url}/FizServices/GetPrices/{$this->api_token}/{$data['code']}";
			case 'lock_price':
				return "{$base_url}/FizServices/LockPrices/{$this->api_token}";
			case 'execute_trade':
				return "{$base_url}/FizServices/ExecuteTrade/{$this->api_token}";
			default:
				return "";
		}
	}

	public function request($url, $method, $body=array()){
		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'body'    => wp_json_encode($body),
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json'
				)
			)
		);

		if(is_wp_error($response)){
			return $response;
		}

		$body = json_decode($response[ 'body' ], true);
		if( isset( $body[ 'error' ] ) ) {
			return new WP_Error('fzt_api_error', $body['error']);
		}

		return $body;
	}

	/**
	 * Returns Product Data from FizTrade
	 */
	public function get_products(){
		$codes    = [ 'Gold', 'Silver', 'Platinum' ];
		$productArr = array();
		$images   = array();

		foreach( $codes as $code ) {
			$url      = $this->get_endpoint_url( 'get_products', array('code'=>$code ) );
			$imgUrl   = $this->get_endpoint_url( 'get_images', array( 'code'=>$code ) );
			$products = $this->request($url,'GET');
			$images   = $this->request( $imgUrl, 'GET');
			if( is_wp_error( $products ) ) {
				SELF::log( "Error in getting products with code {$code}: {$url}".( $products->get_error_message() ) );
				return array();
			}

			if( is_wp_error( $images ) ) {
				SELF::log( "Error in fetching images for code {$code}: ".($images->get_error_message()) );
				return array();
			}

			SELF::log( "Product and images fetched for code { $code } Products" );
			foreach($products as $product){
				$product_code              = $product['code'];
				$productArr[$product_code] = array(
					'name'            => $product[ 'name' ],
					'description'     => $product[ 'description' ],
					'meta'            => array(
						'metalType'   => $product[ 'metalType' ],
						'origin'      => $product[ 'origin' ],
						'meltFactor'  => $product[ 'meltFactor' ]
					)
				);
			}

			foreach( $images as $image ) {
				$product_code = $image[ 'code' ];
				if(array_key_exists( $product_code, $productArr ) ) {
					$productArr[$product_code]['imageUrl']  = $image['imageURL'];
				}
			}
		}

		return $productArr;
	}

	/**
	 * @param WC_Product $product Product id.
	 * @return float $product Price of the product.
	 */ 
	public function get_price( $product ) {

	}
	/**
	 * @params array $dataArray data of trade to be executed(Products, qty, etc)
	 * @return array $data with keys 'total_price' ,lockToken
	 */
	public function lock_price( $dataArray ) {

	}

	/**
	 * @param array $dataArray data of trade to be executed(Products, qty, etc).
	 * @return array $executedTrade Information about executed trade.
	 */
	public function executeTrade($dataArray,$lockToken){
	
	}

	/**
	 * Logging method.
	 *
	 * @param string $message Log message.
	 * @param string $source Source of log.
	 * @param string $level   Optional. Default 'info'. Possible values:
	 *                        emergency|alert|critical|error|warning|notice|info|debug.
	 */
	public static function log( $message, $source = 'fzt-api', $level = 'info' ) {
		if ( self::$log_enabled ) {
			if ( empty( self::$log ) ) {
				self::$log = wc_get_logger();
			}
			self::$log->log( $level, $message, array( 'source' => $source ) );
		}
	}

}
?>