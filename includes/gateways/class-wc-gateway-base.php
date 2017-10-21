<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WC_Gateway_CryptoBase' ) ) :

    /**
     * Bitcoin Payment Gateway
     *
     * Provides a Bitcoin Payment Gateway
     *
     * @class    WC_Gateway_Bitcoin
     * @extends  WC_Payment_Gateway
     * @version  0.0.1
     * @package  DashPayments
     * @author   "Black Carrot Ventures" <nmarley@blackcarrot.be>
     */
    abstract class WC_Gateway_CryptoBase extends WC_Payment_Gateway {
        protected static $currency;
        protected static $currencyDesc;
        protected static $currencyUrl ;
        protected static $iconPath ;
        protected static $defaultInsight;
        protected static $walletUrl;
        protected static $walletTitle;
        protected static $currency_ticker_symbol;
        protected static $currency_symbol;

        // inherited from WC_Payment_Gateway
        public $enabled;
        public $title;
        public $description;
        public $method_title;
        public $method_description;
        public $has_fields = false;
        public $icon;
        public $id;
        public $settings = [];
        public $form_fields = [];

        // private

        protected $xpub_key;
        protected $confirmations;
        protected $exchange_multiplier;


        /**
         * Constructor for the gateway.
         *
         * @access public
         * @return void
         */
        public function __construct() {
            $this->id                 = DP_Gateways::gateway_id( static::$currency );
            $this->icon               = plugins_url(static::$iconPath, DP_PLUGIN_FILE);
            $this->has_fields         = false;
            $this->method_title       = __(static::$currency, 'dashpay-woocommerce');
            $this->method_description = __('Pay with '.static::$currency.' - ' . static::$currencyDesc, 'dashpay-woocommerce');

            // Init settings
            $this->init_form_fields();
            $this->init_settings();

            // Use settings
            $this->enabled     = $this->settings['enabled'];
            $this->title       = $this->settings['title'];
            $this->description = $this->settings['description'];

            $this->xpub_key = $this->settings['xpub_key'];
            $this->confirmations = $this->settings['confirmations'];
            $this->exchange_multiplier = $this->settings['exchange_multiplier'];

            // a trailing slash will break insight api
            $this->settings['insight_api_url'] = rtrim($this->settings['insight_api_url'], '/');

            // hooks
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page') );
            add_action( 'woocommerce_thankyou', array( $this, 'add_ajax_order_check') );
            add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );

            // Effectively broken, so not going to implement this 'til WordPress fixes it
            add_action( 'admin_notices', array( $this, 'admin_notices' ) );
        }


        /**
         * Check if this gateway is enabled and all dependencies are fine.
         * Disable the plugin if dependencies fail.
         *
         * @access      public
         * @return      bool
         */
        public function is_available() {
            if ( $this->enabled === 'no' ) {
                return false;
            }

            // ensure required extensions
            if ( 0 !== count(DP()->missing_required_extensions()) ) {
                return false;
            }

            // Validate settings
            if ( ! $this->xpub_key ) {
                return false;
            }

            // validate xpub key
            if ( ! CoinUtil::is_valid_public_xkey( $this->xpub_key ) ) {
                return false;
            }

            // Must be a valid insight instance that we can connect to
            $insight = new DP_Insight_API( $this->settings['insight_api_url'] );
            if ( ! $insight->is_valid() ) {
                return false;
            }

            // ensure we can fetch exchange rate
            $exchange_rate;
            try {
                DP_Exchange_Rate::get_exchange_rate(static::$currency_ticker_symbol, get_woocommerce_currency());
            }
            catch (\Exception $e) {
                return false;
            }

            // ensure matching xpub and insight networks
            $xpub_network = CoinUtil::guess_network_from_xkey( $this->correct_xpub_key() );

            $insight_network;
            try {
                $insight_network = $insight->get_network();
            }
            catch (\Exception $ex) {
                return false;
            }

            if ( $xpub_network != $insight_network ) {
                return false;
            }

            return true;
        }


        /**
         * Check if this gateway is enabled and available for the store's default currency
         *
         * @access public
         * @return bool
         */
        protected function check_valid() {
            if ( $this->enabled == 'no') {
                return __('Gateway is not enabled.', 'dashpay-woocommerce');
            }

            // required PHP extensions
            $missing = DP()->missing_required_extensions();
            if ( 0 !== count($missing) ) {
                $msg = sprintf(
                    esc_html__("Required extension(s) not loaded/enabled. Please enable '%s' PHP extension(s) on your WordPress server.", 'dashpay-woocommerce'),
                    join(', ', $missing)
                );
                return $msg;
            }

            // Ensure xpub entered
            if ( ! $this->xpub_key ) {
                $msg = __("Please add a " . static::$currency . " BIP32 extended public key (Launch your Electrum-" . static::$currency . " wallet, Select Wallet->Master Public Keys)", 'dashpay-woocommerce');
                return $msg;
            }

            // Ensure valid xpub
            if ( ! CoinUtil::is_valid_public_xkey( $this->xpub_key ) ) {
                $msg = __("Invalid xpub key.", 'dashpay-woocommerce');
                return $msg;
            }

            // Must be a valid insight instance that we can connect to
            $insight = new DP_Insight_API( $this->settings['insight_api_url'] );
            if ( ! $insight->is_valid() ) {
                $msg = __(static::$currency . ' Insight-API error in connection or network status ', 'dashpay-woocommerce');
                return $msg;
            }

            // ensure can connect to exchange rate webservice
            $exchange_rate;
            try {
                $exchange_rate = DP_Exchange_Rate::get_exchange_rate(static::$currency_ticker_symbol, get_woocommerce_currency());
            }
            catch (\Exception $e) {
                $msg = __('Error retrieving exchange rate.');
                return $msg;
            }


            // ensure matching xpub and insight networks
            $xpub_network = CoinUtil::guess_network_from_xkey( $this->correct_xpub_key() );
            try {
                $insight_network = $insight->get_network();
            }
            catch (\Exception $ex) {
                $msg = __('Insight-API error.', 'dashpay-woocommerce');
                return $msg;
            }

            if ( $xpub_network != $insight_network ) {
                $msg = sprintf(
                    esc_html__('Network discrepancy. Network for xpub key determined as %s, but Insight-API instance is running %s.', 'dashpay-woocommerce'),
                    $xpub_network,
                    $insight_network
                );
                return $msg;
            }

            return null;
        }


        /**
         * Send notices to users if requirements fail, or for any other reason
         *
         * @access      public
         * @return      bool
         */
        public function admin_notices() {
            if ( $this->enabled == 'no') {
                return false;
            }

            $missing = DP()->missing_required_extensions();
            if ( 0 !== count($missing) ) {
                $msg = sprintf(
                    __("<strong>" . static::$currency . " Payments for WooCommerce:</strong> Required extension(s) not loaded/enabled. Please enable '%s' PHP extension(s) on your WordPress server.", 'dashpay-woocommerce'),
                    join(', ', $missing)
                );
                self::_admin_error( $msg );
                return false;
            }

            // Check for xpub key
            if ( ! $this->xpub_key ) {
                self::_admin_error( __("Pleace add a " . static::$currency . " BIP32 extended public key (Launch your Electrum-" . static::$currency . " wallet, Select Wallet->Master Public Keys)", 'dashpay-woocommerce') );
                return false;
            }

            // valid xpub key
            if ( ! CoinUtil::is_valid_public_xkey( $this->xpub_key ) ) {
                $msg = __("Invalid xpub key.", 'dashpay-woocommerce');
                self::_admin_error( $msg );
                return false;
            }

            // Must be a valid insight instance that we can connect to
            $insight = new DP_Insight_API( $this->settings['insight_api_url'] );
            $insight_valid = false;
            try {
                $insight_valid = $insight->is_valid();
            }
            catch (\Exception $e) {
                $insight_valid = false;
            }

            if ( ! $insight_valid ) {
                $msg = __(static::$currency . ' Insight-API error in connection or network status', 'dashpay-woocommerce');
                self::_admin_error( $msg );
                return false;
            }

            // ensure can connect to exchange rate webservice
            $exchange_rate;
            try {
                $exchange_rate = DP_Exchange_Rate::get_exchange_rate(static::$currency_ticker_symbol, get_woocommerce_currency());
            }
            catch (\Exception $e) {
                $msg = __('Error retrieving exchange rate.');
                self::_admin_error( $msg );
                return false;
            }


            // ensure matching xpub and insight networks
            $xpub_network = CoinUtil::guess_network_from_xkey( $this->correct_xpub_key() );
            try {
                $insight_network = $insight->get_network();
            }
            catch (\Exception $ex) {
                $msg = __('Insight-API error.', 'dashpay-woocommerce');
                self::_admin_error( $msg );
                return false;
            }

            if ( $xpub_network != $insight_network ) {
                $msg = sprintf(
                    esc_html__('Network discrepancy. Network for xpub key determined as %s, but Insight-API instance is running %s.', 'dashpay-woocommerce'),
                    $xpub_network,
                    $insight_network
                );
                self::_admin_error( $msg );
                return false;
            }

            return true;
        }



        /**
         * Initialise Gateway Settings Form Fields
         *
         * @access public
         * @return void
         */
        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __( 'Enable/Disable', 'dashpay-woocommerce' ),
                    'type' => 'checkbox',
                    'label' => __( 'Enable ' . static::$currency . ' Payments', 'dashpay-woocommerce' ),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __( 'Title', 'dashpay-woocommerce' ),
                    'type' => 'text',
                    'description' => __( 'This controls the title which the user sees during checkout.', 'dashpay-woocommerce' ),
                    'default' => __( 'Pay with ' . static::$currency, 'dashpay-woocommerce' ),
                    // 'desc_tip'    => true,
                ),
                'xpub_key' => array(
                    'title' => __( static::$currency . " BIP32 Extended Public Key", 'dashpay-woocommerce' ),
                    'type' => 'textarea',
                    'default' => '',
                    'description' => __('Start <a href="' . static::$walletUrl . '" target="_blank">' . static::$walletTitle . '</a> and get Master Public Key value from:<br>Wallet -> Master Public Key<br>Copy/paste the string starting with "xpub" into this field.', 'dashpay-woocommerce'),
                    // 'desc_tip'    => true,
                ),
                'confirmations' => array(
                    'title' => __( 'Confirmations required', 'dashpay-woocommerce' ),
                    'type' => 'text',
                    'description' => __( 'Number of confirmations required before payment is accepted.', 'dashpay-woocommerce' ),
                    'default' => '6',
                    // 'desc_tip'    => true,
                ),
                'exchange_multiplier' => array(
                    'title' => __('Exchange rate multiplier', 'dashpay-woocommerce' ),
                    'type' => 'text',
                    'description' => 'Extra multiplier to apply to convert store default currency to price.',
                    'default' => '1.00',
                    // 'desc_tip'    => true,
                ),
                'insight_api_url' => array(
                    'title' => __('Insight API url', 'dashpay-woocommerce' ),
                    'description' => 'This plugin requires a running instance of Insight-API. You may use the default, or provide your own for greater security.',
                    'type' => 'text',
                    'default' => static::$defaultInsight,
                    // 'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __( 'Description', 'woocommerce' ),
                    'type' => 'textarea',
                    'description' => __( 'Payment method description that the customer will see on your checkout.', 'dashpay-woocommerce' ),
                    'default' => __( 'Please proceed to the next screen to see necessary payment details.', 'dashpay-woocommerce' ),
                    // 'desc_tip'    => true,
                ),
            );
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         *
         * @access public
         * @return void
         */
        public function admin_options() {
            echo '<h3>' . __('Pay with ' . static::$currency, 'dashpay-woocommerce') . "</h3>\n";
            echo '<p>' . __('Enables direct ' . static::$currency . ' payments with WooCommerce. ' . static::$currencyDesc, 'dashpay-woocommerce') . "</p>\n";

            $error_message = $this->check_valid();

            if ( $error_message ) {
                $msg = sprintf(
                    esc_html__(static::$currency . ' payment gateway NOT operational. %s', 'dashpay-woocommerce'),
                    $error_message
                );
                self::_OLD_admin_error( $msg );
            }
            else {
                self::_OLD_admin_success(__(static::$currency . ' payment gateway is operational.','dashpay-woocommerce'));
            }

            // Generate the HTML for the settings form
            echo "<table class=\"form-table\">\n";
            $this->generate_settings_html();
            echo "</table>\n";
        }

        // Hook into admin options saving
        public function process_admin_options() {
            parent::process_admin_options();
        }

        /**
         * Process the payment and return the result
         *
         * @access public
         * @param int $order_id
         * @return array
         */
        public function process_payment( $order_id ) {
            global $woocommerce;
            $order = new WC_Order($order_id);

            add_action( 'wp_enqueue_scripts', array( $this, 'add_ajax_order_check') );

            try {
                // Pull exchange rate
                $exchange_rate = DP_Exchange_Rate::get_exchange_rate(static::$currency_ticker_symbol, get_woocommerce_currency());
            }
            catch (\Exception $e) {
                $msg = "Error retrieving exchange rate.";
                exit ('<h2 style="color:red;">' . $msg . '</h2>');
            }

            $order_total_in_coins = bcdiv(
                (string) $order->get_total(),
                (string) $exchange_rate,
                8
            );

            if ( 1.0 != $exchange_rate ) {
                $order_total_in_coins = bcmul(
                    (string) $order_total_in_coins,
                    (string) $this->exchange_multiplier,
                    8
                );
            }

            $expires_in_hours = 1;
            $expires_at = (time() + ($expires_in_hours * 60 * 60));

            $order_info = array(
                'order_id' => $order_id,
                'payment_currency' => static::$currency,
                'xpub' => $this->correct_xpub_key(),
                'expires_at' => $expires_at,
                'requested_by_ip' => @$_SERVER['REMOTE_ADDR'],
                'order_total_coins' => $order_total_in_coins,
            );

            $invoice = DP_Invoice::create( $order_info );

            $woocommerce->cart->empty_cart();

            return array(
                'result'   => 'success',
                'redirect'    => $this->get_return_url( $order )
            );
        }

        /**
         * Output for the order received page.
         *
         * @access public
         * @return void
         */
        public function thankyou_page( $order_id ) {
            $invoice = new DP_Invoice($order_id);

            // wrap instructions here in this div...
            $instructions = '<div id="payment_instructions">' .
                $invoice->paymentInstructionsHTML()
                . '</div>';
            echo wpautop(wptexturize($instructions));
        }

        /**
         * Add content to the WC emails.
         *
         * @access public
         * @param WC_Order $order
         * @param bool $sent_to_admin
         * @param bool $plain_text
         * @return void
         */
        public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
            $invoice = new DP_Invoice( $order->id );
            if ( !$sent_to_admin && 'dashpay-woocommerce' === $order->payment_method && $order->has_status('pending') ) {
                echo wpautop(wptexturize( $invoice->paymentInstructionsHTML() )) . PHP_EOL;
            }
        }

        // == WooCommerce Hooks
        public static function add_gateway_to_woocommerce( $methods ) {
            $methods[] = static::CLASS;
            return $methods;
        }

        public static function add_this_currency($currencies) {
            $currencies[static::$currency_ticker_symbol] = __( static::$currency, 'dashpay-woocommerce' );
            return $currencies;
        }

        public static function add_this_currency_symbol($currency_symbol, $currency) {
            switch( $currency ) {
                case static::$currency_ticker_symbol:
                    $currency_symbol = static::$currency_symbol;
                    break;
            }
            return $currency_symbol;
        }

        /**
         * Javascript for checking order status from an Ajax call
         *
         * @access public
         * @return void
         */
        public function add_ajax_order_check() {
            wp_enqueue_script( 'jquery-base64', plugins_url( '/assets/js/jquery.base64.js?' . time(), DP_PLUGIN_FILE), array( 'jquery' ));
            wp_enqueue_script( 'check_order_status', plugins_url( '/assets/js/check_order_status.js?' . time(), DP_PLUGIN_FILE), array( 'jquery' ));
            wp_localize_script(
                'check_order_status',
                'obj',
                array( 'ajaxurl' => WC()->ajax_url() )
            );
        }

        /**
         * Return properly serialized extended public key
         *
         * @access protected
         * @return void
         */
        protected function correct_xpub_key() {
            // must be valid xpub
            if ( ! CoinUtil::is_valid_public_xkey( $this->xpub_key ) ) {
                return '';
            }
            $correct_xpub_key = CoinUtil::reserialize_key( $this->xpub_key, static::$currency );
            return $correct_xpub_key;
        }

        protected static function _admin_error($msg) {
            echo '<div class="notice notice-error"><p>' . $msg . '</p></div>';
        }

        protected static function _OLD_admin_error($msg) {
            echo '<p style="border:1px solid #ebccd1;padding:5px 10px;font-weight:bold;color:#a94442;background-color:#f2dede;">' . $msg . '</p>';
        }

        protected static function _OLD_admin_success($msg) {
            echo '<p style="border:1px solid #d6e9c6;padding:5px 10px;font-weight:bold;color:#3c763d;background-color:#dff0d8;">' . $msg . '</p>';
        }

    }

endif;

return 'WC_Gateway_CryptoBase';

