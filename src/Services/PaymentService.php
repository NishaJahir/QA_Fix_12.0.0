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

namespace Novalnet\Services;

use Plenty\Modules\Basket\Models\Basket;
use Novalnet\Services\SettingsService;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Modules\Frontend\Services\AccountService;
use Novalnet\Helper\PaymentHelper;
use Novalnet\Constants\NovalnetConstants;
use Plenty\Modules\Helper\Services\WebstoreHelper;
use Novalnet\Services\TransactionService;
use Plenty\Modules\Plugin\DataBase\Contracts\DataBase;
use Plenty\Modules\Plugin\DataBase\Contracts\Query;
use Novalnet\Models\TransactionLog;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Plugin\Log\Loggable;

/**
 * Class PaymentService
 *
 * @package Novalnet\Services
 */
class PaymentService
{
    use Loggable;
    
    /**
     * @var SettingsService
    */
    private $settingsService;
    
    /**
     * @var PaymentHelper
     */
    private $paymentHelper;
    
    /**
     * @var WebstoreHelper
     */
    private $webstoreHelper;
    
    /**
     * @var AddressRepositoryContract
     */
    private $addressRepository;
    
    /**
     * @var CountryRepositoryContract
     */
    private $countryRepository;
    
    /**
     * @var FrontendSessionStorageFactoryContract
     */
    private $sessionStorage;
    
    /**
     * @var TransactionService
     */
    private $transactionService;
    
    /**
     * @var PaymentRepositoryContract
     */
    private $paymentRepository;
    
    /**
     * @var redirectPayment
     */
    private $redirectPayment = ['NOVALNET_APPLEPAY', 'NOVALNET_IDEAL', 'NOVALNET_SOFORT', 'NOVALNET_GIROPAY', 'NOVALNET_PRZELEWY24', 'NOVALNET_EPS', 'NOVALNET_PAYPAL', 'POSTFINANCE_CARD', 'POSTFINANCE_EFINANCE', 'NOVALNET_BANCONTACT', 'NOVALNET_ONLINE_BANK_TRANSFER', 'NOVALNET_ALIPAY', 'NOVALNET_WECHAT_PAY', 'NOVALNET_TRUSTLY'];
    
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService
     * @param PaymentHelper $paymentHelper
     * @param WebstoreHelper $webstoreHelper
     * @param AddressRepositoryContract $addressRepository
     * @param CountryRepositoryContract $countryRepository
     * @param FrontendSessionStorageFactoryContract $sessionStorage
     * @param TransactionService $transactionService
     * @param PaymentRepositoryContract $paymentRepository,
     */
    public function __construct(SettingsService $settingsService,
                                PaymentHelper $paymentHelper,
                                WebstoreHelper $webstoreHelper,
                                AddressRepositoryContract $addressRepository,
                                CountryRepositoryContract $countryRepository,
                                FrontendSessionStorageFactoryContract $sessionStorage,
                                TransactionService $transactionService,
                                PaymentRepositoryContract $paymentRepository
                               )
    {
        $this->settingsService = $settingsService;
        $this->paymentHelper = $paymentHelper;
        $this->webstoreHelper = $webstoreHelper;
        $this->addressRepository  = $addressRepository;
        $this->countryRepository  = $countryRepository;
        $this->sessionStorage  = $sessionStorage;
        $this->transactionService = $transactionService;
        $this->paymentRepository = $paymentRepository;
    }
    
    /**
     * Check if the merchant details configured
     *
     * @return bool
     */
    public function isMerchantConfigurationValid()
    {
        return (bool) ($this->settingsService->getNnPaymentSettingsValue('novalnet_public_key') != '' && $this->settingsService->getNnPaymentSettingsValue('novalnet_private_key') != '' && $this->settingsService->getNnPaymentSettingsValue('novalnet_tariff_id') != '');
    }
    
    /**
     * Show payment for allowed countries
     *
     * @param string $allowed_country
     *
     * @return bool
     */
    public function allowedCountries(Basket $basket, $allowed_country) 
    {
        $allowed_country = str_replace(' ', '', strtoupper($allowed_country));
        $allowed_country_array = explode(',', $allowed_country);    
        try {
            if(!is_null($basket) && $basket instanceof Basket && !empty($basket->customerInvoiceAddressId)) {         
                $billingAddressId = $basket->customerInvoiceAddressId;              
                $billingAddress = $this->paymentHelper->getCustomerBillingOrShippingAddress((int) $billingAddressId);
                $country = $this->countryRepository->findIsoCode($billingAddress->countryId, 'iso_code_2');
                if(!empty($billingAddress) && !empty($country) && in_array($country,$allowed_country_array)) {                             
                        return true;
                }
            }
        } catch(\Exception $e) {
            return false;
        }
        return false;
    }
    
    /**
     * Show payment for Minimum Order Amount
     *
     * @param object $basket
     * @param int $minimum_amount
     *
     * @return bool
     */
    public function getMinBasketAmount(Basket $basket, $minimum_amount) 
    {   
        if(!is_null($basket) && $basket instanceof Basket) {
            $amount = $this->paymentHelper->ConvertAmountToSmallerUnit($basket->basketAmount);
            if(!empty($minimum_amount) && $minimum_amount<=$amount)    {
                return true;
            }
        } 
        return false;
    }
    
    /**
     * Show payment for Maximum Order Amount
     *
     * @param object $basket
     * @param int $maximum_amount
     *
     * @return bool
     */
    public function getMaxBasketAmount(Basket $basket, $maximum_amount) 
    {   
        if(!is_null($basket) && $basket instanceof Basket) {
            $amount = $this->paymentHelper->ConvertAmountToSmallerUnit($basket->basketAmount);
            if(!empty($maximum_amount) && $maximum_amount>=$amount)    {
            
                return true;
            }
        } 
        return false;
    }
    
