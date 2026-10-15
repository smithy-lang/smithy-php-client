<?php

declare(strict_types=1);

namespace Smithy\Client;

use Smithy\Client\Auth\AuthSchemeInterface;
use Smithy\Client\Auth\AuthSchemeResolver;
use Smithy\Client\Auth\AuthSchemeResolverInterface;
use Smithy\Client\Auth\NoAuthScheme;
use Smithy\Client\Endpoint\EndpointResolverInterface;
use Smithy\Client\Interceptor\InterceptorChain;
use Smithy\Client\Interceptor\InterceptorInterface;
use Smithy\Client\Plugin\{
    AutoPlugin,
    PluginCollection,
    PluginInterface,
    PluginResolver,
    PluginState
};
use Smithy\Client\Retry\NoRetryStrategy;
use Smithy\Client\Retry\RetryStrategyInterface;
use Smithy\Core\Shape\ServiceInterface;
use Smithy\Http\Client\HttpClientInterface;
use Smithy\Protocol\ProtocolInterface;

/**
 * Immutable configuration for a {@see ClientInterface}.
 *
 * Holds every dependency the pipeline needs to execute an operation:
 * protocol, HTTP client, endpoint resolver, retry strategy, auth
 * schemes, and interceptors. `withXxx()` helpers return copies.
 *
 * The `context` associative bag stores extension state for
 * interceptors and generated client config subclasses.
 */
class ClientConfig
{
    private readonly PluginState $pluginState;

    public readonly InterceptorChain $interceptorChain;

    /** @var list<AuthSchemeInterface> */
    public readonly array $supportedAuthSchemes;

    /** @var array<string, AuthSchemeInterface> */
    private readonly array $supportedAuthSchemesById;

    /** @var list<string> */
    public readonly array $authSchemePreference;

    public readonly AuthSchemeResolverInterface $authSchemeResolver;

    public readonly RetryStrategyInterface $retryStrategy;

    public readonly string $retryScope;

    /** @var array<string, mixed> */
    public readonly array $context;

    /**
     * @param ProtocolInterface $protocol Protocol implementation for the
     *     target service.
     * @param HttpClientInterface $httpClient Transport for `$protocol`'s
     *                            serialized requests.
     * @param EndpointResolverInterface $endpointResolver Resolves the
     *                                  endpoint once per operation execution.
     * @param InterceptorChain $interceptors Interceptors invoked at each
     *                                      pipeline hook point.
     * @param list<AuthSchemeInterface> $supportedAuthSchemes Auth schemes
     *                                  the resolver may select from.
     * @param AuthSchemeResolverInterface|null $authSchemeResolver
     *                                         Selects a scheme per operation.
     * @param list<string> $authSchemePreference Auth scheme names in
     *        customer-preferred order.
     * @param RetryStrategyInterface|null $retryStrategy Defaults to
     *                                    {@see NoRetryStrategy}.
     * @param string $retryScope Retry scope identifier shared across
     *               executions.
     * @param array<string, mixed> $context Extension bag for
     *                      interceptor state or forward-compatible fields.
     * @param ServiceInterface|null $service Runtime service metadata.
     * @param PluginState|null $pluginState Internal plugin application state.
     */
    public function __construct(
        public readonly ProtocolInterface $protocol,
        public readonly HttpClientInterface $httpClient,
        public readonly EndpointResolverInterface $endpointResolver,
        InterceptorChain $interceptors = new InterceptorChain(),
        array $supportedAuthSchemes = [],
        ?AuthSchemeResolverInterface $authSchemeResolver = null,
        ?RetryStrategyInterface $retryStrategy = null,
        string $retryScope = '',
        array $context = [],
        array $authSchemePreference = [],
        public readonly ?ServiceInterface $service = null,
        ?PluginState $pluginState = null
    )
    {
        $this->interceptorChain = $interceptors;

        $schemes = \array_values($supportedAuthSchemes);
        $hasNoAuth = false;
        foreach ($schemes as $s) {
            if ($s->getSchemeId() === NoAuthScheme::ID) {
                $hasNoAuth = true;
                break;
            }
        }
        if (!$hasNoAuth) {
            $schemes[] = NoAuthScheme::instance();
        }
        $this->supportedAuthSchemes = $schemes;
        $supportedAuthSchemesById = [];
        foreach ($schemes as $scheme) {
            $supportedAuthSchemesById[$scheme->getSchemeId()] = $scheme;
        }
        $this->supportedAuthSchemesById = $supportedAuthSchemesById;
        $this->authSchemePreference = self::validateAuthSchemePreference(
            $authSchemePreference
        );

        $this->authSchemeResolver = $authSchemeResolver
            ?? new AuthSchemeResolver();
        $this->retryStrategy = $retryStrategy ?? NoRetryStrategy::instance();
        $this->retryScope = $retryScope;
        $this->context = $context;
        $this->pluginState = $pluginState ?? PluginState::defaults();
    }

