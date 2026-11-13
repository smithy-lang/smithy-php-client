<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Endpoint\Bytecode;

use JmesPath\Env;
use Smithy\Client\Endpoint\EndpointResolverInterface;
use Smithy\Client\Endpoint\EndpointResolverParams;
use Smithy\Core\Endpoint\Endpoint;
use Smithy\Core\Serde\{
    DynamicTraitValue,
    Schema,
    SerializableStructInterface
};
use Smithy\Core\Shape\OperationInterface;
use Smithy\Core\Shape\Utils\ArrayShapeSerializer;

/**
 * Adapt an endpoint bytecode {@see Evaluator} to the Smithy client contract.
 *
 * The resolver builds the parameter map from client-wide static context,
 * modeled operation context, and request-specific endpoint parameters. It
 * then delegates rules evaluation and returns the resulting {@see Endpoint}.
 *
 * Successful resolutions are cached by the bytecode's declared endpoint
 * inputs. A per-resolver last-result slot handles consecutive identical
 * requests, while a bounded process-wide cache allows equivalent resolver
 * instances to share results. Cache namespaces include bytecode identity,
 * static context, auth mappings, key builders, and opcode handlers so
 * incompatible configurations remain isolated.
 *
 * @internal Caching is an implementation detail. External code should
 * depend on {@see EndpointResolverInterface}; the cache representation,
 * eviction policy, and default size may change without notice.
 */
final class BytecodeEndpointResolver implements EndpointResolverInterface
{
    private const CONTEXT_PARAM_TRAIT = 'smithy.rules#contextParam';
    private const OPERATION_CONTEXT_PARAMS_TRAIT =
        'smithy.rules#operationContextParams';
    private const STATIC_CONTEXT_PARAMS_TRAIT =
        'smithy.rules#staticContextParams';

    /** Default upper bound on the shared endpoint cache. */
    private const CACHE_MAX_ENTRIES = 128;

    private const KEY_MISSING = "\x00";
    private const KEY_NULL = "\x01";
    private const KEY_FALSE = "\x02";
    private const KEY_TRUE = "\x03";
    private const KEY_STRING = "\x04";
    private const KEY_INTEGER = "\x05";
    private const KEY_SERIALIZED = "\x06";

    private readonly Evaluator $evaluator;

    private readonly Bytecode $bytecode;

    private readonly ?OpcodeHandlerInterface $handler;

    /** @var array<string, string> */
    private readonly array $endpointAuthSchemeIds;

    /** @var array<string, int> */
    private readonly array $inputRegisterMap;

    /** Effective shared-cache limit. Zero disables shared caching. */
    private readonly int $cacheMax;

    /** Cache namespace for the bytecode and resolver configuration. */
    private readonly string $resolverNamespace;

    /** Last resolved cache key. */
    private string $lastKey = '';

    private ?Endpoint $lastEndpoint = null;

    /**
     * @var array<string, array{
     *     Bytecode,
     *     array<string, mixed>,
     *     OpcodeHandlerInterface|null,
     *     \Closure|null,
     *     array<string, string>,
     *     Endpoint
     * }>
     */
    protected static array $sharedCache = [];

    /** @var (\Closure(array<string, mixed>): string)|null */
    private readonly ?\Closure $keyBuilder;

    /**
     * @var array<string, false|array{
     *     list<array{Schema, string}>,
     *     list<array{string, string}>,
     *     array<string, mixed>
     * }>
     */
    private array $operationContexts = [];

    private ?OperationInterface $lastOperation = null;

    /**
     * @var false|array{
     *     list<array{Schema, string}>,
     *     list<array{string, string}>,
     *     array<string, mixed>
     * }
     */
    private array|false $lastOperationContext = false;

