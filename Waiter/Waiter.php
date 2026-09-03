<?php

declare(strict_types=1);

namespace Smithy\Client\Waiter;

use JmesPath\Env;
use Smithy\Async\Async;
use Smithy\Core\Exception\ApiException;
use Smithy\Core\Serde\SerializableStructInterface;

/**
 * Poll an operation until an ordered acceptor reaches a terminal state.
 */
final readonly class Waiter
{
    /** @var \Closure(SerializableStructInterface): SerializableStructInterface */
    private \Closure $poll;
    /** @var list<Acceptor> */
    private array $acceptors;
    private Backoff $backoff;

    /**
     * Create a waiter for an operation.
     *
     * @param \Closure(SerializableStructInterface): SerializableStructInterface $poll
     * @param list<Acceptor> $acceptors Ordered acceptors.
     * @param int<1, max> $minDelay Minimum retry delay in seconds.
     * @param int<1, max> $maxDelay Maximum retry delay in seconds.
     *
     * @throws \InvalidArgumentException When no acceptors are provided or
     *                                   delay bounds are invalid.
     */
    public function __construct(
        \Closure $poll,
        array $acceptors,
        int $minDelay = 2,
        int $maxDelay = 120
    )
    {
        if ($acceptors === []) {
            throw new \InvalidArgumentException(
                'A waiter requires at least one acceptor.'
            );
        }

        foreach ($acceptors as $acceptor) {
            if (!$acceptor instanceof Acceptor) {
                throw new \InvalidArgumentException(
                    'Every waiter acceptor must be an Acceptor instance.'
                );
            }
        }

        $this->poll = $poll;
        $this->acceptors = \array_values($acceptors);
        $this->backoff = new Backoff($minDelay, $maxDelay);
    }

    /**
     * Wait for an operation to reach a modeled success state.
     *
     * @param float $maxWaitTime Maximum total wait time in seconds.
     *
     * @return WaiterResult Terminal output or accepted error.
     * @throws WaiterFailureException When the waiter times out, reaches a
     *                                failure state, or encounters an
     *                                unmatched error.
     * @throws \InvalidArgumentException When `$maxWaitTime` is invalid.
     */
    public function wait(
        SerializableStructInterface $input,
        float $maxWaitTime
    ): WaiterResult
    {
        if ($maxWaitTime <= 0.0 || !\is_finite($maxWaitTime)) {
            throw new \InvalidArgumentException(
                'Maximum waiter time must be finite and greater than zero.'
            );
        }

        $startTime = self::monotonicTime();
        $attempts = 0;

        while (true) {
            $attempts++;
            try {
                $attempt = $this->poll($input);
            } catch (\Throwable $error) {
                $elapsedTime = self::monotonicTime() - $startTime;

                throw new WaiterFailureException(
                    'Waiter encountered an unexpected polling error.',
                    $attempts,
                    $elapsedTime,
                    $error
                );
            }

            $elapsedTime = self::monotonicTime() - $startTime;

            if ($elapsedTime >= $maxWaitTime) {
                throw new WaiterFailureException(
                    "Waiter timed out after {$attempts} attempts.",
                    $attempts,
                    $elapsedTime,
                    $attempt->error
                );
            }

            $acceptor = $this->resolveAcceptor(
                $attempt,
                $attempts,
                $elapsedTime
            );
            if ($acceptor === null && $attempt->error !== null) {
                throw new WaiterFailureException(
                    'Waiter encountered an unmatched polling error.',
                    $attempts,
                    $elapsedTime,
                    $attempt->error
                );
            }

            if ($acceptor?->state === Acceptor::SUCCESS) {
                if ($attempt->output !== null) {
                    return WaiterResult::fromOutput($attempt->output);
                }

                return WaiterResult::fromError($attempt->error);
            }

            if ($acceptor?->state === Acceptor::FAILURE) {
                $message = 'Waiter reached a terminal failure state.';
                $reason = $this->resolveFailureMessage(
                    $acceptor,
                    $attempt,
                    $attempts,
                    $elapsedTime
                );
                if ($reason !== null && $reason !== '') {
                    $message .= " {$reason}";
                }

                throw new WaiterFailureException(
                    $message,
                    $attempts,
                    $elapsedTime,
                    $attempt->error
                );
            }

            $remainingTime = $maxWaitTime - $elapsedTime;
            $delay = $this->backoff->computeDelay(
                $attempts,
                $remainingTime
            );

            Async::delay($delay);
        }
    }

    private function poll(
        SerializableStructInterface $input
    ): AttemptContext
    {
        try {
            $output = ($this->poll)($input);

            return new AttemptContext(input: $input, output: $output);
        } catch (\Throwable $error) {
            if ($error instanceof ApiException
                && $error instanceof SerializableStructInterface
            ) {
                return new AttemptContext(input: $input, error: $error);
            }

            throw $error;
        }
    }

    private function resolveAcceptor(
        AttemptContext $attempt,
        int $attempts,
        float $elapsedTime
    ): ?Acceptor
    {
        try {
            foreach ($this->acceptors as $acceptor) {
                if ($acceptor->matcher->matches($attempt)) {
                    return $acceptor;
                }
            }
        } catch (\Throwable $error) {
            throw new WaiterFailureException(
                'Waiter could not evaluate an acceptor.',
                $attempts,
                $elapsedTime,
                $error
            );
        }

        return null;
    }

    private function resolveFailureMessage(
        Acceptor $acceptor,
        AttemptContext $attempt,
        int $attempts,
        float $elapsedTime
    ): ?string
    {
        if ($acceptor->message === null) {
            return null;
        }

        $output = $attempt->outputData();
        if ($output === null) {
            return null;
        }

        try {
            $message = Env::search($acceptor->message, $output);
        } catch (\Throwable $error) {
            throw new WaiterFailureException(
                'Waiter could not evaluate a failure message.',
                $attempts,
                $elapsedTime,
                $error
            );
        }

        if (\is_string($message)) {
            return $message;
        }

        if (\is_array($message) && \array_is_list($message)) {
            foreach ($message as $value) {
                if (!\is_string($value)) {
                    throw new WaiterFailureException(
                        'Waiter failure message did not resolve to strings.',
                        $attempts,
                        $elapsedTime
                    );
                }
            }

            return \implode('; ', $message);
        }

        throw new WaiterFailureException(
            'Waiter failure message did not resolve to strings.',
            $attempts,
            $elapsedTime
        );
    }

    private static function monotonicTime(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }
}