    /**
     * Build payment parameters to server
     *
     * @param  Basket $basket
     * @param  string $paymentKey
     * 
     * @return array
     */
    public function generatePaymentParams(Basket $basket, $paymentKey = '')
    {
        $this->getLogger(__METHOD__)->error('basket', $basket);
        // Get the customer billing and shipping details
        $billingAddressId = $basket->customerInvoiceAddressId;
        $shippingAddressId = $basket->customerShippingAddressId;
        $billingAddress = $this->paymentHelper->getCustomerBillingOrShippingAddress((int) $billingAddressId);
        $shippingAddress = $billingAddress;
        if(!empty($shippingAddressId)) {
            $shippingAddress = $this->paymentHelper->getCustomerBillingOrShippingAddress((int) $shippingAddressId);
        }
        
        // Get the customer name if the salutation as Person
        $customerName = $this->getCustomerName($billingAddress);
        
        // Get the customerId
        $account = pluginApp(AccountService::class);
        $customerId = $account->getAccountContactId();
        
        // Get the testMode value
        $paymentKeyLower = strtolower((string) $paymentKey);
        $testModeKey = $this->settingsService->getNnPaymentSettingsValue('test_mode', $paymentKeyLower);
        
        /** @var \Plenty\Modules\Frontend\Services\VatService $vatService */
        $vatService = pluginApp(\Plenty\Modules\Frontend\Services\VatService::class);

        //we have to manipulate the basket because its stupid and doesnt know if its netto or gross
        if(!count($vatService->getCurrentTotalVats())) {
            $basket->itemSum = $basket->itemSumNet;
            $basket->shippingAmount = $basket->shippingAmountNet;
            $basket->basketAmount = $basket->basketAmountNet;
        }
        
        // Build the Payment Request Parameters
        $paymentRequestData = [];
        
        // Building the merchant Data
        $paymentRequestData['merchant'] = [
                                            'signature'    => $this->settingsService->getNnPaymentSettingsValue('novalnet_public_key'),
                                            'tariff'       => $this->settingsService->getNnPaymentSettingsValue('novalnet_tariff_id')
                                          ];
                                          
        // Building the customer Data
        $paymentRequestData['customer'] = [
                                            'first_name'   => $billingAddress->firstName ?? $customerName['firstName'],
                                            'last_name'    => $billingAddress->lastName ?? $customerName['lastName'],
                                            'gender'       => $billingAddress->gender ?? 'u',
                                            'email'        => $billingAddress->email,
                                            'customer_no'  => $customerId ?? 'guest',
                                            'customer_ip'  => $this->paymentHelper->getRemoteAddress()
                                          ];
        
        if(!empty($billingAddress->phone)) { // Check if phone field is given
            $paymentRequestData['customer']['tel'] = $billingAddress->phone;
        }
        
        // Obtain the required billing and shipping details from the customer address object        
        $billingShippingDetails = $this->paymentHelper->getRequiredBillingShippingDetails($billingAddress, $shippingAddress);
        
        $paymentRequestData['customer'] = array_merge($paymentRequestData['customer'], $billingShippingDetails);
        
        // If the billing and shipping are equal, we notify it too 
        if($paymentRequestData['customer']['billing'] == $paymentRequestData['customer']['shipping']) {
            $paymentRequestData['customer']['shipping']['same_as_billing'] = '1';
        }
        
        if(!empty($billingAddress->companyName)) { // Check if company field is given in the billing address
            $paymentRequestData['customer']['billing']['company'] = $billingAddress->companyName;
        }
        
        if(!empty($shippingAddress->companyName)) { // Check if company field is given in the shipping address
            $paymentRequestData['customer']['shipping']['company'] = $shippingAddress->companyName;
        }
        
        if(!empty($billingAddress->state)) { // Check if state field is given in the billing address
            $paymentRequestData['customer']['billing']['state'] = $billingAddress->state;
        }
        
        if(!empty($shippingAddress->state)) { // Check if state field is given in the shipping address
            $paymentRequestData['customer']['shipping']['state'] = $shippingAddress->state;
        }
        
        if(empty($billingAddress->companyName) && !empty($billingAddress->birthday) && in_array($paymentKey, ['NOVALNET_GUARANTEED_INVOICE', 'NOVALNET_GUARANTEED_SEPA'])) { // check if birthday field is given in the billing address
            $paymentRequestData['customer']['birth_date'] = $billingAddress->birthday;
        }
        
        // Building the transaction Data
        $paymentRequestData['transaction'] = [
                                               'test_mode' => ($testModeKey == true) ? 1 : 0,
                                               'amount'    => $this->paymentHelper->ConvertAmountToSmallerUnit($basket->basketAmount),
                                               'currency'  => $basket->currency,
                                               'system_name'   => 'Plentymarkets',
                                               'system_version' => NovalnetConstants::PLUGIN_VERSION,
                                               'system_url' => $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl,
                                               'system_ip'  => $_SERVER['SERVER_ADDR']
                                             ];
        
        // Build the custom parameters
        $paymentRequestData['custom'] = [
                                           'lang' => strtoupper($this->sessionStorage->getLocaleSettings()->language)
                                        ];
        
        // Build additional specific payment method request parameters
        $paymentUrl = $this->getPaymentData($paymentRequestData, $paymentKey);
    
        return [
            'paymentRequestData' => $paymentRequestData,
            'paymentUrl' => $paymentUrl
        ];
    }

    /**
     * Get customer name if the salutation as Person
     *
     * @param object $billingAddress
     *
     * @return array
     */
    public function getCustomerName($billingAddress) 
    {
        foreach($billingAddress->options as $option) {
            if($option->typeId == 12) {
                    $customerName = $option->value;
            }
        }
        $customerName = explode(' ', $customerName);
        $firstName = $customerName[0];
            if( count( $customerName ) > 1 ) {
                unset($customerName[0]);
                $lastName = implode(' ', $customerName);
            } else {
                $lastName = $firstName;
            }
        $firstName = empty ($firstName) ? $lastName : $firstName;
        $lastName = empty ($lastName) ? $firstName : $lastName;
        return ['firstName' => $firstName, 'lastName' => $lastName];
    }
    
