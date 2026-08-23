define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        $(element).find('.product-item-info').each(function () {
            var $card    = $(this);
            var $compare = $card.find('.action.tocompare').first();
            var $wish    = $card.find('.action.towishlist').first();

            if (!$compare.length && !$wish.length) return;

            var $row = $('<div class="ahy-card-icons"></div>');

            // Compare left, wishlist right
            if ($compare.length) $row.append($('<div class="ahy-icon ahy-icon--left"></div>').append($compare));
            if ($wish.length)    $row.append($('<div class="ahy-icon ahy-icon--right"></div>').append($wish));

            // Place before the image anchor
            $card.find('.product-item-photo').first().before($row);
        });
    };
});
