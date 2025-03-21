<?php

use Kirby\Data\Yaml;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Toolkit\A;

// get all files from /translations and register them as language files
// shameless copy from https://github.com/tobimori/kirby-dreamform
return A::keyBy(
    A::map(
        Dir::read(__DIR__ . '/../translations'),
        function ($file): array
        {
            $translations = [];
            foreach (Yaml::read(__DIR__ . '/../translations/' . $file) as $key => $value) {
                $translations['stripe-checkout.' . $key] = $value;
            }

            return A::merge(
                ['lang' => F::name($file)],
                $translations
            );
        }
    ),
    'lang'
);
