<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Generic assembly engine for ordered identity providers.
 *
 * Concrete runtimes supply the canonical slot order plus discovered provider
 * implementations. The chain validates provider declarations, assembles their
 * resolver registrations once, caches the most recent successful identity
 * while it remains fresh, and then behaves as a normal identity resolver.
 */
final class IdentityChain implements IdentityChainInterface
{
    private const REFRESH_WINDOW_SECONDS = 300;

    /** @var class-string<IdentityInterface> */
    private readonly string $identityType;

    /** @var list<IdentityResolverRegistration> */
    private readonly array $registrations;

    /** @var list<string> */
    private readonly array $detectedSlots;

    private ?IdentityInterface $cachedIdentity = null;

    /** @var array<string, mixed>|null */
    private ?array $cachedIdentityProperties = null;

    private ?ResolvedIdentityMetadata $lastResolvedMetadata = null;

    private bool $refreshRequired = false;

    /** @var \Closure(IdentityInterface, IdentityInterface): bool */
    private readonly \Closure $identityMatcher;

    /**
     * @param class-string<IdentityInterface> $identityType
     * @param list<ChainIdentityProviderInterface> $providers
     * @param list<string> $slotOrder
     * @param array<string, mixed> $properties
     * @param null|callable(IdentityInterface, IdentityInterface): bool
     *     $identityMatcher Compare the cached identity to a rejected identity.
     */
    public function __construct(
        string $identityType,
        array $providers,
        array $slotOrder,
        array $properties = [],
        ?callable $identityMatcher = null
    )
    {
        if (!\is_a($identityType, IdentityInterface::class, true)) {
            throw new \InvalidArgumentException(
                'identityType must be a class-string implementing '
                    . IdentityInterface::class
                    . '.'
            );
        }

        $this->identityType = $identityType;
        $this->identityMatcher = $identityMatcher === null
            ? static fn(
                IdentityInterface $cached,
                IdentityInterface $rejected
            ): bool => $cached === $rejected
            : \Closure::fromCallable($identityMatcher);
        $sortedProviders = self::sortProviders($providers, $slotOrder);
        $setup = new ChainSetup($properties);

        foreach ($sortedProviders as $provider) {
            $setup->setCurrentProvider($provider);
            $provider->setup($identityType, $setup);
            if ($setup->isTerminal()) {
                break;
            }
        }

        $this->registrations = $setup->registrations();
        $this->detectedSlots = $setup->detectedSlots();
    }

    /** @inheritDoc */
    public function resolveIdentity(array $properties = []): IdentityResult
    {
        if ($this->cachedIdentity !== null
            && $this->cachedIdentityProperties === $properties
            && !$this->refreshRequired
            && !$this->requiresRefresh($this->cachedIdentity)
        ) {
            return IdentityResult::success($this->cachedIdentity);
        }

        if ($this->registrations === []) {
            return IdentityResult::notFound(
                self::class,
                'No identity resolvers were registered in the chain.'
            );
        }

        $failures = [];

        foreach ($this->registrations as $registration) {
            $result = $registration->resolver->resolveIdentity($properties);
            $identity = $result->identity;
            if ($identity === null) {
                if ($registration->terminal) {
                    return $result;
                }

                $failures[] = $registration->metadata->providerName
                    . ': '
                    . $result->error;

                continue;
            }

            if (!$identity instanceof $this->identityType) {
                throw new \RuntimeException(
                    'Identity resolver returned '
                        . $identity::class
                        . ', expected '
                        . $this->identityType
                        . '.'
                );
            }

            $this->cachedIdentity = $identity;
            $this->cachedIdentityProperties = $properties;
            $this->lastResolvedMetadata = $registration->metadata;
            $this->refreshRequired = false;

            return $result;
        }

        return IdentityResult::notFound(
            self::class,
            'Unable to resolve identity from any provider in the chain. '
                . 'Tried: '
                . \implode('; ', $failures)
        );
    }

    /** @inheritDoc */
    public function invalidate(IdentityInterface $rejectedIdentity): void
    {
        if ($this->cachedIdentity !== null
            && ($this->identityMatcher)(
                $this->cachedIdentity,
                $rejectedIdentity
            )
        ) {
            $this->refreshRequired = true;
        }

        foreach ($this->registrations as $registration) {
            $registration->invalidate($rejectedIdentity);
        }
    }

    /** @inheritDoc */
    public function getLastResolvedMetadata(): ?ResolvedIdentityMetadata
    {
        return $this->lastResolvedMetadata;
    }

    /**
     * Return resolver registrations in execution order.
     *
     * @return list<IdentityResolverRegistration>
     */
    public function getRegistrations(): array
    {
        return $this->registrations;
    }

    /**
     * Return the provider slots detected during setup.
     *
     * @return list<string>
     */
    public function getDetectedSlots(): array
    {
        return $this->detectedSlots;
    }

    /**
     * Return the identity type this chain resolves.
     *
     * @return class-string<IdentityInterface>
     */
    public function getIdentityType(): string
    {
        return $this->identityType;
    }

    /**
     * @param list<ChainIdentityProviderInterface> $providers
     * @param list<string> $slotOrder
     * @return list<ChainIdentityProviderInterface>
     */
    private static function sortProviders(
        array $providers,
        array $slotOrder
    ): array
    {
        $seenNames = [];
        $claimedSlots = [];
        $slotSet = [];
        $before = [];
        $standard = [];
        $after = [];

        foreach ($slotOrder as $slot) {
            if (isset($slotSet[$slot])) {
                throw new \InvalidArgumentException(
                    'Duplicate provider slot registered: ' . $slot . '.'
                );
            }

            $slotSet[$slot] = true;
        }

        foreach ($providers as $provider) {
            $name = $provider->name();
            if ($name === '') {
                throw new \InvalidArgumentException(
                    'Provider names must not be empty.'
                );
            }

            if (isset($seenNames[$name])) {
                throw new \InvalidArgumentException(
                    'Duplicate provider name registered: ' . $name . '.'
                );
            }
            $seenNames[$name] = true;

            $ordering = $provider->ordering();
            if (!isset($slotSet[$ordering->slot])) {
                throw new \InvalidArgumentException(
                    'Provider '
                        . $name
                        . ' references unknown slot '
                        . $ordering->slot
                        . '.'
                );
            }

            if ($ordering->isStandard()) {
                if (isset($claimedSlots[$ordering->slot])) {
                    throw new \InvalidArgumentException(
                        'Multiple providers claim standard slot '
                            . $ordering->slot
                            . '.'
                    );
                }
                $claimedSlots[$ordering->slot] = true;
                $standard[$ordering->slot] = $provider;
                continue;
            }

            if ($ordering->isBefore()) {
                $before[$ordering->slot] ??= [];
                $before[$ordering->slot][] = $provider;
                continue;
            }

            $after[$ordering->slot] ??= [];
            $after[$ordering->slot][] = $provider;
        }

        $sorted = [];
        foreach ($slotOrder as $slot) {
            foreach ($before[$slot] ?? [] as $provider) {
                $sorted[] = $provider;
            }

            if (isset($standard[$slot])) {
                $sorted[] = $standard[$slot];
            }

            foreach ($after[$slot] ?? [] as $provider) {
                $sorted[] = $provider;
            }
        }

        return $sorted;
    }

    private function requiresRefresh(IdentityInterface $identity): bool
    {
        $expiry = $identity->getExpirationTime();
        if ($expiry === null) {
            return false;
        }

        return $expiry - \microtime(true) <= self::REFRESH_WINDOW_SECONDS;
    }
}
