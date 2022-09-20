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
 
namespace Novalnet\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Order\Models\Order;
use Novalnet\Services\PaymentService;
use Novalnet\Constants\NovalnetConstants;

/**
 * Class CaptureEventProcedure
 * 
 * @package Novalnet\Procedures
 */
class CaptureEventProcedure
{
    /**
     *
     * @var PaymentService
     */
    private $paymentService;
    
    /**
     * Constructor.
     *
     * @param PaymentService $paymentService
     */
     
    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService; 
    }   
    
    /**
     * @param EventProceduresTriggered $eventTriggered
     * 
     */
    public function run(EventProceduresTriggered $eventTriggered) 
    {
        /* @var $order Order */
        $order = $eventTriggered->getOrder(); 
        
        // Get necessary information for the capture process
        $transactionDetails = $this->paymentService->getDetailsFromPaymentProperty($order->id);
        
        // Call the Capture process for the On-Hold payments
        if($transactionDetails['tx_status'] == 'ON_HOLD') {
            $this->paymentService->doCaptureVoid($transactionDetails, NovalnetConstants::PAYMENT_CAPTURE_URL);
        }
    }
}