    /**
     * Register a plugin for application when the client is constructed.
     */
    public function withPlugin(PluginInterface $plugin): static
    {
        return $this->withPlugins($plugin);
    }

    /**
     * Register plugins for application when the client is constructed.
     */
    public function withPlugins(PluginInterface ...$plugins): static
    {
        return $this->withPluginCollection(
            new PluginCollection(...$plugins)
        );
    }

    /**
     * Register a collection for application when the client is constructed.
     */
    public function withPluginCollection(
        PluginCollection $plugins
    ): static {
        if ($plugins->isEmpty()) {
            return $this;
        }

        $state = $this->pluginState->withPlugins($plugins);

        return $state === $this->pluginState
            ? $this
            : $this->withResolvedPluginState($state);
    }

    /**
     * Resolve and apply every registered plugin.
     *
     * @internal Called after a client attaches its service metadata.
     */
    public function resolvePlugins(): static
    {
        /** @var static */
        return (new PluginResolver())
            ->resolve(new PluginCollection(), $this->pluginState)
            ->apply($this);
    }

    /**
     * Replace the predicate used to select plugins.
     *
     * Previously applied plugins remain applied. Registered plugins that were
     * previously rejected are reconsidered.
     *
     * @param callable(PluginInterface): bool $predicate
     */
    public function withPluginPredicate(callable $predicate): static
    {
        return $this->resolveWithPluginPredicate(
            self::toClosure($predicate)
        );
    }

    /**
     * Combine a predicate with the currently configured predicate.
     *
     * @param callable(PluginInterface): bool $predicate
     */
    public function withAddedPluginPredicate(callable $predicate): static
    {
        $added = self::toClosure($predicate);
        $existing = $this->pluginState->predicate;

        return $this->resolveWithPluginPredicate(
            $existing === null
                ? $added
                : static fn(PluginInterface $plugin): bool =>
                    $existing($plugin) && $added($plugin)
        );
    }

    /**
     * Disable Composer-registered automatic plugins.
     *
     * Automatic plugins already applied to this configuration are not
     * removed.
     */
    public function withoutAutoPlugins(): static
    {
        return $this->withAddedPluginPredicate(
            static fn(PluginInterface $plugin): bool =>
                $plugin !== AutoPlugin::instance()
        );
    }

    /**
     * Return internal plugin application state.
     *
     * @internal
     */
    public function getPluginState(): PluginState
    {
        return $this->pluginState;
    }

    /**
     * Return a config carrying resolved plugin state.
     *
     * @internal
     */
    public function withResolvedPluginState(PluginState $state): static
    {
        if ($state === $this->pluginState) {
            return $this;
        }

        return $this->copyWith(['pluginState' => $state]);
    }

    /**
     * Create a config associated with runtime service metadata.
     */
    public function withService(ServiceInterface $service): static
    {
        if ($service === $this->service) {
            return $this;
        }

        return $this->copyWith(['service' => $service]);
    }

    /**
     * Create a config with `$interceptor` appended.
     */
    public function withInterceptor(InterceptorInterface $interceptor): static
    {
        return $this->copyWith([
            'interceptors' => $this->interceptorChain->add($interceptor)
        ]);
    }

    /**
     * Create a config with `$interceptor` prepended.
     */
    public function withPrependedInterceptor(
        InterceptorInterface $interceptor
    ): static
    {
        return $this->copyWith([
            'interceptors' => $this->interceptorChain->prepend($interceptor)
        ]);
    }

    /**
     * Create a config with the interceptors replaced.
     */
    public function withInterceptors(
        InterceptorInterface ...$interceptors
    ): static
    {
        return $this->copyWith([
            'interceptors' => new InterceptorChain(...$interceptors)
        ]);
    }

    /**
     * Create a config with the retry strategy replaced.
     */
    public function withRetryStrategy(RetryStrategyInterface $strategy): static
    {
        return $this->copyWith(['retryStrategy' => $strategy]);
    }

    /**
     * Create a config with the retry scope string replaced.
     *
     * The retry scope is passed to the {@see RetryStrategyInterface} so
     * that multiple executions with the same scope share token-bucket
     * state.
     */
    public function withRetryScope(string $scope): static
    {
        return $this->copyWith(['retryScope' => $scope]);
    }

    /**
     * Create a config with the endpoint resolver replaced.
     */
    public function withEndpointResolver(
        EndpointResolverInterface $resolver
    ): static
    {
        return $this->copyWith(['endpointResolver' => $resolver]);
    }