    /**
     * Get the additional payment request parameters
     *
     * @param array $paymentRequestData
     * @param string $paymentKey
     *
     * @return string
     */
    public function getPaymentData(&$paymentRequestData, $paymentKey)
    {
        $paymentUrl = ($paymentKey == 'NOVALNET_APPLEPAY') ? NovalnetConstants::PAYGATE_URL : NovalnetConstants::PAYMENT_URL;
    
        // Sent the payment authorize call to Novalnet server if the authorization is enabled
        if(in_array($paymentKey, ['NOVALNET_INVOICE', 'NOVALNET_CC']) && !empty($this->settingsService->getNnPaymentSettingsValue('payment_action', strtolower($paymentKey)))) {
            // Limit for the manual on-hold
            $authorizeAmount = $this->settingsService->getNnPaymentSettingsValue('onhold_amount', strtolower($paymentKey));

            // "Authorization" activated if the manual limit is configured and the order amount exceeds it 
            if(!empty($authorizeAmount) && is_numeric($authorizeAmount) && $paymentRequestData['transaction']['amount'] > $authorizeAmount) {
               $paymentUrl = NovalnetConstants::PAYMENT_AUTHORIZE_URL;
            }
        }
        
        $paymentRequestData['transaction']['payment_type'] = $this->getNnPaymentType($paymentKey);
        
        // Send due date to the Novalnet server if it configured
        if(in_array($paymentKey, ['NOVALNET_INVOICE', 'NOVALNET_SEPA'])) {
            $dueDate = $this->settingsService->getNnPaymentSettingsValue('due_date', strtolower($paymentKey));
            if(is_numeric($dueDate)) {
                $paymentRequestData['transaction']['due_date'] = $this->paymentHelper->dateFormatter($dueDate);
            }
        }
        
        // Send enforce cc value to Novalnet server
        if($paymentKey == 'NOVALNET_CC' && $this->settingsService->getNnPaymentSettingsValue('enforce', $paymentKey) == true) {
             $paymentRequestData['transaction']['payment_data']['enforce_3d'] = 1;
        }
        
        if($this->isRedirectPayment($paymentKey)) {
            $paymentRequestData['transaction']['return_url'] = $this->getReturnPageUrl();
        }
        
        if($paymentKey == 'NOVALNET_APPLEPAY') {
            $paymentRequestData['hosted_page']['hide_blocks'] = ['ADDRESS_FORM', 'SHOP_INFO', 'LANGUAGE_MENU', 'TARIFF'];
            $paymentRequestData['hosted_page']['display_payments'] = ['APPLEPAY'];
        }
        
        return $paymentUrl;
    }
    
    /**
     * Get the Novalnet payment types
     *
     * @param string $paymentKey
     *
     * @return string
     */
    public function getNnPaymentType($paymentKey)
    {
        $paymentMethodType = [
            'NOVALNET_SEPA' => 'DIRECT_DEBIT_SEPA',
            'NOVALNET_CC' => 'CREDITCARD',
            'NOVALNET_APPLEPAY' => 'APPLEPAY',
            'NOVALNET_INVOICE' => 'INVOICE',
            'NOVALNET_PREPAYMENT' => 'PREPAYMENT',
            'NOVALNET_GUARANTEED_INVOICE' => 'GUARANTEED_INVOICE',
            'NOVALNET_GUARANTEED_SEPA' => 'GUARANTEED_DIRECT_DEBIT_SEPA',
            'NOVALNET_IDEAL' => 'IDEAL',
            'NOVALNET_SOFORT' => 'ONLINE_TRANSFER',
            'NOVALNET_GIROPAY' => 'GIROPAY',
            'NOVALNET_CASHPAYMENT' => 'CASHPAYMENT',
            'NOVALNET_PRZELEWY' => 'PRZELEWY24',
            'NOVALNET_EPS' => 'EPS',
            'NOVALNET_PAYPAL' => 'PAYPAL',
            'NOVALNET_POSTFINANCE_CARD' => 'POSTFINANCE_CARD',
            'NOVALNET_POSTFINANCE_EFINANCE' => 'POSTFINANCE',
            'NOVALNET_BANCONTACT' => 'BANCONTACT',
            'NOVALNET_MULTIBANCO' => 'MULTIBANCO',
            'NOVALNET_ONLINE_BANK_TRANSFER' => 'ONLINE_BANK_TRANSFER',
            'NOVALNET_ALIPAY' => 'ALIPAY',
            'NOVALNET_WECHAT_PAY' => 'WECHATPAY',
            'NOVALNET_TRUSTLY' => 'TRUSTLY',
            'NOVALNET_GOOGLEPAY' => 'GOOGLEPAY'
        ];
        
        return $paymentMethodType[$paymentKey];
    }
    
    /**
     * Check if the payment is redirection or not
     *
     * @param string $paymentKey
     * @param bool $doRedirect
     * 
     * return bool
     */
    public function isRedirectPayment($paymentKey) 
    {
        return (bool) (in_array($paymentKey, $this->redirectPayment));
    }
    
    /**
     * Get the payment response controller URL to be handled
     *
     * @return string
     */
    public function getReturnPageUrl()
    {   
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/' . $this->sessionStorage->getLocaleSettings()->language . '/payment/novalnet/paymentResponse/';
    }
    
