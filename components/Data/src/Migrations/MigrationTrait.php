<?php declare (strict_types = 1);

namespace Limoncello\Data\Migrations;

/**
 * Copyright 2015-2019 info@neomerx.com
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Limoncello\Contracts\Data\MigrationInterface;
use Limoncello\Contracts\Data\ModelSchemaInfoInterface;
use Limoncello\Contracts\Data\RelationshipTypes;
use Limoncello\Contracts\Data\TimestampFields;
use Limoncello\Data\Contracts\MigrationContextInterface;
use Limoncello\Flute\Types\UuidType;
use Psr\Container\ContainerInterface;
use function array_key_exists;
use function assert;
use function call_user_func;
use function implode;
use function is_string;

/**
 * @package Limoncello\Data
 */
trait MigrationTrait
{
    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var array
     */
    private $enumerations = [];

    /**
     * @inheritdoc
     */
    public function init(ContainerInterface $container): MigrationInterface
    {
        $this->container = $container;

        /** @var MigrationInterface $self */
        $self = $this;

        return $self;
    }

    /**
     * @return ContainerInterface
     */
    protected function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * @return Connection
     */
    protected function getConnection(): Connection
    {
        assert($this->getContainer()->has(Connection::class) === true);

        return $this->getContainer()->get(Connection::class);
    }

    /**
     * @return ModelSchemaInfoInterface
     */
    protected function getModelSchemas(): ModelSchemaInfoInterface
    {
        assert($this->getContainer()->has(ModelSchemaInfoInterface::class) === true);

        return $this->getContainer()->get(ModelSchemaInfoInterface::class);
    }

    /**
     * @return AbstractSchemaManager
     */
    protected function getSchemaManager(): AbstractSchemaManager
    {
        return $this->getConnection()->getSchemaManager();
    }

    /**
     * @param string    $modelClass
     * @param Closure[] $expressions
     *
     * @return Table
     *
     * @throws DBALException
     */
    protected function createTable(string $modelClass, array $expressions = []): Table
    {
        $context = new MigrationContext($modelClass, $this->getModelSchemas());
        assert(
            $this->getModelSchemas()->hasClass($modelClass),
            "Class `$modelClass` is not found in model Schemas."
        );
        $tableName = $this->getModelSchemas()->getTable($modelClass);
        $table     = new Table($tableName);
        foreach ($expressions as $expression) {
            /** @var Closure $expression */
            $expression($table, $context);
        }

        $this->getSchemaManager()->dropAndCreateTable($table);

        return $table;
    }

    /**
     * @param string $modelClass
     *
     * @return void
     */
    protected function dropTableIfExists(string $modelClass): void
    {
        assert(
            $this->getModelSchemas()->hasClass($modelClass),
            "Class `$modelClass` is not found in model Schemas."
        );
        $tableName     = $this->getModelSchemas()->getTable($modelClass);
        $schemaManager = $this->getSchemaManager();

        if ($schemaManager->tablesExist([$tableName]) === true) {
            $schemaManager->dropTable($tableName);
        }
    }

    /**
     * @param string $name
     * @param array  $values
     *
     * @return void
     *
     * @throws DBALException
     */
    protected function createEnum(string $name, array $values): void
    {
        assert(empty($name) === false);

        // check all values are strings
        assert(
            call_user_func(function () use ($values): bool {
                $allAreStrings = true;
                foreach ($values as $value) {
                    $allAreStrings = $allAreStrings && is_string($value);
                }

                return $allAreStrings;
            }) === true,
            'All enum values should be strings.'
        );

        assert(array_key_exists($name, $this->enumerations) === false, "Enum name `$name` has already been used.");
        $this->enumerations[$name] = $values;

        $connection = $this->getConnection();
        if ($connection->getDriver()->getName() === 'pdo_pgsql') {
            $valueList = implode("', '", $values);
            $sql       = "CREATE TYPE $name AS ENUM ('$valueList');";
            $connection->exec($sql);
        }
    }

