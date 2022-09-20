<?php
/**
 * This file is used for synchronize with Novalnet to shopsystem
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
use Plenty\Plugin\Templates\Twig;
use Novalnet\Helper\PaymentHelper;
use Novalnet\Services\PaymentService;
use Novalnet\Services\SettingsService;
use \Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Novalnet\Constants\NovalnetConstants;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Novalnet\Services\TransactionService;
use Plenty\Modules\Payment\Models\PaymentProperty;
use \stdClass;
use Plenty\Plugin\Log\Loggable;

/**
 * Class WebhookController
 *
 * @package Novalnet\Controllers
 */
class WebhookController extends Controller
{
    use Loggable;
    
    /**
     * @var eventData
     */
    protected $eventData = [];
    
    /**
     * @var Twig
     */
    private $twig;
    
    /**
     * @var PaymentHelper
     */
    private $paymentHelper;
    
    /**
     * @var paymentService
     */
    private $paymentService;
    
    /**
     * @var ipAllowed
     * @IP-ADDRESS Novalnet IP, is a fixed value, DO NOT CHANGE!!!!!
     */
    protected $ipAllowed = ['213.95.190.5', '213.95.190.7'];
    
    /**
     * @var SettingsService
    */
    private $settingsService;
    
    /**
     * @var eventType
     */
    protected $eventType;

    /**
     * @var eventTid
     */
    protected $eventTid;
        
    /**
     * @var parentTid
     */
    protected $parentTid;
    
    /**
     * @var object
     */
    private $orderDetails;
    
    /**
     * @var string
     */
    private $orderLanguage;
    
    /**
     * @var OrderRepositoryContract
     */
    private $orderRepository;
    
    /**
     * @var PaymentRepositoryContract
     */
    private $paymentRepository;
    
    /**
     * @var TransactionService
     */
    private $transactionService;
    
    /**
     * Webhook constructor.
     *
     * @param Request $request
     * @param PaymentHelper $paymentHelper
     * @param PaymentService $paymentService
     * @param SettingsService $settingsService
     * @param OrderRepositoryContract $orderRepository
     * @param PaymentRepositoryContract $paymentRepository
     * @param TransactionService $transactionService
     */
    public function __construct(Request $request,
                                PaymentHelper $paymentHelper,
                                PaymentService $paymentService,
                                SettingsService $settingsService,
                                OrderRepositoryContract $orderRepository,
                                PaymentRepositoryContract $paymentRepository,
                                TransactionService $transactionService
                                )
    {
        $this->eventData = $request->all();
        $this->paymentHelper = $paymentHelper;
        $this->paymentService = $paymentService;
        $this->settingsService = $settingsService;
        $this->orderRepository = $orderRepository;
        $this->paymentRepository = $paymentRepository;
        $this->transactionService = $transactionService;
    }
    
    /**
     * Handle the webhook process
     * 
     */
    public function processNnWebhook() 
    {
        
        // validated the IP Address
        $this->validateIpAddress();
        
        // Validates the webhook params before processing
        $this->validateEventParams();
        
        // Set Event data
        $this->eventType = $this->eventData['event']['type'];
        $this->parentTid = !empty($this->eventData['event']['parent_tid']) ? $this->eventData['event']['parent_tid'] :$this->eventData['event']['tid'];
        $this->eventTid  = $this->eventData['event']['tid'];
        
        // Retreiving the shop's order information based on the transaction 
        $this->orderDetails = $this->getOrderDetails();
        
        //  Get order language from the order object
        $this->orderLanguage = $this->orderLanguage($this->orderDetails);
        
        // Handle the individual webhook process
        if($this->eventData['result']['status'] == 'SUCCESS') {
            switch($this->eventType) {
                case 'PAYMENT':
                    $this->renderTemplate('The Payment has been received');
                    break;
                case 'TRANSACTION_CAPTURE':
                case 'TRANSACTION_CANCEL':
                    $this->handleNnTransactionCaptureCancel();
                    break;
                case 'TRANSACTION_UPDATE':
                    $this->handleNnTransactionUpdate();
                    break;
                case 'TRANSACTION_REFUND':
                    $this->handleNnTransactionRefund();
                    break;
                case 'CREDIT':
                    $this->handleNnTransactionCredit();
                    break;
                case 'CHARGEBACK':
                    $this->handleNnChargeback();
                    break;
                default:
                    return $this->renderTemplate('The webhook notification has been received for the unhandled EVENT type ( ' . $this->eventType . ')' );
            }
        } else {
             return $this->renderTemplate('Status is not valid...The webhook notification has been received for the unhandled EVENT type ( ' . $this->eventType . ')' );
        }
    }
    
