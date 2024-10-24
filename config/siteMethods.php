<?php

use Kirby\Cms\Site;
use Symfony\Component\Intl\Currencies;

return [
    'stripeCurrencySymbol' => function(): string
    {
        $currency = strtoupper(option('programmatordev.stripe-checkout.currency'));
        return Currencies::getSymbol($currency);
    },
    'stripeCountriesUrl' => function(?string $locale = null): string
    {
        $url = sprintf('%s/api/stripe/countries', kirby()->url());

        if ($locale !== null) {
            $url = sprintf('%s?locale=%s', $url, $locale);
        }

        return $url;
    },
    'stripeCheckoutUrl' => function(): string
    {
        /** @var Site $this */
        return sprintf('%s/%s', $this->url(), 'stripe/checkout');
    },
    'stripeCheckoutEmbeddedUrl' => function(): string
    {
        /** @var Site $this */
        return sprintf('%s/%s', $this->url(), 'stripe/checkout/embedded');
    }
];