    /**
     * @param string $name
     *
     * @return void
     *
     * @throws DBALException
     */
    protected function dropEnumIfExists(string $name): void
    {
        unset($this->enumerations[$name]);

        $connection = $this->getConnection();
        if ($connection->getDriver()->getName() === 'pdo_pgsql') {
            $name = $connection->quoteIdentifier($name);
            $sql  = "DROP TYPE IF EXISTS $name;";
            $connection->exec($sql);
        }
    }

    /**
     * @param string $columnName
     * @param string $enumName
     * @param bool   $notNullable
     *
     * @return Closure
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     * @SuppressWarnings(PHPMD.ElseExpression)
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    protected function useEnum(string $columnName, string $enumName, bool $notNullable = true): Closure
    {
        if ($this->getConnection()->getDriver()->getName() === 'pdo_pgsql') {
            return function (Table $table) use ($columnName, $enumName): void {
                $typeName = RawNameType::TYPE_NAME;
                Type::hasType($typeName) === true ?: Type::addType($typeName, RawNameType::class);
                $table
                    ->addColumn($columnName, $typeName)
                    ->setCustomSchemaOption($typeName, $enumName);
            };
        } else {
            $enumValues = $this->enumerations[$enumName];

            return function (Table $table) use ($columnName, $enumValues, $notNullable) {
                Type::hasType(EnumType::TYPE_NAME) === true ?: Type::addType(EnumType::TYPE_NAME, EnumType::class);
                $table
                    ->addColumn($columnName, EnumType::TYPE_NAME)
                    ->setCustomSchemaOption(EnumType::TYPE_NAME, $enumValues)
                    ->setNotnull($notNullable);
            };
        }
    }

    /**
     * @param string $name
     *
     * @return Closure
     */
    protected function primaryInt(string $name): Closure
    {
        return function (Table $table) use ($name) {
            $table->addColumn($name, Types::INTEGER)->setAutoincrement(true)->setUnsigned(true)->setNotnull(true);
            $table->setPrimaryKey([$name]);
        };
    }

    /**
     * @param string $name
     *
     * @return Closure
     */
    protected function primaryString(string $name): Closure
    {
        return function (Table $table, MigrationContextInterface $context) use ($name) {
            $length = $context->getModelSchemas()->getAttributeLength($context->getModelClass(), $name);
            $table->addColumn($name, Types::STRING)->setLength($length)->setNotnull(true);
            $table->setPrimaryKey([$name]);
        };
    }

    /**
     * @param string   $name
     * @param null|int $default
     *
     * @return Closure
     */
    protected function int(string $name, int $default = null): Closure
    {
        return function (Table $table) use ($name, $default) {
            $column = $table->addColumn($name, Types::INTEGER)->setUnsigned(false)->setNotnull(true);
            $default === null ?: $column->setDefault($default);
        };
    }

    /**
     * @param string   $name
     * @param null|int $default
     *
     * @return Closure
     */
    protected function nullableInt(string $name, int $default = null): Closure
    {
        return function (Table $table) use ($name, $default) {
            $table->addColumn($name, Type::INTEGER)->setUnsigned(false)->setNotnull(false)->setDefault($default);
        };
    }

    /**
     * @param string   $name
     * @param null|int $default
     *
     * @return Closure
     */
    protected function unsignedInt(string $name, int $default = null): Closure
    {
        return $this->unsignedIntImpl($name, true, $default);
    }

    /**
     * @param string   $name
     * @param null|int $default
     *
     * @return Closure
     */
    protected function nullableUnsignedInt(string $name, int $default = null): Closure
    {
        return $this->unsignedIntImpl($name, false, $default);
    }

    /**
     * @param string $name
     *
     * @return Closure
     */
    protected function float(string $name): Closure
    {
        // precision and scale both seems to be ignored in Doctrine so not much sense to have them as inputs

        return function (Table $table) use ($name) {
            $table->addColumn($name, Type::FLOAT)->setNotnull(true);
        };
    }

    /**
     * @param string $name
     * @param int|null $default
     * @return Closure
     */
    protected function nullableFloat(string $name, int $default = null): Closure
    {
        return function (Table $table) use ($name, $default) {
            $table->addColumn($name, Types::FLOAT)->setUnsigned(false)->setNotnull(false)->setDefault($default);
        };
    }

