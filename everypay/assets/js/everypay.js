jQuery(document).ready(function() {

    var or = document.getElementsByName("virtuemart_paymentmethod_id");

    jQuery('#btn-payment-cc').click(function(e){
        try {
            var $form = jQuery('#checkoutForm');
            var key = jQuery('#everypay-key').val();
            Everypay.setPublicKey(key);
            Everypay.createToken($form, handleTokenResponse);
        } catch (e) {
            alert(e);
        }
    });


    function handleTokenResponse(status, response) {
        var $form = jQuery("#checkoutForm");

        if (response.error) {
            alert(response.error.message);
        } else {
            var token = response.token;
            $form.append(jQuery('<input type="hidden" name="everypayToken"/>').val(token));
            $form.get(0).submit();
        }
    }
});

