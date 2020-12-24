jQuery(document).ready(function($) {
    function initSelect2 () {
        $('#store-select').select2({
            templateResult: formatState,
            matcher: matchCustom,
            placeholder: "Select a Store...",
            allowClear: true,
            width: "100%"
        }).on('change.select2', function () {
            console.log(this.value);
            $('.shipping_method[value="' + this.value + '"]').prop('checked', true).trigger('change');
        });
        $('#show-local-shipping').on('change', function (e) {
            if(this.checked) {
                $('.shipping_method').prop('checked', false);
                $('#store-selector').show();
                $('.woocommerce-shipping-destination').hide();
            }
        })
        $('.shipping_method').on('change', function (e) {
            if(this.checked) {
                $('#show-local-shipping').prop('checked', false);
                $('#store-selector').hide();
                $('.woocommerce-shipping-destination').show();
            }
        })
        $('.checkout-button, #place_order').on('click', function (e) {
            if($('#show-local-shipping').prop('checked') && $('#store-select').val() === '') {
                e.preventDefault();
                $('#store-selector-error').text('Please select a store!').show();
            }
        })
    }

    function formatState (state) {
        if (!state.id) return state.text;
        console.log(state);
        return $("<span></span>").html('<b>' + state.text + '</b><span class="truncate">' + state.element.dataset.address + '</span>');
    }

    function matchCustom(params, data) {
        console.log(data);
        // If there are no search terms, return all of the data
        if ($.trim(params.term) === '') {
            return data;
        }

        // Do not display the item if there is no 'text' property
        if (typeof data.text === 'undefined' && typeof data.element.dataset.address === 'undefined') {
            return null;
        }

        // `params.term` should be the term that is used for searching
        // `data.text` is the text that is displayed for the data object
        let textToCompare = params.term.toLowerCase()
        if (data.text.toLowerCase().indexOf(textToCompare) > -1 || (data.element.dataset.address && data.element.dataset.address.toLowerCase().indexOf(textToCompare) > -1)) {
            // var modifiedData = $.extend({}, data, true);
            // modifiedData.text += ' (matched)';

            // You can return modified objects from here
            // This includes matching the `children` how you want in nested data sets
            return data;
        }

        // Return `null` if the term should not be displayed
        return null;
    }

    initSelect2();
    $(document.body).on('updated_shipping_method', initSelect2);
    $(document.body).on('updated_checkout', initSelect2);
});