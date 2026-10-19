<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

use Smithy\Client\Transport\TransportRequestInterface;

/**
 * Sign a transport request using a resolved {@see IdentityInterface}.
 *
 * Called once per attempt from inside the retry loop, after any
 * `modifyBeforeSigning` interceptor has run. Retried attempts sign
 * fresh. Timestamps and nonces are recomputed each time.
 *
 * Signers are attempt-scoped. The pipeline closes the signer after each
 * signing attempt, including when signing fails.
 */
interface SignerInterface
{
    /**
     * @param array<string, mixed> $properties Effective signing properties
     *                                         from the auth scheme and
     *                                         endpoint.
     */
    public function sign(
        TransportRequestInterface $request,
        IdentityInterface $identity,
        array $properties = []
    ): SignerResult;

    /**
     * Release resources owned by this signer.
     *
     * Implementations without resources should use a no-op. Calling this
     * method more than once must be safe.
     *
     * @return void
     */
    public function close(): void;
}
