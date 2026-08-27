<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Discoverable participant in a long-lived identity chain.
 *
 * Providers are assembled once into an {@see IdentityChain}. During that
 * assembly pass each provider inspects the requested identity type plus any
 * shared setup state, then registers zero or more resolvers.
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
     * Register zero or more resolvers for the requested identity type.
     *
     * Providers that do not support `$identityType` should return without
     * mutating the setup.
     *
     * @param class-string<IdentityInterface> $identityType
     *
     * @return void
     */
    public function setup(string $identityType, ChainSetup $setup): void;
}