    /**
     * Render twig template for webhook message
     *
     * @param string $webhookMsg
     * @return string
     */
    public function renderTemplate($webhookMsg)
    {
        return $this->twig->render('Novalnet::webhook.NovalnetWebhook', ['webhookMsg' => $webhookMsg]);
    }
    
    /**
     * Validate the IP control check
     *
     * @return bool|string
     */
    public function validateIpAddress()
    {
        $clientIp = $this->paymentHelper->getRemoteAddress();
        // Condition to check whether the webhook is called from authorized IP
        if(!in_array($clientIp, $this->ipAllowed) && $this->settingsService->getNnPaymentSettingsValue('novalnet_webhook_testmode') != true) {
            return $this->renderTemplate('Unauthorised access from the IP ' . $clientIp);
        }
        return false;
    }
    
    /**
     * Validates the event parameters
     *
     * @return none
     */
    public function validateEventParams()
    {
        // Mandatory webhook params
        $requiredParams = ['event' => ['type', 'checksum', 'tid'], 'result' => ['status']];
                                
        // Validate required parameters
        foreach($requiredParams as $category => $parameters) {
            if(empty($this->eventData[$category])) {
                // Could be a possible manipulation in the notification data
                return $this->renderTemplate('Required parameter category(' . $category. ') not received');
            } elseif(!empty($parameters)) {
                foreach($parameters as $parameter) {
                    if(empty($this->eventData[$category][$parameter])) {
                       // Could be a possible manipulation in the notification data
                       return $this->renderTemplate('Required parameter(' . $parameter . ') in the category (' . $category . ') not received');
                    }
                }
            }
        }

        // Validate the received checksum.
        $this->validateChecksum();
    }
    
    /**
     * Validate checksum
     *
     * @return none
     */
    public function validateChecksum()
    {
        $privatekey = $this->settingsService->getNnPaymentSettingsValue('novalnet_private_key'); 
        $tokenString  = $this->eventData['event']['tid'] . $this->eventData['event']['type'] . $this->eventData['result']['status'];
        
        if(isset($this->eventData['transaction']['amount'])) {
            $tokenString .= $this->eventData['transaction']['amount'];
        }
        
        if(isset($this->eventData['transaction']['currency'])) {
            $tokenString .= $this->eventData['transaction']['currency'];
        }
        
        if(!empty($privatekey)) {
            $tokenString .= $this->paymentHelper->reverseString($privatekey);
        }
        
        $generatedChecksum = hash('sha256', $tokenString);      
        if($generatedChecksum !== $this->eventData['event']['checksum']) {
            return $this->renderTemplate('While notifying some data has been changed. The hash check failed');
        }
    }
    
