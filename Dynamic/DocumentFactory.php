<?php

declare(strict_types=1);

namespace Smithy\Client\Dynamic;

use Smithy\Core\Serde\{
    DynamicTraitValue,
    Schema,
    SerializableStructInterface
};
use Smithy\Core\Shape\Document\{
    AbstractDocument,
    BlobValue,
    DocumentInterface,
    IncompatibleDocumentTypeException,
    StructDocument,
    TimestampValue,
    UnionDocument
};
use Smithy\Core\Shape\ShapeType;
use Smithy\Core\Shape\Exception\InvalidShapeValueException;
use Smithy\Core\Shape\{
    BigDecimal,
    BigInteger,
    EnumInterface,
    UnionInterface
};

/**
 * Create schema-backed Smithy documents from runtime values.
 *
 * @internal
 */
final class DocumentFactory
{
    /**
     * Create a schema-backed document.
     *
     * @throws InvalidShapeValueException When the value is incompatible.
     */
    public function create(
        Schema $schema,
        mixed $value
    ): AbstractDocument
    {
        $target = $schema->resolveTarget();
        if ($value === null
            && $target->type === ShapeType::Structure->value
        ) {
            return $this->coerceStructure($schema, [], '$');
        }

        $coerced = $this->coerce($schema, $value, '$');
        $document = AbstractDocument::fromSchemaValue(
            $schema,
            $coerced
        );
        if ($document === null) {
            throw new InvalidShapeValueException(
                'Cannot create a null root document.'
            );
        }

        return $document;
    }

    /**
     * Create a schema-backed structure document.
     *
     * @throws InvalidShapeValueException When the schema is not a structure.
     */
    public function createStruct(
        Schema $schema,
        mixed $value
    ): StructDocument
    {
        $document = $this->create($schema, $value);
        if (!$document instanceof StructDocument) {
            throw new InvalidShapeValueException(
                "Schema {$schema->id} is not a structure."
            );
        }

        return $document;
    }

    /**
     * Coerce a value according to its schema.
     */
    private function coerce(
        Schema $schema,
        mixed $value,
        string $path
    ): mixed
    {
        if ($value === null) {
            return null;
        }

        try {
            return match ($schema->type) {
                ShapeType::Boolean->value =>
                    $this->coerceBoolean($value, $path),
                ShapeType::Byte->value =>
                    $this->coerceInteger($value, $path, -128, 127),
                ShapeType::Short->value =>
                    $this->coerceInteger($value, $path, -32768, 32767),
                ShapeType::Integer->value => $this->coerceInteger(
                    $value,
                    $path,
                    -2147483648,
                    2147483647
                ),
                ShapeType::Long->value =>
                    $this->coerceInteger($value, $path),
                ShapeType::Float_->value,
                ShapeType::Double->value =>
                    $this->coerceFloat($value, $path),
                ShapeType::BigInteger->value =>
                    $this->coerceBigInteger($value, $path),
                ShapeType::BigDecimal->value =>
                    $this->coerceBigDecimal($value, $path),
                ShapeType::String_->value =>
                    $this->coerceString($value, $path),
                ShapeType::Blob->value =>
                    $this->coerceBlob($value, $path),
                ShapeType::Timestamp->value =>
                    $this->coerceTimestamp($value, $path),
                ShapeType::Enum->value =>
                    $this->coerceEnum($value, $path),
                ShapeType::IntEnum->value =>
                    $this->coerceIntEnum($value, $path),
                ShapeType::Structure->value =>
                    $this->coerceStructure($schema, $value, $path),
                ShapeType::Union->value =>
                    $this->coerceUnion($schema, $value, $path),
                ShapeType::List_->value =>
                    $this->coerceList($schema, $value, $path),
                ShapeType::Map->value =>
                    $this->coerceMap($schema, $value, $path),
                ShapeType::Document->value =>
                    $this->coerceDocument($value, $path),
                default => throw $this->invalid(
                    $path,
                    ShapeType::from($schema->type)->name,
                    $value
                )
            };
        } catch (IncompatibleDocumentTypeException) {
            throw $this->invalid(
                $path,
                $this->expectedType($schema),
                $value
            );
        }
    }