    /**
     * @param string $name
     * @param bool $notNullable
     * @param null $default
     * @return Closure
     */
    private function unsignedFloatImpl(string $name, bool $notNullable, $default = null): Closure
    {
        return function (Table $table) use ($name, $notNullable, $default) {
            $column = $table->addColumn($name, Types::FLOAT)->setUnsigned(true)->setNotnull($notNullable);
            $default === null ?: $column->setDefault($default);
        };
    }

    /**
     * @param string $name
     * @param int|null $default
     * @return Closure
     */
    protected function unsignedFloat(string $name, int $default = null): Closure
    {
        return $this->unsignedFloatImpl($name, true, $default);
    }

    /**
     * @param string $name
     * @param int|null $default
     * @return Closure
     */
    protected function nullableUnsignedFloat(string $name, int $default = null): Closure
    {
        return $this->unsignedFloatImpl($name, false, $default);
    }

    /**
     * @param string $name
     *
     * @return Closure
     */
    protected function string(string $name, string $default = null): Closure
    {
        return function (Table $table, MigrationContextInterface $context) use ($name, $default) {
            $length = $context->getModelSchemas()->getAttributeLength($context->getModelClass(), $name);
            $column = $table->addColumn($name, Types::STRING)->setLength($length)->setNotnull(true);
            $default === null ?: $column->setDefault($default);
        };
    }

    /**
     * @param string $name
     *
     * @return Closure
     */
    protected function nullableString(string $name): Closure
    {
        return function (Table $table, MigrationContextInterface $context) use ($name) {
            $length = $context->getModelSchemas()->getAttributeLength($context->getModelClass(), $name);
            $table->addColumn($name, Types::STRING)->setLength($length)->setNotnull(false);
        };
    }

    /**
     * @param string $name
     *
     * @return Closure
     */
    protected function text(string $name): Closure
    {
        return function (Table $table) use ($name) {
            $table->addColumn($name, Types::TEXT)->setNotnull(true);
        };
    }

    /**
     * @param string $name
     *
     * @return Closure
     */
    protected function nullableText(string $name): Closure
    {
        return function (Table $table) use ($name) {
            $table->addColumn($name, Types::TEXT)->setNotnull(false);
        };
    }

    /**
     * @param string    $name
     * @param null|bool $default
     *
     * @return Closure
     */
    protected function bool(string $name, bool $default = null): Closure
    {
        return function (Table $table) use ($name, $default) {
            $column = $table->addColumn($name, Types::BOOLEAN)->setNotnull(true);
            if ($default !== null) {
                $column->setDefault($default);
            }
        };
    }

    /**
     * @param string $name
     *
     * @return Closure
     */
    protected function binary(string $name): Closure
    {
        return function (Table $table) use ($name) {
            $table->addColumn($name, Types::BINARY)->setNotnull(true);
        };
    }

    /**
     * @param string $name
     * @return Closure
     */
    protected function json(string $name): Closure
    {
        return function (Table $table) use ($name) {
            $table->addColumn($name, Types::JSON)->setNotnull(true);
        };
    }

    /**
     * @param string $name
     * @return Closure
     */
    protected function nullableJson(string $name): Closure
    {
        return function (Table $table) use ($name) {
            $table->addColumn($name, Types::JSON)->setNotnull(false);
        };
    }

    /**
     * @param string $name
     * @return Closure
     */
    protected function primaryUuid(string $name): Closure
    {
        return function (Table $table) use ($name) {
            $table->addColumn($name, UuidType::NAME)->setNotnull(true);
            $table->setPrimaryKey([$name]);
        };
    }

    /**
     * @param string $name
     * @return Closure
     */
    protected function uuid(string $name): Closure
    {
        return function (Table $table) use ($name) {
            $table->addColumn($name, UuidType::NAME)->setNotnull(true);
        };
    }

    /**
     * @param string $name
     * @return Closure
     */
    protected function nullableUuid(string $name): Closure
    {
        return function (Table $table) use ($name) {
            $table->addColumn($name, UuidType::NAME)->setNotnull(false);
        };
    }

