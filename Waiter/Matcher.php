<?php

declare(strict_types=1);

namespace Smithy\Client\Waiter;

use JmesPath\Env;

/**
 * Create waiter matchers from modeled acceptor conditions.
 */
final readonly class Matcher
{
    private const SUCCESS = 0;
    private const ERROR_TYPE = 1;
    private const OUTPUT = 2;
    private const INPUT_OUTPUT = 3;

    private function __construct(
        private int $type,
        private string $expected = '',
        private bool $expectedSuccess = false,
        private string $path = '',
        private PathComparator $comparator = PathComparator::StringEquals
    ) {}

    /**
     * Match the success or failure of a polling operation.
     */
    public static function success(bool $expected): self
    {
        return new self(
            type: self::SUCCESS,
            expectedSuccess: $expected
        );
    }

    /**
     * Match a modeled error by shape ID or shape name.
     */
    public static function errorType(string $expected): self
    {
        return new self(
            type: self::ERROR_TYPE,
            expected: $expected
        );
    }

    /**
     * Match a JMESPath expression evaluated against a successful output.
     */
    public static function output(
        string $path,
        string $expected,
        PathComparator $comparator
    ): self
    {
        return new self(
            type: self::OUTPUT,
            expected: $expected,
            path: $path,
            comparator: $comparator
        );
    }

    /**
     * Match a JMESPath expression evaluated against input and output.
     */
    public static function inputOutput(
        string $path,
        string $expected,
        PathComparator $comparator
    ): self
    {
        return new self(
            type: self::INPUT_OUTPUT,
            expected: $expected,
            path: $path,
            comparator: $comparator
        );
    }

    /**
     * Return true when the polling attempt meets the matcher condition.
     */
    public function matches(AttemptContext $attempt): bool
    {
        return match ($this->type) {
            self::SUCCESS => $attempt->isSuccess()
                === $this->expectedSuccess,
            self::ERROR_TYPE => $this->matchesErrorType($attempt),
            self::OUTPUT => $this->matchesPath($attempt->outputData()),
            self::INPUT_OUTPUT => $this->matchesPath(
                $attempt->inputOutputData()
            ),
            default => false
        };
    }

    private function matchesErrorType(AttemptContext $attempt): bool
    {
        $error = $attempt->error;
        if ($error === null) {
            return false;
        }

        $shapeId = $error->getSchema()->id;
        if (\str_contains($this->expected, '#')) {
            return $shapeId === $this->expected;
        }

        $separator = \strrpos($shapeId, '#');
        $actual = $separator === false
            ? $shapeId
            : \substr($shapeId, $separator + 1);

        return $actual === $this->expected;
    }

    /** @param array<string, mixed>|null $data */
    private function matchesPath(?array $data): bool
    {
        if ($data === null) {
            return false;
        }

        return $this->comparator->compare(
            Env::search($this->path, $data),
            $this->expected
        );
    }
}
