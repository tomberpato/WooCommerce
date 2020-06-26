<?php
/**
 * Plugin Name: WooCommerce DinKassa Integration
 * Description: A plugin demonstrating how to add a new WooCommerce integration.
 * Author: Tom Boye
 * Author URI: http://speakinginbytes.com/
 * Version: 1.0
 */
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'WC_ESKassa_Integration' ) ) :

    class WC_ESKassa_Integration {

        /**
         * Construct the plugin.
         */
        public function __construct() {
            add_action( 'plugins_loaded', array( $this, 'init' ) );
        }

        /**
         * Initialize the plugin.
         */
        public function init() {

            // Checks if WooCommerce is installed.
            if ( class_exists( 'WC_Integration' ) ) {
                // Include our integration class.
                include_once 'includes/class-wc-dinkassa-integration.php';

                // Register the integration.
                add_filter( 'woocommerce_integrations', array( $this, 'add_integration' ) );
            } else {
                // throw an admin error if you like
            }
        }

        /**
         * Add a new integration to WooCommerce.
         */
        public function add_integration( $integrations ) {
            $integrations[] = 'WC_DinKassa_Integration';
            return $integrations;
        }

    }

    $WC_ESKassa_Integration = new WC_ESKassa_Integration(  );

endif;
