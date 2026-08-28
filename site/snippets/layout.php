<?php

/** @var Kirby\Cms\App $kirby */
/** @var Kirby\Cms\Site $site */
/** @var Kirby\Template\Slots $slots */
/** @var Kirby\Content\Field|string|null $title */

$documentTitle = isset($title) ? $title . ' · ' . $site->title() : $site->title();
$languageCode = $kirby->language()?->code() ?? 'en';
?>
<!doctype html>
<html lang="<?= esc($languageCode) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($documentTitle) ?></title>
    <style>
        :root {
            color-scheme: light dark;
            font-family: ui-sans-serif, system-ui, sans-serif;
            line-height: 1.5;
        }

        body {
            margin: 0;
        }

        header,
        main,
        footer {
            margin-inline: auto;
            max-width: 70rem;
            padding: 1.25rem;
        }

        header {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            justify-content: space-between;
        }

        nav {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
        }

        a {
            color: inherit;
        }

        .grid {
            display: grid;
            gap: 1rem;
            grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
        }

        .card {
            border: 1px solid color-mix(in srgb, currentColor 20%, transparent);
            border-radius: .75rem;
            padding: 1rem;
        }

        .eyebrow {
            font-size: .75rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        code {
            overflow-wrap: anywhere;
        }

        footer {
            opacity: .7;
        }
    </style>
</head>
<body>
    <header>
        <strong><a href="<?= esc($site->url()) ?>"><?= esc($site->title()) ?></a></strong>
        <nav aria-label="Development site">
            <?php foreach ($site->children()->listed() as $navigationPage): ?>
                <a href="<?= esc($navigationPage->url()) ?>"><?= esc($navigationPage->title()) ?></a>
            <?php endforeach ?>
            <a href="<?= esc($site->url() . '/panel') ?>">Panel</a>
        </nav>
    </header>
    <main>
        <?= $slots->content() ?>
    </main>
    <footer>
        Development fixture for Kirby Stripe Checkout. No Checkout or Stripe request behavior is currently registered.
    </footer>
</body>
</html>