    /**
     * Find and retrieves the shop order details for the Novalnet transaction
     *
     * @return object|string
     */
    public function getOrderDetails()
    {
        // Get the order details if the Novalnet transaction is alreay in the Novalnet database
        $novalnetOrderDetails = $this->transactionService->getTransactionData('tid', $this->parentTid);
        
        // Use the initial transaction details
        $novalnetOrderDetail = $novalnetOrderDetails[0];
        
        // If both the order number from Novalnet and in shop is missing, then something is wrong
        if(empty($novalnetOrderDetail->orderNo) && empty($this->eventData['transaction']['order_no'])) {
            return $this->renderTemplate('Order reference not found for the TID ' . $this->parentTid);
        }
        
        // Get the Order No and proceed further
        $orderNo = !empty($novalnetOrderDetail->orderNo) ?? $this->eventData['transaction']['order_no'];
        
        // If the order in the Novalnet server to the order number in Novalnet database doesn't match, then there is an issue
        if(!empty($this->eventData['transaction']['order_no']) && !empty($novalnetOrderDetail->orderNo) && (($this->eventData['transaction']['order_no']) != $novalnetOrderDetail->orderNo)) {
            return $this->renderTemplate('Order reference not matching for the order number ' . $orderNo);
        }
          
        if(!empty($novalnetOrderDetail)) {
            
            $orderObj                     = pluginApp(stdClass::class);
            $orderObj->tid                = $this->parentTid;
            $orderObj->orderTotalAmount = $novalnetOrderDetail->amount;
            $orderObj->orderPaidAmount  = 0; // Collect paid amount information from the novalnet DB
            $orderObj->orderNo            = $novalnetOrderDetail->orderNo;
            $orderObj->paymentName        = $novalnetOrderDetail->paymentName;
            // Get the Novalnet payment methods Id
            $mop = $this->paymentHelper->getPaymentMethodByKey(strtoupper($novalnetOrderDetail->paymentName));
            $orderObj->mopId = $mop[0];

            // Get the total paid amounts for an order
            if($this->eventType != 'CREDIT') {
                // Get the entire transaction details to the specific order
                $getOrderDetails = $this->transactionService ->getTransactionData('orderNo', $novalnetOrderDetail->orderNo);
                if(!empty($getOrderDetails)) {
                    $paidAmount = 0;
                    foreach($getOrderDetails as $getOrderDetail) {
                        $paidAmount += $getOrderDetail->callbackAmount;
                    }
                    $orderObj->orderPaidAmount = $paidAmount;
                }
            }
        } else {
            if(!empty($orderNo)) {
                $orderObj = $this->orderObject($orderNo);
                // handle the communication break scenario
                return $this->handleCommunicationBreak($orderObj);               
            }   
            else 
            {
               return $this->renderTemplate('Transaction mapping failed ' . $orderNo);  
            }
        }
        return $orderObj;
    }
    
    /**
     * Retrieves the order details from shop order ID
     *
     * @param int $orderId
     * 
     * @return object
     */
    public function orderObject($orderId)
    {
        $orderId = (int)$orderId;
        try {
            $authHelper = pluginApp(AuthHelper::class);
            $orderRef = $authHelper->processUnguarded(function () use ($orderId) {
                $orderObj = $this->orderRepository->findById($orderId);                                       
                return $orderObj;              
            });
            return $orderRef;
        } catch (\Exception $e) {
               return null;                     
        }
    }
    
    /**
     * Handling communication breakup
     *
     * @param array $orderObj
     * 
     * @return string
     */
    public function handleCommunicationBreak($orderObj)
    {
        //  Get order language from the order object
        $orderlanguage = $this->orderLanguage($orderObj);
        
        foreach($orderObj->properties as $orderProperty) {
            if($orderProperty->typeId == '3' && $this->paymentHelper->getPaymentKeyByMop($orderProperty->value)) {  // Is the Novalnet payment methods
                $this->eventData['custom']['lang'] = $orderlanguage;
                $this->eventData['mop'] = $orderProperty->value;
                $this->eventData['payment_method'] = $this->paymentHelper->getNnPaymentKey($this->eventData['transaction']['payment_type']);
                $this->eventData['transaction']['system_version'] = NovalnetConstants::PLUGIN_VERSION;
                
                // Create the payment to the broken order and insert the payment details into Novalnet DB 
                if($this->eventData['result']['status'] == 'SUCCESS' && in_array($this->eventData['transaction']['status'], ['PENDING', 'ON_HOLD', 'SUCCESS', 'DEACTIVATED', 'FAILURE'])) {
                    // Insert the transaction details into Novalnet DB
                    $this->paymentService->insertPaymentResponseIntoNnDb($this->eventData);
                    // Create the payment to the plenty order
                    $this->paymentHelper->createPlentyPaymentToNnOrder($this->eventData);
                }
                // Webhook executed message
                $webhookComments = $this->paymentHelper->getTranslatedText('nn_tid_label') . $this->eventData['transaction']['tid'];
                if(!empty($this->eventData['transaction']['test_mode'])) {
                    $webhookComments .= '<br>' . $this->paymentHelper->getTranslatedText('test_order') . $this->eventData['transaction']['test_mode'];
                }
                $webhookComments = '<br>' . $this->eventData['result']['status_text'];
                return $this->renderTemplate($webhookComments);
            } else {
                return $this->renderTemplate('Webhook executed already');
            }
        }
    }
    
