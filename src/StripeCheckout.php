<?php

namespace ProgrammatorDev\StripeCheckout;

use Brick\Money\Exception\UnknownCurrencyException;
use Kirby\Uuid\Uuid;
use ProgrammatorDev\StripeCheckout\Cart\Item;
use ProgrammatorDev\StripeCheckout\Exception\EmptyCartException;
use ProgrammatorDev\StripeCheckout\Exception\NoSuchPageException;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

class StripeCheckout
{
    private array $options;

    private string $currencySymbol;

    private string $checkoutUrl;

    private string $checkoutEmbeddedUrl;

    private StripeClient $stripe;

    private static ?self $instance = null;

    public function __construct(array $options = [])
    {
        $this->options = $this->resolveOptions($options);

        $this->currencySymbol = Currencies::getSymbol($this->currency());
        $this->checkoutUrl = sprintf('%s/%s', site()->url(), 'stripe/checkout');
        $this->checkoutEmbeddedUrl = sprintf('%s/%s', site()->url(), 'stripe/checkout/embedded');

        $this->stripe = new StripeClient($this->stripeSecretKey());
    }

    public static function instance(array $options = []): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        self::$instance = new self($options);

        return self::$instance;
    }

    public function stripePublicKey(): string
    {
        return $this->options['stripePublicKey'];
    }

    public function stripeSecretKey(): string
    {
        return $this->options['stripeSecretKey'];
    }

    public function stripeWebhookSecret(): string
    {
        return $this->options['stripeWebhookSecret'];
    }

    public function currency(): string
    {
        return $this->options['currency'];
    }

    public function currencySymbol(): string
    {
        return $this->currencySymbol;
    }

    public function uiMode(): string
    {
        return $this->options['uiMode'];
    }

    public function returnPage(): ?string
    {
        return $this->options['returnPage'];
    }

    public function successPage(): ?string
    {
        return $this->options['successPage'];
    }

    public function cancelPage(): ?string
    {
        return $this->options['cancelPage'];
    }

    public function ordersPage(): string
    {
        return $this->options['ordersPage'];
    }

    public function settingsPage(): ?string
    {
        return $this->options['settingsPage'];
    }

    public function checkoutUrl(): string
    {
        return $this->checkoutUrl;
    }

    public function checkoutEmbeddedUrl(): string
    {
        return $this->checkoutEmbeddedUrl;
    }

    /**
     * @throws ApiErrorException
     * @throws UnknownCurrencyException
     */
    public function createSession(): Session
    {
        $cart = cart();

        if ($cart->totalQuantity() === 0) {
            throw new EmptyCartException('Cart must have at least one added item.');
        }

        $languageCode = kirby()->language()?->code();

        $params = [
            'mode' => Session::MODE_PAYMENT,
            'ui_mode' => $this->uiMode(),
            'metadata' => [
                // generate a unique id for the order
                // required in webhooks to sync the different event payment steps
                'order_id' => Uuid::generate(),
                // save language to know which one was used when ordering
                // useful to set language programmatically on webhooks
                // example: to use with hooks when sending emails with the same language
                // as when the user made the order
                'language_code' => $languageCode
            ]
        ];

        $this->addLineItemsParams($params);
        $this->addShippingParams($params);

        match ($this->uiMode()) {
            Session::UI_MODE_HOSTED => $this->addHostedParams($params, $languageCode),
            Session::UI_MODE_EMBEDDED => $this->addEmbeddedParams($params, $languageCode)
        };

        // trigger event that allows modification of session parameters
        // https://docs.stripe.com/api/checkout/sessions/create?lang=php
        $params = kirby()->apply(
            'stripe-checkout.session.create:before',
            ['sessionParams' => $params],
            'sessionParams'
        );

        return $this->stripe->checkout->sessions->create($params);
    }

    /**
     * @throws ApiErrorException
     */
    public function retrieveSession(string $sessionId, array $params = [], array $options = []): Session
    {
        return $this->stripe->checkout->sessions->retrieve($sessionId, $params, $options);
    }

    /**
     * @throws SignatureVerificationException
     */
    public function constructWebhookEvent(string $payload, string $sigHeader): Event
    {
        return Webhook::constructEvent($payload, $sigHeader, $this->stripeWebhookSecret());
    }

    /**
     * @throws UnknownCurrencyException
     */
    private function addLineItemsParams(array &$params): void
    {
        $cart = cart();
        $params['line_items'] = [];

        /** @var Item $item */
        foreach ($cart->items() as $item) {
            $productData = [
                'name' => $item->name(),
                'metadata' => [
                    // save product page uuid
                    // can be useful for relation with product page
                    'page_id' => (string) $item->productPage()->uuid()
                ]
            ];

            if ($item->thumbnail()) {
                // Checkout Session allows multiple images
                // for now add just the item thumbnail as the first image
                $productData['images'] = [
                    $item->thumbnail()->url()
                ];
            }

            if (!empty($item->options())) {
                $productData['description'] = implode(', ', array_map(
                    fn ($option, $name) => sprintf('%s: %s', $name, $option),
                    $item->options(),
                    array_keys($item->options())
                ));
            }

            // set Stripe line_items data
            // https://docs.stripe.com/api/checkout/sessions/create?lang=php#create_checkout_session-line_items
            $params['line_items'][] = [
                'price_data' => [
                    // present currency in lowercase
                    // https://docs.stripe.com/currencies#presentment-currencies
                    'currency' => strtolower($this->currency()),
                    // Stripe expects amounts to be provided in the currency smallest unit
                    // https://docs.stripe.com/currencies#zero-decimal
                    'unit_amount' => MoneyFormatter::toMinorUnit($item->price(), $this->currency()),
                    'product_data' => $productData
                ],
                'quantity' => $item->quantity(),
            ];
        }
    }

    /**
     * @throws UnknownCurrencyException
     */
    private function addShippingParams(array &$params): void
    {
        $settingsPage = $this->settingsPage() ? page($this->settingsPage()) : null;

        // stop here in case there is no configured settings page or if shipping is disabled
        if ($settingsPage?->shippingEnabled()->toBool() !== true) {
            return;
        }

        // set Stripe shipping data
        // https://docs.stripe.com/payments/during-payment/charge-shipping?payment-ui=checkout&lang=php
        $params['shipping_address_collection']['allowed_countries'] = $settingsPage->shippingAllowedCountries()->split();
        $params['shipping_options'] = [];

        foreach ($settingsPage->shippingOptions()->toStructure() as $shippingOption) {
            $shippingRateData = [
                'type' => 'fixed_amount',
                'fixed_amount' => [
                    'amount' => MoneyFormatter::toMinorUnit($shippingOption->amount()->value(), $this->currency()),
                    'currency' => $this->currency(),
                ],
                'display_name' => $shippingOption->name()->value()
            ];

            if ($shippingOption->deliveryEstimateMin()->isNotEmpty()) {
                $shippingRateData['delivery_estimate']['minimum'] = [
                    'unit' => $shippingOption->deliveryEstimateMinUnit()->value(),
                    'value' => $shippingOption->deliveryEstimateMin()->value(),
                ];
            }

            if ($shippingOption->deliveryEstimateMax()->isNotEmpty()) {
                $shippingRateData['delivery_estimate']['maximum'] = [
                    'unit' => $shippingOption->deliveryEstimateMaxUnit()->value(),
                    'value' => $shippingOption->deliveryEstimateMax()->value(),
                ];
            }

            $params['shipping_options'][] = [
                'shipping_rate_data' => $shippingRateData
            ];
        }
    }

    private function addHostedParams(array &$params, ?string $languageCode): void
    {
        $params['success_url'] = $this->buildPageUrl($this->successPage(), $languageCode, true);
        $params['cancel_url'] = $this->buildPageUrl($this->cancelPage(), $languageCode);
    }

    private function addEmbeddedParams(array &$params, ?string $languageCode): void
    {
        $params['return_url'] = $this->buildPageUrl($this->returnPage(), $languageCode, true);
    }

    private function buildPageUrl(string $pageId, ?string $languageCode = null, bool $withCheckoutSessionParam = false): string
    {
        $page = page($pageId);

        if ($page === null) {
            throw new NoSuchPageException(
                sprintf('Page with id "%s" was not found.', $pageId)
            );
        }

        // get language specific URL
        $url = $page->url($languageCode);

        if ($withCheckoutSessionParam === true) {
            // include the {CHECKOUT_SESSION_ID} template variable in the URL
            // https://docs.stripe.com/checkout/embedded/quickstart#return-url
            $url = sprintf('%s?session_id={CHECKOUT_SESSION_ID}', $url);
        }

        return $url;
    }

    private function resolveOptions(array $options): array
    {
        $resolver = new OptionsResolver();

        $resolver->setDefaults(option('programmatordev.stripe-checkout'));

        $resolver->setAllowedTypes('stripePublicKey', ['string']);
        $resolver->setAllowedTypes('stripeSecretKey', ['string']);
        $resolver->setAllowedTypes('stripeWebhookSecret', ['string']);
        $resolver->setAllowedTypes('uiMode', ['string']);
        $resolver->setAllowedTypes('currency', ['string']);
        $resolver->setAllowedTypes('returnPage', ['null', 'string']);
        $resolver->setAllowedTypes('successPage', ['null', 'string']);
        $resolver->setAllowedTypes('cancelPage', ['null', 'string']);
        $resolver->setAllowedTypes('ordersPage', ['string']);
        $resolver->setAllowedTypes('settingsPage', ['null', 'string']);

        $resolver->setAllowedValues('uiMode', [Session::UI_MODE_HOSTED, Session::UI_MODE_EMBEDDED]);
        $resolver->setAllowedValues('currency', Currencies::getCurrencyCodes());

        $resolver->setNormalizer('returnPage', function (Options $options, ?string $returnPage): ?string {
            if ($options['uiMode'] === Session::UI_MODE_EMBEDDED && $returnPage === null) {
                throw new InvalidOptionsException(
                    sprintf('The option "returnPage" must be set when the option "uiMode" is "%s".', Session::UI_MODE_EMBEDDED)
                );
            }

            return $returnPage;
        });

        $resolver->setNormalizer('successPage', function (Options $options, ?string $successPage): ?string {
            if ($options['uiMode'] === Session::UI_MODE_HOSTED && $successPage === null) {
                throw new InvalidOptionsException(
                    sprintf('The option "successPage" must be set when the option "uiMode" is "%s".', Session::UI_MODE_HOSTED)
                );
            }

            return $successPage;
        });

        $resolver->setNormalizer('cancelPage', function (Options $options, ?string $cancelPage): ?string {
            if ($options['uiMode'] === Session::UI_MODE_HOSTED && $cancelPage === null) {
                throw new InvalidOptionsException(
                    sprintf('The option "cancelPage" must be set when the option "uiMode" is "%s".', Session::UI_MODE_HOSTED)
                );
            }

            return $cancelPage;
        });

        return $resolver->resolve($options);
    }
}
