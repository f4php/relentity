<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use InvalidArgumentException;

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
array_is_list,
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
        ActionType::Count,
        ActionType::Fetch,
    ];

    protected RelentityInterface $source;
    protected RelentityInterface $target;
    protected null|string|array|ConditionCollection $relationCondition;
    final protected function __construct(string|RelentityInterface $entity, null|string|array|ConditionCollection $condition = null)
    {
        $this->target = match ($entity instanceof RelentityInterface) {
            true => match (in_array($actionType = $entity->getActionType(), static::SUPPORTED_ACTIONS, strict: true)) {
                    false => throw new InvalidArgumentException(static::class . " does not support {$actionType->name} action"),
                    default => $entity,
                },
            default => match (is_subclass_of($entity, RelentityInterface::class)) {
                    false => throw new InvalidArgumentException("Relation must be a " . RelentityInterface::class . ' class'),
                    default => $entity::fetch(),
                },
        };
        $this->relationCondition = $condition;
    }
    public static function of(string|RelentityInterface $entity, null|string|array|ConditionCollection $condition = null): static
    {
        return new static($entity, $condition);
    }
    public function withSource(RelentityInterface $relentity): static
    {
        $this->source = $relentity;
        return $this;
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
        if (is_array($condition) && (count($condition) !== 1 || array_is_list($condition) || !is_string(current($condition)))) {
            throw new InvalidArgumentException('Condition as array must contain exactly one array key with one value');
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
        string $sourceTableName, string $alias, 
        Fragment $rows, 
        QueryBuilderInterface $targetQuery
    ): QueryBuilderInterface
    {
        return $query
            ->leftJoinLateral(new Fragment('({#::#}) AS {#::#}', [
                DB::select($rows)
                    ->from([
                        static::SOURCE_ALIAS => DB::select(new Fragment('{#::#}.*', [
                            DB::escapeIdentifier($sourceTableName),
                        ]))
                    ])
                    ->crossJoinLateral(new Fragment('({#::#}) AS {#::#}', [
                        $targetQuery,
                        DB::escapeIdentifier(static::SUBQUERY_ALIAS),
                    ])),
                DB::escapeIdentifier($alias),
            ]))
            ->on('true');
    }

    // /**
    //  * Name of the only column of the joined subquery, selected under the relation alias.
    //  */
    // protected const string ROWS_COLUMN = 'relation_rows';

    // /**
    //  * A target stored in the source table shadows the source inside its own subquery, so the
    //  * source key is exposed to it under these names instead, see buildJoinSubquery().
    //  */
    // protected const string SOURCE_KEY_ALIAS = 'relation_source';
    // protected const string SOURCE_KEY_COLUMN = 'key';

    // /**
    //  * @param class-string<RelentityInterface> $target
    //  */
    // public function __construct(
    //     protected string $target,
    //     protected ?string $sourceKey = null,
    //     protected ?string $targetKey = null,
    //     protected ?Closure $modifyTarget = null,
    //     protected ?Closure $customSelectCallback = null,
    //     protected ?Closure $customJoinCallback = null,
    // ) {}

    // public function appendSelect(QueryBuilderInterface $query, RelentityInterface $source, string $alias): QueryBuilderInterface {
    //     return match ($this->customSelectCallback) {
    //         null => $query->select(
    //             new Fragment('{#::#}.{#::#} AS {#::#}', [
    //                 $query->escapeIdentifier($alias),
    //                 $query->escapeIdentifier(static::ROWS_COLUMN),
    //                 $query->escapeIdentifier($alias),
    //             ])
    //         ),
    //         default => ($this->customSelectCallback)($query, $source, $alias),
    //     };
    // }

    // /**
    //  * Related rows as JSON objects, without the keys the target conceals: the target is only
    //  * a subquery here, so concealment the query builder applies to executed queries never reaches it.
    //  * Must be called once the target is final, i.e. after modifyTarget.
    //  */
    // protected function buildRowsAsJsonb(QueryBuilderInterface $query, RelentityInterface $target): Fragment {
    //     $keys = [];
    //     $paths = [];
    //     foreach ($target->getConcealedColumns() as $column) {
    //         $segments = ConcealmentPath::fromString($column)->getSegments();
    //         if (count($segments) === 1) {
    //             $keys[] = $segments[0];
    //         } else {
    //             $paths[] = $segments;
    //         }
    //     }
    //     $expression = 'to_jsonb({#::#}.*)';
    //     // The subquery of target rows is aliased by the target table name
    //     $parameters = [$query->escapeIdentifier($target::TABLE_NAME)];
    //     // The casts are required, bound parameters alone would leave the operators ambiguous
    //     if ($keys) {
    //         $expression .= ' - ARRAY[{#,...#}]::text[]';
    //         $parameters[] = $keys;
    //     }
    //     // Unlike a key, a path does not descend into JSON lists
    //     foreach ($paths as $segments) {
    //         $expression .= ' #- ARRAY[{#,...#}]::text[]';
    //         $parameters[] = $segments;
    //     }
    //     return new Fragment($expression, $parameters);
    // }

    // protected function isSelfReferencing(RelentityInterface $source): bool {
    //     return $this->target::TABLE_NAME === $source::TABLE_NAME;
    // }

    // /**
    //  * The source key column, as the target query must refer to it.
    //  */
    // protected function buildSourceKeyReference(QueryBuilderInterface $query, RelentityInterface $source, string $sourceKey): Fragment {
    //     return new Fragment('{#::#}.{#::#}', match ($this->isSelfReferencing($source)) {
    //         true => [
    //             $query->escapeIdentifier(static::SOURCE_KEY_ALIAS),
    //             $query->escapeIdentifier(static::SOURCE_KEY_COLUMN),
    //         ],
    //         default => [
    //             $query->escapeIdentifier($source::TABLE_NAME),
    //             $query->escapeIdentifier($sourceKey),
    //         ],
    //     });
    // }

    // /**
    //  * The joined subquery: $expression selected from the target rows, which are aliased by the target table name.
    //  * The target query must be correlated through buildSourceKeyReference() of the same $sourceKey.
    //  */
    // protected function buildJoinSubquery(
    //     QueryBuilderInterface $query,
    //     RelentityInterface $source,
    //     string $sourceKey,
    //     RelentityInterface $target,
    //     Fragment $expression,
    // ): QueryBuilderInterface {
    //     $select = DB::select(new Fragment('{#::#} AS {#::#}', [
    //         $expression,
    //         $query->escapeIdentifier(static::ROWS_COLUMN),
    //     ]));
    //     $rows = [$this->target::TABLE_NAME => $target->asQuery()];
    //     if (!$this->isSelfReferencing($source)) {
    //         return $select->from($rows);
    //     }
    //     // Selected outside of the target query, where the table name still refers to the source
    //     return $select
    //         ->from([
    //             static::SOURCE_KEY_ALIAS => DB::select(new Fragment('{#::#}.{#::#} AS {#::#}', [
    //                 $query->escapeIdentifier($source::TABLE_NAME),
    //                 $query->escapeIdentifier($sourceKey),
    //                 $query->escapeIdentifier(static::SOURCE_KEY_COLUMN),
    //             ])),
    //         ])
    //         ->crossJoinLateral($rows);
    // }

    // abstract public function appendJoin(QueryBuilderInterface $query, RelentityInterface $source, string $alias): QueryBuilderInterface;
}
