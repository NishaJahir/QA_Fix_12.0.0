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

namespace Novalnet\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Modules\Basket\Events\Basket\AfterBasketCreate;
use Plenty\Modules\Basket\Events\Basket\AfterBasketChanged;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Payment\Events\Checkout\ExecutePayment;
use Plenty\Modules\Payment\Events\Checkout\GetPaymentMethodContent;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodContainer;
use Novalnet\Helper\PaymentHelper;
use Novalnet\Services\PaymentService;
use Plenty\Modules\Wizard\Contracts\WizardContainerContract;
use Novalnet\Assistants\NovalnetAssistant;
use Novalnet\Methods\NovalnetPaymentAbstract;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Novalnet\Constants\NovalnetConstants;
use Plenty\Plugin\Templates\Twig;
use Plenty\Modules\EventProcedures\Services\Entries\ProcedureEntry;
use Plenty\Modules\EventProcedures\Services\EventProceduresService;
use Plenty\Modules\Order\Pdf\Events\OrderPdfGenerationEvent;
use Plenty\Modules\Order\Pdf\Models\OrderPdfGeneration;
use Plenty\Modules\Document\Models\Document;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Novalnet\Services\SettingsService;
use Plenty\Plugin\Log\Loggable;

/**
 * Class NovalnetServiceProvider
 *
 * @package Novalnet\Providers
 */
class NovalnetServiceProvider extends ServiceProvider
{
    use Loggable;

    /**
     * Register the route service provider
     */
    public function register()
    {
        $this->getApplication()->register(NovalnetRouteServiceProvider::class);
    }

    /**
     * Boot additional services for the payment method
     * 
     * @param Dispatcher $eventDispatcher
     * @param BasketRepositoryContract $basketRepository
     * @param PaymentMethodContainer $payContainer
     * @param PaymentHelper $paymentHelper
     * @param PaymentService $paymentService
     * @param FrontendSessionStorageFactoryContract $sessionStorage
     * @param Twig $twig
     * @param EventProceduresService $eventProceduresService
     * @param PaymentRepositoryContract $paymentRepository
     * @param SettingsService $settingsService
     */
    public function boot(Dispatcher $eventDispatcher,
                        BasketRepositoryContract $basketRepository,
                        PaymentMethodContainer $payContainer,
                        PaymentHelper $paymentHelper,
                        PaymentService $paymentService,
                        FrontendSessionStorageFactoryContract $sessionStorage,
                        Twig $twig,
                        EventProceduresService $eventProceduresService,
                        PaymentRepositoryContract $paymentRepository,
			SettingsService $settingsService
                        )
    {
        $this->registerPaymentMethods($payContainer);
        
        $this->registerPaymentRendering($eventDispatcher, $basketRepository, $paymentHelper, $paymentService, $sessionStorage, $twig, $settingsService);

        $this->registerPaymentExecute($eventDispatcher, $paymentHelper, $paymentService, $sessionStorage, $settingsService);
        
        $this->registerEvents($eventProceduresService);
        
        $this->invoicePdfGenerationEvent($eventDispatcher, $paymentService, $paymentHelper, $paymentRepository);
        
        pluginApp(WizardContainerContract::class)->register('payment-novalnet-assistant', NovalnetAssistant::class);
    }
     