    /**
     * Coerce a structure value.
     */
    private function coerceStructure(
        Schema $schema,
        mixed $value,
        string $path
    ): StructDocument
    {
        $target = $schema->resolveTarget();
        if ($value instanceof SerializableStructInterface) {
            if ($value->getSchema()->id !== $target->id) {
                throw $this->invalid($path, $target->id, $value);
            }

            $document = $value instanceof StructDocument
                ? $value
                : StructDocument::fromStruct($value);
            $value = \array_filter(
                $document->asMap(),
                static fn(?AbstractDocument $member): bool =>
                    $member !== null
            );
        } elseif ($value instanceof DocumentInterface) {
            $value = $value->asMap();
        } elseif ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (!\is_array($value)) {
            throw $this->invalid($path, 'structure', $value);
        }

        foreach ($value as $name => $_) {
            if (!\is_string($name)
                || $target->getMember($name) === null
            ) {
                throw new InvalidShapeValueException(
                    "Unknown member {$path}.{$name}."
                );
            }
        }

        $members = \array_fill(
            0,
            $target->getMemberCount(),
            null
        );
        foreach ($target->getMembersByIndex() as $index => $member) {
            $name = $member->memberName;
            $memberPath = $path . '.' . $name;
            if (\array_key_exists($name, $value)) {
                $memberValue = $value[$name];
                if ($memberValue === null
                    && $member->hasTrait(Schema::T_REQUIRED)
                ) {
                    throw new InvalidShapeValueException(
                        "Required member {$memberPath} cannot be null."
                    );
                }
                $members[$index] = $memberValue === null
                    ? null
                    : $this->coerce(
                        $member,
                        $memberValue,
                        $memberPath
                    );
                continue;
            }

            if ($member->hasDirectTraitById('smithy.api#default')) {
                $members[$index] = $this->coerce(
                    $member,
                    $this->getDirectTraitValue(
                        $member,
                        'smithy.api#default'
                    ),
                    $memberPath
                );
            } elseif ($member->hasTrait(Schema::T_REQUIRED)) {
                throw new InvalidShapeValueException(
                    "Missing required member {$memberPath}."
                );
            }
        }

        return $this->createStructDocument($schema, $members);
    }

    /**
     * Coerce a union value.
     */
    private function coerceUnion(
        Schema $schema,
        mixed $value,
        string $path
    ): UnionDocument
    {
        $target = $schema->resolveTarget();
        if ($value instanceof UnionDocument) {
            if ($value->getSchema()->id !== $target->id) {
                throw $this->invalid($path, $target->id, $value);
            }

            $value = $value->asMap();
        } elseif ($value instanceof UnionInterface) {
            if ($value->getSchema()->id !== $target->id) {
                throw $this->invalid($path, $target->id, $value);
            }

            $value = UnionDocument::fromUnion($value)->asMap();
        } elseif ($value instanceof DocumentInterface) {
            $value = $value->asMap();
        } elseif ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (!\is_array($value) || \count($value) !== 1) {
            throw $this->invalid(
                $path,
                'single-entry union',
                $value
            );
        }

        $tag = \array_key_first($value);
        $member = \is_string($tag)
            ? $target->getMember($tag)
            : null;
        if ($member === null) {
            throw new InvalidShapeValueException(
                "Unknown union variant {$path}.{$tag}."
            );
        }

        $memberPath = $path . '.' . $tag;
        $memberValue = $value[$tag];
        if ($memberValue === null) {
            if ($member->resolveTarget()->id !== 'smithy.api#Unit') {
                throw new InvalidShapeValueException(
                    "Union variant {$memberPath} cannot be null."
                );
            }

            $memberValue = $this->createStructDocument($member, []);
        } else {
            $memberValue = $this->coerce(
                $member,
                $memberValue,
                $memberPath
            );
        }

        return UnionDocument::create(
            $schema,
            $tag,
            $memberValue
        );
    }

    /**
     * Create a schema-backed structure document.
     *
     * @param array<int, mixed> $members Positional member values.
     */
    private function createStructDocument(
        Schema $schema,
        array $members
    ): StructDocument
    {
        return StructDocument::fromMembers(
            $schema,
            $members
        );
    }

