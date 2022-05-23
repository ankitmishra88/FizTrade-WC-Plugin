<?php
    defined('ABSPATH') || die('No Script Kiddies Please');

    class FZT_WC{
        public function __construct(){
            add_action( 'wp_ajax_get_fzt_products', [ $this,'get_fzt_products' ]);
            add_action( 'wp_ajax_nopriv_get_fzt_products', [ $this,'get_fzt_products' ]);

            add_action( 'wp_ajax_update_fzt_product_skus', [ $this,'update_skus_call' ]);
            add_action( 'wp_ajax_nopriv_update_fzt_product_skus', [ $this,'update_skus_call' ]);
            // show the product image in shop list.
            add_filter( 'woocommerce_product_get_image', array( $this, 'get_image' ), 10, 6 );

            // show the gallery images in single product page.
            add_filter( 'woocommerce_single_product_image_thumbnail_html', array( $this, 'thumbnail_html' ), 10, 2 );

            add_action( 'init', array( $this, 'update_products_in_cart' ), 100 );
            add_action( 'template_redirect', array($this, 'update_current_product') );

            //lock the price
            add_action( 'woocommerce_checkout_order_processed', array( $this, 'lock_the_price' ), 10, 1 );

            //execute trade
            add_action( 'woocommerce_payment_complete', array( $this, 'execute_the_trade' ), 10, 1 );
			
			// Show Fiztrade Confirmation number in WooCommerce as well
			add_filter( 'manage_edit-shop_order_columns', array($this, 'fzt_confirmation_column' ), 20 );
			
			add_action( 'manage_shop_order_posts_custom_column' , array( $this, 'print_fzt_confirmation_number' ) , 20, 2 );
        }
		
		public function print_fzt_confirmation_number( $column, $order_id ) {
			switch( $column ) {
				case 'fzt_confirmation_number':
					echo get_post_meta( $order_id, 'fzt_confirmation_number', true );
					break;
				default:
					break;
			}
		}
		
		public function fzt_confirmation_column($columns) {
			$columns['fzt_confirmation_number'] = "FizTrade Confirmation Number";
			return $columns;
		}

        public function lock_the_price( $order_id ) {
            $order = wc_get_order( $order_id );
            $items = $order->get_items();
            $skus = array();
            

            foreach( $items as $item ) {
                $product_id = $item->get_product_id();
                $quantity   = $item->get_quantity();
                //print_r(array( $product_id, $quantity));

                if( get_post_meta($product_id, 'is_fiztrade_product', true) ) {
                    $wc_product = wc_get_product( $product_id );
                    $sku = $wc_product->get_sku();
                    //var_dump($sku);
                    if( $sku ) {
                        $skus[ $sku ] = $quantity;
                    }
                }

            }

            $api   = new FZT_API();
            $locked_data = $api->lock_price($order_id,$skus);
            if( is_wp_error( $locked_data ) ) {
                $api::log( "Error in locking price for order id {$order_id}: ".( $locked_data->get_error_message() ), 'fzt-api-trade' );
                throw new Exception( $locked_data->get_error_message() );
            }
            $api::log( "Successfuly locked price for order id {$order_id}", 'fzt-api-trade' );
            update_post_meta( $order_id, 'fzt_locked_token', $locked_data['lockToken'] );
            // I will update the order price here as well

        }

        public function execute_the_trade( $order_id ) {
            $locked_token = get_post_meta( $order_id, 'fzt_locked_token', true );
            if( !empty( $locked_token ) ) {
                $order  = wc_get_order( $order_id );
                $api    = new FZT_API();
				$api::log("Trying to execute order for {$order_id}", 'fzt-api-trade');
                $executed = $api->execute_trade($locked_token, $order);
                if( is_wp_error( $executed ) ) {
                    $api::log("Error in executing trade for order id {$order_id}: ".( $executed->get_error_message() ), 'fzt-api-trade' );
                    $this->trigger_failed_trade_mail( $order_id, $executed->get_error_message() );
                    throw new Exception( 
                                            "Some error occured in processing your trade, 
                                            please note the reference number FZTOD{$order_id}." 
                                        );
                    return;
                }

                $api::log( "Successfully executed traded for order id {$order_id} with response ".json_encode( $executed ), 'fzt-api-trade' );
                $confirmation_number = array_values( $executed['confirmationNumber'] )[0];
                $api::log( "Received confirmation number is {$confirmation_number}", 'fzt-api-trade' );
                update_post_meta( $order_id, 'fzt_confirmation_number', $confirmation_number );
                update_post_meta( $order_id, 'fzt_confirmation_data', $executed );

                $this->trigger_success_trade_mail( $order_id, $executed );

            }
        }

        public function trigger_failed_trade_mail( $order_id, $error ) {

        }

        public function trigger_success_trade_mail( $order_id, $executed ) {

        }

        public function update_current_product() {
            //If it's a single product page, Update Current Page Product first
            $skus = array();
            if( is_product() ) {
                global $post;
                
                $product_id = $post->ID;
                $product = wc_get_product( $product_id );
                if( get_post_meta($product_id, 'is_fiztrade_product', true) ) { 
                    $sku = $product->get_sku();
                    if( $sku ) {
                        $skus[]=$sku;
                    }
                }

            }

            if( ! empty($skus) ){
                $updated = $this->update_skus( $skus );
            }
        }

        public function update_products_in_cart(){
            $cart_instance = WC()->cart;
            if( ! $cart_instance ) {
                return;
            }
            $items = $cart_instance->get_cart();
            $skus = array();
           
            foreach($items as $item => $values) { 
                $cart_product = $values['data'];
                $product_id = $cart_product->get_id();
                if( get_post_meta($product_id, 'is_fiztrade_product', true) ){
                    $wc_product =  wc_get_product( $product_id ); 
                    $sku = $wc_product->get_sku();
                    if( $sku ) {
                        $skus[]=$sku;
                    }
                }   
            }
            
            if( ! empty($skus) ) {
                $skus = array_unique( $skus );
                $updated = $this->update_skus( $skus );
            }

        }

        public function get_template( $template, $template_name, $args, $template_path, $default_path ) {
            if ( 'single-product/product-thumbnails.php' === $template_name ) {
                $template =  FIZTRADE_PLUGIN_DIR.'/templates/single-product/product-thumbnails.php';
            }
    
            return $template;
        }

        public function update_skus_call() {
            $status = $this->update_skus(array("R$5COMMEM","1DUCA"));
            wp_send_json( $status );
        }

        public function update_skus( $skus ) {
            $api   = new FZT_API();
            $coins = $api->getCoinsDataByCode( $skus );
            if( is_wp_error( $coins ) ) {
                FZT_API::log( "Error in updating coins: ".$coins->get_error_message()  );
                return;
            }

            $status = array();

            foreach($coins as $sku => $coin ) {

                try{
                    $wc_product_id = wc_get_product_id_by_sku( $sku );
                    if( ! $wc_product_id ) {
                        $status[ $sku ] = array( 'error'=>'Product doesn\'t exist' );
                        continue;
                    }
                    $wc_product    = new WC_Product( $wc_product_id );
                    $is_active_sell   = 'Y' === $coin['isActiveSell'];
                    $wc_product->set_status( $is_active_sell ? 'publish' : 'pending' );
                    $wc_product->set_regular_price( $is_active_sell? $coin[ 'price' ] : false );
                    $wc_product->save();
                    $status[$sku] = array( $wc_product_id=>$coin );
                }
                catch( Exception $e ){
                    $status[$sku] = array( 'error'=>$e->get_error_message() );
                }
                

            }

            return $status;
        }

        public function thumbnail_html( $html, $post_thumbnail_id ) {
            global $product;
            $product_id = $product->get_id();
            if( get_post_meta( $product_id, 'is_fiztrade_product', true ) ) {
                $thumb_url = get_post_meta( $product_id, 'fiztrade_img_url', true );
                if( ! empty($thumb_url ) ) {
                    return sprintf(
                        '<div data-thumb="%1$s" data-thumb-alt="" class="woocommerce-product-gallery__image"><a href="%1$s"><img width="600" height="642" src="%1$s" class="" alt="" loading="lazy" title="61S2qlMWh6L._AC_SX679_" data-caption="" data-src="%1$s" data-large_image="%1$s" data-large_image_width="679" data-large_image_height="727" /></a></div>',
                        $thumb_url
                    );
                }
                
            }

            return $html;
        }

        public function get_image( $html, $product, $woosize, $attr, $placeholder, $image ) {
            $product_id = $product->get_id();

            //echo "I am called";
            if( $img_url = get_post_meta( $product_id, 'fiztrade_img_url', true ) ){
                return '<img width="260" height="300" src="' . esc_url( $img_url ) . '" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail" alt="" loading="lazy" />';
            }
            
            return $html;
        }


        function get_fzt_products(){
            //Set no time limit for this script
            set_time_limit(3600);
            $api = new FZT_API();
            $products = $api->get_products();
            if( is_wp_error( $products ) ) {
                wp_send_json( array( 'status'=>false, 'message'=>$products->get_error_message() ) );
            }
            $status = array();
            foreach( $products as $code => $coin ) {
                $sku        = strval( $code );
                $wc_product_id = wc_get_product_id_by_sku( $sku );
                if( empty( $wc_product_id ) ){
                    $wc_product_id  = $this->create_wc_product( $code, $coin );
                    $status[ $sku ] = array('product_id'=>$wc_product_id, 'action'=>'created');
                }
                else{
                    $this->update_wc_product( $wc_product_id, $coin );
                    $status[ $sku ] = array('product_id'=>$wc_product_id, 'action'=>'updated');
                }
                
            }
            wp_send_json($status);
        }

        function create_wc_product( $sku, $coin ) {
            try{
                $imageUrl       = $coin['imageUrl'];
                $availability   = $coin[ 'availability' ] == "Not Available" ? 'outofstock' : 'instock';
                $title          = $coin[ 'name' ];
                $description    = $coin[ 'description' ];
                $attributes     = $coin[ 'attributes' ];
                $price          = $coin[ 'price' ];
                $category       = $coin[ 'category' ];
                $is_active_sell = 'Y' === $coin[ 'isActiveSell' ];
    
                $wc_attributes = array();
    
                $wc_product   = new WC_Product();
                $wc_product->set_name( $title );
                $wc_product->set_description( $description );
                $wc_product->set_sku( $sku );
                $wc_product->set_stock_status($availability);
                $wc_product->set_price( $is_active_sell ? floatval( $price ) : false );
                $wc_product->set_regular_price( $is_active_sell ? floatval( $price ) : false );
                $wc_product->set_status( $is_active_sell ? 'publish' : 'pending' );
    
                foreach($attributes as $att_id => $attribute){
                    $wc_attribute = new WC_Product_Attribute();
                    $wc_attribute->set_id($att_id);
                    $wc_attribute->set_name($attribute['name']);
                    $wc_attribute->set_options( array( $attribute['value'] ) );
                    $wc_attribute->set_visible( true );
                    $wc_attribute->set_variation( false );

                    $wc_attributes[] =  $wc_attribute;
                }
    
                $wc_product->set_attributes( $wc_attributes );
                $wc_product_id = $wc_product->save();
                wp_set_object_terms( $wc_product_id, $category, 'product_cat' );
                update_post_meta( $wc_product_id, 'is_fiztrade_product', true );
                update_post_meta( $wc_product_id, 'fiztrade_img_url', $imageUrl );
                return $wc_product_id;
            }
            catch ( Exception $e ) {
                return new WP_Error('fzt_wc_creation_error', $e->get_message() );
            }

        }

        function update_wc_product( $wc_product_id, $coin ) {
            try{
                $wc_product   = new WC_Product( $wc_product_id );
                $price         = floatval( $coin['price'] );
                $availability  = $coin[ 'availability' ] == "Not Available" ? 'outofstock' : 'instock';
                $imageUrl      = $coin[ 'imageUrl' ];
                $attributes    = $coin[ 'attributes' ];
                $category      = $coin[ 'category' ];
                $is_active_sell = 'Y' === $coin[ 'isActiveSell' ];

                $wc_attributes = array();
                foreach($attributes as $att_id => $attribute){
                    $wc_attribute = new WC_Product_Attribute();
                    $wc_attribute->set_id($att_id);
                    $wc_attribute->set_name($attribute['name']);
                    $wc_attribute->set_options( array( $attribute['value'] ) );
                    $wc_attribute->set_visible( true );
                    $wc_attribute->set_variation( false );

                    $wc_attributes[] =  $wc_attribute;
                }

                $wc_product->set_price( $is_active_sell ? floatval( $price ) : false );
                $wc_product->set_regular_price( $is_active_sell ? floatval( $price ) : false );
                $wc_product->set_status( $is_active_sell ? 'publish' : 'pending' );
                $wc_product->set_stock_status( $availability );

                wp_set_object_terms( $wc_product_id, $category, 'product_cat' );

                update_post_meta( $wc_product_id, 'is_fiztrade_product', true );
                update_post_meta( $wc_product_id, 'fiztrade_img_url', $imageUrl );

                return $wc_product->save();
            }
            catch ( Exception $e ) {
                return new WP_Error( 'fzt_wc_update_err', $e->get_message() );
            }
            
        }
    }




?>