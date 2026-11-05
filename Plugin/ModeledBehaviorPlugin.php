<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Plugin;

/**
 * Contribute runtime plugins whose behavior is selected by operation traits.
 */
final class ModeledBehaviorPlugin extends AbstractPlugin
{
    private static ?self $instance = null;

    private function __construct() {}

    /**
     * Access the shared modeled-behavior plugin.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** @inheritDoc */
    public function getChildPlugins(): array
    {
        return [
            new RequestCompressionPlugin(),
            new HttpChecksumPlugin()
        ];
    }
}
