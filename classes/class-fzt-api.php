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
		$this->trader_id   = $settings['fiztrade_trader_id'];
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
			case 'get_product_catalog':
				return "{$base_url}/FizServices/GetProductCatalog/{$this->api_token}";
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
				'timeout' => 100,
				'headers' => array(
					'Content-Type' => 'application/json'
				)
			)
		);

		if(is_wp_error($response)){
			return $response;
		}

		$body = json_decode($response[ 'body' ], true);
		if( empty($body) ) {
			return new WP_Error('fzt_api_error', $response['body']);
		}
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
				return new WP_Error( 'api_error', "Error in getting products with code {$code}: {$url}".( $products->get_error_message() ) );
			}

			if( is_wp_error( $images ) ) {
				return new WP_Error( 'api_error', "Error in fetching images for code {$code}: ".($images->get_error_message()) );
			}

			SELF::log( "Product and images fetched for code { $code } Products" );
			foreach($products as $product){
				$product_code              = $product['code'];
				$productArr[$product_code] = array(
					'name'            => $product[ 'name' ],
					'description'     => $product[ 'description' ],
					'availability'    => $product[ 'availability' ],
					'category'        => $product[ 'category' ],
					'isActiveSell'    => $product[ 'isActiveSell' ],
					'attributes'            => array(
						'metalType'   => array(
											'name' => 'Metal Type',
											'value' => $product[ 'metalType' ],
										),
						'weight'      =>  array(
											'name' => 'Weight',
											'value' => $product[ 'weight' ],
										),
						'meltFactor'  =>  array(
											'name' => 'Melt Factor',
											'value' => $product[ 'meltFactor' ]
										),
						'fineness'  =>  array(
											'name' => 'Fineness',
											'value' => $product[ 'fineness' ]
										),
					)
				);
			}

			foreach( $images as $image ) {
				$product_code = $image[ 'code' ];
				if(array_key_exists( $product_code, $productArr ) ) {
					if( array_key_exists( 'imageURL', $image ) ){
						$productArr[$product_code]['imageUrl']  = $image['imageURL'];
					}
					else{
						SELF::log("imageURL not set for {$product_code}");
						$productArr[$product_code]['imageUrl'] = '';
					}
					
				}
			}

		}

		// Let's try to fetch product prices now
		$price_url = $this->get_endpoint_url('get_prices_for_products');
		

		$prices    = $this->request($price_url, 'POST', array_map( 'strval', array_keys( $productArr ) ));
		
		if( is_wp_error( $prices ) ) {
			//exit
			return new WP_Error( 'api_error', "Error in fetching Product Prices: ".$prices->get_error_message() );
		}

		foreach( $prices as $product_index => $price_data ) {
			$code = $price_data[ 'code' ];
			if( array_key_exists( $code, $productArr ) ) {
				$first_tier = $price_data[ 'tiers' ][1];
				if( empty( $first_tier ) ) {
					//exit
					return new WP_Error( 'api_error', "Tier pricing is not available for code {$code}" );
				}
				else{
					if( ! array_key_exists('askPercise', $first_tier ) ) {
						//exit
						return new WP_Error( 'api_error', "Product price in tier 1 is not available for code {$code}" );
					}
					$product_price = floatval( $first_tier[ 'askPercise' ] );
					$productArr[$code]['price'] = $product_price;

				}

			}
			else{
				//exit
				return new WP_Error( 'api_error', "Code {$code} is not available in our product array" );
			}
		}

		return $productArr;
	}

	public function getCoinsDataByCode( $skus ) {
		$url = $this->get_endpoint_url( 'get_product_catalog' );
		$products = $this->request( $url, 'POST', array( 'items' => $skus ) );
		if( is_wp_error( $products ) ) {
			return new WP_Error( 'api_error', "Error in fetching Product data: ".$products->get_error_message() );
		}
		// Let's try to fetch product prices now
		$price_url = $this->get_endpoint_url('get_prices_for_products');
		

		$prices    = $this->request($price_url, 'POST', $skus);
		
		if( is_wp_error( $prices ) ) {
			return new WP_Error( 'api_error', "Error in fetching Product Prices: ".$prices->get_error_message() );
		}

		$coins = array();

		foreach( $products as $coin ) {
			$code = $coin['code'];
			$coins[$code] = array( 'isActiveSell' => $coin['isActiveSell'] );
		}

		foreach( $prices as $price ) {
			$code                      = $price[ 'code' ];
			$tier1                     = $price['tiers'][1];
			$coins[ $code ][ 'price' ] = $tier1['askPercise'] ;
		}

		return $coins;
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
	public function lock_price( $order_id, $dataArray ) {
		$lock_body = array(
			'transactionId'       => "OD-{$order_id}",
			'includeRetailPrices' => 'yes',
			'items'               => array()
		);
		foreach ( $dataArray as $sku => $quantity ) {
			$lock_body[ 'items' ][] = array(
				'code' => $sku,
				'qty' => strval( absint( $quantity ) ),
				'transactionType' => 'buy'
			);
		}

		//throw new Exception( json_encode($lock_body) );

		$lock_url = $this->get_endpoint_url( 'lock_price' );
		$response = $this->request( $lock_url, 'POST', $lock_body );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		return $response;
	}

	/**
	 * @param string $locked_token locked_token for the trade to be executed(Products, qty, etc).
	 * @return object $order WooCommerce Order object.
	 */
	public function execute_trade($locked_token, $order ){
		$execute_url = $this->get_endpoint_url( 'execute_trade' );
		$order_id = $order->get_id();
		$body = array(
			"transactionId" => "OD-{$order_id}", 
			"referenceNumber" => "OD-{$order_id}", 
			"shippingOption" => "drop_ship", 
			"dropShipInfo" => [
				  "name" => $order->get_billing_first_name()." ".$order->get_billing_last_name(), 
				  "address1" => "".$order->get_billing_address_1(), 
				  "address2" => "".$order->get_billing_address_2(), 
				  "address3" => "", 
				  "address4" => "", 
				  "city" => "".$order->get_billing_city(), 
				  "state" => "".$order->get_billing_state(), 
				  "postalCode" => "".$order->get_billing_postcode(), 
				  "country" => "".$order->get_billing_country() 
			   ], 
			"lockToken" => $locked_token, 
			"traderId" => $this->trader_id
			);
		$response = $this->request( $execute_url, 'POST', $body );
		
		return $response;
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