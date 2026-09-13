<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

/** Stable configuration error codes for exceptions and boundary mappings. */
final class ConfigurationErrorCode
{
    public const CREDENTIAL_MISSING = 'configuration.credential_missing';

    public const CREDENTIAL_MODE_MISMATCH = 'configuration.credential_mode_mismatch';

    public const NOT_READY = 'configuration.not_ready';

    public const OPTION_DUPLICATE = 'configuration.option_duplicate';

    public const OPTION_UNKNOWN = 'configuration.option_unknown';

    public const REQUIRED_MISSING = 'configuration.required_missing';

    public const ROOT_INVALID = 'configuration.root_invalid';

    public const TRANSLATION_INVALID = 'configuration.translation_invalid';

    public const TYPE_INVALID = 'configuration.type_invalid';

    public const VALUE_INVALID = 'configuration.value_invalid';
}
