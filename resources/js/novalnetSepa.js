jQuery(document).ready( function() {
    // Restrict the special characters in the IBAN field
    jQuery('#nn_sepa_iban').on('input',function ( event ) {
        let iban = jQuery(this).val().replace( /[^a-zA-Z0-9]+/g, "" ).replace( /\s+/g, "" );
        jQuery(this).val(iban);      
    });

    // After the form submission disable the action   
    jQuery('#novalnet_form').on('submit',function(){
        jQuery('#novalnet_form_btn').attr('disabled',true);      
    });
});
