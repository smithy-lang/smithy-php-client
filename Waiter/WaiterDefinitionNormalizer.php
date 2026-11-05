<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Waiter;

/**
 * Normalize modeled waiter definitions for runtime and code generation.
 *
 * @internal
 */
final class WaiterDefinitionNormalizer
{
    private const DEFAULT_MIN_DELAY = 2;
    private const DEFAULT_MAX_DELAY = 120;
    private const STATES = ['success', 'failure', 'retry'];
    private const MATCHERS = [
        'output',
        'inputOutput',
        'success',
        'errorType'
    ];
    private const COMPARATORS = [
        'stringEquals',
        'booleanEquals',
        'allStringEquals',
        'anyStringEquals'
    ];

    private function __construct() {}

    /**
     * Return the case-insensitive lookup key for a modeled waiter name.
     */
    public static function normalizeName(string $name): string
    {
        return \strtolower($name);
    }

    /**
     * Index waiter names and enforce service-wide uniqueness.
     *
     * @param array<string, array<string, array<string, mixed>>>
     *        $waitersByOperation Operation shape ID to waiter definitions.
     *
     * @return array<string, string> Normalized waiter name to operation ID.
     */
    public static function indexServiceWaiters(
        string $serviceShapeId,
        array $waitersByOperation
    ): array
    {
        $owners = [];
        foreach ($waitersByOperation as $operationShapeId => $waiters) {
            foreach (\array_keys($waiters) as $waiterName) {
                $normalizedName = self::normalizeName($waiterName);
                $owner = $owners[$normalizedName] ?? null;
                if ($owner !== null) {
                    throw new \LogicException(
                        "Service {$serviceShapeId} has duplicate waiter "
                            . "name {$waiterName} on {$owner} and "
                            . "{$operationShapeId}."
                    );
                }

                $owners[$normalizedName] = $operationShapeId;
            }
        }

        return $owners;
    }

    /**
     * Normalize the value of a `smithy.waiters#waitable` trait.
     *
     * @return array<string, array<string, mixed>>
     * @throws \LogicException If the waiter metadata is invalid.
     */
    public static function normalize(
        string $operationShapeId,
        mixed $trait,
        bool $hasOutput = true
    ): array
    {
        if (!\is_array($trait)
            || ($trait !== [] && \array_is_list($trait))
        ) {
            self::invalid(
                $operationShapeId,
                null,
                'trait value must be a waiter map'
            );
        }

        $waiters = [];
        $waiterNames = [];
        foreach ($trait as $waiterName => $definition) {
            if (!\is_string($waiterName)
                || \preg_match('/^[A-Z][A-Za-z0-9]*$/D', $waiterName) !== 1
            ) {
                self::invalid(
                    $operationShapeId,
                    null,
                    'waiter names must use UpperCamelCase alphanumerics'
                );
            }

            $normalizedName = self::normalizeName($waiterName);
            if (isset($waiterNames[$normalizedName])) {
                self::invalid(
                    $operationShapeId,
                    $waiterName,
                    'waiter name must be case-insensitively unique'
                );
            }
            $waiterNames[$normalizedName] = true;

            if (!\is_array($definition)) {
                self::invalid(
                    $operationShapeId,
                    $waiterName,
                    'definition must be an object'
                );
            }

            $waiters[$waiterName] = self::normalizeWaiter(
                $operationShapeId,
                $waiterName,
                $definition,
                $hasOutput
            );
        }

        return $waiters;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private static function normalizeWaiter(
        string $operationShapeId,
        string $waiterName,
        array $definition,
        bool $hasOutput
    ): array
    {
        $maxDelay = $definition['maxDelay'] ?? self::DEFAULT_MAX_DELAY;
        if (!\is_int($maxDelay) || $maxDelay < 1) {
            self::invalid(
                $operationShapeId,
                $waiterName,
                'maxDelay must be an integer greater than or equal to 1'
            );
        }

        $minDelay = $definition['minDelay'] ?? self::DEFAULT_MIN_DELAY;
        if (!\is_int($minDelay)
            || $minDelay < 1
            || $minDelay > $maxDelay
        ) {
            self::invalid(
                $operationShapeId,
                $waiterName,
                'minDelay must be between 1 and maxDelay'
            );
        }

        $documentation = $definition['documentation'] ?? null;
        if ($documentation !== null && !\is_string($documentation)) {
            self::invalid(
                $operationShapeId,
                $waiterName,
                'documentation must be a string'
            );
        }

        $deprecated = $definition['deprecated'] ?? false;
        if (!\is_bool($deprecated)) {
            self::invalid(
                $operationShapeId,
                $waiterName,
                'deprecated must be a boolean'
            );
        }

        $tags = self::normalizeTags(
            $operationShapeId,
            $waiterName,
            $definition['tags'] ?? []
        );

        $acceptors = $definition['acceptors'] ?? null;
        if (!\is_array($acceptors)
            || !\array_is_list($acceptors)
            || $acceptors === []
        ) {
            self::invalid(
                $operationShapeId,
                $waiterName,
                'acceptors must be a non-empty list'
            );
        }

        $normalizedAcceptors = [];
        $hasSuccess = false;
        foreach ($acceptors as $index => $acceptor) {
            $normalized = self::normalizeAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                $acceptor,
                $hasOutput
            );
            $normalizedAcceptors[] = $normalized;
            $hasSuccess = $hasSuccess
                || $normalized['state'] === 'success';
        }

        if (!$hasSuccess) {
            self::invalid(
                $operationShapeId,
                $waiterName,
                'acceptors must contain a success state'
            );
        }

        return [
            'documentation' => $documentation,
            'acceptors' => $normalizedAcceptors,
            'minDelay' => $minDelay,
            'maxDelay' => $maxDelay,
            'deprecated' => $deprecated,
            'tags' => $tags
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeAcceptor(
        string $operationShapeId,
        string $waiterName,
        int $index,
        mixed $acceptor,
        bool $hasOutput
    ): array
    {
        if (!\is_array($acceptor)) {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                'must be an object'
            );
        }

        $state = $acceptor['state'] ?? null;
        if (!\is_string($state)
            || !\in_array($state, self::STATES, true)
        ) {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                'state must be success, failure, or retry'
            );
        }

        $matcher = self::normalizeMatcher(
            $operationShapeId,
            $waiterName,
            $index,
            $acceptor['matcher'] ?? null,
            $hasOutput
        );

        $message = $acceptor['message'] ?? null;
        if ($message !== null
            && (!\is_string($message) || $message === '')
        ) {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                'message must be a non-empty string'
            );
        }

