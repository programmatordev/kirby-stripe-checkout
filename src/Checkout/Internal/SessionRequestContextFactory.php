<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Kirby\Cms\App;
use Kirby\Cms\Language;
use Kirby\Http\Url;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;
use ProgrammatorDev\StripeCheckout\Configuration\Configuration;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;

/**
 * Normalizes language, expiry, origin, and configured navigation destinations.
 *
 * @internal
 */
final class SessionRequestContextFactory
{
    public const RESULT_QUERY_KEY = '_stripe_checkout_result';

    private const STRIPE_LOCALES = [
        'auto',
        'bg',
        'cs',
        'da',
        'de',
        'el',
        'en',
        'en-GB',
        'es',
        'es-419',
        'et',
        'fi',
        'fil',
        'fr',
        'fr-CA',
        'hr',
        'hu',
        'id',
        'it',
        'ja',
        'ko',
        'lt',
        'lv',
        'ms',
        'mt',
        'nb',
        'nl',
        'pl',
        'pt',
        'pt-BR',
        'ro',
        'ru',
        'sk',
        'sl',
        'sv',
        'th',
        'tr',
        'vi',
        'zh',
        'zh-HK',
        'zh-TW',
    ];

    public function __construct(private readonly App $kirby) {}

    public function create(
        OrderCreationContext $order,
        Configuration $configuration,
        DateTimeImmutable $createdAt,
        ?string $initiatingUrl = null,
    ): SessionRequestContext {
        $settings = $configuration->settings();

        if ($order->uiMode() !== $settings->uiMode()->value) {
            throw new CheckoutInputException('checkout.attempt_conflict');
        }

        $currency = $settings->currency();

        if ($currency === null) {
            throw new ConfigurationException(
                'configuration.required_missing',
                'settings.currency',
            );
        }

        if ($order->currency() !== $currency) {
            throw new CheckoutInputException('checkout.attempt_conflict');
        }

        $language = $this->language($order->languageCode());
        $languageCode = $language?->code();
        $fallback = $this->siteUrl($languageCode);
        $origin = $this->origin($initiatingUrl, $fallback);
        $live = $configuration->stripe()->secretKeyMode() === CredentialMode::Live;

        return new SessionRequestContext(
            order: $order,
            uiMode: $settings->uiMode(),
            locale: $this->locale($language),
            expiresAt: $createdAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->add(new DateInterval('PT' . $settings->checkoutExpirationMinutes() . 'M')),
            originUrl: $origin,
            successDestination: $this->destination(
                $settings->successDestination(),
                'successDestination',
                $languageCode,
                $origin,
                $live,
            ),
            cancelDestination: $this->destination(
                $settings->cancelDestination(),
                'cancelDestination',
                $languageCode,
                $origin,
                $live,
            ),
            returnDestination: $this->destination(
                $settings->returnDestination(),
                'returnDestination',
                $languageCode,
                $origin,
                $live,
            ),
        );
    }

    private function language(?string $languageCode): ?Language
    {
        if ($this->kirby->multilang() === false) {
            return null;
        }

        return $this->kirby->language($languageCode)
            ?? $this->kirby->defaultLanguage();
    }

    private function locale(?Language $language): string
    {
        $locale = $language?->locale(LC_ALL) ?? $language?->code();

        if ($locale === null) {
            $locale = $this->kirby->option('locale');

            if (is_array($locale)) {
                $locale = $locale[LC_ALL]
                    ?? $locale['LC_ALL']
                    ?? null;
            }
        }

        if (is_string($locale) === false || trim($locale) === '') {
            return 'auto';
        }

        $locale = preg_replace('/[.@].*$/', '', trim($locale));
        $locale = is_string($locale) ? str_replace('_', '-', $locale) : '';
        $supported = array_combine(
            array_map('strtolower', self::STRIPE_LOCALES),
            self::STRIPE_LOCALES,
        );
        $exact = $supported[strtolower($locale)] ?? null;

        if (is_string($exact)) {
            return $exact;
        }

        $primary = explode('-', $locale)[0];

        return $supported[strtolower($primary)] ?? 'auto';
    }

    private function siteUrl(?string $languageCode): string
    {
        $url = $this->kirby->site()->url($languageCode);

        return $this->normalizeUrl($url, false, 'site.url');
    }

    private function origin(?string $value, string $fallback): string
    {
        if ($value === null) {
            return $fallback;
        }

        try {
            $origin = $this->normalizeUrl($value, false, 'checkout.originUrl', removeResultKey: true);
        } catch (ConfigurationException) {
            return $fallback;
        }

        return $this->sameOrigin($origin, $fallback) ? $origin : $fallback;
    }

    private function destination(
        ?string $value,
        string $name,
        ?string $languageCode,
        string $fallback,
        bool $live,
    ): string {
        if ($value === null) {
            return $fallback;
        }

        if (str_starts_with($value, '//')) {
            throw new ConfigurationException('configuration.value_invalid', 'settings.' . $name);
        }

        if (str_starts_with($value, '/')) {
            $value = Url::makeAbsolute($value, $this->siteUrl($languageCode));
        } elseif (preg_match('~^https?://~i', $value) !== 1) {
            $page = $this->kirby->page($value, drafts: false);

            if ($page === null || $page->isDraft()) {
                throw new ConfigurationException('configuration.value_invalid', 'settings.' . $name);
            }

            $value = $page->url($languageCode);
        }

        return $this->normalizeUrl($value, $live, 'settings.' . $name);
    }

    private function normalizeUrl(
        string $value,
        bool $live,
        string $path,
        bool $removeResultKey = false,
    ): string {
        $parts = parse_url($value);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';

        if (
            $parts === false
            || strlen($value) > 2048
            || filter_var($value, FILTER_VALIDATE_URL) === false
            || in_array($scheme, ['http', 'https'], true) === false
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || $live && $scheme !== 'https' && $this->isLocalHost($host) === false
        ) {
            throw new ConfigurationException('configuration.value_invalid', $path);
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        if (array_key_exists(self::RESULT_QUERY_KEY, $query)) {
            if ($removeResultKey === false) {
                throw new ConfigurationException('configuration.value_invalid', $path);
            }

            unset($query[self::RESULT_QUERY_KEY]);
            $value = Url::build([
                'fragment' => null,
                'query' => $query,
            ], $value);
        }

        return Url::stripFragment($value);
    }

    private function sameOrigin(string $url, string $siteUrl): bool
    {
        $origin = parse_url($url);
        $site = parse_url($siteUrl);

        if (is_array($origin) === false || is_array($site) === false) {
            return false;
        }

        return strtolower((string) ($origin['scheme'] ?? '')) === strtolower((string) ($site['scheme'] ?? ''))
            && strtolower((string) ($origin['host'] ?? '')) === strtolower((string) ($site['host'] ?? ''))
            && $this->port($origin) === $this->port($site);
    }

    /** @param array<string, mixed> $parts */
    private function port(array $parts): int
    {
        $port = $parts['port'] ?? null;

        if (is_int($port)) {
            return $port;
        }

        $scheme = $parts['scheme'] ?? null;

        return is_string($scheme) && strtolower($scheme) === 'https' ? 443 : 80;
    }

    private function isLocalHost(string $host): bool
    {
        $host = trim($host, '[]');

        return $host === 'localhost'
            || $host === '::1'
            || str_starts_with($host, '127.')
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.ddev.site');
    }
}
