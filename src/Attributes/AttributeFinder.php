<?php

namespace Spatie\ModelInfo\Attributes;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use UnitEnum;

class AttributeFinder
{
    /**
     * @param  class-string<Model>|Model  $model
     * @return \Illuminate\Support\Collection<Attribute>
     */
    public static function forModel(string|Model $model): Collection
    {
        if (is_string($model)) {
            $model = new $model;
        }

        return (new self())->attributes($model);
    }

    /**
     * @return \Illuminate\Support\Collection<Attribute>
     */
    protected function attributes(Model $model): Collection
    {
        $schema = $model->getConnection()->getSchemaBuilder();
        $table = $model->getTable();

        $columns = $schema->getColumns($table);
        $indexes = $schema->getIndexes($table);

        return collect($columns)
            ->values()
            ->map(function (array $column) use ($model, $indexes) {
                $columnIndexes = $this->getIndexes($column['name'], $indexes);

                return new Attribute(
                    name: $column['name'],
                    phpType: $this->getPhpType($column),
                    type: $column['type'],
                    increments: $column['auto_increment'],
                    nullable: $column['nullable'],
                    default: $this->getColumnDefault($column, $model),
                    primary: $columnIndexes->contains(fn (array $index) => $index['primary']),
                    unique: $columnIndexes->contains(fn (array $index) => $index['unique']),
                    fillable: $model->isFillable($column['name']),
                    appended: null,
                    cast: $this->getCastType($column['name'], $model),
                    virtual: false,
                    hidden: $this->attributeIsHidden($column['name'], $model)
                );
            })
            ->merge($this->getVirtualAttributes($model, $columns));
    }

    protected function getPhpType(array $column): string
    {
        $type = match ($column['type']) {
            'tinyint(1)', 'bit' => 'bool',
            default => null,
        };

        $type ??= match ($column['type_name']) {
            'tinyint', 'integer', 'int', 'int4', 'smallint', 'int2', 'mediumint' => 'int',
            'float', 'real', 'float4', 'double', 'float8' => 'float',
            'binary', 'varbinary', 'bytea', 'image', 'blob', 'tinyblob', 'mediumblob', 'longblob' => 'resource',
            'boolean', 'bool' => 'bool',
            'date', 'time', 'timetz', 'datetime', 'datetime2', 'smalldatetime', 'datetimeoffset', 'timestamp', 'timestamptz' => 'DateTime',
            'json', 'jsonb' => 'mixed',
            // 'char', 'bpchar', 'nchar',
            // 'varchar', 'nvarchar',
            // 'text', 'tinytext', 'longtext', 'mediumtext', 'ntext',
            // 'bigint', 'int8',
            // 'decimal', 'numeric',
            // 'money', 'smallmoney',
            // 'uuid', 'uniqueidentifier',
            // 'enum',
            // 'set',
            // 'inet', 'inet4', 'inet6', 'cidr', 'macaddr', 'macaddr8',
            // 'xml',
            // 'bit', 'varbit',
            // 'year',
            // 'interval',
            // 'geometry', 'geometrycollection', 'linestring', 'multilinestring', 'multipoint', 'multipolygon', 'point', 'polygon',
            // 'box', 'circle', 'line', 'lseg', 'path',
            // 'geography',
            // 'tsvector', 'tsquery' => 'string',
            default => null,
        };

        return $type ?? 'string';
    }

    protected function getColumnDefault(array $column, Model $model): mixed
    {
        $attributeDefault = $model->getAttributes()[$column['name']] ?? null;

        return match (true) {
            $attributeDefault instanceof BackedEnum => $attributeDefault->value,
            $attributeDefault instanceof UnitEnum => $attributeDefault->name,
            default => $attributeDefault ?? $column['default'],
        };
    }

    /**
     * @param  array $indexes
     * @return Collection<int, array>
     */
    protected function getIndexes(string $column, array $indexes)
    {
        return collect($indexes)
            ->filter(fn (array $index) => count($index['columns']) === 1 && $index['columns'][0] === $column);
    }

    protected function attributeIsHidden(string $attribute, Model $model): bool
    {
        if (count($model->getHidden()) > 0) {
            return in_array($attribute, $model->getHidden());
        }

        if (count($model->getVisible()) > 0) {
            return ! in_array($attribute, $model->getVisible());
        }

        return false;
    }

    protected function getCastType(string $column, Model $model): ?string
    {
        if ($model->hasGetMutator($column) || $model->hasSetMutator($column)) {
            return 'accessor';
        }

        if ($model->hasAttributeMutator($column)) {
            return 'attribute';
        }

        return $this->getCastsWithDates($model)->get($column) ?? null;
    }

    protected function getCastsWithDates(Model $model): Collection
    {
        return collect($model->getDates())
            ->whereNotNull()
            ->flip()
            ->map(fn () => 'datetime')
            ->merge($model->getCasts());
    }

    /**
     * @param  array  $columns
     * @return Collection<Attribute>
     */
    protected function getVirtualAttributes(Model $model, array $columns): Collection
    {
        $class = new ReflectionClass($model);

        return collect($class->getMethods())
            ->reject(
                fn (ReflectionMethod $method) => $method->isStatic()
                    || $method->isAbstract()
                    || $method->getDeclaringClass()->getName() !== get_class($model)
            )
            ->map(function (ReflectionMethod $method) use ($model) {
                if (preg_match('/(?<=^|;)get([^;]+?)Attribute(;|$)/', $method->getName(), $matches) === 1) {
                    return [
                        'name' => Str::snake($matches[1]),
                        'cast_type' => 'accessor',
                        'php_type' => $method->getReturnType()?->getName(),
                    ];
                }

                if (preg_match('/(?<=^|;)set([^;]+?)Attribute(;|$)/', $method->getName(), $matches) === 1) {
                    return [
                        'name' => Str::snake($matches[1]),
                        'cast_type' => 'mutator',
                        'php_type' => collect($method->getParameters())->firstWhere('name', 'value')?->getType()?->__toString(),
                    ];
                }

                if ($model->hasAttributeMutator($method->getName())) {
                    return [
                        'name' => Str::snake($method->getName()),
                        'cast_type' => 'attribute',
                        'php_type' => null,
                    ];
                }

                return [];
            })
            ->reject(fn ($cast) => ! isset($cast['name']) || collect($columns)->has($cast['name']))
            ->map(fn ($cast) => new Attribute(
                name: $cast['name'],
                phpType: $cast['php_type'] ?? null,
                type: null,
                increments: false,
                nullable: null,
                default: null,
                primary: null,
                unique: null,
                fillable: $model->isFillable($cast['name']),
                appended: $model->hasAppended($cast['name']),
                cast: $cast['cast_type'],
                virtual: true,
                hidden: $this->attributeIsHidden($cast['name'], $model)
            ))
            // Convert duplicate entries for accessor-mutator combinations
            ->groupBy('name')
            ->flatMap(function (Collection $items) {
                if ($items->count() !== 2) {
                    return $items;
                }

                if (! $items->firstWhere('cast', 'accessor') || ! $items->firstWhere('cast', 'mutator')) {
                    return $items;
                }

                $attribute = $items->first();
                $attribute->phpType = $items[0]->phpType ?? $items[1]->phpType;
                $attribute->cast = 'attribute';

                return [$attribute];
            });
    }
}
