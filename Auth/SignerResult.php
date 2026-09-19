<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

use Psr\Http\Message\RequestInterface;

/**
 * Result of signing an HTTP request.
 *
 * The signature is empty when the selected auth scheme does not produce a
 * reusable signature value.
 */
final readonly class SignerResult
{
    public function __construct(
        public RequestInterface $signedRequest,
        public string $signature = ''
    ) {}
}