    /**
     * Send the payment call request to Novalnet server
     *
     * @return array|none
     */
    public function performServerCall()
    {
        $paymentRequestData = $this->sessionStorage->getPlugin()->getValue('nnPaymentData');
        $paymentRequestData['paymentRequestData']['transaction']['order_no'] = $this->sessionStorage->getPlugin()->getValue('nnOrderNo');
        $this->getLogger(__METHOD__)->error('Payment Request', $paymentRequestData);
        $paymentKey = $this->sessionStorage->getPlugin()->getValue('paymentkey');
        $privateKey = $this->settingsService->getNnPaymentSettingsValue('novalnet_private_key');
        $paymentResponseData = $this->paymentHelper->executeCurl($paymentRequestData['paymentRequestData'], $paymentRequestData['paymentUrl'], $privateKey);
        $isPaymentSuccess = isset($paymentResponseData['result']['status']) && $paymentResponseData['result']['status'] == 'SUCCESS';
        $nnDoRedirect = $this->sessionStorage->getPlugin()->getValue('nnDoRedirect');
        $this->getLogger(__METHOD__)->error('Payment Response', $paymentResponseData);
        // Do redirect if the redirect URL is present
        if($isPaymentSuccess && ($this->isRedirectPayment($paymentKey) || !empty($nnDoRedirect))) {
            return $paymentResponseData;
        } else {
            // Push notification to customer regarding the payment response
            if($isPaymentSuccess) {
                $this->pushNotification($paymentResponseData['result']['status_text'], 'success', 100);
            } else {
                $this->pushNotification($paymentResponseData['result']['status_text'], 'error', 100);
            }
        
            // Set the payment response in the session for the further processings
            $this->sessionStorage->getPlugin()->setValue('nnPaymentData', array_merge($paymentRequestData['paymentRequestData'], $paymentResponseData));
            // Handle the further process to the order based on the payment response
            $this->HandlePaymentResponse();
        }
    }
    
    
    /**
     * Push notification for the success and failure case
     * 
     * @param string $message
     * @param string $type
     * @param int $code
     *
     */
    public function pushNotification($message, $type, $code = 0) 
    {
        
        $notifications = json_decode($this->sessionStorage->getPlugin()->getValue('notifications'), true);  
            
        $notification = [
                'message'       => $message,
                'code'          => $code,
                'stackTrace'    => []
               ];
            
        $lastNotification = $notifications[$type];

        if(!is_null($lastNotification)) {
                $notification['stackTrace'] = $lastNotification['stackTrace'];
                $lastNotification['stackTrace'] = [];
                array_push( $notification['stackTrace'], $lastNotification );
            }
            $notifications[$type] = $notification;
            $this->sessionStorage->getPlugin()->setValue('notifications', json_encode($notifications));
    }
    
    /**
     * Validate the checksum generated for redirection payments
     *
     * @param array $paymentResponseData
     * 
     * @return array
     */
    public function validateChecksumAndGetTxnStatus($paymentResponseData)
    {
        if($paymentResponseData['status'] && $paymentResponseData['status'] == 'SUCCESS') {
            
            $nnTxnSecret = $this->sessionStorage->getPlugin()->getValue('nnTxnSecret');
            $strRevPrivateKey = $this->paymentHelper->reverseString($this->settingsService->getNnPaymentSettingsValue('novalnet_private_key'));
           
            // Condition to check whether the payment is redirect
            if(!empty($paymentResponseData['checksum']) && !empty($paymentResponseData['tid']) && !empty($nnTxnSecret)) {                            
                $generatedChecksum = hash('sha256', $paymentResponseData['tid'] . $nnTxnSecret . $paymentResponseData['status'] . $strRevPrivateKey);
                // If the checksum isn't matching, there could be a possible manipulation in the data received 
                if($generatedChecksum !== $paymentResponseData['checksum']) {                              
                    $paymentResponseData['nn_checksum_invalid'] = $this->paymentHelper->getTranslatedText('checksum_error');
                    return $paymentResponseData;
                }
            }
                                          
            $paymentRequestData = [];
            $paymentRequestData['transaction']['tid'] = $paymentResponseData['tid'];
            
            $privatekey = $this->settingsService->getNnPaymentSettingsValue('novalnet_private_key');
            return $this->paymentHelper->executeCurl($paymentRequestData, NovalnetConstants::TXN_RESPONSE_URL, $privatekey);
        } else {
            $paymentResponseData['nn_checksum_invalid'] = $paymentResponseData['status_text'];
            return $paymentResponseData;
        }                  
    }
    
    /**
     * Handle the further processing after the payment call getting success or failure
     *
     * @return none
     */
    public function HandlePaymentResponse()
    {
        $nnPaymentData = $this->sessionStorage->getPlugin()->getValue('nnPaymentData');
        $this->sessionStorage->getPlugin()->setValue('nnPaymentData', null);
        
        $nnPaymentData['mop']            = $this->sessionStorage->getPlugin()->getValue('mop');
        $nnPaymentData['payment_method'] = strtolower($this->paymentHelper->getPaymentKeyByMop($nnPaymentData['mop']));
        
        // Set the cashpayment token to session      
        if($nnPaymentData['payment_method'] == 'novalnet_cashpayment' && !empty($nnPaymentData['transaction']['checkout_token']) && $nnPaymentData['transaction']['status'] == 'PENDING') {
            $this->sessionStorage->getPlugin()->setValue('novalnetCheckoutToken', $nnPaymentData['transaction']['checkout_token']);
            $this->sessionStorage->getPlugin()->setValue('novalnetCheckoutUrl', $nnPaymentData['transaction']['checkout_js']);        
        }
        
        // Insert payment response into Novalnet table
        $this->insertPaymentResponseIntoNnDb($nnPaymentData);
        
        // Create a plenty payment to the order
        $this->paymentHelper->createPlentyPaymentToNnOrder($nnPaymentData);
    }
    
    /**
     * Insert the payment response into Novalnet database
     *
     * @return none
     */
    public function insertPaymentResponseIntoNnDb($paymentResponseData, $parentTid = 0, $refundOrderTotalAmount = 0, $creditOrderTotalAmount = 0)
    {
         // Assign the payment method
        if(empty($paymentResponseData['payment_method'])) {
            $paymentResponseData['payment_method'] = $this->paymentHelper->getNnPaymentKey($paymentResponseData['transaction']['payment_type']); 
        }
        
        $additionalInfo = $this->additionalPaymentInfo($paymentResponseData);
        
        $orderTotalAmount = 0;
        // Set the order total amount for Refund and Credit followups
        if(!empty($refundOrderTotalAmount) || !empty($creditOrderTotalAmount)) {
            $orderTotalAmount = $refundOrderTotalAmount ?? $creditOrderTotalAmount;
        }

         $transactionData = [
            'order_no'         => $paymentResponseData['transaction']['order_no'],
            'amount'           => !empty($orderTotalAmount) ? $orderTotalAmount : $paymentResponseData['transaction']['amount'],
            'callback_amount'  => $paymentResponseData['transaction']['refund']['amount'] ?? $paymentResponseData['transaction']['amount'],
            'tid'              => !empty($parentTid) ? $parentTid : $paymentResponseData['transaction']['tid'],
            'ref_tid'          => $paymentResponseData['transaction']['refund']['tid'] ?? $paymentResponseData['transaction']['tid'],
            'payment_name'     => $paymentResponseData['payment_method'],
            'additional_info'  => $additionalInfo ?? 0,
        ];
        
        if($transactionData['payment_name'] == 'NOVALNET_INVOICE' || $paymentResponseData['result']['status'] != 'SUCCESS') {
            $transactionData['callback_amount'] = 0;
        }

        $this->transactionService->saveTransaction($transactionData);
    }
    