    /**
     * Create a config with the protocol replaced.
     */
    public function withProtocol(ProtocolInterface $protocol): static
    {
        return $this->copyWith(['protocol' => $protocol]);
    }

    /**
     * Create a config with the HTTP client replaced.
     */
    public function withHttpClient(HttpClientInterface $httpClient): static
    {
        return $this->copyWith(['httpClient' => $httpClient]);
    }

    /**
     * Create a config with the auth-scheme resolver replaced.
     */
    public function withAuthSchemeResolver(
        AuthSchemeResolverInterface $resolver
    ): static
    {
        return $this->copyWith(['authSchemeResolver' => $resolver]);
    }

    /**
     * Create a config with the auth scheme preference replaced.
     *
     * @param list<string> $preference
     */
    public function withAuthSchemePreference(array $preference): static
    {
        return $this->copyWith([
            'authSchemePreference' => \array_values($preference)
        ]);
    }

    /**
     * Create a config with `$scheme` appended to the supported list.
     *
     * `NoAuthScheme` is always present. This method appends additional
     * schemes callers explicitly opt into.
     */
    public function withAddedAuthScheme(AuthSchemeInterface $scheme): static
    {
        return $this->withSupportedAuthSchemes([
            ...$this->supportedAuthSchemes,
            $scheme
        ]);
    }

    /**
     * Create a config with the supported auth schemes list replaced.
     *
     * `NoAuthScheme` is auto-re-added by the constructor if omitted.
     *
     * @param list<AuthSchemeInterface> $schemes
     */
    public function withSupportedAuthSchemes(array $schemes): static
    {
        return $this->copyWith([
            'supportedAuthSchemes' => \array_values($schemes)
        ]);
    }

    /**
     * Return the configured implementation of an auth scheme.
     */
    public function getSupportedAuthScheme(
        string $schemeId
    ): ?AuthSchemeInterface
    {
        return $this->supportedAuthSchemesById[$schemeId] ?? null;
    }

    /**
     * Create a config with a value stored in the `context` bag.
     */
    public function withContext(string $key, mixed $value): static
    {
        return $this->copyWith([
            'context' => [...$this->context, $key => $value]
        ]);
    }

    /**
     * Shallow copy the config, overriding fields named in `$overrides`.
     * Subclasses override this to include their own fields.
     *
     * Explicit `null` values are ignored and preserve the current value.
     *
     * @param array<string, mixed> $overrides
     */
    protected function copyWith(array $overrides): static
    {
        $protocol = $overrides['protocol'] ?? $this->protocol;
        $httpClient = $overrides['httpClient'] ?? $this->httpClient;
        $endpointResolver =
            $overrides['endpointResolver'] ?? $this->endpointResolver;
        $interceptors =
            $overrides['interceptors'] ?? $this->interceptorChain;
        $supportedAuthSchemes = $overrides['supportedAuthSchemes']
            ?? $this->supportedAuthSchemes;
        $authSchemeResolver = $overrides['authSchemeResolver']
            ?? $this->authSchemeResolver;
        $retryStrategy =
            $overrides['retryStrategy'] ?? $this->retryStrategy;
        $retryScope = $overrides['retryScope'] ?? $this->retryScope;
        $context = $overrides['context'] ?? $this->context;
        $service = $overrides['service'] ?? $this->service;
        $pluginState = $overrides['pluginState'] ?? $this->pluginState;

        return new static(
            protocol: $protocol,
            httpClient: $httpClient,
            endpointResolver: $endpointResolver,
            interceptors: $interceptors,
            supportedAuthSchemes: $supportedAuthSchemes,
            authSchemeResolver: $authSchemeResolver,
            retryStrategy: $retryStrategy,
            retryScope: $retryScope,
            context: $context,
            authSchemePreference: $overrides['authSchemePreference']
                ?? $this->authSchemePreference,
            service: $service,
            pluginState: $pluginState
        );
    }

    private function resolveWithPluginPredicate(
        \Closure $predicate
    ): static {
        $state = $this->pluginState->withPredicate($predicate);

        return $this->withResolvedPluginState($state);
    }

    private static function toClosure(callable $callable): \Closure
    {
        return $callable instanceof \Closure
            ? $callable
            : \Closure::fromCallable($callable);
    }

    /**
     * @param list<string> $preference
     *
     * @return list<string>
     */
    private static function validateAuthSchemePreference(
        array $preference
    ): array
    {
        $validated = [];
        foreach ($preference as $name) {
            if (!\is_string($name) || $name === '') {
                throw new \InvalidArgumentException(
                    'Auth scheme preference names must be non-empty strings.'
                );
            }
            if (!\in_array($name, $validated, true)) {
                $validated[] = $name;
            }
        }

        return $validated;
    }
}
