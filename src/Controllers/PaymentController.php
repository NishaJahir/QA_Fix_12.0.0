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

namespace Novalnet\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Novalnet\Services\PaymentService;
use Novalnet\Services\SettingsService;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Plugin\Log\Loggable;

/**
 * Class PaymentController
 *
 * @package Novalnet\Controllers
 */
class PaymentController extends Controller
{
    use Loggable;
    /**
     * @var Request
     */
    private $request;

    /**
     * @var Response
     */
    private $response;
    
    /**
     * @var PaymentService
     */
    private $paymentService;
    
    /**
     * @var SettingsService
    */
    private $settingsService;
    
    /**
     * @var FrontendSessionStorageFactoryContract
     */
    private $sessionStorage;
    
    /**
     * @var BasketRepositoryContract
     */
    private $basketRepository;

    /**
     * PaymentController constructor.
     *
     * @param Request $request
     * @param Response $response
     * @param PaymentService $paymentService
     * @param SettingsService $settingsService
     * @param FrontendSessionStorageFactoryContract $sessionStorage
     * @param BasketRepositoryContract $basketRepository
     */
    public function __construct(Request $request,
                                Response $response,
                                PaymentService $paymentService,
                                SettingsService $settingsService,
                                FrontendSessionStorageFactoryContract $sessionStorage,
                                BasketRepositoryContract $basketRepository
                               )
    {

        $this->request         = $request;
        $this->response        = $response;
        $this->paymentService  = $paymentService;
        $this->settingsService = $settingsService;
        $this->sessionStorage  = $sessionStorage;
        $this->basketRepository = $basketRepository;
    }

    /**
     * Novalnet redirects to this page if the payment was executed successfully
     *
     */
    public function paymentResponse()
    {
        
        // Get the initial payment call response
        $paymentResponseData = $this->request->all();
       
        // Checksum validation for redirects
        if(!empty($paymentResponseData['tid'])) {
            
            if($paymentResponseData['status'] == 'SUCCESS') {
                // Checksum validation and transaction status call to retrieve the full response
                $paymentResponseData = $this->paymentService->validateChecksumAndGetTxnStatus($paymentResponseData);

                // Checksum validation is failure return back to the customer to confirmation page with error message
                if(!empty($paymentResponseData['nn_checksum_invalid'])) {
                    $this->paymentService->pushNotification($paymentResponseData['nn_checksum_invalid'], 'error', 100);
                    return $this->response->redirectTo($this->sessionStorage->getLocaleSettings()->language . '/confirmation');
                }

                $isPaymentSuccess = isset($paymentResponseData['result']['status']) && $paymentResponseData['result']['status'] == 'SUCCESS';

                if($isPaymentSuccess) {
                    $this->paymentService->pushNotification($paymentResponseData['result']['status_text'], 'success', 100);
                } else {
                    $this->paymentService->pushNotification($paymentResponseData['result']['status_text'], 'error', 100);    
                }
            } else {
                $this->paymentService->pushNotification($paymentResponseData['status_text'], 'error', 100);
            }
           
            $paymentRequestData = $this->sessionStorage->getPlugin()->getValue('nnPaymentData');
            
            // Set the payment response in the session for the further processings
            $this->sessionStorage->getPlugin()->setValue('nnPaymentData', array_merge_recursive($paymentRequestData, $paymentResponseData));
            // Handle the further process to the order based on the payment response
            $this->paymentService->HandlePaymentResponse();
            
            return $this->response->redirectTo($this->sessionStorage->getLocaleSettings()->language . '/confirmation');
            
        } else {
            $this->paymentService->pushNotification($paymentResponseData['status_text'], 'error', 100);  
            return $this->response->redirectTo($this->sessionStorage->getLocaleSettings()->language . '/confirmation');
        }
    }
    
