<?php

use Kirby\Cms\Site;

return [
    'cart' => function() {
        return cart();
    },
    'checkoutUrl' => function() {
        /** @var Site $this */
        return sprintf('%s/%s', $this->url(), 'checkout');
    },
    'checkoutClientSecretUrl' => function() {
        /** @var Site $this */
        return sprintf('%s/%s', $this->url(), 'checkout/embedded');
    }
];
