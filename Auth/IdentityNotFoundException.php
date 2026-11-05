<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Raised when an identity resolver reports an expected resolution miss.
 */
final class IdentityNotFoundException extends \RuntimeException
{
}