    /**
     * Get the order language based on the order
     *
     * @param object $orderObj
     * 
     * @return string
     */
    public function orderLanguage($orderObj)
    {
        foreach($orderObj->properties as $orderProperty) {
            if($orderProperty->typeId == '6' ) {
                $orderLanguage = $orderProperty->value;

                return $orderLanguage;
            }
        }
    }
    
    /**
     * Handling the Novalnet transaction authorization process
     *
     * @return string
     */
    public function handleNnTransactionCaptureCancel()
    {
        // Get the previous transaction status
        $previousTxStatus = $this->getNnTxStatus((int) $this->eventData['transaction']['order_no'], $this->parentTid);
        
        // Capture the transaction when it is in On-Hold
        if(in_array($previousTxStatus, array('PENDING', 'ON_HOLD'))) {   
            // If the transaction is captured, we update necessary alterations in DB        
            if($this->eventType == 'TRANSACTION_CAPTURE') {
                $webhookComments = sprintf($this->paymentHelper->getTranslatedText('webhook_order_confirmation_text', $this->orderLanguage), date('d.m.Y'), date('H:i:s'));
            } else {
                $webhookComments = sprintf($this->paymentHelper->getTranslatedText('webhook_transaction_cancellation', $this->orderLanguage), date('d.m.Y'), date('H:i:s'));
            }
            // Insert the updated transaction details into Novalnet DB
            $this->paymentService->insertPaymentResponseIntoNnDb($this->eventData);
            
            // Booking Message
            $this->eventData['bookingText'] = $webhookComments;
            
            // Create the payment to the plenty order
            $this->paymentHelper->createPlentyPaymentToNnOrder($this->eventData);
            
            return $this->renderTemplate($webhookComments);
        } else {
            return $this->renderTemplate('Transaction already captured/cancelled');
        }
    }
    
