<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Mutable assembly context used while constructing an {@see IdentityChain}.
 *
 * Providers share this object sequentially during assembly. They may attach
 * arbitrary state into the property bag and register resolvers tagged with the
 * current provider's metadata.
 */
final class ChainSetup
{
    /** @var array<string, mixed> */
    private array $properties;

    /** @var list<IdentityResolverRegistration> */
    private array $registrations = [];

    /** @var list<string> */
    private array $detectedSlots = [];

    private ?ResolvedIdentityMetadata $currentProvider = null;

    private bool $terminal = false;

    /**
     * @param array<string, mixed> $properties
     */
    public function __construct(array $properties = [])
    {
        $this->properties = $properties;
    }

    /**
     * Return all setup properties.
     *
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return $this->properties;
    }

    /**
     * Return true if a setup property exists.
     */
    public function hasProperty(string $key): bool
    {
        return \array_key_exists($key, $this->properties);
    }

    /**
     * Get a setup property.
     */
    public function getProperty(string $key, mixed $default = null): mixed
    {
        return \array_key_exists($key, $this->properties)
            ? $this->properties[$key]
            : $default;
    }

    /**
     * Set a setup property.
     *
     * @return void
     */
    public function setProperty(string $key, mixed $value): void
    {
        $this->properties[$key] = $value;
    }

    /**
     * Set the provider whose resolver registrations are being collected.
     *
     * @internal
     *
     * @return void
     */
    public function setCurrentProvider(
        ChainIdentityProviderInterface $provider
    ): void
    {
        $this->currentProvider = new ResolvedIdentityMetadata(
            providerName: $provider->name(),
            featureIds: $provider->featureIds()
        );
    }

    /**
     * Add a resolver to the identity chain.
     *
     * @param null|callable(): void $invalidator
     * @param null|list<string> $featureIds
     *
     * @return void
     */
    public function addResolver(
        IdentityResolverInterface $resolver,
        mixed $invalidator = null,
        ?array $featureIds = null
    ): void
    {
        if ($this->terminal) {
            throw new \LogicException(
                'Cannot register more resolvers after a terminal resolver.'
            );
        }

        $this->registrations[] = new IdentityResolverRegistration(
            resolver: $resolver,
            metadata: $this->metadataForRegistration($featureIds),
            terminal: false,
            invalidator: $invalidator
        );
    }

    /**
     * Add a terminal resolver to the identity chain.
     *
     * @param null|callable(): void $invalidator
     * @param null|list<string> $featureIds
     *
     * @return void
     */
    public function addTerminalResolver(
        IdentityResolverInterface $resolver,
        mixed $invalidator = null,
        ?array $featureIds = null
    ): void
    {
        if ($this->terminal) {
            throw new \LogicException(
                'A terminal resolver has already been registered.'
            );
        }

        $this->registrations[] = new IdentityResolverRegistration(
            resolver: $resolver,
            metadata: $this->metadataForRegistration($featureIds),
            terminal: true,
            invalidator: $invalidator
        );
        $this->terminal = true;
    }

    /**
     * Mark a provider slot as detected.
     *
     * @return void
     */
    public function markDetected(string $slot): void
    {
        if ($slot === '' || \in_array($slot, $this->detectedSlots, true)) {
            return;
        }

        $this->detectedSlots[] = $slot;
    }

    /**
     * Return detected provider slots.
     *
     * @return list<string>
     */
    public function detectedSlots(): array
    {
        return $this->detectedSlots;
    }

    /**
     * Return resolver registrations in insertion order.
     *
     * @return list<IdentityResolverRegistration>
     */
    public function registrations(): array
    {
        return $this->registrations;
    }

    /**
     * Return true if a terminal resolver has been registered.
     */
    public function isTerminal(): bool
    {
        return $this->terminal;
    }

    private function requireCurrentProvider(): ResolvedIdentityMetadata
    {
        if ($this->currentProvider === null) {
            throw new \LogicException(
                'No current provider is active on the chain setup.'
            );
        }

        return $this->currentProvider;
    }

    /**
     * @param null|list<string> $featureIds
     */
    private function metadataForRegistration(
        ?array $featureIds
    ): ResolvedIdentityMetadata
    {
        $metadata = $this->requireCurrentProvider();
        if ($featureIds === null) {
            return $metadata;
        }

        return new ResolvedIdentityMetadata(
            providerName: $metadata->providerName,
            featureIds: $featureIds
        );
    }
}
