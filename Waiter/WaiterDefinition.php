<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Waiter;

/**
 * Runtime representation of one modeled waiter.
 *
 * @internal
 */
final readonly class WaiterDefinition
{
    /**
     * @param list<Acceptor> $acceptors Ordered modeled acceptors.
     * @param int<1, max> $minDelay Modeled minimum delay in seconds.
     * @param int<1, max> $maxDelay Modeled maximum delay in seconds.
     * @param list<string> $tags Modeled waiter tags.
     */
    public function __construct(
        public string $name,
        public array $acceptors,
        public int $minDelay,
        public int $maxDelay,
        public ?string $documentation,
        public bool $deprecated,
        public array $tags,
        public bool $requiresOutput
    ) {}

    /**
     * Create a runtime definition from normalized waiter metadata.
     *
     * @param array<string, mixed> $definition
     */
    public static function fromArray(
        string $name,
        array $definition
    ): self
    {
        $acceptors = [];
        $requiresOutput = false;
        foreach ($definition['acceptors'] as $acceptor) {
            $matcher = $acceptor['matcher'];
            $type = $matcher['type'];
            $requiresOutput = $requiresOutput
                || $type === 'output'
                || $type === 'inputOutput';
            $acceptors[] = new Acceptor(
                self::state($acceptor['state']),
                self::matcher($matcher),
                $acceptor['message']
            );
        }

        return new self(
            name: $name,
            acceptors: $acceptors,
            minDelay: $definition['minDelay'],
            maxDelay: $definition['maxDelay'],
            documentation: $definition['documentation'],
            deprecated: $definition['deprecated'],
            tags: $definition['tags'],
            requiresOutput: $requiresOutput
        );
    }

    private static function state(string $state): int
    {
        return match ($state) {
            'success' => Acceptor::SUCCESS,
            'failure' => Acceptor::FAILURE,
            'retry' => Acceptor::RETRY,
            default => throw new \LogicException(
                "Unknown normalized waiter state {$state}."
            )
        };
    }

    /** @param array<string, mixed> $matcher */
    private static function matcher(array $matcher): Matcher
    {
        return match ($matcher['type']) {
            'success' => Matcher::success($matcher['expected']),
            'errorType' => Matcher::errorType($matcher['expected']),
            'output' => Matcher::output(
                $matcher['path'],
                $matcher['expected'],
                self::comparator($matcher['comparator'])
            ),
            'inputOutput' => Matcher::inputOutput(
                $matcher['path'],
                $matcher['expected'],
                self::comparator($matcher['comparator'])
            ),
            default => throw new \LogicException(
                "Unknown normalized waiter matcher {$matcher['type']}."
            )
        };
    }

    private static function comparator(string $comparator): PathComparator
    {
        return match ($comparator) {
            'stringEquals' => PathComparator::StringEquals,
            'booleanEquals' => PathComparator::BooleanEquals,
            'allStringEquals' => PathComparator::AllStringEquals,
            'anyStringEquals' => PathComparator::AnyStringEquals,
            default => throw new \LogicException(
                "Unknown normalized waiter comparator {$comparator}."
            )
        };
    }
}
