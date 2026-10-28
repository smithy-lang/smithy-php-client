<?php

declare(strict_types=1);

namespace Smithy\Client\Dynamic;

use Smithy\Core\Model\SmithyModel;
use Smithy\Core\Serde\{
    Schema,
    SerializableStructInterface,
    TypeRegistry
};
use Smithy\Core\Shape\Document\{
    DocumentInterface,
    StructDocument
};
use Smithy\Core\Shape\OperationInterface;

/**
 * Runtime operation metadata derived from a Smithy model.
 */
final class DynamicOperation implements OperationInterface
{
    private readonly Schema $operationSchema;
    private readonly Schema $inputSchema;
    private readonly Schema $outputSchema;
    private readonly string $name;
    private readonly string $runtimeIdentity;
    /** @var list<string> */
    private readonly array $effectiveAuthSchemes;
    private readonly ?Schema $inputStreamMember;
    private readonly ?Schema $outputStreamMember;
    private readonly DocumentFactory $documentFactory;
    /** @var list<Schema> */
    private array $errorSchemas = [];
    /** @var array<string, true> */
    private array $errorSchemaIds = [];
    /** @var array<string, Schema> */
    private array $errorsByDiscriminator = [];

    /**
     * Create runtime metadata for one modeled operation.
     *
     * @param array<string, string> $errorDiscriminatorTraits
     */
    public function __construct(
        private readonly SmithyModel $model,
        private readonly TypeRegistry $typeRegistry,
        private readonly string $serviceId,
        private readonly string $operationId,
        ?string $protocolId = null,
        private readonly array $errorDiscriminatorTraits = []
    )
    {
        $operation = $model->expectShape($operationId);
        if (($operation['type'] ?? null) !== 'operation') {
            throw new \InvalidArgumentException(
                "Shape {$operationId} is not an operation."
            );
        }
        $service = $model->expectShape($serviceId);
        if (($service['type'] ?? null) !== 'service') {
            throw new \InvalidArgumentException(
                "Shape {$serviceId} is not a service."
            );
        }
        if (!\in_array(
            $operationId,
            $model->getServiceOperationIds($serviceId),
            true
        )) {
            throw new \InvalidArgumentException(
                "Operation {$operationId} does not belong to service "
                    . "{$serviceId}."
            );
        }

        $this->operationSchema = $this->expectSchema($operationId);
        $this->inputSchema = $this->expectSchema(
            $model->getOperationInputId($operationId)
        );
        $this->outputSchema = $this->expectSchema(
            $model->getOperationOutputId($operationId)
        );
        $this->name = SmithyModel::getShapeName(
            $this->operationSchema->id
        );
        static $nextRuntimeIdentity = 0;
        $this->runtimeIdentity = self::class
            . ':' . $serviceId
            . ':' . $operationId
            . ':' . ($protocolId ?? '')
            . ':' . (++$nextRuntimeIdentity);
        $this->effectiveAuthSchemes =
            $model->getEffectiveAuthSchemeIds(
                $serviceId,
                $operationId
            );
        $this->inputStreamMember = self::findStreamingMember(
            $this->inputSchema
        );
        $this->outputStreamMember = self::findStreamingMember(
            $this->outputSchema
        );
        $this->documentFactory = new DocumentFactory();

        foreach ($model->getServiceErrorIds($serviceId) as $errorId) {
            $this->registerError($errorId);
        }
        foreach ($model->getOperationErrorIds($operationId) as $errorId) {
            $this->registerError($errorId);
        }
    }

    /**
     * Return the operation name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Return the operation shape ID.
     */
    public function getId(): string
    {
        return $this->operationSchema->id;
    }

    /**
     * Return the service shape ID.
     */
    public function getServiceId(): ?string
    {
        return $this->model->getOriginalShapeId($this->serviceId);
    }

    /**
     * Return the generic exception used for unmodeled service errors.
     */
    public function getServiceExceptionClass(): string
    {
        return \Smithy\Core\Exception\ApiException::class;
    }

    /**
     * Return the model-scoped runtime identity.
     */
    public function getRuntimeIdentity(): string
    {
        return $this->runtimeIdentity;
    }

