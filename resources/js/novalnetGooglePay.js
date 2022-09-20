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

jQuery(document).ready(function() {
    // Load the Google Pay button
    try {
        // Load the payment instances
        var NovalnetPaymentInstance = NovalnetPayment(); 
        var googlepayNovalnetPaymentObj = NovalnetPaymentInstance.createPaymentObject();
        // Setup the payment intent
        var requestData = {
            clientKey: String(jQuery('#nn_client_key').val()),
            paymentIntent: {
                merchant: {
                    countryCode : String(jQuery('#nn_google_pay').attr('data-country'))
                },
                transaction: {
                    amount: String(jQuery('#nn_google_pay').attr('data-total-amount')),
                    currency: String(jQuery('#nn_google_pay').attr('data-currency')),   
                    paymentMethod: "GOOGLEPAY",
                    environment: "SANDBOX"
                },
                custom: {
                    lang: String(jQuery('#nn_google_pay').attr('data-order-lang'))
                },
                wallet: {
                    merchantName: "Testing purpose"
                },
                button: {
                    type: "buy",
                    style: "black",
                    locale: "en-US",
                    boxSizing: "fill",
                    dimensions: {
                        height: 45,
                        width: 200
                    }
                },
                callbacks: {
                    onProcessCompletion: function (response, processedStatus) {
                        // Only on success, we proceed further with the booking
                        if(response.result.status == "SUCCESS") {
                            console.log(response);
                            jQuery('#nn_google_pay_token').val(response.transaction.token);
                            jQuery('#nn_google_pay_form').submit();
                        } else {
                            // Upon failure, displaying the error text 
                            if(response.result.status_text) {
                                alert(response.result.status_text);
                            }
                        }
                    }
                }
            }
        };
        googlepayNovalnetPaymentObj.setPaymentIntent(requestData);
        // Checking for the Payment Method availability
        googlepayNovalnetPaymentObj.isPaymentMethodAvailable(function(displayGooglePayButton) {
            var mopId = jQuery('#nn_google_pay_mop').val();
            if(displayGooglePayButton) {
                // Display the Google Pay payment
                jQuery('li[data-id="'+mopId+'"]').show();
                jQuery('li[data-id="'+mopId+'"]').click(function() {
                    // Initiating the Payment Request for the Wallet Payment
                    googlepayNovalnetPaymentObj.addPaymentButton("#nn_google_pay");
                    // Hide the shop place-order button
                    jQuery('.widget-place-order').hide();
                });
            } else {
                // Hide the Google Pay payment if it is not possible
                jQuery('li[data-id="'+mopId+'"]').show();
            }
        });
    } catch (e) {
        // Handling the errors from the payment intent setup
        console.log(e.message);
    }
});
