<?php

use Kirby\Cms\Site;

return [
    'cart' => function() {
        return cart();
    },
    'stripeCheckoutUrl' => function() {
        /** @var Site $this */
        return sprintf('%s/%s', $this->url(), 'stripe/checkout');
    },
    'stripeCheckoutClientSecretUrl' => function() {
        /** @var Site $this */
        return sprintf('%s/%s', $this->url(), 'stripe/checkout/embedded');
    }
];
