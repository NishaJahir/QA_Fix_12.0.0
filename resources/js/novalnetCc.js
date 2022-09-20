jQuery(document).ready(function () {
    loadNovalnetCcIframe();

    jQuery('#nn_cc_form').submit( function (e) {
        jQuery('#novalnet_form_btn').attr('disabled',true);
     
        if(jQuery('#nn_pan_hash').val().trim() == '') {
            NovalnetUtility.getPanHash();
            e.preventDefault();
            e.stopImmediatePropagation();
        }
     });
});

function loadNovalnetCcIframe()
{
    var ccCustomFields = jQuery('#nn_cc_formfields').val() != '' ? JSON.parse(jQuery('#nn_cc_formfields').val()) : null;
    var ccFormDetails= jQuery('#nn_cc_formdetails').val() != '' ? JSON.parse(jQuery('#nn_cc_formdetails').val()) : null;

    // Set your Client key
    NovalnetUtility.setClientKey((ccFormDetails.client_key !== undefined) ? ccFormDetails.client_key : '');

    var requestData = {
        callback: {
            on_success: function (result) {
                jQuery('#nn_pan_hash').val(result['hash']);
                jQuery('#nn_unique_id').val(result['unique_id']);
                jQuery('#nn_cc3d_redirect').val(result['do_redirect']);
                jQuery('#nn_cc_form').submit();
                jQuery('.modal').hide();
                return true;
            },
            on_error: function (result) {
                if(undefined !== result['error_message']) {
                    jQuery('#novalnet_form_btn').attr('disabled',false);
                    alert(result['error_message']);
                    return false;
                }
            }
        },

        // You can customize your Iframe container style, text etc.
        iframe: {
            // Passed the Iframe Id
            id: "nn_iframe",

            // Display the normal cc form
            inline: (ccFormDetails.inline_form !== undefined) ? ccFormDetails.inline_form : 0,
             
            // Adjust the creditcard style and text 
            style: {
                container: (ccCustomFields.standard_style_css !== undefined) ? ccCustomFields.standard_style_css : '',
                input: (ccCustomFields.standard_style_field !== undefined) ? ccCustomFields.standard_style_field : '' ,
                label: (ccCustomFields.standard_style_label !== undefined) ? ccCustomFields.standard_style_label : '' ,
            },
            
            text: {
                error: (ccCustomFields.credit_card_error !== undefined) ? ccCustomFields.credit_card_error : '',
                card_holder : {
                    label: (ccCustomFields.template_novalnet_cc_holder_Label !== undefined) ? ccCustomFields.template_novalnet_cc_holder_Label : '',
                    place_holder: (ccCustomFields.template_novalnet_cc_holder_input !== undefined) ? ccCustomFields.template_novalnet_cc_holder_input : '',
                    error: (ccCustomFields.template_novalnet_cc_error !== undefined) ? ccCustomFields.template_novalnet_cc_error : ''
                },
                    card_number : {
                    label: (ccCustomFields.template_novalnet_cc_number_label !== undefined) ? ccCustomFields.template_novalnet_cc_number_label : '',
                    place_holder: (ccCustomFields.template_novalnet_cc_number_input !== undefined) ? ccCustomFields.template_novalnet_cc_number_input : '',
                    error: (ccCustomFields.template_novalnet_cc_error !== undefined) ? ccCustomFields.template_novalnet_cc_error : ''
                },
                    expiry_date : {
                    label: (ccCustomFields.template_novalnet_cc_expirydate_label !== undefined) ? ccCustomFields.template_novalnet_cc_expirydate_label : '',
                    place_holder: (ccCustomFields.template_novalnet_cc_expirydate_input !== undefined) ? ccCustomFields.template_novalnet_cc_expirydate_input : '',
                    error: (ccCustomFields.template_novalnet_cc_error !== undefined) ? ccCustomFields.template_novalnet_cc_error : ''
                },
                cvc : {
                    label: (ccCustomFields.template_novalnet_cc_cvc_label !== undefined) ? ccCustomFields.template_novalnet_cc_cvc_label : '',
                    place_holder: (ccCustomFields.template_novalnet_cc_cvc_input !== undefined) ? ccCustomFields.template_novalnet_cc_cvc_input : '',
                    error: (ccCustomFields.template_novalnet_cc_error !== undefined) ? ccCustomFields.template_novalnet_cc_error : ''
                }
            }
        },

         // Add Customer data
        customer: {
            first_name: (ccFormDetails.first_name !== undefined) ? ccFormDetails.first_name : '',
            last_name: (ccFormDetails.last_name !== undefined) ? ccFormDetails.last_name : ccFormDetails.first_name,
            email: (ccFormDetails.email !== undefined) ? ccFormDetails.email : '',
            billing: {
                street: (ccFormDetails.street !== undefined) ? ccFormDetails.street : '',
                city: (ccFormDetails.city !== undefined) ? ccFormDetails.city : '',
                zip: (ccFormDetails.zip !== undefined) ? ccFormDetails.zip : '',
                country_code: (ccFormDetails.country_code !== undefined) ? ccFormDetails.country_code : ''
            },
            shipping: {
                same_as_billing: (ccFormDetails.same_as_billing !== undefined) ? ccFormDetails.same_as_billing : 0
                street: (ccFormDetails.shipping.street !== undefined) ? ccFormDetails.shipping.street : '',
                city: (ccFormDetails.shipping.city !== undefined) ? ccFormDetails.shipping.city : '',
                zip: (ccFormDetails.shipping.zip !== undefined) ? ccFormDetails.shipping.zip : '',
                country_code: (ccFormDetails.shipping.country_code !== undefined) ? ccFormDetails.shipping.country_code : ''
            }
        },

         // Add transaction data
        transaction: {
            amount: (ccFormDetails.amount !== undefined) ? ccFormDetails.amount : '',
            currency: (ccFormDetails.currency !== undefined) ? ccFormDetails.currency : '',
            test_mode: (ccFormDetails.test_mode !== undefined) ? ccFormDetails.test_mode : 0,
            enforce_3d: (ccFormDetails.enforce_3d !== undefined) ? ccFormDetails.enforce_3d : 0
        },
        
        // Add custom data
        custom: {
            lang: (ccFormDetails.lang !== undefined) ? ccFormDetails.lang : 'en'
        }
    };

    NovalnetUtility.createCreditCardForm(requestData);
    jQuery('.loader').hide(700);
}
