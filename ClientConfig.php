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
use Smithy\Client\Retry\NoRetryStrategy;
use Smithy\Client\Retry\RetryStrategyInterface;
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
        array $authSchemePreference = []
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
                ?? $this->authSchemePreference
        );
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
