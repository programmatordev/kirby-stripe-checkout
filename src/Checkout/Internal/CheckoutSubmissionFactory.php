<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use DateTimeImmutable;
use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartSnapshot;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartStoreInterface;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutLineItem;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\RequestErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\SelectionErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Configuration\Configuration;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\ProductErrorCode;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;

/**
 * Authorizes one submission, resolves its facts once, and atomically accepts its purchase binding.
 * The HTTP edge supplies parsed input after verifying transport, CSRF and origin requirements.
 * Shipping quotation and Order/Session mutation belong to CheckoutSessionCreator.
 *
 * @internal
 */
final readonly class CheckoutSubmissionFactory
{
    public function __construct(
        private App $kirby,
        private Configuration $configuration,
        private CheckoutResolver $resolver,
        private CartStoreInterface $cartStore,
        private BrowserAttemptStore $attemptStore,
    ) {}

    public function create(CheckoutSubmissionInput $input, DateTimeImmutable $acceptedAt): CheckoutSubmission
    {
        return $this->createAuthorized(
            authorization: $this->authorize($input, $acceptedAt),
            acceptedAt: $acceptedAt,
        );
    }

    public function authorize(CheckoutSubmissionInput $input, DateTimeImmutable $checkedAt): CheckoutSubmissionAuthorization
    {
        $uiMode = $this->configuration->settings()->uiMode();

        if ($uiMode === UiMode::Embedded && $input->isJson() === false) {
            throw new CheckoutInputException(RequestErrorCode::UNSUPPORTED_REPRESENTATION);
        }

        if ($input->checkoutSource() === CheckoutSource::Cart && $this->configuration->cartEnabled() === false) {
            throw new CheckoutInputException(CheckoutErrorCode::CART_DISABLED);
        }

        // Use one snapshot for revision, selections and country so they cannot come from different cart versions.
        $cart = $input->checkoutSource() === CheckoutSource::Cart ? $this->cartStore->read() : null;

        if ($input->cartRevision() !== $cart?->revision()) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_CONFLICT);
        }

        $context = BrowserAttemptContext::capture(
            checkoutSource: $input->checkoutSource(),
            uiMode: $uiMode,
            languageCode: $this->kirby->language()?->code(),
            userUuid: $this->kirby->user()?->uuid()->toString(),
            csrf: (string) $this->kirby->csrf(),
            cart: $cart,
        );
        // Verify browser authority before resolver callbacks; purchase acceptance follows successful resolution.
        $attempt = $this->attemptStore->load($input->attemptToken(), $context, $checkedAt);

        return new CheckoutSubmissionAuthorization(
            input: $input,
            attempt: $attempt,
            context: $context,
            cart: $cart,
        );
    }

    public function createAuthorized(
        CheckoutSubmissionAuthorization $authorization,
        DateTimeImmutable $acceptedAt,
    ): CheckoutSubmission {
        $input = $authorization->input();
        $cart = $authorization->cart();
        $context = $authorization->context();
        $shipping = $cart !== null
            ? $this->resolver->shippingContext($cart->shippingCountry())
            : $this->resolver->directShippingContext($input->shippingCountry());

        // Browser authorization releases its native lock before any project/product or provider resolution.
        if ($cart !== null) {
            $checkout = $this->cartCheckoutContext($cart);
        } else {
            /** @var non-empty-list<ProductRequest> $requests Direct transport has already parsed the complete selection. */
            $requests = $input->items();
            $checkout = $this->resolver->directCheckoutContextFromRequests($requests);
        }

        // Country and shipping tax inputs affect the saved request even when merchandise has not changed.
        // Include them in the binding so a duplicate cannot retarget an accepted purchase.
        $contextFingerprint = hash('sha256', json_encode([
            'checkout' => CheckoutContextFingerprint::fromCheckout($checkout),
            'shippingCountry' => $shipping->shippingCountry(),
            'shippingTaxBehavior' => $shipping->taxBehavior()->value,
            'shippingTaxCode' => $shipping->taxCode(),
        ], JSON_THROW_ON_ERROR));
        $binding = $cart !== null
            ? AttemptBinding::cart(
                cart: $cart,
                contextFingerprint: $contextFingerprint,
                userUuid: $context->userUuid(),
                guestReference: $context->guestReference(),
            )
            : AttemptBinding::direct(
                items: array_map(static fn(CheckoutLineItem $lineItem): ProductRequest => $lineItem->productRequest(), $checkout->items()),
                contextFingerprint: $contextFingerprint,
                userUuid: $context->userUuid(),
                guestReference: $context->guestReference(),
            );
        $acceptedInput = $this->acceptedInput($input, $checkout, $shipping);
        // A competitor may bind while resolution runs; the store compares the winning fingerprint under its native lock.
        $attempt = $this->attemptStore->bind(
            token: $input->attemptToken(),
            context: $context,
            binding: $binding,
            acceptedInput: $acceptedInput,
            boundAt: $acceptedAt,
        );

        return new CheckoutSubmission(
            acceptedInput: $acceptedInput,
            attempt: $attempt,
            checkout: $checkout,
            shipping: $shipping,
            binding: $binding,
        );
    }

    private function acceptedInput(
        CheckoutSubmissionInput $input,
        CheckoutContext $checkout,
        ShippingContext $shipping,
    ): CheckoutSubmissionInput {
        $direct = $input->checkoutSource() === CheckoutSource::Direct;
        $items = $direct
            ? array_map(static fn(CheckoutLineItem $lineItem): ProductRequest => $lineItem->productRequest(), $checkout->items())
            : [];

        return new CheckoutSubmissionInput(
            checkoutSource: $input->checkoutSource(),
            attemptToken: $input->attemptToken(),
            cartRevision: $input->cartRevision(),
            items: $items,
            shippingCountry: $direct ? $shipping->shippingCountry() : null,
            json: false,
        );
    }

    private function cartCheckoutContext(CartSnapshot $cart): CheckoutContext
    {
        if ($cart->entries() === []) {
            throw new CheckoutInputException(SelectionErrorCode::INVALID);
        }

        $lineItems = [];

        foreach ($cart->entries() as $entry) {
            $product = $this->resolver->resolveProduct($entry->request());

            // Stored Cart selections are already canonical; a stale locator must not become another product.
            if (ProductRequestData::sameItem($entry->request(), $product->request()) === false) {
                throw new InvalidProductException(ProductErrorCode::RESOLVER_CHANGED_REQUEST);
            }

            $lineItems[] = $this->resolver->checkoutLineItem($product);
        }

        return $this->resolver->checkoutContext($lineItems, CheckoutSource::Cart);
    }
}
