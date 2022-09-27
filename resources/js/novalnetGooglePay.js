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
                    paymentDataPresent: false,
                    countryCode : String(jQuery('#nn_google_pay').attr('data-country')),
                    partnerId: jQuery('#nn_merchant_id').val(),
                },
                transaction: {
                    amount: String(jQuery('#nn_google_pay').attr('data-total-amount')),
                    currency: String(jQuery('#nn_google_pay').attr('data-currency')), 
                    enforce3d: jQuery('#nn_enforce').val(),
                    paymentMethod: "GOOGLEPAY",
                    environment: jQuery('#nn_environment').val(),
                },
                custom: {
                    lang: String(jQuery('#nn_google_pay').attr('data-order-lang'))
                },
                order: {
                    paymentDataPresent: false,
                    merchantName: String(jQuery('#nn_business_name').val()),
                },
                button: {
                    type: jQuery('#nn_button_type').val(),
                    style: jQuery('#nn_button_theme').val(),
                    locale: "en-US",
                    boxSizing: "border-box",
                    dimensions: {
                        height: jQuery('#nn_button_height').val(),
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
