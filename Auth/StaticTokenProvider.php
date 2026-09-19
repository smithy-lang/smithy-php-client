<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Resolve one configured bearer token.
 */
final readonly class StaticTokenProvider implements IdentityResolverInterface
{
    private BearerToken $token;

    /**
     * Create a static token provider.
     */
    public function __construct(
        BearerToken|string $token,
        ?float $expirationTime = null
    )
    {
        $this->token = \is_string($token)
            ? new BearerToken($token, $expirationTime)
            : $token;
    }

    /** @inheritDoc */
    public function resolveIdentity(
        array $properties = []
    ): IdentityResult
    {
        return IdentityResult::success($this->token);
    }

    /** @inheritDoc */
    public function invalidate(IdentityInterface $rejectedIdentity): void {}
}