    /**
     * Create an endpoint resolver.
     *
     * @param Bytecode $bytecode Compiled endpoint ruleset.
     * @param array<string, mixed> $staticContext Client-wide static
     *                                            parameters applied to
     *                                            every resolution.
     * @param OpcodeHandlerInterface|null $handler Optional vendor handler
     *                                             for extended opcodes.
     * @param int|null $cacheSize Shared-cache entry limit. `null` uses
     *                            {@see self::CACHE_MAX_ENTRIES}; zero
     *                            disables shared caching.
     * @param (\Closure(array<string, mixed>): string)|null $keyBuilder
     *                            Optional generated cache-key builder. It
     *                            receives the merged input map and must
     *                            return a stable key using the same logical
     *                            inputs as {@see self::buildCacheKey()}.
     * @param array<string, string> $endpointAuthSchemeIds Raw endpoint
     *                            auth scheme names mapped to canonical
     *                            Smithy shape IDs.
     */
    public function __construct(
        Bytecode $bytecode,
        private readonly array $staticContext = [],
        ?OpcodeHandlerInterface $handler = null,
        ?int $cacheSize = null,
        ?\Closure $keyBuilder = null,
        array $endpointAuthSchemeIds = []
    )
    {
        foreach ($endpointAuthSchemeIds as $name => $schemeId) {
            if (!\is_string($name)
                || $name === ''
                || !\is_string($schemeId)
                || $schemeId === ''
            ) {
                throw new \InvalidArgumentException(
                    'Endpoint auth scheme mappings must contain non-empty '
                    . 'names mapped to non-empty Smithy shape IDs.'
                );
            }
        }

        $this->bytecode = $bytecode;
        $this->handler = $handler;
        $this->endpointAuthSchemeIds = $endpointAuthSchemeIds;
        $this->evaluator = new Evaluator(
            $bytecode,
            $handler,
            $endpointAuthSchemeIds
        );
        $this->inputRegisterMap = $bytecode->inputRegisterMap;
        $this->cacheMax = $cacheSize ?? self::CACHE_MAX_ENTRIES;
        $this->keyBuilder = $keyBuilder;

        // Build a namespace prefix identifying the resolver configuration.
        // The shared-cache entry also keeps the actual identity values so
        // recycled object IDs cannot return stale endpoints.
        $bytecodeId = \spl_object_id($bytecode);
        $resolverConfig = [
            $staticContext,
            $endpointAuthSchemeIds,
            $keyBuilder === null ? null : \spl_object_id($keyBuilder),
            $handler === null ? null : \spl_object_id($handler)
        ];
        $configDigest = $staticContext === []
            && $endpointAuthSchemeIds === []
            && $keyBuilder === null
            && $handler === null
            ? ''
            : \hash('xxh3', \serialize($resolverConfig), binary: true);
        $bytecodeKey = (string) $bytecodeId;
        $this->resolverNamespace = \strlen($bytecodeKey)
            . ':' . $bytecodeKey
            . \strlen($configDigest)
            . ':' . $configDigest;
    }

