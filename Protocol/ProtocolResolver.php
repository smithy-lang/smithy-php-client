<?php

declare(strict_types=1);

namespace Smithy\Client\Protocol;

use Smithy\Core\Shape\ServiceInterface;
use Smithy\Protocol\{
    ProtocolInterface,
    RpcV2\RpcV2CborProtocol,
    RpcV2\RpcV2JsonProtocol
};

/**
 * Resolve modeled protocols in deterministic preference order.
 */
final readonly class ProtocolResolver implements ProtocolResolverInterface
{
    /** @var array<string, ProtocolInterface> */
    private array $protocols;

    /** @var list<string> */
    private array $preference;

    /**
     * @param array<string, ProtocolInterface> $protocols
     * @param list<string> $preference
     */
    public function __construct(
        array $protocols = [],
        array $preference = []
    )
    {
        $this->protocols = self::validateProtocols($protocols);
        $this->preference = self::validatePreference($preference);
    }

    /**
     * Return the protocols included with the generic runtime.
     */
    public static function defaults(): self
    {
        return new self([
            RpcV2CborProtocol::ID => new RpcV2CborProtocol(),
            RpcV2JsonProtocol::ID => new RpcV2JsonProtocol()
        ]);
    }

    /**
     * Merge another resolver's registrations over this resolver.
     */
    public function merge(self $resolver): self
    {
        if ($resolver === $this) {
            return $this;
        }

        return new self(
            protocols: [
                ...$this->protocols,
                ...$resolver->protocols
            ],
            preference: [
                ...$resolver->preference,
                ...$this->preference
            ]
        );
    }

    public function resolveProtocol(
        ServiceInterface $service,
        ?string $protocolId = null,
        ?ProtocolInterface $protocol = null
    ): ProtocolInterface
    {
        if ($protocol !== null) {
            $resolvedId = $protocol->getId();
            if ($protocolId !== null && $protocolId !== $resolvedId) {
                throw new \InvalidArgumentException(
                    "Protocol implementation {$resolvedId} does not match "
                    . "configured protocol {$protocolId}."
                );
            }
            $this->assertModeled($service, $resolvedId);

            return $protocol;
        }

        if ($protocolId !== null) {
            $protocol = $this->protocols[$protocolId] ?? null;
            if ($protocol === null) {
                throw new \RuntimeException(
                    'No runtime implementation is available for protocol '
                    . "{$protocolId}."
                );
            }
            $this->assertModeled($service, $protocolId);

            return $protocol;
        }

        $applicable = $this->getApplicableProtocols($service);
        if ($applicable !== []) {
            return \reset($applicable);
        }

        $traits = \array_keys($service->getSchema()->getTraitsById());
        throw new \RuntimeException(
            'No available protocol implementation matched service '
            . $service->getId()
            . '. Service traits: '
            . ($traits === [] ? '(none)' : \implode(', ', $traits))
        );
    }

    public function getProtocols(): array
    {
        return $this->protocols;
    }

    /**
     * Return configured protocol preference order.
     *
     * @return list<string>
     */
    public function getPreference(): array
    {
        return $this->preference;
    }

    public function getApplicableProtocols(ServiceInterface $service): array
    {
        $traits = $service->getSchema()->getTraitsById();
        $applicable = [];
        foreach ($this->orderedProtocolIds() as $protocolId) {
            if (\array_key_exists($protocolId, $traits)) {
                $applicable[$protocolId] = $this->protocols[$protocolId];
            }
        }

        return $applicable;
    }

    /**
     * Return preferred IDs followed by remaining registration order.
     *
     * @return list<string>
     */
    private function orderedProtocolIds(): array
    {
        $ordered = [];
        foreach ([...$this->preference, ...\array_keys($this->protocols)]
            as $protocolId
        ) {
            if (isset($this->protocols[$protocolId])
                && !\in_array($protocolId, $ordered, true)
            ) {
                $ordered[] = $protocolId;
            }
        }

        return $ordered;
    }

    private function assertModeled(
        ServiceInterface $service,
        string $protocolId
    ): void
    {
        if (!\array_key_exists(
            $protocolId,
            $service->getSchema()->getTraitsById()
        )) {
            throw new \RuntimeException(
                "Protocol {$protocolId} is not modeled by service "
                . $service->getId()
                . '.'
            );
        }
    }

    /**
     * @param array<mixed> $protocols
     *
     * @return array<string, ProtocolInterface>
     */
    private static function validateProtocols(array $protocols): array
    {
        foreach ($protocols as $protocolId => $protocol) {
            if (!\is_string($protocolId)
                || $protocolId === ''
                || !$protocol instanceof ProtocolInterface
                || $protocol->getId() !== $protocolId
            ) {
                throw new \InvalidArgumentException(
                    'Protocol mappings must contain protocol shape IDs '
                    . 'mapped to matching ProtocolInterface instances.'
                );
            }
        }

        return $protocols;
    }

    /**
     * @param array<mixed> $preference
     *
     * @return list<string>
     */
    private static function validatePreference(array $preference): array
    {
        $validated = [];
        foreach ($preference as $protocolId) {
            if (!\is_string($protocolId) || $protocolId === '') {
                throw new \InvalidArgumentException(
                    'Protocol preference entries must be protocol shape IDs.'
                );
            }
            if (!\in_array($protocolId, $validated, true)) {
                $validated[] = $protocolId;
            }
        }

        return $validated;
    }
}
