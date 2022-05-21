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
            $api = new FZT_API();
            $products = $api->get_products();
            if( is_wp_error( $products ) ) {
                wp_send_json( array( 'status'=>false, 'message'=>$products->get_error_message() ) );
            }
            $status = array();
            foreach( $products as $code => $coin ) {
                set_time_limit(40);
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