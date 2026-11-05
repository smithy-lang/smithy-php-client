<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Waiter;

use Smithy\Core\Exception\ApiException;
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Shape\Utils\ArrayShapeSerializer;

/**
 * Hold one polling attempt and lazily prepare its JMESPath data.
 *
 * @internal
 */
final class AttemptContext
{
    /** @var array<string, mixed>|null */
    private ?array $inputData = null;
    /** @var array<string, mixed>|null */
    private ?array $outputData = null;

    /**
     * Create a successful or modeled-error polling attempt.
     *
     * @throws \InvalidArgumentException When both or neither result is set.
     */
    public function __construct(
        public readonly SerializableStructInterface $input,
        public readonly ?SerializableStructInterface $output = null,
        public readonly (ApiException&SerializableStructInterface)|null $error = null
    )
    {
        if (($output === null) === ($error === null)) {
            throw new \InvalidArgumentException(
                'A waiter attempt requires exactly one output or error.'
            );
        }
    }

    /**
     * Return true when the polling operation completed successfully.
     */
    public function isSuccess(): bool
    {
        return $this->error === null;
    }

    /**
     * Serialize the polling input once.
     *
     * @return array<string, mixed>
     */
    public function inputData(): array
    {
        return $this->inputData ??= ArrayShapeSerializer::toArray(
            $this->input
        );
    }

    /**
     * Serialize the polling output once.
     *
     * @return array<string, mixed>|null
     */
    public function outputData(): ?array
    {
        if ($this->output === null) {
            return null;
        }

        return $this->outputData ??= ArrayShapeSerializer::toArray(
            $this->output
        );
    }

    /**
     * Build the input-output document used by JMESPath matchers.
     *
     * @return array{input: array<string, mixed>, output: array<string, mixed>}|null
     */
    public function inputOutputData(): ?array
    {
        $output = $this->outputData();
        if ($output === null) {
            return null;
        }

        return [
            'input' => $this->inputData(),
            'output' => $output
        ];
    }
}
