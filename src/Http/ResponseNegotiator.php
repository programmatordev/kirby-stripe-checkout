<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Http;

use Kirby\Cms\App;

/** @internal Selects a supported representation while respecting explicit Accept exclusions. */
final class ResponseNegotiator
{
    /** @param non-empty-list<string> $types In preference order when qualities are equal. */
    public static function preferred(App $kirby, array $types): ?string
    {
        $header = $kirby->request()->header('Accept', '*/*');
        $accept = is_string($header) ? $header : '';

        if (trim($accept) === '') {
            $accept = '*/*';
        }

        $best = null;
        $bestQuality = 0.0;

        // Kirby's preferredMimeType does not exclude q=0 or give an explicit exclusion precedence over a wildcard.
        // Apply that narrow HTTP rule here.
        foreach ($types as $type) {
            $quality = 0.0;
            $specificity = -1;

            foreach (explode(',', strtolower($accept)) as $range) {
                $parts = array_map('trim', explode(';', $range));
                $mime = array_shift($parts);
                $rank = match ($mime) {
                    $type => 2,
                    explode('/', $type)[0] . '/*' => 1,
                    '*/*' => 0,
                    default => -1,
                };

                if ($rank < 0 || $rank < $specificity) {
                    continue;
                }

                $q = 1.0;

                foreach ($parts as $parameter) {
                    if (str_starts_with($parameter, 'q=')) {
                        $q = is_numeric(substr($parameter, 2)) ? (float) substr($parameter, 2) : 0.0;
                    }
                }

                $specificity = $rank;
                $quality = $q >= 0 && $q <= 1 ? $q : 0.0;
            }

            if ($quality > $bestQuality) {
                $best = $type;
                $bestQuality = $quality;
            }
        }

        return $best;
    }
}
