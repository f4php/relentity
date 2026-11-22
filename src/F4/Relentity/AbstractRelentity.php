<?php

declare(strict_types=1);

namespace F4\Relentity;

use InvalidArgumentException;
use RuntimeException;
use F4\DB;
use F4\DB\{
    ConditionCollection,
    Fragment,
    OrderCollection,
    QueryBuilderInterface,
    SelectExpressionCollection,
    Adapter\AdapterInterface,
    Concealment\ConcealmentPath,
};
use F4\Relentity\{
    ActionType,
    RelentityInterface,
    Exception\RelentityException,
    Relation\RelationInterface,
};

use function
array_diff_key,
array_flip,
array_is_list,
array_reduce,
array_values,
in_array,
is_array,
is_numeric,
is_string
;

abstract class AbstractRelentity implements RelentityInterface
{
    public const array RELATIONS = [];
    public const null|string|array DEFAULT_SELECT = null;
    public const array DEFAULT_WHERE = [];
    public const array DEFAULT_CONCEALING = [];
    public const array DEFAULT_ORDER = [];
    public const ?string DEFAULT_RETURNING = '*';
    public const ?int DEFAULT_LIMIT = 100;
    public const ?string DEFAULT_ADAPTER = null;

    private string|array|SelectExpressionCollection $select = [];
    private array $values = [];
    private array $changes = [];
    private array $joins = [];
    private array $where = [];
    private array $concealing = [];
    private array $revealing = [];
    private array $order = [];
    private ?string $returning = null;
    private ?int $limit = null;
    private int $offset = 0;
    private array $relations = [];
    private ?AdapterInterface $adapter = null;

    final protected function __construct(private ActionType $actionType, mixed $parameters = null)
    {
        if (static::TABLE_NAME === '') {
            throw new RelentityException(static::class . ' must define TABLE_NAME');
        }
        if (static::PRIMARY_KEY === '') {
            throw new RelentityException(static::class . ' must define PRIMARY_KEY');
        }
        switch ($this->actionType) {
            case ActionType::Add:
                $this->values = ((array) $parameters) or throw new InvalidArgumentException("Missing data for add action");
                break;
            case ActionType::Change:
                $this->changes = ((array) $parameters) or throw new InvalidArgumentException("Missing data for change action");
                break;
            case ActionType::Aggregate:
                $this->select = match ($parameters) {
                    null, '', [] => throw new InvalidArgumentException('Missing expressions for aggregate action'),
                    default => $parameters,
                };
                break;
            case ActionType::Fetch:
                $this->select = $parameters ?? static::DEFAULT_SELECT ?? [];
                break;
        }
        $this->where = static::DEFAULT_WHERE;
        $this->order = static::DEFAULT_ORDER;
        $this->limit = static::DEFAULT_LIMIT;
        $this->returning = static::DEFAULT_RETURNING;
        $this->relations = array_reduce(
            array: static::DEFAULT_RELATIONS,
            callback: fn(array $relations, string $alias) =>
                match (static::RELATIONS[$alias] ?? null) {
                    null => throw new InvalidArgumentException("Relation {$alias} undefined"),
                    default => [
                        ...$relations,
                        ...[
                            $alias => static::RELATIONS[$alias],
                        ],
                    ],
                },
            initial: [],
        );
        if (static::DEFAULT_ADAPTER) {
            $this->adapter = new (static::DEFAULT_ADAPTER)();
        }
    }

