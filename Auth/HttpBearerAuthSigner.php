<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

use Smithy\Client\Transport\TransportRequestInterface;
use Smithy\Http\Message\HttpTransportRequest;

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
        TransportRequestInterface $request,
        IdentityInterface $identity,
        array $properties = []
    ): SignerResult
    {
        $request = HttpTransportRequest::expect($request);
        $httpRequest = $request->request;
        if (!$identity instanceof BearerToken) {
            throw new \InvalidArgumentException(
                'HTTP bearer auth requires a '
                . BearerToken::class
                . ' identity.'
            );
        }

        if (\strtolower($httpRequest->getUri()->getScheme()) !== 'https') {
            throw new \InvalidArgumentException(
                'HTTP bearer authentication requires an HTTPS endpoint.'
            );
        }

        return new SignerResult(
            $request->withRequest(
                $httpRequest->withHeader(
                    'Authorization',
                    'Bearer ' . $identity->getToken()
                )
            )
        );
    }

    /** @inheritDoc */
    public function close(): void {}
}
