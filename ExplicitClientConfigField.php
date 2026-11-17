<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client;

/**
 * Client configuration fields whose provenance is tracked.
 *
 * Case values match the {@see ClientConfig} constructor argument names and
 * `copyWith()` override keys.
 */
enum ExplicitClientConfigField: string
{
    case Protocol = 'protocol';
    case ProtocolResolver = 'protocolResolver';
    case AuthSchemeResolver = 'authSchemeResolver';
    case RetryStrategy = 'retryStrategy';
}