    /**
     * @param string $name
     * @param array  $values
     *
     * @return Closure
     *
     * @throws DBALException
     */
    protected function enum(string $name, array $values): Closure
    {
        $this->createEnum($name, $values);

        return $this->useEnum($name, $name, true);
    }

    /**
     * @param string $name
     * @param array  $values
     *
     * @return Closure
     *
     * @throws DBALException
     */
    protected function nullableEnum(string $name, array $values): Closure
    {
        $this->createEnum($name, $values);

        return $this->useEnum($name, $name, false);
    }

    /**
     * @return Closure
     */
    protected function timestamps(): Closure
    {
        return function (Table $table, MigrationContextInterface $context) {
            $modelClass = $context->getModelClass();

            $createdAt = TimestampFields::FIELD_CREATED_AT;
            $updatedAt = TimestampFields::FIELD_UPDATED_AT;
            $deletedAt = TimestampFields::FIELD_DELETED_AT;

            // a list of data columns and `nullable` flag
            $datesToAdd = [];
            if ($this->getModelSchemas()->hasAttributeType($modelClass, $createdAt) === true) {
                $datesToAdd[$createdAt] = true;
            }
            if ($this->getModelSchemas()->hasAttributeType($modelClass, $updatedAt) === true) {
                $datesToAdd[$updatedAt] = false;
            }
            if ($this->getModelSchemas()->hasAttributeType($modelClass, $deletedAt) === true) {
                $datesToAdd[$deletedAt] = false;
            }

            foreach ($datesToAdd as $column => $isNullable) {
                $table->addColumn($column, Types::DATETIME_MUTABLE)->setNotnull($isNullable);
            }
        };
    }

    /**
     * @param string $name
     *
     * @return Closure
     */
    protected function datetime(string $name): Closure
    {
        return function (Table $table) use ($name) {
            $table->addColumn($name, Types::DATETIME_MUTABLE)->setNotnull(true);
        };
    }

    /**
     * @param string $name
     *
     * @return Closure
     */
    protected function nullableDatetime(string $name): Closure
    {
        return function (Table $table) use ($name) {
            $table->addColumn($name, Types::DATETIME_MUTABLE)->setNotnull(false);
        };
    }

    /**
     * @param string $name
     *
     * @return Closure
     */
    protected function date(string $name): Closure
    {
        return function (Table $table) use ($name) {
            $table->addColumn($name, Types::DATE_MUTABLE)->setNotnull(true);
        };
    }

    /**
     * @param string $name
     *
     * @return Closure
     */
    protected function nullableDate(string $name): Closure
    {
        return function (Table $table) use ($name) {
            $table->addColumn($name, Types::DATE_MUTABLE)->setNotnull(false);
        };
    }

    /**
     * @param string $name
     * @return Closure
     */
    protected function time(string $name): Closure
    {
        return function (Table $table) use ($name) {
            $table->addColumn($name, Types::TIME_MUTABLE)->setNotnull(true);
        };
    }

    /**
     * @param string $name
     * @return Closure
     */
    protected function nullableTime(string $name): Closure
    {
        return function (Table $table) use ($name) {
            $table->addColumn($name, Types::TIME_MUTABLE)->setNotnull(false);
        };
    }

    /**
     * @param string[] $names
     *
     * @return Closure
     */
    protected function unique(array $names): Closure
    {
        return function (Table $table) use ($names) {
            $table->addUniqueIndex($names);
        };
    }

    /**
     * @param string[] $names
     *
     * @return Closure
     */
    protected function searchable(array $names): Closure
    {
        return function (Table $table) use ($names) {
            $table->addIndex($names, null, ['fulltext']);
        };
    }

