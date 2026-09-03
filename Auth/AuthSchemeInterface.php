<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

use Smithy\Client\ClientConfig;

/**
 * Named authentication scheme: identity resolver plus signer.
 *
 * An {@see AuthSchemeResolverInterface} selects the scheme. The client
 * pipeline then calls `getIdentityResolver()` and `getSigner()` on the
 * resolved scheme. The scheme is responsible for wiring the correct
 * resolver from the client config.
 *
 * Scheme IDs follow Smithy convention: `smithy.api#httpBearerAuth`,
 * `smithy.api#noAuth`, and vendor-defined scheme IDs.
 */
interface AuthSchemeInterface
{
    /**
     * Return the Smithy shape ID of this auth scheme.
     */
    public function getSchemeId(): string;

    /**
     * Return the identity resolver for this scheme.
     */
    public function getIdentityResolver(
        ClientConfig $config
    ): IdentityResolverInterface;

    /**
     * Return the signer for this scheme.
     */
    public function getSigner(): SignerInterface;
}