    /**
     * Form the required payment information for the database entry
     *
     * @param array $paymentResponseData
     * 
     * @return string
     */
    public function additionalPaymentInfo($paymentResponseData)
    {
        $lang = !empty($paymentResponseData['custom']['lang']) ? strtolower((string)$paymentResponseData['custom']['lang']) : $paymentResponseData['lang'];
        
        // Add the extra information for the further processing
        $additionalInfo = [
                            'currency' => $paymentResponseData['transaction']['currency'] ?? 0,
                            'test_mode' => !empty($paymentResponseData['transaction']['test_mode']) ? $this->paymentHelper->getTranslatedText('test_order',$lang) : 0,
                            'plugin_version' => $paymentResponseData['transaction']['system_version'] ?? NovalnetConstants::PLUGIN_VERSION,
                          ];
        // Add the Bank details for the invoice payments                   
        if($paymentResponseData['result']['status'] == 'SUCCESS' && (in_array($paymentResponseData['payment_method'], ['novalnet_invoice', 'novalnet_prepayment']))) {
            $additionalInfo['invoice_account_holder'] = $paymentResponseData['transaction']['bank_details']['account_holder'];
            $additionalInfo['invoice_iban'] = $paymentResponseData['transaction']['bank_details']['iban'];
            $additionalInfo['invoice_bic'] = $paymentResponseData['transaction']['bank_details']['bic'];
            $additionalInfo['invoice_bankname'] = $paymentResponseData['transaction']['bank_details']['bank_name'];
            $additionalInfo['invoice_bankplace'] = $paymentResponseData['transaction']['bank_details']['bank_place'];
            $additionalInfo['due_date'] = $paymentResponseData['transaction']['due_date'];
            $additionalInfo['invoice_ref'] = $paymentResponseData['transaction']['invoice_ref'];
        }
        
        // Add the store details for the cashpayment
        if($paymentResponseData['result']['status'] == 'SUCCESS' && $paymentResponseData['payment_method'] == 'novalnet_cashpayment') {
            $additionalInfo['store_details'] = $paymentResponseData['transaction']['nearest_stores'];
            $additionalInfo['cp_due_date'] = $paymentResponseData['transaction']['due_date'];
        }
        
        // Add the pament reference details for the Multibanco
        if($paymentResponseData['result']['status'] == 'SUCCESS' && $paymentResponseData['payment_method'] == 'novalnet_multibanco') {
            $additionalInfo['partner_payment_reference'] = $paymentResponseData['transaction']['partner_payment_reference'];
            $additionalInfo['service_supplier_id'] = $paymentResponseData['transaction']['service_supplier_id'];
        }
        
        // Add the type param when the refund was executed
        if(isset($paymentResponseData['refund'])) {
            $additionalInfo['type'] = 'debit';
        }
        
        // Add the type param when the credit was executed
        if(isset($paymentResponseData['credit'])) {
            $additionalInfo['type'] = 'credit';
        }
           
        return json_encode($additionalInfo);
    }
    
    /**
     * Evaluate the Guaranteed payments conditions
     *
     * @param object $basket
     * @param string $paymentKey
     * 
     * @return string
     */
    public function isGuaranteePaymentToBeDisplayed(Basket $basket, $paymentKey)
    {
        try {
            if(!is_null($basket) && $basket instanceof Basket && !empty($basket->customerInvoiceAddressId)) {
                // Check if the guaranteed payment method is enabled
                if($this->settingsService->getNnPaymentSettingsValue('payment_active', $paymentKey) == true) {

                    // Get the customer billing and shipping details
                    if(!empty($basket->customerInvoiceAddressId)) {
                       $billingAddress = $this->paymentHelper->getCustomerBillingOrShippingAddress((int) $basket->customerInvoiceAddressId);
                       $shippingAddress = $billingAddress; 
                    }
                    
                    if(!empty($basket->customerShippingAddressId)) {
                        $shippingAddress = $this->paymentHelper->getCustomerBillingOrShippingAddress((int) $basket->customerShippingAddressId);
                    }

                    // Get the billing and shipping details
                    $billingShippingDetails = $this->paymentHelper->getRequiredBillingShippingDetails($billingAddress, $shippingAddress);
                    
                    // Set the minimum guaranteed amount
                    $configuredMinimumGuaranteedAmount = $this->settingsService->getNnPaymentSettingsValue('minimum_guaranteed_amount', $paymentKey);

                    $minimumGuaranteedAmount = !empty($configuredMinimumGuaranteedAmount) ? $configuredMinimumGuaranteedAmount : 999;
                    
                    // Get the basket total amount
                    $basketAmount = !empty($basket->basketAmount) ? $this->paymentHelper->ConvertAmountToSmallerUnit($basket->basketAmount) : 0;
                    
                    // First, we check the billing and shipping addresses are matched
                    // Second, we check the customer from the guaranteed payments supported countries
                    // Third, we check if the supported currency is selected
                    // Finally, we check if the minimum order amount configured to process the payment method. By default, the minimum order amount is 999 cents
                    if( $billingShippingDetails['billing'] == $billingShippingDetails['shipping'] && 
                        (in_array($billingShippingDetails['billing']['country_code'], ['AT', 'DE', 'CH']) || ($this->settingsService->getNnPaymentSettingsValue('allow_b2b_customer', $paymentKey) && 
                        in_array($billingShippingDetails['billing']['country_code'], $this->getEuropeanRegionCountryCodes()))) && 
                        (!empty($basket->currency) && $basket->currency == 'EUR') && 
                        (!empty($minimumGuaranteedAmount) &&  (int) $minimumGuaranteedAmount <= (int) $basketAmount)) {
                        // If the guaranteed conditions are met, display the guaranteed payments
                        return 'guarantee';
                    }

                    // Further we check if the normal payment method can be enabled if the condition not met 
                    if($this->settingsService->getNnPaymentSettingsValue('force', $paymentKey) == true) {
                        return 'normal';
                    }

                    // If none matches, error message displayed 
                    return 'error'; 
                }
                // If payment guarantee is not enabled, we show default one 
                return 'normal';
            }
            // If payment guarantee is not enabled, we show default one 
            return 'normal';
        } catch(\Exception $e) {
            $this->getLogger(__METHOD__)->error('Novalnet::isGuaranteePaymentToBeDisplayedFailed', $e);
        }
    }
    