    public static function fetch(null|string|array|SelectExpressionCollection $columns = null): static
    {
        return new static(ActionType::Fetch, $columns);
    }
    public static function change(array $changes): static
    {
        return new static(ActionType::Change, $changes);
    }
    /**
     * Starts a statement that returns aggregate expressions, e.g. 'sum("hours") AS "hours"',
     * in the same forms as fetch() accepts columns. DEFAULT_ORDER and DEFAULT_LIMIT do not apply.
     */
    public static function aggregate(string|array|SelectExpressionCollection $expressions): static
    {
        return new static(ActionType::Aggregate, $expressions);
    }
    /**
     * Shorthand for aggregate() of COUNT(*) AS "count"
     */
    public static function count(): static
    {
        return static::aggregate(new SelectExpressionCollection(new Fragment('COUNT(*) AS {#::#}', [DB::escapeIdentifier(static::COUNT_ALIAS)])));
    }
    public static function add(array $values): static
    {
        return new static(ActionType::Add, $values);
    }
    public static function remove(): static
    {
        return new static(ActionType::Remove);
    }
    public function getActionType(): ActionType
    {
        return $this->actionType;
    }
    public function getConcealedColumns(): array
    {
        return $this->resolveConcealedColumns($this->actionType === ActionType::Fetch ? $this->buildRelations() : []);
    }
    final protected function resolveConcealedColumns(array $relations): array
    {
        $canonical = static fn(string $column): string => ConcealmentPath::fromString($column)->getCanonical();
        $concealed = [];
        foreach (static::DEFAULT_CONCEALING as $column) {
            $concealed[$canonical($column)] = $column;
        }
        foreach ($relations as $alias => $relation) {
            foreach ($relation->getConcealedPaths($alias) as $path) {
                $concealed[$canonical($path)] = $path;
            }
        }
        foreach ($this->revealing as $column) {
            unset($concealed[$canonical($column)]);
        }
        foreach ($this->concealing as $column) {
            $concealed[$canonical($column)] = $column;
        }
        return array_values($concealed);
    }
    final protected function buildRelations(): array
    {
        $relations = [];
        foreach ($this->relations as $alias => $definition) {
            if (!is_string($alias) || $alias === '' || is_numeric($alias)) {
                throw new InvalidArgumentException('Relation alias must be non-empty string');
            }
            if (!is_callable($definition)) {
                throw new InvalidArgumentException('Relation definition must be callable');
            }
            $relations[$alias] = match (($relation = $definition($this)) instanceof RelationInterface) {
                false => throw new RuntimeException('Relation definition must return instance of ' . RelationInterface::class),
                default => $relation->withSource($this),
            };
        }
        return $relations;
    }
    public function joinedWith(string|array $table, null|string|array|ConditionCollection $condition = null, string $method = 'join'): static
    {
        if (
            !in_array($this->actionType, [
                ActionType::Fetch,
            ], strict: true)
        ) {
            throw new InvalidArgumentException(__FUNCTION__ . "() is not supported for intended action");
        }
        $condition = ($condition === [] || $condition === '') ? null : $condition;
        if (
            !in_array($method, [
                'crossJoin',
                'crossJoinLateral',
                'fullOuterJoin',
                'innerJoin',
                'innerJoinLateral',
                'join',
                'joinLateral',
                'leftJoin',
                'leftJoinLateral',
                'leftOuterJoin',
                'naturalJoin',
                'naturalLeftOuterJoin',
                'naturalRightOuterJoin',
                'rightJoin',
                'rightOuterJoin',
            ], strict: true)
        ) {
            throw new InvalidArgumentException("Unsupported join method {$method}");
        }
        $withoutCondition = [
            'crossJoin', 'crossJoinLateral', 
            'naturalJoin', 'naturalLeftOuterJoin', 'naturalRightOuterJoin'
        ];
        if (in_array($method, $withoutCondition, strict: true) !== ($condition === null)) {
            throw new InvalidArgumentException(match ($condition === null) {
                true => "{$method}() requires a condition",
                default => "{$method}() does not accept a condition",
            });
        }
        $this->joins[] = [
            'table' => $table,
            'condition' => $condition,
            'method' => $method,
        ];
        return $this;
    }
    public function asQuery(
        null|array|string|SelectExpressionCollection $columns = null,
        ?string $returning = null,
    ): QueryBuilderInterface {
        switch ($this->actionType) {
            case ActionType::Fetch:
                $relations = $this->buildRelations();
                $query = DB::select($columns ?? $this->select ?: ['{#::#}.*' => DB::escapeIdentifier($this->getTableName())]);
                $query->concealing($this->resolveConcealedColumns($relations));
                foreach ($relations as $alias => $relation) {
                    $relation->appendSelectExpression($query, $alias);
                }
                $query->from($this->getTableName());
                if ($this->joins) {
                    foreach ($this->joins as $join) {
                        $method = $join['method'];
                        $query->$method($join['table']);
                        if (is_string($join['condition'])) {
                            $query->using($join['condition']);
                        } elseif (!empty($join['condition'])) {
                            $query->on($join['condition']);
                        }
                    }
                }
                foreach ($relations as $alias => $relation) {
                    $relation->appendJoinExpression($query, $alias);
                }
                break;
            case ActionType::Aggregate:
                $query = new DB();
                $query = $query
                    ->select($this->select)
                    ->from($this->getTableName());
                break;
            case ActionType::Change:
                $query = DB::update($this->getTableName())->set($this->changes);
                break;
            case ActionType::Add:
                $query = DB::insert()->into($this->getTableName())->values($this->values);
                break;
            case ActionType::Remove:
                $query = DB::delete()->from($this->getTableName());
                break;
            default:
                throw new InvalidArgumentException('Unknown entity action');
        }
        ;
        if ($this->adapter) {
            $query->useAdapter($this->adapter);
        }
        if ($this->actionType !== ActionType::Add && $this->where) {
            $query->where($this->where);
        }
        if ($this->actionType === ActionType::Fetch && $this->order) {
            $query->order($this->order);
        }
        if ($this->actionType === ActionType::Fetch && $this->limit !== null) {
            $query->limit($this->limit);
        }
        if ($this->actionType === ActionType::Fetch && $this->offset) {
            $query->offset($this->offset);
        }
        if (in_array($this->actionType, [ActionType::Add, ActionType::Change, ActionType::Remove], strict: true) && ($returning ?? $this->returning)) {
            $query->returning($returning ?? $this->returning);
        }
        if (in_array($this->actionType, [ActionType::Add, ActionType::Change, ActionType::Remove], strict: true)) {
            $query->concealing($this->getConcealedColumns());
        }
        return $query;
    }
    public function asRow(): ?array
    {
        return $this->asQuery()->asRow();
    }
    public function asSQL(): string
    {
        return $this->asQuery()->asSQL();
    }
    public function asTable(): array
    {
        return $this->asQuery()->asTable();
    }
    public function asValue(string|int $column = 0): mixed
    {
        return $this->asQuery()->asValue($column);
    }
    public function concealing(string|array ...$columns): static
    {
        array_is_list($columns) or throw new InvalidArgumentException(__FUNCTION__ . '() does not accept named arguments');
        $this->concealing = array_reduce(
            array: $columns,
            callback: fn(array $carry, mixed $column): array => [...$carry, ...(array) $column],
            initial: $this->concealing,
        );
        return $this;
    }
    public function revealing(string|array ...$columns): static
    {
        array_is_list($columns) or throw new InvalidArgumentException(__FUNCTION__ . '() does not accept named arguments');
        $this->revealing = array_reduce(
            array: $columns,
            callback: fn(array $carry, mixed $column): array => [...$carry, ...(array) $column],
            initial: $this->revealing,
        );
        return $this;
    }
    public function getPrimaryKey(): string
    {
        return static::PRIMARY_KEY;
    }
    public function getTableName(): string
    {
        return static::TABLE_NAME;
    }
    /**
     * Replaces the columns given to fetch(), or DEFAULT_SELECT, instead of adding to them.
     * Only applies to fetch().
     */
    public function selecting(string|array|SelectExpressionCollection ...$columns): static
    {
        if (!in_array($this->actionType, [ActionType::Fetch], strict: true)) {
            throw new InvalidArgumentException(__FUNCTION__ . '() is not supported for intended action');
        }
        array_is_list($columns) or throw new InvalidArgumentException(__FUNCTION__ . '() does not accept named arguments');
        $this->select = $columns;
        return $this;
    }
    public function unlimited(): static
    {
        $this->limit = null;
        return $this;
    }
    public function limitedTo(?int $rows, ?int $skipping = null): static
    {
        if (in_array($this->actionType, [ActionType::Aggregate, ActionType::Remove], strict: true)) {
            throw new InvalidArgumentException(__FUNCTION__ . '() is not supported for intended action');
        }
        $this->limit = $rows;
        if ($skipping !== null) {
            $this->offset = $skipping;
        }
        return $this;
    }
    public function skipping(int $rows): static
    {
        if (in_array($this->actionType, [ActionType::Aggregate, ActionType::Remove], strict: true)) {
            throw new InvalidArgumentException(__FUNCTION__ . '() is not supported for intended action');
        }
        $this->offset = $rows;
        return $this;
    }
    public function execute(): void
    {
        $this->asQuery()->commit();
    }
    public function matching(array|ConditionCollection ...$filters): static
    {
        array_is_list($filters) or throw new InvalidArgumentException(__FUNCTION__ . '() does not accept named arguments');
        $this->where = [...$this->where, ...$filters];
        return $this;
    }
    public function sortedBy(array|OrderCollection ...$criteria): static
    {
        if (in_array($this->actionType, [ActionType::Aggregate], strict: true)) {
            throw new InvalidArgumentException(__FUNCTION__ . '() is not supported for intended action');
        }
        array_is_list($criteria) or throw new InvalidArgumentException(__FUNCTION__ . '() does not accept named arguments');
        $this->order = $criteria;
        return $this;
    }
    public function with(string|array ...$relations): static
    {
        if (in_array($this->actionType, [ActionType::Add, ActionType::Change, ActionType::Aggregate, ActionType::Remove], strict: true)) {
            throw new InvalidArgumentException(__FUNCTION__ . '() is not supported for intended action');
        }
        array_is_list($relations) or throw new InvalidArgumentException(__FUNCTION__ . '() does not accept named arguments');
        foreach ($relations as $relation) {
            if (is_array($relation)) {
                foreach ($relation as $alias => $definition) {
                    if (!is_string($alias) || $alias === '' || is_numeric($alias)) {
                        throw new InvalidArgumentException('Relation customization must have an alias');
                    }
                    if (!is_callable($definition)) {
                        throw new InvalidArgumentException('Relation customization must be a callable');
                    }
                    $this->relations[$alias] = $definition;
                }
            } else if ((static::RELATIONS[$relation] ?? null) === null) {
                throw new InvalidArgumentException("Unknown relation {$relation}");
            } else {
                $this->relations[$relation] = static::RELATIONS[$relation];
            }
        }
        return $this;
    }
    public function without(string ...$relations): static
    {
        if (in_array($this->actionType, [ActionType::Add, ActionType::Change, ActionType::Aggregate, ActionType::Remove], strict: true)) {
            throw new InvalidArgumentException(__FUNCTION__ . '() is not supported for intended action');
        }
        array_is_list($relations) or throw new InvalidArgumentException(__FUNCTION__ . '() does not accept named arguments');
        $this->relations = array_diff_key($this->relations, array_flip($relations));
        return $this;
    }
}
