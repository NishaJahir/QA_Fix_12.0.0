<?php
/**
 * This module is used for real time processing of
 * Novalnet payment module of customers.
 * This free contribution made by request.
 * 
 * If you have found this script useful a small
 * recommendation as well as a comment on merchant form
 * would be greatly appreciated.
 *
 * @author       Novalnet AG
 * @copyright(C) Novalnet
 * All rights reserved. https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */

namespace Novalnet\Providers\DataProvider;

use Plenty\Plugin\Templates\Twig;
use Plenty\Modules\Basket\Models\Basket;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Novalnet\Helper\PaymentHelper;
use Plenty\Modules\Basket\Contracts\BasketItemRepositoryContract;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Novalnet\Services\PaymentService;
use Novalnet\Services\SettingsService;

/**
 * Class NovalnetGooglePayButtonDataProvider
 *
 * @package Novalnet\Providers\DataProvider
 */
class NovalnetGooglePayButtonDataProvider
{
    /**
     * Setup the Novalnet transaction comments for the requested order
     *
     * @param Twig $twig
     * @param BasketRepositoryContract $basketRepository
     * @param BasketItemRepositoryContract $basketItem
     * @param Arguments $arg
     * 
     * @return string
     */
    public function call(Twig $twig, 
                         BasketRepositoryContract $basketRepository, 
                         BasketItemRepositoryContract $basketItem,
                         $arg)
    {
        $basket = $basketRepository->load();
        $paymentHelper = pluginApp(PaymentHelper::class);
        $sessionStorage = pluginApp(FrontendSessionStorageFactoryContract::class);
        $paymentService = pluginApp(PaymentService::class);
        $settingsService = pluginApp(SettingsService::class);
        $orderAmount = 0;
        if(!empty($basket->basketAmount)) {
            // Get the order total basket amount
            $orderAmount = $paymentHelper->ConvertAmountToSmallerUnit($basket->basketAmount);
        }
        // Get the Payment MOP Id
        $paymentMethodDetails = $paymentHelper->getPaymentMethodByKey('NOVALNET_GOOGLEPAY');
        // Get the order language
        $orderLang = strtoupper($sessionStorage->getLocaleSettings()->language);
        // Required details for the Google Pay button
        $googlePayData = [
            'clientKey' => trim($settingsService->getNnPaymentSettingsValue('novalnet_client_key')),
            'MerchantId' => $this->settingsService->getNnPaymentSettingsValue('payment_active', 'novalnet_google_pay'),
            'sellerName' => $this->settingsService->getNnPaymentSettingsValue('business_name', 'novalnet_google_pay'),
            'enforce' => $this->settingsService->getNnPaymentSettingsValue('enforce', 'novalnet_google_pay'),
            'buttonType' => $this->settingsService->getNnPaymentSettingsValue('button_type', 'novalnet_google_pay'),
            'buttonTheme' => $this->settingsService->getNnPaymentSettingsValue('button_theme', 'novalnet_google_pay'),
            'buttonHeight' => $this->settingsService->getNnPaymentSettingsValue('button_height', 'novalnet_google_pay')
           ];
        
        
        // Render the Google Pay button
       return $twig->render('Novalnet::PaymentForm.NovalnetGooglePayButton', ['mopId' => $paymentMethodDetails->id, 'googlePayData' => $googlePayData, 'countryCode' => 'DE', 'orderTotalAmount' => $orderAmount, 'orderLang' => $orderLang, 'orderCurrency' => $basket->currency, 'nnPaymentProcessUrl' => $paymentService->getProcessPaymentUrl()]);
    }
}
