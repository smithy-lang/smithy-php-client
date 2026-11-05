<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Discoverable participant in a long-lived identity chain.
 *
 * Providers are assembled once into an {@see IdentityChain}. During that
 * assembly pass each provider inspects shared setup state, then registers
 * zero or more resolvers.
 *
 * @template TIdentity of IdentityInterface
 */
interface ChainIdentityProviderInterface
{
    /**
     * Canonical provider name used for diagnostics and telemetry.
     */
    public function name(): string;

    /**
     * Provider position relative to the chain's ordered slots.
     */
    public function ordering(): OrderingConstraint;

    /**
     * Provider feature IDs emitted when this provider wins resolution.
     *
     * @return list<string>
     */
    public function featureIds(): array;

    /**
     * Register zero or more resolvers.
     *
     * @param ChainSetup<TIdentity> $setup
     * @return void
     */
    public function setup(ChainSetup $setup): void;
}