        if ($message !== null && $state !== 'failure') {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                'message is only valid for a failure state'
            );
        }

        return [
            'state' => $state,
            'matcher' => $matcher,
            'message' => $message
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeMatcher(
        string $operationShapeId,
        string $waiterName,
        int $index,
        mixed $matcher,
        bool $hasOutput
    ): array
    {
        if (!\is_array($matcher) || \count($matcher) !== 1) {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                'matcher must set exactly one member'
            );
        }

        $type = \array_key_first($matcher);
        if (!\is_string($type)
            || !\in_array($type, self::MATCHERS, true)
        ) {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                'matcher type is not recognized'
            );
        }

        $value = $matcher[$type];
        if ($type === 'success') {
            if (!\is_bool($value)) {
                self::invalidAcceptor(
                    $operationShapeId,
                    $waiterName,
                    $index,
                    'success matcher must be a boolean'
                );
            }

            return [
                'type' => $type,
                'path' => null,
                'expected' => $value,
                'comparator' => null
            ];
        }

        if ($type === 'errorType') {
            if (!\is_string($value) || $value === '') {
                self::invalidAcceptor(
                    $operationShapeId,
                    $waiterName,
                    $index,
                    'errorType matcher must be a non-empty string'
                );
            }

            return [
                'type' => $type,
                'path' => null,
                'expected' => $value,
                'comparator' => null
            ];
        }

        if (!$hasOutput) {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                'output matchers require an operation output'
            );
        }

        return self::normalizePathMatcher(
            $operationShapeId,
            $waiterName,
            $index,
            $type,
            $value
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizePathMatcher(
        string $operationShapeId,
        string $waiterName,
        int $index,
        string $type,
        mixed $value
    ): array
    {
        if (!\is_array($value)) {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                "{$type} matcher must be an object"
            );
        }

        $path = $value['path'] ?? null;
        if (!\is_string($path) || $path === '') {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                "{$type} matcher path must be a non-empty string"
            );
        }

        $expected = $value['expected'] ?? null;
        if (!\is_string($expected)) {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                "{$type} matcher expected must be a string"
            );
        }

        $comparator = $value['comparator'] ?? null;
        if (!\is_string($comparator)
            || !\in_array($comparator, self::COMPARATORS, true)
        ) {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                "{$type} matcher comparator is not recognized"
            );
        }

        if ($comparator === 'booleanEquals'
            && $expected !== 'true'
            && $expected !== 'false'
        ) {
            self::invalidAcceptor(
                $operationShapeId,
                $waiterName,
                $index,
                'booleanEquals expected must be "true" or "false"'
            );
        }

        return [
            'type' => $type,
            'path' => $path,
            'expected' => $expected,
            'comparator' => $comparator
        ];
    }

    /**
     * @return list<string>
     */
    private static function normalizeTags(
        string $operationShapeId,
        string $waiterName,
        mixed $tags
    ): array
    {
        if (!\is_array($tags) || !\array_is_list($tags)) {
            self::invalid(
                $operationShapeId,
                $waiterName,
                'tags must be a list of strings'
            );
        }

        foreach ($tags as $tag) {
            if (!\is_string($tag)) {
                self::invalid(
                    $operationShapeId,
                    $waiterName,
                    'tags must be a list of strings'
                );
            }
        }

        return $tags;
    }

    private static function invalidAcceptor(
        string $operationShapeId,
        string $waiterName,
        int $index,
        string $message
    ): never
    {
        self::invalid(
            $operationShapeId,
            $waiterName,
            "acceptor {$index} {$message}"
        );
    }

    private static function invalid(
        string $operationShapeId,
        ?string $waiterName,
        string $message
    ): never
    {
        $subject = $waiterName === null
            ? "Operation {$operationShapeId} waitable trait"
            : "Operation {$operationShapeId} waiter {$waiterName}";

        throw new \LogicException("{$subject}: {$message}.");
    }
}
