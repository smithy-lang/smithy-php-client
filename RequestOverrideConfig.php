<?php

declare(strict_types=1);

namespace Smithy\Client;

use Smithy\Client\Plugin\{
    PluginCollection,
    PluginInterface
};
use Smithy\Client\Transport\{
    TransportInterface,
    TransportOptionsInterface,
    TransportRequestInterface,
    TransportRequestOverrideInterface
};
use Smithy\Http\Client\HttpRequestOverride;

/**
 * Configure per-request behavior outside modeled operation input.
 */
final readonly class RequestOverrideConfig
{
    public const HTTP = HttpRequestOverride::KEY;
    public const TRANSPORT = '@transport';
    public const RETRIES = '@retries';
    public const AUTH = '@auth';
    public const PLUGINS = '@plugins';

    /**
     * @param TransportRequestOverrideInterface|null $transportOverrides
     *        Transport-specific request behavior.
     * @param int|null $maxAttempts Maximum attempts for this request.
     * @param list<string>|null $authSchemePreference Auth scheme preference.
     * @param PluginCollection $plugins Plugins applied only to this call.
     */
    private function __construct(
        public ?TransportRequestOverrideInterface $transportOverrides,
        public ?int $maxAttempts,
        public ?array $authSchemePreference,
        public PluginCollection $plugins = new PluginCollection()
    ) {}

    /**
     * Create overrides from `@`-prefixed operation input entries.
     *
     * @param array<string, mixed> $overrides
     *
     * @throws \InvalidArgumentException If an override is invalid.
     */
    public static function fromArray(array $overrides): self
    {
        foreach ($overrides as $name => $_) {
            if ($name !== self::HTTP
                && $name !== self::TRANSPORT
                && $name !== self::RETRIES
                && $name !== self::AUTH
                && $name !== self::PLUGINS
            ) {
                throw new \InvalidArgumentException(
                    "Unknown request override '{$name}'."
                );
            }
        }

        $httpOverrides = $overrides[self::HTTP] ?? null;
        $transportOverrides = $overrides[self::TRANSPORT] ?? null;
        if ($httpOverrides !== null && $transportOverrides !== null) {
            throw new \InvalidArgumentException(
                self::HTTP . ' and ' . self::TRANSPORT
                    . ' cannot both be provided.'
            );
        }

        if ($httpOverrides !== null) {
            $transportOverrides = HttpRequestOverride::fromValue(
                $httpOverrides
            );
        } elseif ($transportOverrides !== null) {
            if (!$transportOverrides
                instanceof TransportRequestOverrideInterface
            ) {
                throw new \InvalidArgumentException(
                    self::TRANSPORT . ' must implement '
                        . TransportRequestOverrideInterface::class . '.'
                );
            }
        }

        $maxAttempts = null;
        if (isset($overrides[self::RETRIES])) {
            $retries = $overrides[self::RETRIES];
            if (!\is_int($retries) || $retries < 0) {
                throw new \InvalidArgumentException(
                    self::RETRIES
                    . ' must be a non-negative integer.'
                );
            }

            if ($retries === \PHP_INT_MAX) {
                throw new \InvalidArgumentException(
                    self::RETRIES
                    . ' is too large.'
                );
            }
            $maxAttempts = $retries + 1;
        }

        $authSchemePreference = null;
        if (isset($overrides[self::AUTH])) {
            $authSchemePreference = self::authSchemePreference(
                $overrides[self::AUTH]
            );
        }

        $plugins = self::plugins($overrides[self::PLUGINS] ?? null);

        return new self(
            $transportOverrides,
            $maxAttempts,
            $authSchemePreference,
            $plugins
        );
    }

    /**
     * Return overrides with a call-scoped plugin appended.
     */
    public function withPlugin(PluginInterface $plugin): self
    {
        return new self(
            $this->transportOverrides,
            $this->maxAttempts,
            $this->authSchemePreference,
            $this->plugins->add($plugin)
        );
    }

    /**
     * Return overrides with call-scoped plugins appended.
     */
    public function withPlugins(PluginInterface ...$plugins): self
    {
        return new self(
            $this->transportOverrides,
            $this->maxAttempts,
            $this->authSchemePreference,
            $this->plugins->merge(new PluginCollection(...$plugins))
        );
    }

    /**
     * Resolve request options for the configured transport.
     */
    public function resolveTransportOptions(
        TransportInterface $transport
    ): ?TransportOptionsInterface
    {
        return $this->transportOverrides?->resolveOptions($transport);
    }

    /**
     * Apply message-level overrides to a serialized request.
     */
    public function applyToRequest(
        TransportRequestInterface $request
    ): TransportRequestInterface
    {
        return $this->transportOverrides?->applyToRequest($request)
            ?? $request;
    }

    private static function plugins(mixed $value): PluginCollection
    {
        if ($value === null) {
            return new PluginCollection();
        }
        if ($value instanceof PluginCollection) {
            return $value;
        }
        if (!\is_array($value) || !\array_is_list($value)) {
            throw new \InvalidArgumentException(
                self::PLUGINS
                . ' must be a PluginCollection or list of plugins.'
            );
        }

        foreach ($value as $plugin) {
            if (!$plugin instanceof PluginInterface) {
                throw new \InvalidArgumentException(
                    self::PLUGINS
                    . ' must contain only PluginInterface instances.'
                );
            }
        }

        return new PluginCollection(...$value);
    }

    /** @return list<string> */
    private static function authSchemePreference(mixed $value): array
    {
        if (!\is_array($value)
            || $value === []
            || !\array_is_list($value)
        ) {
            throw new \InvalidArgumentException(
                self::AUTH
                . ' must be a non-empty list of auth scheme names.'
            );
        }

        $preference = [];
        foreach ($value as $name) {
            if (!\is_string($name) || $name === '') {
                throw new \InvalidArgumentException(
                    self::AUTH
                    . ' values must be non-empty strings.'
                );
            }

            if (!\in_array($name, $preference, true)) {
                $preference[] = $name;
            }
        }

        return $preference;
    }
}
