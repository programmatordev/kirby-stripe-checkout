<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Support\Stripe;

use Closure;
use LogicException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Plugin\PluginMetadata;
use Stripe\HttpClient\ClientInterface;

/** Explicit offline HTTP responses for the webhook's real SDK/runtime/reconciler integration. */
final class WebhookCheckoutClient implements ClientInterface
{
    /** @var list<string> */
    public array $requests = [];

    /** @var array<string, mixed> */
    public array $session;

    /** @var array<string, mixed> */
    public array $paymentIntent;

    public int $httpStatus = 200;

    public ?Closure $beforeSessionRead = null;

    /** @var list<array<string, mixed>> */
    public array $refunds = [];
    /** @var array<string, array<string, mixed>> */
    public array $refundRecords = [];
    /** @var array<string, array<string, mixed>> */
    public array $refundPages = [];
    /** @var array<string, array<string, mixed>> */
    public array $charges = [];
    /** @var list<array<string, mixed>>|null */
    public ?array $sessionMatches = null;
    public bool $sessionLookupHasMore = false;
    public ?Closure $beforeRefundListRead = null;

    public function __construct(string $pageUuid, int $createdAt)
    {
        $metadata = [
            PluginMetadata::OWNER_KEY => PluginMetadata::NAME,
            PluginMetadata::ORDER_KEY => $pageUuid,
        ];
        $this->session = [
            'id' => 'cs_webhook',
            'object' => 'checkout.session',
            'created' => $createdAt,
            'expires_at' => $createdAt + 86400,
            'livemode' => false,
            'client_reference_id' => $pageUuid,
            'metadata' => $metadata,
            'integration_identifier' => 'kirby_stripe_checkout_abcdefgh',
            'mode' => 'payment',
            'ui_mode' => 'hosted_page',
            'status' => 'complete',
            'payment_status' => 'paid',
            'currency' => 'eur',
            'amount_subtotal' => 3200,
            'amount_total' => 3200,
            'shipping_options' => [],
            'custom_fields' => [],
            'automatic_tax' => ['enabled' => false],
            'total_details' => [
                'amount_discount' => 0,
                'amount_shipping' => 0,
                'amount_tax' => 0,
                'breakdown' => ['discounts' => []],
            ],
        ];
        $this->paymentIntent = [
            'id' => 'pi_webhook',
            'object' => 'payment_intent',
            'created' => $createdAt,
            'livemode' => false,
            'metadata' => $metadata,
            'currency' => 'eur',
            'amount' => 3200,
            'amount_received' => 3200,
            'capture_method' => 'automatic',
            'status' => 'succeeded',
            'payment_method' => [
                'id' => 'pm_webhook',
                'object' => 'payment_method',
                'type' => 'card',
            ],
            'latest_charge' => null,
        ];
    }

    /**
     * @param list<string> $headers
     * @param array<string, mixed> $params
     * @return array{string, int, array<string, string>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        if ($method !== 'get') {
            throw new LogicException('Webhook tests allow only explicit provider reads.');
        }

        $this->requests[] = $absUrl;
        $path = parse_url($absUrl, PHP_URL_PATH);

        if ($path === '/v1/checkout/sessions/cs_webhook') {
            $this->beforeSessionRead?->__invoke();
        }

        if ($path === '/v1/refunds') {
            $this->beforeRefundListRead?->__invoke();
        }

        if ($this->httpStatus !== 200) {
            return [json_encode(['error' => [
                'message' => 'PRIVATE_PROVIDER_CANARY',
                'type' => 'api_error',
            ]], JSON_THROW_ON_ERROR), $this->httpStatus, ['request-id' => 'req_webhook_failure']];
        }

        if ($path === '/v1/checkout/sessions/cs_webhook') {
            $body = [...$this->session, 'payment_intent' => $this->paymentIntent];
        } elseif ($path === '/v1/checkout/sessions/cs_webhook/line_items') {
            $body = [
                'object' => 'list',
                'has_more' => false,
                'data' => [[
                    'id' => 'li_webhook',
                    'object' => 'item',
                    'metadata' => [
                        PluginMetadata::OWNER_KEY => PluginMetadata::NAME,
                        PluginMetadata::ORDER_KEY => $this->session['client_reference_id'],
                        PluginMetadata::LINE_KEY => 'line_0',
                    ],
                    'quantity' => 2,
                    'currency' => 'eur',
                    'description' => 'T-shirt',
                    'amount_subtotal' => 3200,
                    'amount_discount' => 0,
                    'amount_tax' => 0,
                    'amount_total' => 3200,
                    'discounts' => [],
                    'taxes' => [],
                    'price' => [
                        'id' => 'price_webhook',
                        'object' => 'price',
                        'currency' => 'eur',
                        'unit_amount' => 1600,
                        'product' => 'prod_webhook',
                        'billing_scheme' => 'per_unit',
                        'type' => 'one_time',
                    ],
                ]],
            ];
        } elseif ($path === '/v1/payment_intents/pi_webhook') {
            $body = $this->paymentIntent;
        } elseif (is_string($path) && str_starts_with($path, '/v1/charges/')) {
            $body = $this->charges[basename($path)] ?? throw new LogicException('No Charge fixture.');
        } elseif ($path === '/v1/checkout/sessions') {
            if (($params['payment_intent'] ?? null) !== 'pi_webhook' || ($params['limit'] ?? null) !== 2) {
                throw new LogicException('Session lookup must be exact and bounded.');
            }

            $body = [
                'object' => 'list',
                'has_more' => $this->sessionLookupHasMore,
                'data' => $this->sessionMatches ?? [[...$this->session, 'payment_intent' => 'pi_webhook']],
            ];
        } elseif ($path === '/v1/refunds') {
            if (($params['payment_intent'] ?? null) !== 'pi_webhook' || ($params['limit'] ?? null) !== 100) {
                throw new LogicException('Refund listing must be filtered and paginated.');
            }

            $body = $this->refundPages[OrderData::string($params['starting_after'] ?? '')] ?? [
                'object' => 'list',
                'has_more' => false,
                'data' => $this->refunds,
            ];
        } elseif (is_string($path) && str_starts_with($path, '/v1/refunds/')) {
            $body = $this->refundRecords[basename($path)] ?? throw new LogicException('No Refund fixture.');
        } else {
            throw new LogicException('No webhook fixture exists for this provider read.');
        }

        return [json_encode($body, JSON_THROW_ON_ERROR), 200, ['request-id' => 'req_webhook']];
    }
}
