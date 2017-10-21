<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WC_Gateway_Bitcoin' ) ) :

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
    class WC_Gateway_Bitcoin extends WC_Gateway_CryptoBase {
        protected static $currency = 'Bitcoin';
        protected static $currencyDesc = '<a href="https://www.bitcoin.org/" target="_blank">Bitcoin</a> is a peer-to-peer, decentralized digital currency that enables instant payments from anyone to anyone, anywhere in the world.';
        protected static $currencyUrl = 'https://bitcoin.org';
        protected static $iconPath = '/assets/images/dash-32x.png';
        protected static $defaultInsight = 'https://insight.bitpay.com';
        protected static $walletUrl = 'https://electrum.org';
        protected static $walletTitle = 'Electrum Wallet';
        protected static $currency_ticker_symbol = 'BTC';
        protected static $currency_symbol = 'BTC';
    }

endif;

return 'WC_Gateway_Bitcoin';