    /**
     * Coerce a list value.
     *
     * @return list<mixed> Coerced list values.
     */
    private function coerceList(
        Schema $schema,
        mixed $value,
        string $path
    ): array
    {
        if ($value instanceof DocumentInterface) {
            $value = $value->asList();
        }
        if (!\is_array($value) || !\array_is_list($value)) {
            throw $this->invalid($path, 'list', $value);
        }

        $target = $schema->resolveTarget();
        $member = $target->getListMember();
        if ($member === null) {
            return $value;
        }

        $sparse = $schema->hasTrait(Schema::T_SPARSE);
        $items = [];
        foreach ($value as $index => $item) {
            $itemPath = $path . '[' . $index . ']';
            if ($item === null) {
                if (!$sparse) {
                    throw new InvalidShapeValueException(
                        "Null value is not allowed at {$itemPath}."
                    );
                }
                $items[] = null;
                continue;
            }
            $items[] = $this->coerce($member, $item, $itemPath);
        }

        return $items;
    }

    /**
     * Coerce a map value.
     *
     * @return array<string, mixed> Coerced map values.
     */
    private function coerceMap(
        Schema $schema,
        mixed $value,
        string $path
    ): array
    {
        if ($value instanceof DocumentInterface) {
            $value = $value->asMap();
        } elseif ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (!\is_array($value)) {
            throw $this->invalid($path, 'map', $value);
        }

        $target = $schema->resolveTarget();
        $valueSchema = $target->getMapValue();
        if ($valueSchema === null) {
            return $value;
        }

        $sparse = $schema->hasTrait(Schema::T_SPARSE);
        $entries = [];
        foreach ($value as $key => $entry) {
            $key = (string) $key;
            $entryPath = $path . '[' . \var_export($key, true) . ']';
            if ($entry === null) {
                if (!$sparse) {
                    throw new InvalidShapeValueException(
                        "Null value is not allowed at {$entryPath}."
                    );
                }
                $entries[$key] = null;
                continue;
            }
            $entries[$key] = $this->coerce(
                $valueSchema,
                $entry,
                $entryPath
            );
        }

        return $entries;
    }

    /**
     * Coerce an enum value.
     */
    private function coerceEnum(
        mixed $value,
        string $path
    ): string
    {
        $value = match (true) {
            $value instanceof DocumentInterface => $value->asString(),
            $value instanceof EnumInterface => $value->getValue(),
            default => $value
        };
        if (!\is_string($value)) {
            throw $this->invalid($path, 'string enum', $value);
        }

        return $value;
    }

    /**
     * Coerce an integer enum value.
     */
    private function coerceIntEnum(
        mixed $value,
        string $path
    ): int
    {
        $value = match (true) {
            $value instanceof DocumentInterface => $value->asInteger(),
            $value instanceof EnumInterface => $value->getValue(),
            default => $value
        };
        if (!\is_int($value)) {
            throw $this->invalid($path, 'integer enum', $value);
        }

        return $value;
    }

    /**
     * Coerce a boolean value.
     */
    private function coerceBoolean(mixed $value, string $path): bool
    {
        if ($value instanceof DocumentInterface) {
            return $value->asBoolean();
        }
        if (!\is_bool($value)) {
            throw $this->invalid($path, 'boolean', $value);
        }

        return $value;
    }

    /**
     * Coerce a bounded integer value.
     */
    private function coerceInteger(
        mixed $value,
        string $path,
        ?int $minimum = null,
        ?int $maximum = null
    ): int
    {
        if ($value instanceof DocumentInterface) {
            $value = $value->asLong();
        }
        if (!\is_int($value)) {
            throw $this->invalid($path, 'integer', $value);
        }
        if (($minimum !== null && $value < $minimum)
            || ($maximum !== null && $value > $maximum)
        ) {
            throw new InvalidShapeValueException(
                "Integer value at {$path} is outside the modeled range."
            );
        }

        return $value;
    }

    /**
     * Coerce a floating-point value.
     */
    private function coerceFloat(mixed $value, string $path): float
    {
        if ($value instanceof DocumentInterface) {
            return $value->asDouble();
        }
        if (!\is_int($value) && !\is_float($value)) {
            throw $this->invalid($path, 'floating-point number', $value);
        }

        return (float) $value;
    }

