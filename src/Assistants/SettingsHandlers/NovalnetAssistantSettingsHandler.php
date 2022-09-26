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

namespace Novalnet\Assistants\SettingsHandlers;

use Plenty\Modules\Plugin\PluginSet\Contracts\PluginSetRepositoryContract;
use Plenty\Modules\Wizard\Contracts\WizardSettingsHandler;
use Novalnet\Helper\PaymentHelper;
use Novalnet\Services\SettingsService;

/**
 * Class NovalnetAssistantSettingsHandler
 *
 * @package Novalnet\Assistants\SettingsHandlers
 */
class NovalnetAssistantSettingsHandler implements WizardSettingsHandler
{   
    public function handle(array $postData)
    {
        /** @var PluginSetRepositoryContract $pluginSetRepo */
        $pluginSetRepo = pluginApp(PluginSetRepositoryContract::class);
        
        /** @var PaymentHelper $paymentHelper */
        $paymentHelper = pluginApp(PaymentHelper::class);
        
        $clientId = $postData['data']['clientId'];
        $pluginSetId = $pluginSetRepo->getCurrentPluginSetId();
        
        $data = $postData['data'];
        
        // Novalnet Global and Webhook Configuration values
        $novalnetSettings = [
            'novalnet_public_key' => $data['novalnetPublicKey'] ?? '',
            'novalnet_private_key' => $data['novalnetAccessKey'] ?? '',
            'novalnet_tariff_id' => $data['novalnetTariffId'] ?? '',
            'novalnet_client_key' => $data['novalnetClientKey'] ?? '',
            'novalnet_order_creation' => $data['novalnetOrderCreation'] ?? '',
            'novalnet_webhook_testmode' => $data['novalnetWebhookTestMode'] ?? '',
            'novalnet_webhook_email_to' => $data['novalnetWebhookEmailTo'] ?? '',
        ];
        
        // Payment method common configuration values
        foreach($paymentHelper->getPaymentMethodsKey() as $paymentMethodKey) {
            $paymentKey = str_replace('_','',ucwords(strtolower($paymentMethodKey),'_'));
            $paymentKey[0] = strtolower($paymentKey[0]);
            $paymentMethodKey = strtolower($paymentMethodKey);
            
            $novalnetSettings[$paymentMethodKey]['payment_active'] = $data[$paymentKey. 'PaymentActive'] ?? '';
            $novalnetSettings[$paymentMethodKey]['test_mode'] = $data[$paymentKey. 'TestMode'] ?? '';
            $novalnetSettings[$paymentMethodKey]['payment_log'] = $data[$paymentKey. 'PaymentLogo'] ?? '';
            $novalnetSettings[$paymentMethodKey]['minimum_order_amount'] = $data[$paymentKey. 'MinimumOrderAmount'] ?? '';
            $novalnetSettings[$paymentMethodKey]['maximum_order_amount'] = $data[$paymentKey. 'MaximumOrderAmount'] ?? '';
            $novalnetSettings[$paymentMethodKey]['allowed_country'] = $data[$paymentKey. 'AllowedCountry'] ?? '';
            
            switch ($paymentMethodKey) {
                case 'novalnet_cc':
                    $novalnetSettings[$paymentMethodKey]['enforce'] = $data[$paymentKey. 'Enforce'] ?? '';
                    $novalnetSettings[$paymentMethodKey]['inline_form'] = $data[$paymentKey. 'InlineForm'] ?? '';
                    $novalnetSettings[$paymentMethodKey]['logos'] = $data[$paymentKey. 'Logos'] ?? '';
                    $novalnetSettings[$paymentMethodKey]['standard_style_label'] = $data[$paymentKey. 'StandardStyleLabel'] ?? '';
                    $novalnetSettings[$paymentMethodKey]['standard_style_field'] = $data[$paymentKey. 'StandardStyleField'] ?? '';
                    $novalnetSettings[$paymentMethodKey]['standard_style_css'] = $data[$paymentKey. 'StandardStyleCss'] ?? '';
                    $novalnetSettings[$paymentMethodKey]['payment_action'] = $data[$paymentKey. 'PaymentAction'] ?? '';
                    $novalnetSettings[$paymentMethodKey]['onhold_amount'] = $data[$paymentKey. 'OnHold'] ?? '';
                    break;
                case 'novalnet_invoice':
                case 'novalnet_sepa':
                    $novalnetSettings[$paymentMethodKey]['due_date'] = $data[$paymentKey. 'Duedate'] ?? '';
                    $novalnetSettings[$paymentMethodKey]['payment_action'] = $data[$paymentKey. 'PaymentAction'] ?? '';
                    $novalnetSettings[$paymentMethodKey]['onhold_amount'] = $data[$paymentKey. 'OnHold'] ?? '';
                    break;
                case 'novalnet_prepayment':
                case 'novalnet_cashpayment':
                    $novalnetSettings[$paymentMethodKey]['due_date'] = $data[$paymentKey. 'Duedate'] ?? '';
                case 'novalnet_guaranteed_invoice':
                case 'novalnet_guaranteed_sepa':
                    $novalnetSettings[$paymentMethodKey]['force'] = $data[$paymentKey. 'force'] ?? '';
                    $novalnetSettings[$paymentMethodKey]['allow_b2b_customer'] = $data[$paymentKey. 'allowB2bCustomer'] ?? '';
                    $novalnetSettings[$paymentMethodKey]['minimum_guaranteed_amount'] = $data[$paymentKey. 'minimumGuaranteedAmount'] ?? '';
                    $novalnetSettings[$paymentMethodKey]['payment_action'] = $data[$paymentKey. 'PaymentAction'] ?? '';
                    $novalnetSettings[$paymentMethodKey]['onhold_amount'] = $data[$paymentKey. 'OnHold'] ?? '';
                case 'novalnet_paypal':
                    $novalnetSettings[$paymentMethodKey]['payment_action'] = $data[$paymentKey. 'PaymentAction'] ?? '';
                    $novalnetSettings[$paymentMethodKey]['onhold_amount'] = $data[$paymentKey. 'OnHold'] ?? '';
                
            }
        }
        
        /** @var SettingsService $settingsService */
        $settingsService = pluginApp(SettingsService::class);
        $settingsService->createOrUpdateNovalnetConfigurationSettings($novalnetSettings, $clientId, $pluginSetId);
        
        return true;
    }
}
