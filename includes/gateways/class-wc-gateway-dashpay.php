<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WC_Gateway_DashPay' ) ) :

/**
 * Dash Payment Gateway
 *
 * Provides a Dash Payment Gateway
 *
 * @class    WC_Gateway_DashPay
 * @extends  WC_Payment_Gateway
 * @version  0.0.1
 * @package  DashPayments
 * @author   "Black Carrot Ventures" <nmarley@blackcarrot.be>
 */
class WC_Gateway_DashPay extends WC_Gateway_CryptoBase {
    protected static $currency = 'Dash';
    protected static $currencyDesc = '<a href="https://www.dash.org/" target="_blank">Dash</a> is a peer-to-peer, decentralized digital currency that enables instant payments from anyone to anyone, anywhere in the world.';
    protected static $currencyUrl = 'https://dash.org';
    protected static $iconPath = '/assets/images/dash-32x.png';
    protected static $defaultInsight = 'https://insight.dash.org';
    protected static $walletUrl = 'https://dash.org/downloads';
    protected static $walletTitle = 'Electrum Dash Wallet';
    protected static $currency_ticker_symbol = 'DASH';
    protected static $currency_symbol = 'D';
}

endif;

return 'WC_Gateway_DashPay';

