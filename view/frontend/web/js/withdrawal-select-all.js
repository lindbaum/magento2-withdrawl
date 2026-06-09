define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        var $selectAll = $(element);

        $selectAll.on('change', function () {
            $('.withdrawal-item-checkbox').prop('checked', this.checked);
        });

        $(document).on('change', '.withdrawal-item-checkbox', function () {
            var total = $('.withdrawal-item-checkbox').length;
            var checked = $('.withdrawal-item-checkbox:checked').length;
            $selectAll.prop('indeterminate', checked > 0 && checked < total);
            $selectAll.prop('checked', checked === total);
        });

        $(document).on('change', '.withdrawal-item-checkbox', function () {
            var itemId = $(this).val();
            $('input[name="item_qty[' + itemId + ']"]').prop('disabled', !this.checked);
        });
    };
});
