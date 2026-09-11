<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Successful identity resolution or an expected identity-specific miss.
 */
final readonly class IdentityResult
{
    private function __construct(
        public ?IdentityInterface $identity,
        public ?string $error,
        public ?string $resolver
    ) {}

    /**
     * Create a successful identity result.
     */
    public static function success(IdentityInterface $identity): self
    {
        return new self($identity, null, null);
    }

    /**
     * Create an expected identity-resolution miss.
     *
     * @param class-string $resolver
     */
    public static function notFound(
        string $resolver,
        string $error
    ): self
    {
        return new self(null, $error, $resolver);
    }

    /**
     * Return the resolved identity or throw for an expected miss.
     */
    public function unwrap(): IdentityInterface
    {
        if ($this->identity === null) {
            throw new IdentityNotFoundException(
                'Unable to resolve an identity: '
                    . $this->error
                    . ' ('
                    . $this->resolver
                    . ')'
            );
        }

        return $this->identity;
    }
}
