<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Raised when an identity resolver reports an expected resolution miss.
 */
final class IdentityNotFoundException extends \RuntimeException
{
}