    /**
     * Process the Form payment
     *
     */
    public function processPayment()
    {
        // Get the payment form post data
        $paymentRequestPostData = $this->request->all();
        
        // Get the payment request params
        $paymentRequestData = $this->paymentService->generatePaymentParams($this->basketRepository->load(), $paymentRequestPostData['nn_payment_key']);
        
        // Setting up the account data to the server for SEPA processing
        if(in_array($paymentRequestPostData['nn_payment_key'], ['NOVALNET_SEPA', 'NOVALNET_GUARANTEED_SEPA'])) {
            $paymentRequestData['paymentRequestData']['transaction']['payment_data'] = [
                                                                'account_holder' => $paymentRequestData['paymentRequestData']['customer']['first_name'] .' '. $paymentRequestData['paymentRequestData']['customer']['last_name'],
                                                                'iban'           => $paymentRequestPostData['nn_sepa_iban']
                                                             ];
        }
        
        // Setting up the birthday for guaranteed payments
        if(in_array($paymentRequestPostData['nn_payment_key'], ['NOVALNET_GUARANTEED_INVOICE', 'NOVALNET_GUARANTEED_SEPA']) && !empty($paymentRequestPostData['nn_show_dob'])) {
            $paymentRequestData['paymentRequestData']['customer']['birth_date'] = sprintf('%4d-%02d-%02d', $paymentRequestPostData['nn_guarantee_year'], $paymentRequestPostData['nn_guarantee_month'], $paymentRequestPostData['nn_guarantee_date']);
        }
        
        // Setting up the alternative card data to the server for card processing
        if($paymentRequestPostData['nn_payment_key'] == 'NOVALNET_CC') {
            $paymentRequestData['paymentRequestData']['transaction']['payment_data'] = [
                                                                    'pan_hash'   => $paymentRequestPostData['nn_pan_hash'],
                                                                    'unique_id'  => $paymentRequestPostData['nn_unique_id']
                                                                 ];
            // Set the Do redirect value into session for the redirection                                                     
            $this->sessionStorage->getPlugin()->setValue('nnDoRedirect', $paymentRequestPostData['nn_cc3d_redirect']);
            if(!empty($paymentRequestPostData['nn_cc3d_redirect'])) {
                $paymentRequestData['paymentRequestData']['transaction']['return_url'] = $this->paymentService->getReturnPageUrl();
            }
        }
        
        // Setting up the wallet token for the Google pay payment
        if($paymentRequestPostData['nn_payment_key'] == 'NOVALNET_GOOGLEPAY') {  
            $paymentRequestData['paymentRequestData']['transaction']['payment_data'] = [
                                                                        'wallet_token'  => $paymentRequestPostData['nn_google_pay_token']
                                                                     ];
        }
        
        // Set the payment requests in the session for the further processings
        $this->sessionStorage->getPlugin()->setValue('nnPaymentData', $paymentRequestData);
        
        if(!empty($paymentRequestPostData['nn_reinitializePayment'])) {
            $this->paymentService->performServerCall();
            return $this->response->redirectTo($this->sessionStorage->getLocaleSettings()->language . '/confirmation');
        } else {
            // Call the shop executePayment function
            return $this->response->redirectTo($this->sessionStorage->getLocaleSettings()->language . '/place-order');
        }
    }
    
    /**
     * Process the direct payment methods when the change payment method option used
     *
     */
    public function directPaymentProcess()
    {
        
        $paymentResponseData = $this->paymentService->performServerCall();
        $paymentKey = $this->sessionStorage->getPlugin()->getValue('paymentkey');
       
        if($this->paymentService->isRedirectPayment($paymentKey)) {
            if(!empty($paymentResponseData) && !empty($paymentResponseData['result']['redirect_url']) && !empty($paymentResponseData['transaction']['txn_secret'])) {
                // Transaction secret used for the later checksum verification
                $this->getLogger(__METHOD__)->error('if res', $paymentResponseData);
                $this->sessionStorage->getPlugin()->setValue('nnTxnSecret', $paymentResponseData['transaction']['txn_secret']);
                $this->response->redirectTo($paymentResponseData['result']['redirect_url']);
            } else {
                // Redirect to confirmation page
                $this->paymentService->pushNotification($paymentResponseData['result']['status_text'], 'error', 100);  
                return $this->response->redirectTo($this->sessionStorage->getLocaleSettings()->language . '/confirmation');
            }
        }
    }
}
