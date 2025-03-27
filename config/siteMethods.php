<?php

return [
    'stripeCountriesUrl' => function(?string $locale = null): string
    {
        $url = sprintf('%s/api/stripe/countries', kirby()->url());

        if ($locale !== null) {
            $url = sprintf('%s?locale=%s', $url, $locale);
        }

        return $url;
    }
];
