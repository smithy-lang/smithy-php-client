<?php

declare(strict_types=1);

namespace Smithy\Client;

use Smithy\Http\Client\{
    HttpRequestOptions,
    RequestTrailers
};
use Smithy\Http\Client\Cancellation\CancellationInterface;

/**
 * Configure per-request behavior outside modeled operation input.
 */
final readonly class RequestOverrideConfig
{
    public const HTTP = '@http';
    public const RETRIES = '@retries';
    public const AUTH = '@auth';

    /** @var array<string, mixed> */
    private array $httpOverrides;
    /** @var array<string, string|list<string>> */
    public array $headers;

    /**
     * @param array<string, mixed> $httpOverrides Partial HTTP options.
     * @param array<string, string|list<string>> $headers Request headers.
     * @param HttpRequestOptions|null $httpOptions Complete HTTP options.
     * @param int|null $maxAttempts Maximum attempts for this request.
     * @param list<string>|null $authSchemePreference Auth scheme preference.
     */
    private function __construct(
        array $httpOverrides,
        array $headers,
        public ?HttpRequestOptions $httpOptions,
        public ?int $maxAttempts,
        public ?array $authSchemePreference
    )
    {
        $this->httpOverrides = $httpOverrides;
        $this->headers = $headers;
    }

    /**
     * Create overrides from `@`-prefixed operation input entries.
     *
     * @param array<string, mixed> $overrides
     *
     * @throws \InvalidArgumentException If an override is invalid.
     */
    public static function fromArray(array $overrides): self
    {
        foreach ($overrides as $name => $_) {
            if ($name !== self::HTTP
                && $name !== self::RETRIES
                && $name !== self::AUTH
            ) {
                throw new \InvalidArgumentException(
                    "Unknown request override '{$name}'."
                );
            }
        }

        $http = $overrides[self::HTTP] ?? [];
        $httpOptions = null;
        $httpOverrides = [];
        $headers = [];
        if ($http instanceof HttpRequestOptions) {
            $httpOptions = $http;
        } elseif (\is_array($http)) {
            $httpOverrides = $http;
            $headers = self::extractHeaders($httpOverrides);
            self::validateHttpOverrides($httpOverrides);
        } else {
            throw new \InvalidArgumentException(
                self::HTTP
                . ' must be an array or '
                . HttpRequestOptions::class
                . '.'
            );
        }

        $maxAttempts = null;
        if (isset($overrides[self::RETRIES])) {
            $retries = $overrides[self::RETRIES];
            if (!\is_int($retries) || $retries < 0) {
                throw new \InvalidArgumentException(
                    self::RETRIES
                    . ' must be a non-negative integer.'
                );
            }

            if ($retries === \PHP_INT_MAX) {
                throw new \InvalidArgumentException(
                    self::RETRIES
                    . ' is too large.'
                );
            }
            $maxAttempts = $retries + 1;
        }

        $authSchemePreference = null;
        if (isset($overrides[self::AUTH])) {
            $authSchemePreference = self::authSchemePreference(
                $overrides[self::AUTH]
            );
        }

        return new self(
            $httpOverrides,
            $headers,
            $httpOptions,
            $maxAttempts,
            $authSchemePreference
        );
    }

    /** @return list<string> */
    private static function authSchemePreference(mixed $value): array
    {
        if (!\is_array($value)
            || $value === []
            || !\array_is_list($value)
        ) {
            throw new \InvalidArgumentException(
                self::AUTH
                . ' must be a non-empty list of auth scheme names.'
            );
        }

        $preference = [];
        foreach ($value as $name) {
            if (!\is_string($name) || $name === '') {
                throw new \InvalidArgumentException(
                    self::AUTH
                    . ' values must be non-empty strings.'
                );
            }

            if (!\in_array($name, $preference, true)) {
                $preference[] = $name;
            }
        }

        return $preference;
    }

    /**
     * Apply partial HTTP overrides to client defaults.
     *
     * Return null when this config has no HTTP options.
     *
     * @throws \InvalidArgumentException If an HTTP option is invalid.
     */
    public function resolveHttpOptions(
        HttpRequestOptions $defaults
    ): ?HttpRequestOptions
    {
        if ($this->httpOptions !== null) {
            return $this->httpOptions;
        }

        if ($this->httpOverrides === []) {
            return null;
        }

        $options = $this->httpOverrides;

        return new HttpRequestOptions(
            timeout: self::floatOption(
                $options,
                'timeout',
                $defaults->timeout
            ),
            followRedirects: self::boolOption(
                $options,
                self::redirectOption($options),
                $defaults->followRedirects
            ),
            proxy: self::nullableStringOption(
                $options,
                'proxy',
                $defaults->proxy
            ),
            decodeContent: self::boolOption(
                $options,
                'decode_content',
                $defaults->decodeContent
            ),
            trailers: self::objectOption(
                $options,
                'trailers',
                RequestTrailers::class,
                $defaults->trailers
            ),
            cancellation: self::objectOption(
                $options,
                'cancellation',
                CancellationInterface::class,
                $defaults->cancellation
            ),
            responseBufferSize: self::intOption(
                $options,
                'response_buffer_size',
                $defaults->responseBufferSize
            ),
            maxBufferedResponseBytes: self::intOption(
                $options,
                'max_buffered_response_bytes',
                $defaults->maxBufferedResponseBytes
            ),
            readTimeout: self::floatOption(
                $options,
                'read_timeout',
                $defaults->readTimeout
            ),
            maxResponseBodyBytes: self::intOption(
                $options,
                'max_response_body_bytes',
                $defaults->maxResponseBodyBytes
            ),
            uploadBufferSize: self::intOption(
                $options,
                'upload_buffer_size',
                $defaults->uploadBufferSize
            ),
            bufferedDownloadBufferSize: self::intOption(
                $options,
                'buffered_download_buffer_size',
                $defaults->bufferedDownloadBufferSize
            )
        );
    }

    /**
     * Extract request headers from HTTP overrides.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, string|list<string>>
     */
    private static function extractHeaders(array &$options): array
    {
        $headers = $options['headers'] ?? [];
        unset($options['headers']);

        if (!\is_array($headers)) {
            throw new \InvalidArgumentException(
                self::HTTP . '.headers must be an associative array.'
            );
        }

        foreach ($headers as $name => $value) {
            if (!\is_string($name) || $name === '') {
                throw new \InvalidArgumentException(
                    self::HTTP . '.headers names must be non-empty strings.'
                );
            }

            $values = \is_array($value) ? $value : [$value];
            if (!\array_is_list($values)) {
                throw new \InvalidArgumentException(
                    self::HTTP
                    . ".headers['{$name}'] must be a list of strings."
                );
            }

            if ($values === []) {
                throw new \InvalidArgumentException(
                    self::HTTP
                    . ".headers['{$name}'] must not be empty."
                );
            }

            foreach ($values as $headerValue) {
                if (!\is_string($headerValue)) {
                    throw new \InvalidArgumentException(
                        self::HTTP
                        . ".headers['{$name}'] values must be strings."
                    );
                }
            }
        }

        return $headers;
    }

    /**
     * Validate partial HTTP option names.
     *
     * @param array<string, mixed> $options
     *
     * @return void
     */
    private static function validateHttpOverrides(array $options): void
    {
        $known = [
            'timeout' => true,
            'allow_redirects' => true,
            'follow_redirects' => true,
            'proxy' => true,
            'decode_content' => true,
            'trailers' => true,
            'cancellation' => true,
            'response_buffer_size' => true,
            'max_buffered_response_bytes' => true,
            'read_timeout' => true,
            'max_response_body_bytes' => true,
            'upload_buffer_size' => true,
            'buffered_download_buffer_size' => true
        ];

        foreach ($options as $name => $_) {
            if (!\is_string($name) || !isset($known[$name])) {
                throw new \InvalidArgumentException(
                    "Unknown " . self::HTTP . " option '{$name}'."
                );
            }
        }

        if (isset($options['allow_redirects'])
            && isset($options['follow_redirects'])
        ) {
            throw new \InvalidArgumentException(
                self::HTTP
                . ' cannot contain both allow_redirects and '
                . 'follow_redirects.'
            );
        }
    }

    /** @param array<string, mixed> $options */
    private static function redirectOption(array $options): string
    {
        return isset($options['allow_redirects'])
            ? 'allow_redirects'
            : 'follow_redirects';
    }

    /** @param array<string, mixed> $options */
    private static function floatOption(
        array $options,
        string $name,
        float $default
    ): float
    {
        if (!isset($options[$name])) {
            return $default;
        }

        $value = $options[$name];
        if (!\is_int($value) && !\is_float($value)) {
            throw new \InvalidArgumentException(
                self::HTTP . ".{$name} must be numeric."
            );
        }

        return (float) $value;
    }

    /** @param array<string, mixed> $options */
    private static function intOption(
        array $options,
        string $name,
        int $default
    ): int
    {
        if (!isset($options[$name])) {
            return $default;
        }

        if (!\is_int($options[$name])) {
            throw new \InvalidArgumentException(
                self::HTTP . ".{$name} must be an integer."
            );
        }

        return $options[$name];
    }

    /** @param array<string, mixed> $options */
    private static function boolOption(
        array $options,
        string $name,
        bool $default
    ): bool
    {
        if (!isset($options[$name])) {
            return $default;
        }

        if (!\is_bool($options[$name])) {
            throw new \InvalidArgumentException(
                self::HTTP . ".{$name} must be a boolean."
            );
        }

        return $options[$name];
    }

    /** @param array<string, mixed> $options */
    private static function nullableStringOption(
        array $options,
        string $name,
        ?string $default
    ): ?string
    {
        if (!isset($options[$name])) {
            return $default;
        }

        $value = $options[$name];
        if ($value !== null && !\is_string($value)) {
            throw new \InvalidArgumentException(
                self::HTTP . ".{$name} must be a string or null."
            );
        }

        return $value;
    }

    /**
     * Resolve an object-valued HTTP option.
     *
     * @template T of object
     *
     * @param array<string, mixed> $options
     * @param class-string<T> $class
     * @param T|null $default
     *
     * @return T|null
     */
    private static function objectOption(
        array $options,
        string $name,
        string $class,
        ?object $default
    ): ?object
    {
        if (!isset($options[$name])) {
            return $default;
        }

        $value = $options[$name];
        if ($value !== null && !$value instanceof $class) {
            throw new \InvalidArgumentException(
                self::HTTP . ".{$name} must be an instance of {$class}."
            );
        }

        return $value;
    }
}