    public function resolveEndpoint(EndpointResolverParams $params): Endpoint
    {
        $operation = $params->operation;
        if ($operation === $this->lastOperation) {
            $operationContext = $this->lastOperationContext;
        } else {
            $identity = $operation->getRuntimeIdentity();
            $operationContext = $this->operationContexts[$identity]
                ??= $this->createOperationContext($operation);
            $this->lastOperation = $operation;
            $this->lastOperationContext = $operationContext;
        }

        $inputs = $this->staticContext;

        if ($operationContext !== false) {
            $this->collectOperationContext(
                $operationContext,
                $params->input,
                $inputs
            );
        }

        $context = $params->config->context[
            EndpointResolverParams::CONTEXT_PARAMETERS
        ] ?? [];
        if (!\is_array($context)) {
            throw new \InvalidArgumentException(
                'Endpoint parameters must be an associative array.'
            );
        }
        foreach ($context as $name => $value) {
            if (\is_string($name)) {
                $inputs[$name] = $value;
            }
        }

        $traceSink = $params->config->context[
            EndpointResolverParams::CONTEXT_TRACE_SINK
        ] ?? null;
        if ($traceSink !== null
            && !$traceSink instanceof EndpointResolutionTraceSinkInterface
        ) {
            throw new \InvalidArgumentException(
                'Endpoint resolution trace sink must implement '
                    . EndpointResolutionTraceSinkInterface::class
                    . '.'
            );
        }

        $trace = $traceSink?->begin($this->bytecode, $inputs);
        if ($trace !== null) {
            return $this->evaluator->evaluateTraced($inputs, $trace);
        }

        $key = $this->buildCacheKey($inputs);

        // L1 fast path: back-to-back identical resolve on this
        // resolver instance. `$this->lastKey` is the unqualified body
        // (no namespace prefix) so the compare stays a single `===` on
        // a small string.
        if ($key === $this->lastKey && $this->lastEndpoint !== null) {
            return $this->lastEndpoint;
        }

        // L2: process-wide FIFO map. Prepend the resolver namespace so
        // this resolver only looks at entries with the same identity-shaped
        // key, then verify the retained identities before using the endpoint.
        $namespacedKey = $this->resolverNamespace . $key;
        $cached = $this->cacheMax > 0
            ? (self::$sharedCache[$namespacedKey] ?? null)
            : null;
        if ($cached !== null && $this->isSharedCacheHit($cached)) {
            $this->lastKey = $key;

            return $this->lastEndpoint = $cached[5];
        }

        if ($cached !== null) {
            unset(self::$sharedCache[$namespacedKey]);
        }

        // Do not cache evaluation errors.
        $endpoint = $this->evaluator->evaluate($inputs);

        if ($this->cacheMax > 0) {
            while (\count(self::$sharedCache) >= $this->cacheMax) {
                \array_shift(self::$sharedCache);
            }
            self::$sharedCache[$namespacedKey] = [
                $this->bytecode,
                $this->staticContext,
                $this->handler,
                $this->keyBuilder,
                $this->endpointAuthSchemeIds,
                $endpoint
            ];
        }
        $this->lastKey = $key;
        $this->lastEndpoint = $endpoint;

        return $endpoint;
    }

    /**
     * @param array{
     *     Bytecode,
     *     array<string, mixed>,
     *     OpcodeHandlerInterface|null,
     *     \Closure|null,
     *     array<string, string>,
     *     Endpoint
     * } $entry
     */
    private function isSharedCacheHit(array $entry): bool
    {
        return $entry[0] === $this->bytecode
            && $entry[1] === $this->staticContext
            && $entry[2] === $this->handler
            && $entry[3] === $this->keyBuilder
            && $entry[4] === $this->endpointAuthSchemeIds;
    }

    /**
     * Find the operation and input traits that contribute endpoint context.
     *
     * @return false|array{
     *     list<array{Schema, string}>,
     *     list<array{string, string}>,
     *     array<string, mixed>
     * }
     */
    private function createOperationContext(
        OperationInterface $operation
    ): array|false
    {
        $members = [];
        foreach (
            $operation->getInputSchema()->getMembersByIndex()
                as $member
        ) {
            $trait = self::traitValue(
                $member->getDirectTraitById(self::CONTEXT_PARAM_TRAIT)
            );
            $name = \is_array($trait) ? ($trait['name'] ?? null) : null;
            if (\is_string($name)
                && isset($this->inputRegisterMap[$name])
            ) {
                $members[] = [$member, $name];
            }
        }

        $operationSchema = $operation->getSchema();
        $paths = [];
        $pathTrait = self::traitValue(
            $operationSchema->getDirectTraitById(
                self::OPERATION_CONTEXT_PARAMS_TRAIT
            )
        );
        if (\is_array($pathTrait)) {
            foreach ($pathTrait as $name => $definition) {
                $path = \is_array($definition)
                    ? ($definition['path'] ?? null)
                    : null;
                if (\is_string($name)
                    && \is_string($path)
                    && isset($this->inputRegisterMap[$name])
                ) {
                    $paths[] = [$name, $path];
                }
            }
        }

        $static = [];
        $staticTrait = self::traitValue(
            $operationSchema->getDirectTraitById(
                self::STATIC_CONTEXT_PARAMS_TRAIT
            )
        );
        if (\is_array($staticTrait)) {
            foreach ($staticTrait as $name => $definition) {
                if (\is_string($name)
                    && \is_array($definition)
                    && \array_key_exists('value', $definition)
                    && isset($this->inputRegisterMap[$name])
                ) {
                    $static[$name] = $definition['value'];
                }
            }
        }

        return $members === [] && $paths === [] && $static === []
            ? false
            : [$members, $paths, $static];
    }