    /**
     * Return the operation's effective authentication schemes.
     *
     * @return list<string>
     */
    public function getEffectiveAuthSchemes(): array
    {
        return $this->effectiveAuthSchemes;
    }

    /**
     * Return the operation schema.
     */
    public function getSchema(): Schema
    {
        return $this->operationSchema;
    }

    /**
     * Return the input schema.
     */
    public function getInputSchema(): Schema
    {
        return $this->inputSchema;
    }

    /**
     * Return the output schema.
     */
    public function getOutputSchema(): Schema
    {
        return $this->outputSchema;
    }

    /**
     * Return the effective modeled error schemas.
     *
     * @return list<Schema>
     */
    public function getErrorSchemas(): array
    {
        return $this->errorSchemas;
    }

    /**
     * Create a schema-backed operation input.
     *
     * @param array<int, mixed> $members Positional input values.
     */
    public function createInput(array $members): SerializableStructInterface
    {
        return $this->documentFactory->createStructFromMembers(
            $this->inputSchema,
            $members
        );
    }

    /**
     * Coerce runtime input into a schema-backed structure.
     */
    public function createInputFromValue(
        array|DocumentInterface|null $value = null
    ): StructDocument
    {
        return $this->documentFactory->createStruct(
            $this->inputSchema,
            $value
        );
    }

    /**
     * Create a schema-backed operation output.
     *
     * @param array<int, mixed> $members Positional output values.
     */
    public function createOutput(array $members): SerializableStructInterface
    {
        return $this->documentFactory->createStructFromMembers(
            $this->outputSchema,
            $members
        );
    }

    /**
     * Return the protocol request target.
     */
    public function getRequestTarget(): ?string
    {
        return null;
    }

    /**
     * Resolve a modeled error discriminator.
     */
    public function resolveErrorSchema(string $discriminator): ?Schema
    {
        return $this->errorsByDiscriminator[$discriminator] ?? null;
    }

    /**
     * Return the input streaming member.
     */
    public function getInputStreamMember(): ?Schema
    {
        return $this->inputStreamMember;
    }

    /**
     * Return the output streaming member.
     */
    public function getOutputStreamMember(): ?Schema
    {
        return $this->outputStreamMember;
    }

    /**
     * Register every supported wire discriminator for one modeled error.
     *
     * @return void
     */
    private function registerError(string $errorId): void
    {
        $schema = $this->expectSchema($errorId);
        $schemaId = $schema->id;
        $this->errorsByDiscriminator[$errorId] = $schema;
        $this->errorsByDiscriminator[
            SmithyModel::getShapeName($errorId)
        ] = $schema;

        if ($schemaId !== $errorId) {
            $this->errorsByDiscriminator[$schemaId] = $schema;
            $this->errorsByDiscriminator[
                SmithyModel::getShapeName($schemaId)
            ] = $schema;
        }

        if (!isset($this->errorSchemaIds[$schemaId])) {
            $this->errorSchemaIds[$schemaId] = true;
            $this->errorSchemas[] = $schema;
        }

        $traits = $this->model->getTraits($errorId);
        foreach ($this->errorDiscriminatorTraits
            as $traitId => $memberName
        ) {
            $trait = $traits[$traitId] ?? null;
            $discriminator = \is_array($trait)
                ? $trait[$memberName] ?? null
                : null;
            if (\is_string($discriminator)) {
                $this->errorsByDiscriminator[$discriminator] = $schema;
            }
        }
    }

    /**
     * Require a schema registered in the runtime model.
     */
    private function expectSchema(string $shapeId): Schema
    {
        return $this->typeRegistry->schemaFor($shapeId)
            ?? throw new \LogicException(
                "Unable to resolve modeled schema {$shapeId}."
            );
    }

    /**
     * Find the first streaming member in a structure schema.
     */
    private static function findStreamingMember(Schema $schema): ?Schema
    {
        foreach ($schema->getMembersByIndex() as $member) {
            if ($member->hasTrait(Schema::T_STREAMING)) {
                return $member;
            }
        }

        return null;
    }
}
