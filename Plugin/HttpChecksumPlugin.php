<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Plugin;

use Smithy\Client\AbstractClientConfig;
use Smithy\Client\Interceptor\HttpChecksumInterceptor;

/**
 * Register required HTTP checksum handling.
 */
final class HttpChecksumPlugin extends AbstractPlugin
{
    /** @inheritDoc */
    public function configure(AbstractClientConfig $config): AbstractClientConfig
    {
        return $config->withInterceptor(new HttpChecksumInterceptor());
    }

    /** @inheritDoc */
    public function getPluginPhase(): PluginPhase
    {
        return PluginPhase::Apply;
    }
}
