<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use InvalidArgumentException,
    RuntimeException;

use F4\DB;
use F4\DB\{
    ConditionCollection,
    Fragment,
    QueryBuilderInterface,
    Concealment\ConcealmentPath,
};
use F4\Relentity\{
    ActionType,
    RelentityInterface,
    Relation\RelationInterface,
};

use function
array_key_first,
count,
current,
in_array,
is_array,
is_string,
is_subclass_of
;

abstract class AbstractRelation implements RelationInterface
{
    public const string RELATION_ROWS_ALIAS = '___relation_rows___'; // unique enough to avoid conflict with actual column names
    protected const string SUBQUERY_ALIAS = '___subquery___'; // unique enough to avoid conflict with actual table names
    public const string SOURCE_ALIAS = '___source___'; // unique enough to avoid conflict with actual table names
    protected const array SUPPORTED_ACTIONS = [
        ActionType::Fetch,
    ];

    protected RelentityInterface $source;
    protected RelentityInterface $target;
    protected null|string|array|ConditionCollection $relationCondition;
    protected string|RelentityInterface $viaEntity;
    protected null|string|array|ConditionCollection $viaSourceCondition;
    protected null|string|array|ConditionCollection $viaTargetCondition;
    final protected function __construct(string|RelentityInterface $entity, null|string|array|ConditionCollection $condition = null)
    {
        $target = match ($entity instanceof RelentityInterface) {
            true => $entity,
            default => match (is_subclass_of($entity, RelentityInterface::class)) {
                false => throw new InvalidArgumentException("Relation must be a " . RelentityInterface::class . ' class'),
                default => $entity::fetch()->without(...$entity::DEFAULT_RELATIONS), // prevent recursion
            },
        };
        if (!in_array($actionType = $target->getActionType(), static::SUPPORTED_ACTIONS, strict: true)) {
            throw new InvalidArgumentException(static::class . " does not support {$actionType->name} action");
        }
        $this->target = $target;
        $this->relationCondition = $condition;
    }
    public static function of(string|RelentityInterface $target, null|string|array|ConditionCollection $condition = null): static
    {
        return new static($target, $condition);
    }
    public function getConcealedPaths(string $alias): array
    {
        return match($this->target->getActionType() !== ActionType::Fetch) {
            true => [], // aggregate() targets are not concealed
            default => array_map(
                callback: static fn(string $column): string => ConcealmentPath::render([$alias, ...ConcealmentPath::fromString($column)->getSegments()]),
                array: $this->target->getConcealedColumns(),
            ),
        };
    }
    /**
     * Relates the source to the target through a link table. Replaces the condition passed to of().
     * @param string|RelentityInterface $table a link table name, or a fetch() statement of a link entity
     */
    public function via(string|RelentityInterface $table, null|string|array|ConditionCollection $sourceCondition = null, null|string|array|ConditionCollection $targetCondition = null): static
    {
        if ($table instanceof RelentityInterface && $table->getActionType() !== ActionType::Fetch) {
            throw new InvalidArgumentException(__FUNCTION__ . '() requires Relentity objects to have Fetch action type');
        }
        $this->viaEntity = $table;
        $this->viaSourceCondition = $sourceCondition;
        $this->viaTargetCondition = $targetCondition;
        return $this;
    }
    public function withSource(RelentityInterface $relentity): static
    {
        $this->source = $relentity;
        return $this;
    }
    /**
     * The column compared on both sides when of() has no condition: by default the primary key
     * of the source, i.e. the target rows belong to the source
     */
    protected function getDefaultConditionColumn(RelentityInterface $target): string
    {
        return $this->source->getPrimaryKey();
    }
    /**
     * A copy of the target, filtered by the relation condition, or by the conditions of via()
     */
    final protected function buildTarget(): RelentityInterface
    {
        if (empty($this->source)) {
            throw new RuntimeException('Cannot determine source relentity, a withSource() call on relation instance is required');
        }
        $target = clone ($this->target);
        if (!empty($this->viaEntity)) {
            $viaTable = $this->viaEntity instanceof RelentityInterface ? $this->viaEntity->getTableName() : $this->viaEntity;
            $linkCondition = ConditionCollection::of(
                static::buildJoinCondition(                        // source.col = link.col
                    condition: $this->viaSourceCondition,
                    defaultSourceColumn: $this->source->getPrimaryKey(),
                    defaultTargetColumn: $this->source->getPrimaryKey(),
                    sourceAlias: static::SOURCE_ALIAS,
                    targetAlias: $viaTable,
                ),
                static::buildJoinCondition(                        // link.col = target.col
                    condition: $this->viaTargetCondition,
                    defaultSourceColumn: $target->getPrimaryKey(),
                    defaultTargetColumn: $target->getPrimaryKey(),
                    sourceAlias: $viaTable,
                    targetAlias: $target->getTableName(),
                ),
            );
            $target->matching(['EXISTS({#::#})' => match ($this->viaEntity instanceof RelentityInterface) {
                true => (clone $this->viaEntity)->unlimited()->matching($linkCondition)->asQuery('1'),
                default => DB::select(1)->from($this->viaEntity)->where($linkCondition),
            }]);
        } else {
            $defaultColumn = $this->getDefaultConditionColumn($target);
            $target->matching(static::buildJoinCondition(
                condition: $this->relationCondition,
                defaultSourceColumn: $defaultColumn,
                defaultTargetColumn: $defaultColumn,
                sourceAlias: static::SOURCE_ALIAS,
                targetAlias: $target->getTableName(),
            ));
        }
        return $target;
    }
    abstract public function appendJoinExpression(QueryBuilderInterface $query, string $alias): QueryBuilderInterface;
    public function appendSelectExpression(QueryBuilderInterface $query, string $alias): QueryBuilderInterface
    {
        return $query->select(
            new Fragment('{#::#}.{#::#} AS {#::#}', [
                $query->escapeIdentifier($alias),
                $query->escapeIdentifier(static::RELATION_ROWS_ALIAS),
                $query->escapeIdentifier($alias),
            ]),
        );
    }
    final protected static function buildJoinCondition(
        null|string|array|ConditionCollection $condition,
        string $defaultSourceColumn,
        string $defaultTargetColumn,
        ?string $sourceAlias = null,
        ?string $targetAlias = null,
    ): ConditionCollection {
        if ($condition instanceof ConditionCollection) {
            return $condition;
        }
        if (
            is_array($condition)
            && (
                count($condition) !== 1
                || !is_string($key = array_key_first($condition)) || $key === ''
                || !is_string($value = current($condition)) || $value === ''
            )
        ) {
            throw new InvalidArgumentException('Condition as array must contain exactly one array key with one value, both non-empty column names');
        }
        if ($condition === '') {
            throw new InvalidArgumentException('Condition as string must be a non-empty column name');
        }
        [$sourceColumn, $targetColumn] = match (true) {
            is_array($condition) => [array_key_first($condition), current($condition)],
            $condition === null => [$defaultSourceColumn, $defaultTargetColumn],
            default => [$condition, $condition],
        };
        $columnFn = fn(string $column, ?string $alias): Fragment => match (empty($alias)) {
            true => new Fragment('{#::#}', [DB::escapeIdentifier($column)]),
            default => new Fragment('{#::#}.{#::#}', [DB::escapeIdentifier($alias), DB::escapeIdentifier($column)]),
        };
        return ConditionCollection::of(new Fragment('{#::#} = {#::#}', [
            $columnFn($sourceColumn, $sourceAlias),
            $columnFn($targetColumn, $targetAlias),
        ]));
    }

    final protected static function buildJoinLateralQuery(
        QueryBuilderInterface $query,
        string $sourceTableName,
        string $alias,
        Fragment $rows,
        ?QueryBuilderInterface $targetQuery = null, // null when $rows contains the target itself
    ): QueryBuilderInterface {
        $rowsQuery = DB::select($rows)
            ->from([
                static::SOURCE_ALIAS => DB::select(new Fragment('{#::#}.*', [
                    DB::escapeIdentifier($sourceTableName),
                ]))
            ]);
        if ($targetQuery !== null) {
            $rowsQuery->crossJoinLateral(new Fragment('({#::#}) AS {#::#}', [
                $targetQuery,
                DB::escapeIdentifier(static::SUBQUERY_ALIAS),
            ]));
        }
        return $query
            ->leftJoinLateral(new Fragment('({#::#}) AS {#::#}', [
                $rowsQuery,
                DB::escapeIdentifier($alias),
            ]))
            ->on('true');
    }
}
