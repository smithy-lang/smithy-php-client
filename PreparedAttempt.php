<?php

declare(strict_types=1);

namespace Smithy\Client;

use Psr\Http\Message\RequestInterface;
use Smithy\Client\Auth\{
    IdentityInterface,
    IdentityResolverInterface,
    ResolvedAuthScheme
};

/**
 * Values resolved while preparing one transmission attempt.
 *
 * @internal
 */
final readonly class PreparedAttempt
{
    public function __construct(
        public RequestInterface $request,
        public ResolvedAuthScheme $authScheme,
        public IdentityResolverInterface $identityResolver,
        public IdentityInterface $identity
    ) {}
}