    /**
     * @param string $column
     * @param string $referredClass
     * @param string $onDeleteRestriction
     *
     * @return Closure
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    protected function foreignRelationship(
        string $column,
        string $referredClass,
        string $onDeleteRestriction = RelationshipRestrictions::RESTRICT
    ): Closure {
        return function (
            Table $table,
            MigrationContextInterface $context
        ) use (
            $column,
            $referredClass,
            $onDeleteRestriction
        ) {
            $tableName    = $this->getTableNameForClass($referredClass);
            $pkName       = $this->getModelSchemas()->getPrimaryKey($referredClass);
            $columnType   = $this->getModelSchemas()->getAttributeType($context->getModelClass(), $column);
            $columnLength = $columnType === Types::STRING ?
                $this->getModelSchemas()->getAttributeLength($context->getModelClass(), $column) : null;

            $closure = $this->foreignColumn(
                $column,
                $tableName,
                $pkName,
                $columnType,
                $columnLength,
                $onDeleteRestriction
            );

            return $closure($table, $context);
        };
    }

    /**
     * @param string $column
     * @param string $referredClass
     * @param string $onDeleteRestriction
     *
     * @return Closure
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    protected function nullableForeignRelationship(
        string $column,
        string $referredClass,
        string $onDeleteRestriction = RelationshipRestrictions::RESTRICT
    ): Closure {
        return function (
            Table $table,
            MigrationContextInterface $context
        ) use (
            $column,
            $referredClass,
            $onDeleteRestriction
        ) {
            $tableName    = $this->getTableNameForClass($referredClass);
            $pkName       = $this->getModelSchemas()->getPrimaryKey($referredClass);
            $columnType   = $this->getModelSchemas()->getAttributeType($context->getModelClass(), $column);
            $columnLength = $columnType === Types::STRING ?
                $this->getModelSchemas()->getAttributeLength($context->getModelClass(), $column) : null;

            $closure = $this
                ->nullableForeignColumn($column, $tableName, $pkName, $columnType, $columnLength, $onDeleteRestriction);

            return $closure($table, $context);
        };
    }

    /** @noinspection PhpTooManyParametersInspection
     * @param string   $localKey
     * @param string   $foreignTable
     * @param string   $foreignKey
     * @param string   $type
     * @param int|null $length
     * @param string   $onDeleteRestriction
     *
     * @return Closure
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    protected function foreignColumn(
        string $localKey,
        string $foreignTable,
        string $foreignKey,
        string $type,
        ?int $length = null,
        string $onDeleteRestriction = RelationshipRestrictions::RESTRICT
    ): Closure {
        return $this->foreignColumnImpl(
            $localKey,
            $foreignTable,
            $foreignKey,
            $type,
            $length,
            true,
            $onDeleteRestriction
        );
    }

    /** @noinspection PhpTooManyParametersInspection
     * @param string   $localKey
     * @param string   $foreignTable
     * @param string   $foreignKey
     * @param string   $type
     * @param int|null $length
     * @param string   $onDeleteRestriction
     *
     * @return Closure
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    protected function nullableForeignColumn(
        string $localKey,
        string $foreignTable,
        string $foreignKey,
        string $type,
        ?int $length = null,
        string $onDeleteRestriction = RelationshipRestrictions::RESTRICT
    ): Closure {
        return $this->foreignColumnImpl(
            $localKey,
            $foreignTable,
            $foreignKey,
            $type,
            $length,
            false,
            $onDeleteRestriction
        );
    }

    /**
     * @param string $name
     * @param string $onDeleteRestriction
     *
     * @return Closure
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    protected function nullableRelationship(
        string $name,
        string $onDeleteRestriction = RelationshipRestrictions::RESTRICT
    ): Closure {
        return $this->relationshipImpl($name, false, $onDeleteRestriction);
    }

    /**
     * @param string $name
     * @param string $onDeleteRestriction
     *
     * @return Closure
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    protected function relationship(
        string $name,
        string $onDeleteRestriction = RelationshipRestrictions::RESTRICT
    ): Closure {
        return $this->relationshipImpl($name, true, $onDeleteRestriction);
    }

    /**
     * @param string $modelClass
     *
     * @return string
     */
    protected function getTableNameForClass(string $modelClass): string
    {
        assert(
            $this->getModelSchemas()->hasClass($modelClass),
            "Class `$modelClass` is not found in model Schemas."
        );

        $tableName = $this->getModelSchemas()->getTable($modelClass);

        return $tableName;
    }