    /**
     * Coerce an arbitrary-precision integer.
     */
    private function coerceBigInteger(
        mixed $value,
        string $path
    ): BigInteger
    {
        if ($value instanceof DocumentInterface) {
            return $value->asBigInteger();
        }
        if ($value instanceof BigInteger) {
            return $value;
        }
        if (!\is_int($value)) {
            throw $this->invalid($path, 'BigInteger', $value);
        }

        return new BigInteger((string) $value);
    }

    /**
     * Coerce an arbitrary-precision decimal.
     */
    private function coerceBigDecimal(
        mixed $value,
        string $path
    ): BigDecimal
    {
        if ($value instanceof DocumentInterface) {
            return $value->asBigDecimal();
        }
        if ($value instanceof BigDecimal) {
            return $value;
        }
        if (!\is_int($value) && !\is_float($value)) {
            throw $this->invalid($path, 'BigDecimal', $value);
        }

        return new BigDecimal((string) $value);
    }

    /**
     * Coerce a string value.
     */
    private function coerceString(mixed $value, string $path): string
    {
        if ($value instanceof DocumentInterface) {
            return $value->asString();
        }
        if (!\is_string($value)) {
            throw $this->invalid($path, 'string', $value);
        }

        return $value;
    }

    /**
     * Coerce a binary value.
     */
    private function coerceBlob(mixed $value, string $path): string
    {
        if ($value instanceof DocumentInterface) {
            return $value->asBlob();
        }
        if (!$value instanceof BlobValue) {
            throw $this->invalid($path, 'BlobValue', $value);
        }

        return $value->bytes;
    }

    /**
     * Coerce a timestamp value to epoch seconds.
     */
    private function coerceTimestamp(mixed $value, string $path): float
    {
        if ($value instanceof DocumentInterface) {
            return $value->asTimestamp();
        }
        if ($value instanceof TimestampValue) {
            return $value->epochSeconds;
        }
        if ($value instanceof \DateTimeInterface) {
            return (float) $value->getTimestamp()
                + ((int) $value->format('u') / 1_000_000);
        }
        if (!\is_int($value) && !\is_float($value)) {
            throw $this->invalid($path, 'timestamp', $value);
        }

        return (float) $value;
    }

    /**
     * Coerce an untyped document value.
     */
    private function coerceDocument(
        mixed $value,
        string $path
    ): AbstractDocument
    {
        if ($value instanceof AbstractDocument) {
            return $value;
        }

        try {
            $document = AbstractDocument::fromValue($value);
        } catch (InvalidShapeValueException) {
            throw $this->invalid($path, 'document', $value);
        }
        if ($document === null) {
            throw $this->invalid($path, 'non-null document', $value);
        }

        return $document;
    }

    /**
     * Return the raw value of a directly applied runtime trait.
     */
    private function getDirectTraitValue(
        Schema $schema,
        string $traitId
    ): mixed
    {
        $value = $schema->getDirectTraitById($traitId);

        return $value instanceof DynamicTraitValue
            ? $value->value
            : $value;
    }

    /**
     * Return the public coercion name for a schema type.
     */
    private function expectedType(Schema $schema): string
    {
        return match ($schema->type) {
            ShapeType::Boolean->value => 'boolean',
            ShapeType::Byte->value,
            ShapeType::Short->value,
            ShapeType::Integer->value,
            ShapeType::Long->value => 'integer',
            ShapeType::Float_->value,
            ShapeType::Double->value => 'float',
            ShapeType::BigInteger->value => 'BigInteger',
            ShapeType::BigDecimal->value => 'BigDecimal',
            ShapeType::String_->value => 'string',
            ShapeType::Blob->value => 'BlobValue',
            ShapeType::Timestamp->value => 'timestamp',
            ShapeType::Enum->value => 'string enum',
            ShapeType::IntEnum->value => 'integer enum',
            ShapeType::Structure->value => 'structure',
            ShapeType::Union->value => 'single-entry union',
            ShapeType::List_->value => 'list',
            ShapeType::Map->value => 'map',
            ShapeType::Document->value => 'document-compatible value',
            default => ShapeType::from($schema->type)->name
        };
    }

    /**
     * Create a path-aware coercion error.
     */
    private function invalid(
        string $path,
        string $expected,
        mixed $value
    ): InvalidShapeValueException
    {
        return new InvalidShapeValueException(
            "Expected {$expected} at {$path}, got "
            . \get_debug_type($value) . '.'
        );
    }
}
