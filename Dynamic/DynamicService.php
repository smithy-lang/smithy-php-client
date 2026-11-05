<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Dynamic;

use Smithy\Client\Waiter\WaiterDefinitionNormalizer;
use Smithy\Core\Model\SmithyModel;
use Smithy\Core\Serde\{
    Schema,
    SchemaConverter,
    TypeRegistry
};
use Smithy\Core\Shape\{OperationInterface, ServiceInterface};

/**
 * Runtime service metadata derived from a Smithy model.
 */
final class DynamicService implements ServiceInterface
{
    private readonly string $serviceId;
    private readonly Schema $schema;
    private readonly string $name;
    private readonly string $version;
    private readonly TypeRegistry $typeRegistry;
    /** @var array<string, string> */
    private array $operationIdsByName = [];
    /** @var array<string, DynamicOperation> */
    private array $operationsByName = [];
    /** @var array<string, string>|null */
    private ?array $waiterOperationNamesByName = null;

    /**
     * Create runtime metadata for a modeled service.
     *
     * @param array<string, string> $errorDiscriminatorTraits
     */
    public function __construct(
        private readonly SmithyModel $model,
        SchemaConverter $converter,
        ?string $serviceId = null,
        private readonly ?string $protocolId = null,
        private readonly array $errorDiscriminatorTraits = []
    )
    {
        $this->serviceId = $this->resolveServiceId($serviceId);
        $service = $model->expectShape($this->serviceId);
        $this->typeRegistry = new TypeRegistry($model, $converter);
        $this->schema = $this->typeRegistry->schemaFor($this->serviceId)
            ?? throw new \LogicException(
                "Unable to resolve service schema {$this->serviceId}."
            );
        $this->name = SmithyModel::getShapeName($this->schema->id);
        $version = $service['version'] ?? '';
        $this->version = \is_string($version) ? $version : '';

        $operationIds = $model->getServiceOperationIds($this->serviceId);
        foreach ($operationIds as $operationId) {
            $name = SmithyModel::getShapeName(
                $model->getOriginalShapeId($operationId)
            );
            if (isset($this->operationIdsByName[$name])) {
                throw new \InvalidArgumentException(
                    "Service {$this->serviceId} contains duplicate "
                    . "operation name {$name}."
                );
            }

            $this->operationIdsByName[$name] = $operationId;
        }
    }

    /**
     * Return the service name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Return the service shape ID.
     */
    public function getId(): string
    {
        return $this->schema->id;
    }

    /**
     * Return the service version.
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Return the service schema.
     */
    public function getSchema(): Schema
    {
        return $this->schema;
    }

    /**
     * Return every operation in modeled service order.
     *
     * @return list<OperationInterface> Service operations.
     */
    public function getOperations(): array
    {
        $operations = [];
        foreach (\array_keys($this->operationIdsByName) as $name) {
            $operations[] = $this->getOperation($name);
        }

        return $operations;
    }

    /**
     * Resolve and cache an operation by modeled name.
     */
    public function getOperation(string $name): ?OperationInterface
    {
        if (isset($this->operationsByName[$name])) {
            return $this->operationsByName[$name];
        }

        $operationId = $this->operationIdsByName[$name] ?? null;
        if ($operationId === null) {
            return null;
        }

        return $this->operationsByName[$name] = new DynamicOperation(
            $this->model,
            $this->typeRegistry,
            $this->serviceId,
            $operationId,
            $this->protocolId,
            $this->errorDiscriminatorTraits
        );
    }

    /**
     * Resolve the operation associated with a modeled waiter name.
     */
    public function getWaiterOperation(string $name): ?DynamicOperation
    {
        $this->indexWaiters();

        $operationName = $this->waiterOperationNamesByName[
            WaiterDefinitionNormalizer::normalizeName($name)
        ] ?? null;

        return $operationName === null
            ? null
            : $this->getOperation($operationName);
    }

    /**
     * Build the service-wide waiter-name index on first use.
     */
    private function indexWaiters(): void
    {
        if ($this->waiterOperationNamesByName !== null) {
            return;
        }

        $waitersByOperation = [];
        $operationNamesById = [];
        foreach ($this->operationIdsByName
            as $operationName => $operationId
        ) {
            $operation = $this->model->expectShape($operationId);
            $traits = $operation['traits'] ?? [];
            if (!\is_array($traits)
                || !isset($traits['smithy.waiters#waitable'])
            ) {
                continue;
            }

            $waiters = WaiterDefinitionNormalizer::normalize(
                $this->model->getOriginalShapeId($operationId),
                $traits['smithy.waiters#waitable'],
                $this->model->getOperationOutputId($operationId)
                    !== 'smithy.api#Unit'
            );
            if ($waiters !== []) {
                $waitersByOperation[$operationId] = $waiters;
                $operationNamesById[$operationId] = $operationName;
            }
        }

        $waiterOperationNames = [];
        foreach (WaiterDefinitionNormalizer::indexServiceWaiters(
            $this->serviceId,
            $waitersByOperation
        ) as $normalizedName => $operationId) {
            $waiterOperationNames[$normalizedName] =
                $operationNamesById[$operationId];
        }

        $this->waiterOperationNamesByName = $waiterOperationNames;
    }

    /**
     * Resolve an explicit or unique modeled service.
     */
    private function resolveServiceId(?string $serviceId): string
    {
        if ($serviceId !== null) {
            $service = $this->model->expectShape($serviceId);
            if (($service['type'] ?? null) !== 'service') {
                throw new \InvalidArgumentException(
                    "Shape {$serviceId} is not a service."
                );
            }

            return $serviceId;
        }

        $serviceIds = $this->model->getServiceIds();
        if ($serviceIds === []) {
            throw new \InvalidArgumentException(
                'Smithy model contains no service shapes.'
            );
        }
        if (\count($serviceIds) > 1) {
            throw new \InvalidArgumentException(
                'Smithy model contains multiple service shapes: '
                . \implode(', ', $serviceIds)
            );
        }

        return $serviceIds[0];
    }
}