    /**
     * Handling the Novalnet transaction update process
     *
     * @return string
     */
    public function handleNnTransactionUpdate()
    {
        // Transaction status update process
        if($this->eventData['transaction']['update_type'] == 'STATUS') {
            // // If the transaction is cancelled
            if($this->eventData['transaction']['status'] == 'DEACTIVATED') {
                $webhookComments = sprintf($this->paymentHelper->getTranslatedText('webhook_transaction_cancellation', $this->orderLanguage), date('d.m.Y'), date('H:i:s'));
            } else {
                
                // Get the previous transaction status
                $previousTxStatus = $this->getNnTxStatus((int) $this->eventData['transaction']['order_no'], $this->parentTid);
                
                if($previousTxStatus == 'PENDING' && in_array($this->eventData['transaction']['status'], ['ON_HOLD', 'CONFIRMED'])) {
                    // If the transaction status is On-Hold
                    if($this->eventData['transaction']['status'] == 'ON_HOLD') {
                        $webhookComments = sprintf($this->paymentHelper->getTranslatedText('webhook_pending_to_onhold_status_change', $this->orderLanguage), $this->parentTid, date('d.m.Y'), date('H:i:s'));
                    } else { // For confirmation status display the update text
                        $webhookComments = sprintf($this->paymentHelper->getTranslatedText('webhook_update_confirmation_text', $this->orderLanguage), $this->parentTid, sprintf('%0.2f', ($this->eventData['transaction']['amount']/100)) , $this->eventData['transaction']['currency'], date('d.m.Y'), date('H:i:s'));
                    }
                }
            }
            // Insert the updated transaction details into Novalnet DB
            $this->paymentService->insertPaymentResponseIntoNnDb($this->eventData);
            
            // Booking Message
            $this->eventData['bookingText'] = $webhookComments;
            
            // Create the payment to the plenty order
            $this->paymentHelper->createPlentyPaymentToNnOrder($this->eventData);
            
            return $this->renderTemplate($webhookComments);
        } else { // Due Date and Amount Update process
            
            // Due date update text
            $dueDateUpdateMessage = sprintf($this->paymentHelper->getTranslatedText('webhook_duedate_update_message', $this->orderLanguage), sprintf('%0.2f', ($this->eventData['transaction']['amount']/100)) , $this->eventData['transaction']['currency'], $this->eventData['transaction']['due_date']);
            
            // Amount update text
            $amountUpdateMessage = sprintf($this->paymentHelper->getTranslatedText('webhook_amount_update_message', $this->orderLanguage), sprintf('%0.2f', ($this->eventData['transaction']['amount']/100)) , $this->eventData['transaction']['currency'], date('d.m.Y'), date('H:i:s'));
            
            // Update the transaction details in the Novalnet DB
            $transactionDetails = $this->transactionService->updateTransactionData('orderNo', $this->eventData['transaction']['order_no'], $this->eventData);

            $webhookComments = (($this->eventData['transaction']['update_type'] == 'AMOUNT') ? $amountUpdateMessage : (($this->eventData['transaction']['update_type'] == 'DUE_DATE') ? $dueDateUpdateMessage : $dueDateUpdateMessage . $amountUpdateMessage));
            
            // Update the booking text in the latest payment entry
            $payments = $this->paymentRepository->getPaymentsByOrderId($this->eventData['transaction']['order_no']);
            // Get the end of the payment details
            $finalPaymentDetails = end($payments);
            
            $paymentProperty     = [];
            $paymentProperty[]   = $this->paymentHelper->getPaymentProperty(PaymentProperty::TYPE_BOOKING_TEXT, $webhookComments);
            $finalPaymentDetails->properties = $paymentProperty; 
            // Update the booking text
            $this->paymentRepository->updatePayment($finalPaymentDetails);
            
            return $this->renderTemplate($webhookComments);
        }
    }
    
    /**
     * Handling the transaction refund process
     *
     * @return string
     */
    public function handleNnTransactionRefund()
    {
        // If refund is executing
        if(!empty($this->eventData['transaction']['refund']['amount'])) {
            if(!empty($this->eventData['transaction']['refund']['tid'])) {
                $webhookComments = sprintf($this->paymentHelper->getTranslatedText('webhook_new_tid_refund_execution', $this->orderLanguage), $this->parentTid, sprintf('%0.2f', ($this->eventData['transaction']['amount']/100)) , $this->eventData['transaction']['currency'], $this->eventTid);
            } else {
                $webhookComments = sprintf($this->paymentHelper->getTranslatedText('webhook_refund_execution', $this->orderLanguage), $this->parentTid, sprintf('%0.2f', ($this->eventData['transaction']['amount']/100)) , $this->eventData['transaction']['currency']);
            }
            
            // Get chargeback status it is happened for Full amount or Partially
            $refundStatus = $this->paymentService->getRefundStatus($this->eventData['transaction']['order_no'], $this->orderDetails->orderTotalAmount);
            
            // Set the refund status it Partial or Full refund
            $this->eventData['refund'] = $refundStatus;
            
            // Insert the refund transaction details into Novalnet DB
            $this->paymentService->insertPaymentResponseIntoNnDb($this->eventData);
            
            // Booking Message
            $this->eventData['bookingText'] = $webhookComments;
            
            // Create the payment to the plenty order
            $this->paymentHelper->createPlentyPaymentToNnOrder($this->eventData);
            
            return $this->renderTemplate($webhookComments);
        }
    }
    
