<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

use Psr\Http\Message\RequestInterface;

/**
 * Add RFC 6750 bearer authorization to HTTPS requests.
 */
final class HttpBearerAuthSigner implements SignerInterface
{
    private static ?self $instance = null;

    private function __construct() {}

    /**
     * Return the shared signer.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** @inheritDoc */
    public function sign(
        RequestInterface $request,
        IdentityInterface $identity,
        array $properties = []
    ): SignerResult
    {
        if (!$identity instanceof BearerToken) {
            throw new \InvalidArgumentException(
                'HTTP bearer auth requires a '
                . BearerToken::class
                . ' identity.'
            );
        }

        if (\strtolower($request->getUri()->getScheme()) !== 'https') {
            throw new \InvalidArgumentException(
                'HTTP bearer authentication requires an HTTPS endpoint.'
            );
        }

        return new SignerResult(
            $request->withHeader(
                'Authorization',
                'Bearer ' . $identity->getToken()
            )
        );
    }

    /** @inheritDoc */
    public function close(): void {}
}
