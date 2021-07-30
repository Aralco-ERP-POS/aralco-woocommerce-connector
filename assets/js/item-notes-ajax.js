(function ($) {
    $(document).ready(function () {
        let timers = {};

        $('.aralco-cart-notes').on('change keyup paste', function () {
            let cartId = $(this).data('cart-id');
            if (timers.hasOwnProperty(cartId)) {
                clearTimeout(timers[cartId]);
            }
            timers[cartId] = setTimeout(updateNotes.bind(this, cartId), 1000);
        });

        function updateNotes(cartId){
            clearTimeout(timers[cartId]);
            delete timers[cartId];
            $('.cart_totals').block({
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6
                }
            });
            $.ajax(
                {
                    type: 'POST',
                    url: aralco_vars.ajaxurl,
                    data: {
                        action: 'aralco_update_cart_item_notes',
                        security: $('#woocommerce-cart-nonce').val(),
                        notes: $('#cart_notes_' + cartId).val(),
                        cart_id: cartId
                    },
                    success: function (response) {
                        $('.cart_totals').unblock();
                    }
                }
            )
        }

        $('.toggle_item_note').on('change', function (){
            if(this.checked) {
                $($(this).data('toggle')).show();
            } else {
                $($(this).data('toggle')).hide().empty().trigger('change');
            }
        })
    });
})(jQuery);