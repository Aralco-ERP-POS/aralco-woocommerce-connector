jQuery(document).ready(function ($) {
    function init_points_buttons() {
        $('.use-points').on('click', function (e){
            e.preventDefault();
            $(this).hide();
            $('.points-errors').hide().empty();
            $('.points-entry-label, .apply-points, .points-cancel').show();
        });

        $('.points-cancel').on('click', function (e){
            e.preventDefault();
            if($(this).hasClass('disabled')) return;

            $('.points-entry-dollars').val('');
            $('.points-entry-label, .apply-points, .points-cancel, .points-errors').hide();
            $('.use-points').show();
        });

        $('.apply-points').on('click', function (e){
            e.preventDefault();
            if($(this).hasClass('disabled')) return;

            let amount = $('.points-entry-dollars').val();
            let error = $('.points-errors').hide();
            if(!amount || amount < 0) {
                error.text("Amount can't be empty or less then 0").show();
                return;
            }

            $('.apply-points, .points-cancel').addClass('disabled');

            $.ajax({
                url: aralcoApiSettings.root + "aralco-wc/v1/cart/apply-points",
                method: 'GET',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', aralcoApiSettings.nonce);
                },
                data: {
                    amount: amount
                }
            }).done(function (response) {
                location.reload()
            }).fail(function (response) {
                $('.apply-points, .points-cancel').removeClass('disabled');
                error.text(response.responseJSON.message).show();
            });
        });

        $('.remove-points').on('click', function (e){
            e.preventDefault();
            if($(this).hasClass('disabled')) return;

            $('.remove-points').addClass('disabled');

            $.ajax({
                url: aralcoApiSettings.root + "aralco-wc/v1/cart/remove-points",
                method: 'GET',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', aralcoApiSettings.nonce);
                }
            }).done(function (response) {
                location.reload()
            }).fail(function (response) {
                $('.remove-points').removeClass('disabled');
            });
        });
    }
    $(document.body).on('updated_wc_div', init_points_buttons);
    $(document.body).on('updated_cart_totals', init_points_buttons);
    $(document.body).on('updated_checkout', init_points_buttons);
    $(document.body).on('wc_fragments_refreshed', init_points_buttons);
    init_points_buttons();
});