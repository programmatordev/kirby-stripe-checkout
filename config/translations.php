<?php

use Kirby\Data\Yaml;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Toolkit\A;

// get all files from the "translations" directory and register them as plugin translations
$translations = A::keyBy(
    A::map(
        Dir::read(__DIR__ . '/../translations'),
        function (string $file): array
        {
            $lang = F::name($file);
            $filePath = __DIR__ . '/../translations/' . $file;
            $translations = [];

            foreach (Yaml::read($filePath) as $key => $value) {
                $translations['stripe-checkout.' . $key] = $value;
            }

            return A::merge(['lang' => $lang], $translations);
        }
    ),
    'lang'
);

// merge translations from plugin options in case they exist
$translations = A::merge($translations, option('programmatordev.stripe-checkout.translations', []));

return $translations;