    /**
     * Returning the list of the European Union countries for checking the country code of Guaranteed customer 
     *     
     * @return array
     */
    public function getEuropeanRegionCountryCodes()
    {
        return ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'UK', 'CH'];
    }
    
    /**
    * Get the direct payment process controller URL to be handled
    *
    * @return string
    */
    public function getProcessPaymentUrl()
    {
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/' . $this->sessionStorage->getLocaleSettings()->language . '/payment/novalnet/processPayment/';
    }
    
    /**
     * Collecting the Credit Card for the initial authentication call to PSP
     *
     * @param object $basket
     * @param string $paymentKey
     * @param int $orderAmount
     * 
     * @return string
     */
    public function getCreditCardAuthenticationCallData(Basket $basket, $paymentKey) 
    {
        
        // Get the customer billing and shipping details
        if(!empty($basket->customerInvoiceAddressId)) {
           $billingAddress = $this->paymentHelper->getCustomerBillingOrShippingAddress((int) $basket->customerInvoiceAddressId);
           $shippingAddress = $billingAddress; 
        }
        
        if(!empty($basket->customerShippingAddressId)) {
            $shippingAddress = $this->paymentHelper->getCustomerBillingOrShippingAddress((int) $basket->customerShippingAddressId);
        }
        
        // Get the customer name if the salutation as Person
        $customerName = $this->getCustomerName($billingAddress);
        
        /** @var \Plenty\Modules\Frontend\Services\VatService $vatService */
        $vatService = pluginApp(\Plenty\Modules\Frontend\Services\VatService::class);
        
        //we have to manipulate the basket because its stupid and doesnt know if its netto or gross
        if(!count($vatService->getCurrentTotalVats())) {
            $basket->itemSum = $basket->itemSumNet;
            $basket->shippingAmount = $basket->shippingAmountNet;
            $basket->basketAmount = $basket->basketAmountNet;
        }
        
        $ccFormRequestParameters = [
            'client_key' => trim($this->settingsService->getNnPaymentSettingsValue('novalnet_client_key')),
            'inline_form' => (int)($this->settingsService->getNnPaymentSettingsValue('inline_form', $paymentKey) == true),
            'enforce_3d' => (int)($this->settingsService->getNnPaymentSettingsValue('enforce', $paymentKey) == true),
            'test_mode'  => (int)($this->settingsService->getNnPaymentSettingsValue('test_mode', $paymentKey) == true),
            'first_name' => $billingAddress->firstName ?? $customerName['firstName'],
            'last_name'  => $billingAddress->lastName ?? $customerName['lastName'],
            'email'      => $billingAddress->email,
            'street'     => $billingAddress->street,
            'house_no'   => $billingAddress->houseNumber,
            'city'       => $billingAddress->town,
            'zip'        => $billingAddress->postalCode,
            'country_code' => $this->countryRepository->findIsoCode($billingAddress->countryId, 'iso_code_2'),
            'amount'     => $this->paymentHelper->ConvertAmountToSmallerUnit($basket->basketAmount),
            'currency'   => $basket->currency,
            'lang'       => strtoupper($this->sessionStorage->getLocaleSettings()->language)
        ];
        
        // Obtain the required billing and shipping details from the customer address object        
        $billingShippingDetails = $this->paymentHelper->getRequiredBillingShippingDetails($billingAddress, $shippingAddress);
        
        // Assign the shipping details
        $ccFormRequestParameters['shipping'] = $billingShippingDetails['shipping'];
        
        if($billingShippingDetails['billing'] == $billingShippingDetails['shipping']) {
            $ccFormRequestParameters['same_as_billing'] = 1;
        }
        
        return json_encode($ccFormRequestParameters);
    }
    
    /**
     * Retrieves Credit Card form style set in payment configuration and texts present in language files
     *
     * @return string
     */
    public function getCcFormFields()
    {
        $ccformFields = [];

        $styleConfiguration = array('standard_style_label', 'standard_style_field', 'standard_style_css');

        foreach($styleConfiguration as $value) {
            $ccformFields[$value] = trim($this->settingsService->getNnPaymentSettingsValue($value, 'novalnet_cc'));
        }

        $textFields = array( 'template_novalnet_cc_holder_Label', 'template_novalnet_cc_holder_input', 'template_novalnet_cc_number_label', 'template_novalnet_cc_number_input', 'template_novalnet_cc_expirydate_label', 'template_novalnet_cc_expirydate_input', 'template_novalnet_cc_cvc_label', 'template_novalnet_cc_cvc_input', 'template_novalnet_cc_error' );

        foreach($textFields as $value) {
            $ccformFields[$value] = $this->paymentHelper->getCustomizedTranslatedText($value);
        }
        return json_encode($ccformFields);
    }
    
    /**
     * Get database values
     *
     * @param int $orderId
     *
     * @return array
     */
    public function getDatabaseValues($orderId) 
    {
        
        $database = pluginApp(DataBase::class);
        // Get transaction details from the Novalnet database table
        $transactionDetails = $database->query(TransactionLog::class)->where('orderNo', '=', $orderId)->get();
        if(!empty($transactionDetails)) {
            foreach($transactionDetails as $transactionDetail) {
                 $endTransactionDetail = $transactionDetail; // Set the end of the transaction details
            }
            //Typecasting object to array
            $nnTransactionDetail = (array) $endTransactionDetail;
            $nnTransactionDetail['order_no'] = $nnTransactionDetail['orderNo'];
            $nnTransactionDetail['amount'] = $nnTransactionDetail['amount'] / 100;
            if(!empty($nnTransactionDetail['additionalInfo'])) {
               //Decoding the json as array
                $nnTransactionDetail['additionalInfo'] = json_decode($nnTransactionDetail['additionalInfo'], true);
                //Merging the array
                $nnTransactionDetail = array_merge($nnTransactionDetail, $nnTransactionDetail['additionalInfo']);
                //Unsetting the redundant key
                unset($nnTransactionDetail['additionalInfo']); 
            } else {
                unset($nnTransactionDetail['additionalInfo']);   
            }
            return $nnTransactionDetail;
        }
        return [];
    }
    
    /**
     * Form the Novalnet transaction comments
     *
     * @param array $transactionData
     *
     * @return string
     */
    public function formTransactionComments($transactionData)
    {
        $transactionComments = '';
        
        // Display the Novalnet transaction Id
        if(!empty($transactionData['tid'])) {
            $transactionComments .= PHP_EOL . $this->paymentHelper->getTranslatedText('nn_tid') . $transactionData['tid'];
        }
        
        // Display the text if the transaction processed in test mode
        if(!empty($transactionData['test_mode'])) {
            $transactionComments .= PHP_EOL . $this->paymentHelper->getTranslatedText('test_order');
        }
        
        // Display the text if the transaction was made with Guaranteed payments
        if(in_array($transactionData['paymentName'], ['novalnet_guaranteed_invoice', 'novalnet_guaranteed_sepa']) || in_array($transactionData['payment_id'], ['40','41'])) {
            $transactionComments .= PHP_EOL . $this->paymentHelper->getTranslatedText('guarantee_text');
            if($transactionData['paymentName'] == 'novalnet_guaranteed_invoice' && $transactionData['tx_status'] == 'PENDING') {
                $transactionComments .= PHP_EOL . $this->paymentHelper->getTranslatedText('guarantee_invoice_pending_payment_text');
            } elseif($transactionData['paymentName'] == 'novalnet_guaranteed_sepa' && $transactionData['tx_status'] == 'PENDING') {
                $transactionComments .= PHP_EOL . $this->paymentHelper->getTranslatedText('guarantee_sepa_pending_payment_text');
            }
        }
        
        // Form the bank details for invoice payments
        if(in_array($transactionData['paymentName'], ['novalnet_invoice', 'novalnet_prepayment', 'novalnet_guaranteed_invoice'])) {
            $transactionComments .= PHP_EOL . $this->getBankDetailsInformation($transactionData);
        }
        
        // Form the cashpayment comments
        if($transactionData['paymentName'] == 'novalnet_cashpayment') {
            if(!empty($transactionData['cashpayment_comments'])) {
                $transactionComments .= PHP_EOL . $transactionData['cashpayment_comments'];
            } else {
                $transactionComments .= PHP_EOL . $this->getStoreInformation($transactionData);
            }
        }
        
        // Form the Multibanco payment reference 
        if($transactionData['paymentName'] == 'novalnet_multibanco') {
            $transactionComments .= PHP_EOL . $this->getMultibancoReferenceInformation($transactionData);
        }
        
        return $transactionComments;
    }
    
    /**
     * Get the transaction status as string
     *
     * @param int $txStatus
     * @param int $nnPaymentTypeId
     *
     * @return string
     */
    public function getTxStatusAsString($txStatus, $nnPaymentTypeId)
    {
        if(is_numeric($txStatus)) {
            if(in_array($txStatus, [85, 91, 98, 99])) {
                return 'ON_HOLD';
            } elseif(in_array($txStatus, [75, 86, 90])) {
                return 'PENDING';
            } elseif($txStatus == 100) {
                if(in_array($nnPaymentTypeId, [27, 59])) {
                    return 'PENDING';
                } else {
                    return 'CONFIRMED';
                }
            } elseif($txStatus == 103) {
                return 'DEACTIVATED';
            } else {
                return 'FAILURE';
            }
        }
        return $txStatus;
    }
    
    /**
     * Form the Novalnet bank details transaction comments
     *
     * @param array $transactionData
     *
     * @return string
     */
    public function getBankDetailsInformation($transactionData)
    {
        $invoiceComments = '';
        // If the transaction is in On-Hold not displaying the due date
        if($transactionData['tx_status'] != 'ON_HOLD') {
            $invoiceComments .= PHP_EOL . sprintf($this->paymentHelper->getTranslatedText('transfer_amount_duedate_text'), $transactionData['amount'], $transactionData['currency'], date('Y/m/d', (int)strtotime($transactionData['due_date'])));
        } else {
            $invoiceComments .= PHP_EOL . PHP_EOL . sprintf($this->paymentHelper->getTranslatedText('transfer_amount_text'), $transactionData['amount'], $transactionData['currency']);    
        }
        
        $invoiceComments .= PHP_EOL . $this->paymentHelper->getTranslatedText('account_holder_novalnet') . $transactionData['invoice_account_holder'];
        $invoiceComments .= PHP_EOL . $this->paymentHelper->getTranslatedText('iban') . $transactionData['invoice_iban'];
        $invoiceComments .= PHP_EOL . $this->paymentHelper->getTranslatedText('bic') . $transactionData['invoice_bic'];
        $invoiceComments .= PHP_EOL . $this->paymentHelper->getTranslatedText('bank') . $transactionData['invoice_bankname']. ' ' . $transactionData['invoice_bankplace'];
        
        // Adding the payment reference details 
        $invoiceComments .= PHP_EOL . $this->paymentHelper->getTranslatedText('any_one_reference_text');
        $invoiceComments .= PHP_EOL . $this->paymentHelper->getTranslatedText('payment_reference1'). 'TID '. $transactionData['tid'] . PHP_EOL . $this->paymentHelper->getTranslatedText('payment_reference2') . $transactionData['invoice_ref'] . PHP_EOL;

        return $invoiceComments;
    }
    
    /**
     * Form the cashpayment store details comments
     *
     * @param array $transactionData
     *
     * @return string
     */
    public function getStoreInformation($transactionData)
    {        
        $cashpaymentComments  = PHP_EOL . $this->paymentHelper->getTranslatedText('cashpayment_expire_date') . $transactionData['cp_due_date'];
        $cashpaymentComments .= PHP_EOL . $this->paymentHelper->getTranslatedText('cashpayment_stores_near_you');
        
        // We loop in each of them to print those store details 
        for($storePos = 1; $storePos <= count( $transactionData['store_details']); $storePos++) {
            $cashpaymentComments .= PHP_EOL .  $transactionData['store_details'][$storePos]['store_name'];
            $cashpaymentComments .= PHP_EOL . utf8_encode( $transactionData['store_details'][$storePos]['street']);
            $cashpaymentComments .= PHP_EOL .  $transactionData['store_details'][$storePos]['city'];
            $cashpaymentComments .= PHP_EOL .  $transactionData['store_details'][$storePos]['zip'];
            $cashpaymentComments .= PHP_EOL .  $transactionData['store_details'][$storePos]['country_code'];
            $cashpaymentComments .= PHP_EOL;
        }
        
        return $cashpaymentComments;
    }
    
    /**
     * Form the Multibanco transaction comments
     *
     * @param array $transactionData
     * 
     * @return string
     */
    public function getMultibancoReferenceInformation($transactionData)
    {
        $multibancoComments  = PHP_EOL . sprintf($this->paymentHelper->getTranslatedText('multibanco_reference_text'), $transactionData['amount'], $transactionData['currency'] );
        $multibancoComments .= PHP_EOL . $this->paymentHelper->getTranslatedText('multibanco_reference_one') . $transactionData['partner_payment_ref'];
        $multibancoComments .= PHP_EOL . $this->paymentHelper->getTranslatedText('multibanco_reference_two') . $transactionData['service_supplier_id'];
        
        return $multibancoComments;
    }
    
    /**
     * Process the transaction capture/void 
     *
     * @param array $paymentRequestData
     * @param string $paymentUrl
     *
     * @return none
     */
    public function doCaptureVoid($paymentRequestData, $paymentUrl)
    {
        try {
            // Novalnet access key
            $privateKey = $this->settingsService->getNnPaymentSettingsValue('novalnet_private_key');
            
            $paymentRequestData = [
                                    'tid' => $paymentRequestData['tid'],
                                    'lang' => 'DE'
                                  ];
            
            // Send the payment capture/void call to Novalnet server
            $paymentResponseData = $this->paymentHelper->executeCurl($paymentRequestData, $paymentUrl, $privateKey);
            
            $paymentResponseData = array_merge($paymentRequestData, $paymentResponseData);

            // Booking Message 
            if(in_array($paymentResponseData['transaction']['status'], ['PENDING', 'CONFIRMED'])) {
                $paymentResponseData['bookingText'] = sprintf($this->paymentHelper->getTranslatedText('transaction_confirmation', $paymentRequestData['lang']), date('d.m.Y'), date('H:i:s'));
            } else {
                $paymentResponseData['bookingText'] = sprintf($this->paymentHelper->getTranslatedText('transaction_cancel', $paymentRequestData['lang']), date('d.m.Y'), date('H:i:s'));
            }
            
            // Get the Novalnet payment methods Id
            $mop = $this->paymentHelper->getPaymentMethodByKey(strtoupper($paymentRequestData['paymentName']));
            $paymentResponseData['mop'] = $mop[0];

            // Insert the updated transaction details into Novalnet DB
            $this->insertPaymentResponseIntoNnDb($paymentResponseData);
            
            // Create the payment to the plenty order
            $this->paymentHelper->createPlentyPaymentToNnOrder($paymentResponseData);
        } catch(\Exception $e) {
            $this->getLogger(__METHOD__)->error('Novalnet::doCaptureVoid failed ' . $paymentRequestData['order_no'], $e);
        }
    }
    
    /**
    * Get required details from payment object and Novalnet database
    *
    * @param int $orderId
    *
    * @return array
    */
    public function getDetailsFromPaymentProperty($orderId)
    {
        // Get the payment details
        $paymentDetails = $this->paymentRepository->getPaymentsByOrderId($orderId);
        
        // Fetch the necessary data
        foreach($paymentDetails as $paymentDetail) {
            $paymentProperties = $paymentDetail->properties;
            foreach($paymentProperties as $paymentProperty) {
                  if($paymentProperty->typeId == 1) {
                    $tid = $paymentProperty->value;
                  }
                  if($paymentProperty->typeId == 30) {
                    $txStatus = $paymentProperty->value;
                  }
                  if($paymentProperty->typeId == 21) {
                     $invoiceDetails = $paymentProperty->value;
                  }
            }
        }
        
        // Get Novalnet transaction details from the Novalnet database table
        $nnDbTxDetails = $this->getDatabaseValues($orderId);
        
        // Merge the array if bank details are there
        if(!empty($invoiceDetails)) {
            $nnDbTxDetails = array_merge($nnDbTxDetails, json_decode($invoiceDetails, true));
        }
        
        // Get the transaction status as string for the previous payment plugin version
        $nnDbTxDetails['tx_status'] = $this->getTxStatusAsString($txStatus, $nnDbTxDetails['payment_id']);
        
        return $nnDbTxDetails;
    }
    
    /**
     * Get refund status
     * 
     * @param int $orderId
     * @param int $orderAmount
     * 
     * @return string
     */
    public function getRefundStatus($orderId, $orderAmount)
    {
        // Get the transaction details for an order
        $transactionDetails = $this->transactionService->getTransactionData('orderNo', $orderId);
        
        $totalCallbackDebitAmount = 0;

        foreach($transactionDetails as $transactionDetail) {
            if($transactionDetail->referenceTid != $transactionDetail->tid) {
                if(!empty($transactionDetail->additionalInfo)) {
                    $additionalInfo = json_decode($transactionDetail->additionalInfo, true);
                    if($additionalInfo['type'] == 'debit') {
                        $totalCallbackDebitAmount += $transactionDetail->callbackAmount;  
                    }
                } else {
                    $totalCallbackDebitAmount += $transactionDetail->callbackAmount;
                }
            }
        }
        
        $refundStatus = ($orderAmount > $totalCallbackDebitAmount) ? 'Partial' : 'Full';
        
        return $refundStatus;
    }
}