    /**
     * @param string $name
     * @param mixed  $value
     *
     * @return Closure
     */
    protected function defaultValue(string $name, $value): Closure
    {
        return function (Table $table) use ($name, $value) {
            assert($table->hasColumn($name));
            $table->getColumn($name)->setDefault($value);
        };
    }

    /**
     * @param string $name
     *
     * @return Closure
     */
    protected function nullableValue(string $name): Closure
    {
        return function (Table $table) use ($name) {
            assert($table->hasColumn($name));
            $table->getColumn($name)->setNotnull(false);
        };
    }

    /**
     * @param string $name
     *
     * @return Closure
     */
    protected function notNullableValue(string $name): Closure
    {
        return function (Table $table) use ($name) {
            assert($table->hasColumn($name));
            $table->getColumn($name)->setNotnull(true);
        };
    }

    /**
     * @param string     $name
     * @param bool       $notNullable
     * @param null|mixed $default
     *
     * @return Closure
     */
    private function unsignedIntImpl(string $name, bool $notNullable, $default = null): Closure
    {
        return function (Table $table) use ($name, $notNullable, $default) {
            $column = $table->addColumn($name, Types::INTEGER)->setUnsigned(true)->setNotnull($notNullable);
            $default === null ?: $column->setDefault($default);
        };
    }

    /** @noinspection PhpTooManyParametersInspection
     * @param string   $localKey
     * @param string   $foreignTable
     * @param string   $foreignKey
     * @param string   $type
     * @param int|null $length
     * @param bool     $notNullable
     * @param string   $onDeleteRestriction
     *
     * @return Closure
     */
    private function foreignColumnImpl(
        string $localKey,
        string $foreignTable,
        string $foreignKey,
        string $type,
        ?int $length,
        bool $notNullable,
        string $onDeleteRestriction
    ): Closure {
        return function (Table $table) use (
            $localKey,
            $foreignTable,
            $foreignKey,
            $notNullable,
            $onDeleteRestriction,
            $type,
            $length
        ) {
            $column = $table->addColumn($localKey, $type)->setNotnull($notNullable);
            $length === null ? $column->setUnsigned(true) : $column->setLength($length);
            $table->addForeignKeyConstraint(
                $foreignTable,
                [$localKey],
                [$foreignKey],
                ['onDelete' => $onDeleteRestriction]
            );
        };
    }

    /**
     * @param string $name
     * @param bool   $notNullable
     * @param string $onDeleteRestriction
     *
     * @return Closure
     */
    private function relationshipImpl(string $name, bool $notNullable, string $onDeleteRestriction): Closure
    {
        return function (
            Table $table,
            MigrationContextInterface $context
        ) use (
            $name,
            $notNullable,
            $onDeleteRestriction
        ) {
            $modelClass = $context->getModelClass();

            assert(
                $this->getModelSchemas()->hasRelationship($modelClass, $name),
                "Relationship `$name` not found for model `$modelClass`."
            );
            assert(
                $this->getModelSchemas()->getRelationshipType($modelClass, $name) === RelationshipTypes::BELONGS_TO,
                "Relationship `$name` for model `$modelClass` must be `belongsTo`."
            );

            $localKey     = $this->getModelSchemas()->getForeignKey($modelClass, $name);
            $columnType   = $this->getModelSchemas()->getAttributeType($modelClass, $localKey);
            $columnLength = $columnType === Types::STRING ?
                $this->getModelSchemas()->getAttributeLength($modelClass, $localKey) : null;

            $otherModelClass = $this->getModelSchemas()->getReverseModelClass($modelClass, $name);
            assert(
                $this->getModelSchemas()->hasClass($otherModelClass),
                "Class `$otherModelClass` is not found in model Schemas."
            );
            $foreignTable = $this->getModelSchemas()->getTable($otherModelClass);
            $foreignKey   = $this->getModelSchemas()->getPrimaryKey($otherModelClass);

            $fkClosure = $this->foreignColumnImpl(
                $localKey,
                $foreignTable,
                $foreignKey,
                $columnType,
                $columnLength,
                $notNullable,
                $onDeleteRestriction
            );

            return $fkClosure($table);
        };
    }
}