    /**
     * Handling the credit process
     *
     * @return string
     */
    public function handleNnTransactionCredit()
    {
        $webhookComments = sprintf($this->paymentHelper->getTranslatedText('webhook_initial_execution', $this->orderLanguage), $this->parentTid, sprintf('%0.2f', ($this->eventData['transaction']['amount']/100)), $this->eventData['transaction']['currency'], date('d.m.Y'), date('H:i:s'), $this->eventTid);
        
        if(in_array($this->eventData['transaction']['payment_type'], ['INVOICE_CREDIT', 'CASHPAYMENT_CREDIT', 'ONLINE_TRANSFER_CREDIT', 'MULTIBANCO_CREDIT'])) {
            if($this->orderDetails->orderTotalAmount >= $this->orderDetails->orderPaidAmount) {
                $this->eventData['unaccountable'] = 0;
            } else {
                $this->eventData['unaccountable'] = 1;
            }
        } else {
            $this->eventData['unaccountable'] = 1;
        } 
        
        $this->eventData['credit'] = 1;
        
        // Booking Message
        $this->eventData['bookingText'] = $webhookComments;
        
        $orderTotalAmount = $this->orderDetails->orderTotalAmount;
        
        // Insert the refund details into Novalnet DB
        $this->paymentService->insertPaymentResponseIntoNnDb($this->eventData, $this->parentTid, 0, $orderTotalAmount);
        
        // Create the payment to the plenty order
        $this->paymentHelper->createPlentyPaymentToNnOrder($this->eventData);
        
        return $this->renderTemplate($webhookComments);
    }
    
    /**
     * Handling the chargeback process
     *
     * @return string
     */
    public function handleNnChargeback()
    {
        if($this->eventData['transaction']['payment_type'] == 'RETURN_DEBIT_SEPA') {
            $webhookComments = sprintf($this->paymentHelper->getTranslatedText('webhook_return_debit_execution', $this->orderLanguage), $this->parentTid, sprintf('%0.2f', ($this->eventData['transaction']['amount']/100)), $this->eventData['transaction']['currency'], date('d.m.Y'), date('H:i:s'), $this->eventTid);
        } elseif($this->eventData['transaction']['payment_type'] == 'REVERSAL') {
            $webhookComments = sprintf($this->paymentHelper->getTranslatedText('webhook_reversal_execution', $this->orderLanguage), $this->parentTid, sprintf('%0.2f', ($this->eventData['transaction']['amount']/100)), $this->eventData['transaction']['currency'], date('d.m.Y'), date('H:i:s'), $this->eventTid);
        } else {
            $webhookComments = sprintf($this->paymentHelper->getTranslatedText('webhook_chargeback_execution', $this->orderLanguage), $this->parentTid, sprintf('%0.2f', ($this->eventData['transaction']['amount']/100)) , $this->eventData['transaction']['currency'], date('d.m.Y'), date('H:i:s'), $this->eventTid);
        }
        
        $RefundOrderTotalAmount = $this->orderDetails->orderTotalAmount;
        
        // Insert the refund details into Novalnet DB
        $this->paymentService->insertPaymentResponseIntoNnDb($this->eventData, $this->parentTid, $RefundOrderTotalAmount, 0);
       
        // Get chargeback status it is happened for Full amount or Partially
        $refundStatus = $this->paymentService->getRefundStatus($this->eventData['transaction']['order_no'], $this->orderDetails->orderTotalAmount);
        
        // Set the refund status it Partial or Full refund
        $this->eventData['refund'] = $refundStatus;
        
        // Booking Message
        $this->eventData['bookingText'] = $webhookComments;

        // Create the payment to the plenty order
        $this->paymentHelper->createPlentyPaymentToNnOrder($this->eventData);
        
        return $this->renderTemplate($webhookComments);
    }

    /**
     * Get Novalnet transaction status
     *
     * @param int $orderId
     * @return int|string
     */
    public function getNnTxStatus($orderId, $tid)
    {
        $payments = $this->paymentRepository->getPaymentsByOrderId($orderId);
        $txStatus = 0;
        foreach($payments as $payment) {
            $paymentProperties = $payment->properties;
            foreach($paymentProperties as $paymentProperty) {
              if($paymentProperty->typeId == 1) {
                 $nn_tx_tid = $paymentProperty->value; 
              }
              if($paymentProperty->typeId == 30 && $nn_tx_tid == $tid) { // Get the initial TID status 
                $txStatus = $paymentProperty->value;
              }
            }
        }
        return $txStatus;
    }

}
