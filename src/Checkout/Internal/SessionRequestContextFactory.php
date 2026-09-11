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
 * Normalizes language, expiry, the initiating URL, and configured destinations.
 *
 * @internal
 */
final class SessionRequestContextFactory
{
    private const SUPPORTED_STRIPE_LOCALES = [
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

        if ($order->uiMode() !== $settings->uiMode()) {
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

        $language = $this->resolveLanguage($order->languageCode());
        $resolvedLanguageCode = $language?->code();
        $siteUrl = $this->siteUrl($resolvedLanguageCode);
        $initiatingUrl = $this->resolveInitiatingUrl($initiatingUrl, $siteUrl);
        $isLiveMode = $configuration->stripe()->secretKeyMode() === CredentialMode::Live;

        return new SessionRequestContext(
            order: $order,
            locale: $this->resolveStripeLocale($language),
            expiresAt: $createdAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->add(new DateInterval(CheckoutAttempt::SESSION_LIFETIME)),
            initiatingUrl: $initiatingUrl,
            successDestination: $this->resolveDestination(
                value: $settings->successDestination(),
                name: 'successDestination',
                languageCode: $resolvedLanguageCode,
                fallbackUrl: $initiatingUrl,
                isLiveMode: $isLiveMode,
            ),
            cancelDestination: $this->resolveDestination(
                value: $settings->cancelDestination(),
                name: 'cancelDestination',
                languageCode: $resolvedLanguageCode,
                fallbackUrl: $initiatingUrl,
                isLiveMode: $isLiveMode,
            ),
            returnDestination: $this->resolveDestination(
                value: $settings->returnDestination(),
                name: 'returnDestination',
                languageCode: $resolvedLanguageCode,
                fallbackUrl: $initiatingUrl,
                isLiveMode: $isLiveMode,
            ),
        );
    }

    private function resolveLanguage(?string $languageCode): ?Language
    {
        if ($this->kirby->multilang() === false) {
            return null;
        }

        return $this->kirby->language($languageCode)
            ?? $this->kirby->defaultLanguage();
    }

    private function resolveStripeLocale(?Language $language): string
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
        $supportedLocales = array_combine(
            array_map('strtolower', self::SUPPORTED_STRIPE_LOCALES),
            self::SUPPORTED_STRIPE_LOCALES,
        );
        $exact = $supportedLocales[strtolower($locale)] ?? null;

        if (is_string($exact)) {
            return $exact;
        }

        $primary = explode('-', $locale)[0];

        return $supportedLocales[strtolower($primary)] ?? 'auto';
    }

    private function siteUrl(?string $languageCode): string
    {
        $url = $this->kirby->site()->url($languageCode);

        return $this->normalizeUrl($url, false, 'site.url');
    }

    private function resolveInitiatingUrl(?string $value, string $siteUrl): string
    {
        if ($value === null) {
            return $siteUrl;
        }

        // Unlike a configured destination, this optional request context can be
        // discarded safely when it is malformed or does not belong to this site.
        try {
            $initiatingUrl = $this->normalizeUrl($value, false, 'checkout.initiatingUrl', removeResultKey: true);
        } catch (ConfigurationException) {
            return $siteUrl;
        }

        return $this->sameOrigin($initiatingUrl, $siteUrl) ? $initiatingUrl : $siteUrl;
    }

    private function resolveDestination(
        ?string $value,
        string $name,
        ?string $languageCode,
        string $fallbackUrl,
        bool $isLiveMode,
    ): string {
        if ($value === null) {
            return $fallbackUrl;
        }

        if (str_starts_with($value, '//')) {
            throw new ConfigurationException('configuration.value_invalid', 'settings.' . $name);
        }

        if (str_starts_with($value, '/')) {
            $value = Url::makeAbsolute($value, $this->siteUrl($languageCode));
        } elseif (preg_match('~^https?://~i', $value) !== 1) {
            // Kirby returns null for draft-only locators when drafts are disabled.
            $page = $this->kirby->page($value, drafts: false);

            if ($page === null) {
                throw new ConfigurationException('configuration.value_invalid', 'settings.' . $name);
            }

            $value = $page->url($languageCode);
        }

        return $this->normalizeUrl($value, $isLiveMode, 'settings.' . $name);
    }

    private function normalizeUrl(
        string $value,
        bool $isLiveMode,
        string $path,
        bool $removeResultKey = false,
    ): string {
        if (CheckoutUrlValidator::isDestination($value, $isLiveMode) === false) {
            throw new ConfigurationException('configuration.value_invalid', $path);
        }

        $parts = parse_url($value);

        if (is_array($parts) === false) {
            throw new ConfigurationException('configuration.value_invalid', $path);
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        if (array_key_exists(CheckoutUrlValidator::RESULT_QUERY_KEY, $query)) {
            if ($removeResultKey === false) {
                throw new ConfigurationException('configuration.value_invalid', $path);
            }

            unset($query[CheckoutUrlValidator::RESULT_QUERY_KEY]);
            $value = Url::build([
                'fragment' => null,
                'query' => $query,
            ], $value);
        }

        return Url::stripFragment($value);
    }

    private function sameOrigin(string $url, string $siteUrl): bool
    {
        $urlParts = parse_url($url);
        $siteUrlParts = parse_url($siteUrl);

        if (is_array($urlParts) === false || is_array($siteUrlParts) === false) {
            return false;
        }

        return strtolower((string) ($urlParts['scheme'] ?? '')) === strtolower((string) ($siteUrlParts['scheme'] ?? ''))
            && strtolower((string) ($urlParts['host'] ?? '')) === strtolower((string) ($siteUrlParts['host'] ?? ''))
            && $this->port($urlParts) === $this->port($siteUrlParts);
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
}
