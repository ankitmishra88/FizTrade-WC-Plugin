<?php
    defined('ABSPATH') || die('No Script Kiddies Please');

    class FZT_WC{
        public function __construct(){
            add_action( 'wp_ajax_get_fzt_products', [ $this,'get_fzt_products' ]);
            add_action( 'wp_ajax_nopriv_get_fzt_products', [ $this,'get_fzt_products' ]);
        }

        function get_fzt_products(){
            $api = new FZT_API();
            $products = $api->get_products();
            wp_send_json($products);
        }
    }

?>