    /**
     * Register the Novalnet payment methods in the payment method container
     *
     * @param PaymentMethodContainer $payContainer
     */
    protected function registerPaymentMethods(PaymentMethodContainer $payContainer)
    {
        foreach(PaymentHelper::getPaymentMethods() as $paymentMethodKey => $paymentMethodClass) {
            $payContainer->register('plenty_novalnet::' . $paymentMethodKey, $paymentMethodClass,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        }
    }
    
    /**
     * Rendering the Novalnet payment method content
     *
     * @param Dispatcher $eventDispatcher
     * @param BasketRepositoryContract $basketRepository
     * @param PaymentHelper $paymentHelper
     * @param PaymentService $paymentService
     * @param FrontendSessionStorageFactoryContract $sessionStorage
     * @param Twig $twig
     */
    protected function registerPaymentRendering(Dispatcher $eventDispatcher,
                                              BasketRepositoryContract $basketRepository,
                                              PaymentHelper $paymentHelper,
                                              PaymentService $paymentService,
                                              FrontendSessionStorageFactoryContract $sessionStorage,
                          		      Twig $twig,
					      SettingsService $settingsService
                                              )
    {
        // Listen for the event that gets the payment method content
        $eventDispatcher->listen(
            GetPaymentMethodContent::class, 
            function(GetPaymentMethodContent $event) use($basketRepository, $paymentHelper, $paymentService, $sessionStorage, $twig, $settingsService) {
                
            if($paymentHelper->getPaymentKeyByMop($event->getMop())) {
                $paymentKey = $paymentHelper->getPaymentKeyByMop($event->getMop());
                $paymentRequestData = $paymentService->generatePaymentParams($basketRepository->load(), $paymentKey);
                if(empty($paymentRequestData['paymentRequestData']['customer']['first_name']) && empty($paymentRequestData['paymentRequestData']['customer']['last_name'])) {
                    $content = $paymentHelper->getTranslatedText('nn_first_last_name_error');
                    $contentType = 'errorCode';   
                } else {
                    // Check if the birthday field needs to show for guaranteed payments
                    $showBirthday = ((!isset($paymentRequestData['paymentRequestData']['customer']['billing']['company']) && !isset($paymentRequestData['paymentRequestData']['customer']['birth_date'])) ||  (isset($paymentRequestData['paymentRequestData']['customer']['birth_date']) && time() < strtotime('+18 years', strtotime($paymentRequestData['paymentRequestData']['customer']['birth_date'])))) ? true : false;
                    // Handle the Direct, Redirect and Form payments content type
                    if(in_array($paymentKey, ['NOVALNET_INVOICE', 'NOVALNET_PREPAYMENT', 'NOVALNET_CASHPAYMENT', 'NOVALNET_MULTIBANCO']) || $paymentService->isRedirectPayment($paymentKey)  || ($paymentKey == 'NOVALNET_GUARANTEED_INVOICE' && $showBirthday == false)) {
                        $content = '';
                        $contentType = 'continue';
                    } elseif(in_array($paymentKey, ['NOVALNET_SEPA', 'NOVALNET_GUARANTEED_SEPA'])) {
                        $content = $twig->render('Novalnet::PaymentForm.NovalnetSepa', [
                                            'nnPaymentProcessUrl' => $paymentService->getProcessPaymentUrl(),
                                            'paymentMopKey' =>  $paymentKey,
                                            'paymentName' => $paymentHelper->getCustomizedTranslatedText('template_' . strtolower($paymentKey)),
                                            'showBirthday' => $showBirthday
                                            ]);
                        $contentType = 'htmlContent';
                    } elseif($paymentKey == 'NOVALNET_GUARANTEED_INVOICE' && $showBirthday == true) {
                        $content = $twig->render('Novalnet::PaymentForm.NovalnetGuaranteedInvoice', [
                                                'nnPaymentProcessUrl' => $paymentService->getProcessPaymentUrl(),
                                                'paymentMopKey' =>  $paymentKey,
                                                'paymentName' => $paymentHelper->getCustomizedTranslatedText('template_' . strtolower($paymentKey)),
                                                ]);
                        $contentType = 'htmlContent';
                    } elseif($paymentKey == 'NOVALNET_CC') {
                        $content = $twig->render('Novalnet::PaymentForm.NovalnetCc', [
                                                'nnPaymentProcessUrl' => $paymentService->getProcessPaymentUrl(),
                                                'paymentMopKey' =>  $paymentKey,
                                                'paymentName' => $paymentHelper->getCustomizedTranslatedText('template_' . strtolower($paymentKey)),
                                                'ccFormDetails' => $paymentService->getCreditCardAuthenticationCallData($basketRepository->load(), strtolower($paymentKey)),
                                                'ccCustomFields' => $paymentService->getCcFormFields() ?? ''
                                                ]);
                        $contentType = 'htmlContent';
                    }
                }
                $sessionStorage->getPlugin()->setValue('nnPaymentData', $paymentRequestData);
		// If payment before order creation option was set as 'No' the payment will be created initially
		if($settingsService->getNnPaymentSettingsValue('novalnet_order_creation') != true && (in_array($paymentKey, ['NOVALNET_INVOICE', 'NOVALNET_PREPAYMENT', 'NOVALNET_CASHPAYMENT', 'NOVALNET_MULTIBANCO']) || $paymentService->isRedirectPayment($paymentKey)  || ($paymentKey == 'NOVALNET_GUARANTEED_INVOICE' && $showBirthday == false))) {
	           $sessionStorage->getPlugin()->setValue('paymentkey', $paymentKey);
		   $privateKey = $settingsService->getNnPaymentSettingsValue('novalnet_private_key');
        	   $paymentResponseData = $paymentService->performServerCall();
		   if(!empty($paymentResponseData) && $paymentResponseData['result']['status'] != 'SUCCESS') {
			  $content = $paymentResponseData['result']['status_text'];
			  $contentType = 'errorCode';
		   }
		}
		
                $event->setValue($content);
                $event->setType($contentType);
            }            
        });
    }
    
     /**
     * Execute the Novalnet payment method
     *
     * @param Dispatcher $eventDispatcher
     * @param PaymentHelper $paymentHelper
     * @param PaymentService $paymentService
     * @param FrontendSessionStorageFactoryContract $sessionStorage
     */
    protected function registerPaymentExecute(Dispatcher $eventDispatcher,
                                              PaymentHelper $paymentHelper,
                                              PaymentService $paymentService,
                                              FrontendSessionStorageFactoryContract $sessionStorage,
					      SettingsService $settingsService
                                             )
    {
        // Listen for the event that executes the payment
        $eventDispatcher->listen(
            ExecutePayment::class,
            function (ExecutePayment $event) use ($paymentHelper, $paymentService, $sessionStorage, $settingsService)
            {
                if($paymentHelper->getPaymentKeyByMop($event->getMop())) {
                    $sessionStorage->getPlugin()->setValue('nnOrderNo',$event->getOrderId());
                    $sessionStorage->getPlugin()->setValue('mop',$event->getMop());
                    $paymentKey = $paymentHelper->getPaymentKeyByMop($event->getMop());
                    $sessionStorage->getPlugin()->setValue('paymentkey', $paymentKey);
		    if($settingsService->getNnPaymentSettingsValue('novalnet_order_creation') == true) {
			    $paymentResponseData = $paymentService->performServerCall();
			    $nnDoRedirect = $sessionStorage->getPlugin()->getValue('nnDoRedirect');
			    if($paymentService->isRedirectPayment($paymentKey) || !empty($nnDoRedirect)) {
				if(!empty($paymentResponseData) && !empty($paymentResponseData['result']['redirect_url']) && !empty($paymentResponseData['transaction']['txn_secret'])) {
				    // Transaction secret used for the later checksum verification
				    $sessionStorage->getPlugin()->setValue('nnTxnSecret', $paymentResponseData['transaction']['txn_secret']);
				    $sessionStorage->getPlugin()->setValue('nnDoRedirect', null);
				    $event->setType('redirectUrl');
				    $event->setValue($paymentResponseData['result']['redirect_url']);
				} else {
				   // Handle an error case and set the return type and value for the event.
				      $event->setType('error');
				      $event->setValue('The payment could not be executed!');
				}
			    }
		    } else {
			 // Handle the further process to the order based on the payment response
            		 $paymentService->HandlePaymentResponse();   
		   }
                }
            });
    }
    
    /**
     * Register the Novalnet events
     *
     * @param EventProceduresService $eventProceduresService
     */
    protected function registerEvents(EventProceduresService $eventProceduresService)
    {
        // Event for Onhold - Capture Process
        $captureProcedureTitle = [
            'de' => 'Novalnet | Bestätigen',
            'en' => 'Novalnet | Confirm',
        ];
        $eventProceduresService->registerProcedure(
            'Novalnet',
            ProcedureEntry::EVENT_TYPE_ORDER,
            $captureProcedureTitle,
            '\Novalnet\Procedures\CaptureEventProcedure@run'
        );
        
        // Event for Onhold - Void Process
        $voidProcedureTitle = [
            'de' => 'Novalnet | Stornieren',
            'en' => 'Novalnet | Cancel',
        ];
        $eventProceduresService->registerProcedure(
            'Novalnet',
            ProcedureEntry::EVENT_TYPE_ORDER,
            $voidProcedureTitle,
            '\Novalnet\Procedures\VoidEventProcedure@run'
        );
        
        // Event for Onhold - Refund Process
        $refundProcedureTitle = [
            'de' =>  'Novalnet | Rückerstattung',
            'en' =>  'Novalnet | Refund',
        ];
        $eventProceduresService->registerProcedure(
            'Novalnet',
            ProcedureEntry::EVENT_TYPE_ORDER,
            $refundProcedureTitle,
            '\Novalnet\Procedures\RefundEventProcedure@run'
        );
    }
    
    /**
     * Display the Novalnet transaction comments in the invoice PDF
     *
     * @param EventProceduresService $eventProceduresService
     */
    public function invoicePdfGenerationEvent(Dispatcher $eventDispatcher,
                                              PaymentService $paymentService,
                                              PaymentHelper $paymentHelper,
                                              PaymentRepositoryContract $paymentRepository)
    {
		$eventDispatcher->listen(
			OrderPdfGenerationEvent::class,
			function (OrderPdfGenerationEvent $event) use ($paymentService, $paymentHelper, $paymentRepository) {
			/** @var Order $order */ 
			$order = $event->getOrder();	
			$this->getLogger(__METHOD__)->error('order pro', $order);
			try {
				$payments = $paymentRepository->getPaymentsByOrderId($order->id);
				// Get Novalnet transaction details from the Novalnet database table
				$nnDbTxDetails = $paymentService->getDatabaseValues($order->id);
				if(!empty($nnDbTxDetails['plugin_version']) && strpos($nnDbTxDetails['paymentName'], 'novalnet') !== false) { // If Novalnet Payments do the invoice PDF process
					$transactionComments = '';
					$transactionComments .= $paymentService->displayTransactionComments($order->id, $payments);
					$orderPdfGenerationModel = pluginApp(OrderPdfGeneration::class);
					$orderPdfGenerationModel->advice = $paymentHelper->getTranslatedText('novalnet_details'). PHP_EOL . $transactionComments;
					if ($event->getDocType() == Document::INVOICE) { // Add the comments into Invoice PDF document
						$event->addOrderPdfGeneration($orderPdfGenerationModel); 
					}
				}
			} catch(\Exception $e) {
				$this->getLogger(__METHOD__)->error('Adding PDF comment failed for order ' . $order->id , $e);
			} 
			
		});
	}
}
