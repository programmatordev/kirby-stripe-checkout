<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

/** Stable product error codes for exceptions and boundary mappings. */
final class ProductErrorCode
{
    public const CONTEXT_INVALID = 'product.context_invalid';

    public const CURRENCY_MISMATCH = 'product.currency_mismatch';

    public const CURRENCY_MISSING = 'product.currency_missing';

    public const FIELD_INVALID = 'product.field_invalid';

    public const IMAGES_INVALID = 'product.images_invalid';

    public const INVALID = 'product.invalid';

    public const METADATA_INVALID = 'product.metadata_invalid';

    public const NAME_MISSING = 'product.name_missing';

    public const NOT_FOUND = 'product.not_found';

    public const OPTIONS_INVALID = 'product.options_invalid';

    public const PRICE_INVALID = 'product.price_invalid';

    public const PRICE_MISSING = 'product.price_missing';

    public const PRICE_SOURCE_MISMATCH = 'product.price_source_mismatch';

    public const REQUEST_INVALID = 'product.request_invalid';

    public const RESOLUTION_UNAVAILABLE = 'product.resolution_unavailable';

    public const RESOLVER_CHANGED_REQUEST = 'product.resolver_changed_request';

    public const RESOLVER_FAILED = 'product.resolver_failed';

    public const SELECTED_OPTIONS_INVALID = 'product.selected_options_invalid';

    public const SHIPPING_INVALID = 'product.shipping_invalid';

    public const SHIPPING_MISSING = 'product.shipping_missing';

    public const STRIPE_PRICE_INELIGIBLE = 'product.stripe_price_ineligible';

    public const STRIPE_PRICE_INVALID = 'product.stripe_price_invalid';

    public const STRIPE_PRICE_UNAVAILABLE = 'product.stripe_price_unavailable';

    public const STRIPE_PRODUCT_INELIGIBLE = 'product.stripe_product_ineligible';

    public const UNAVAILABLE = 'product.unavailable';

    public const VARIANT_INVALID = 'product.variant_invalid';

    public const VARIANT_UNAVAILABLE = 'product.variant_unavailable';
}