    /**
     * Merge one operation's request-scoped context into endpoint inputs.
     *
     * Directly bound input members are applied first. JMESPath bindings
     * fill names that were not resolved by a direct member, and modeled
     * operation-static values are applied last. This mirrors Smithy's
     * operation context precedence without allocating an intermediate map.
     *
     * @param array{
     *     list<array{Schema, string}>,
     *     list<array{string, string}>,
     *     array<string, mixed>
     * } $context
     *
     * @param array<string, mixed> $inputs
     *
     * @return void
     */
    private function collectOperationContext(
        array $context,
        SerializableStructInterface $input,
        array &$inputs
    ): void
    {
        $resolved = [];
        foreach ($context[0] as [$member, $name]) {
            $value = $input->getMemberValue($member);
            if ($member->isRequired()
                && (
                    $value === null
                    || $value === []
                    || (\is_string($value) && \trim($value) === '')
                )
            ) {
                throw new \InvalidArgumentException(
                    "Required endpoint context parameter {$name} must "
                        . 'not be empty.'
                );
            }
            if ($value !== null) {
                $inputs[$name] = $value;
                $resolved[$name] = true;
            }
        }

        if ($context[1] !== []) {
            $document = ArrayShapeSerializer::toArray($input);
            foreach ($context[1] as [$name, $path]) {
                $value = Env::search($path, $document);
                if ($value !== null && !isset($resolved[$name])) {
                    $inputs[$name] = $value;
                }
            }
        }

        foreach ($context[2] as $name => $value) {
            $inputs[$name] = $value;
        }
    }

    /**
     * Unwrap a runtime-preserved trait value.
     */
    private static function traitValue(mixed $trait): mixed
    {
        return $trait instanceof DynamicTraitValue
            ? $trait->value
            : $trait;
    }

    /**
     * Build a stable, unambiguous key from declared endpoint inputs.
     *
     * Only names present in the bytecode's input register map contribute,
     * in register-definition order. Type markers distinguish missing,
     * null, booleans, strings, integers, and serialized composite values;
     * length prefixes prevent adjacent values from colliding.
     *
     * When codegen supplies a key builder, it replaces the generic loop
     * but must preserve those logical key semantics.
     *
     * @param array<string, mixed> $inputs
     */
    private function buildCacheKey(array $inputs): string
    {
        if ($this->keyBuilder !== null) {
            return ($this->keyBuilder)($inputs);
        }

        $key = '';
        foreach ($this->inputRegisterMap as $name => $_) {
            if (!\array_key_exists($name, $inputs)) {
                $key .= self::KEY_MISSING;
                continue;
            }

            $value = $inputs[$name];
            if ($value === null) {
                $key .= self::KEY_NULL;
            } elseif ($value === false) {
                $key .= self::KEY_FALSE;
            } elseif ($value === true) {
                $key .= self::KEY_TRUE;
            } elseif (\is_string($value)) {
                $key .= self::KEY_STRING
                    . \strlen($value) . ':' . $value;
            } elseif (\is_int($value)) {
                $key .= self::KEY_INTEGER . $value . ';';
            } else {
                $encoded = \serialize($value);
                $key .= self::KEY_SERIALIZED
                    . \strlen($encoded) . ':' . $encoded;
            }
        }

        return $key;
    }
}
