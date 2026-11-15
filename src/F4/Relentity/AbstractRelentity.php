<?php

declare(strict_types=1);

namespace F4\Relentity;

use Error;
use InvalidArgumentException;
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
    Relation\OneToMany,
    Relation\RelationInterface,
};

use function
    array_diff_key,
    array_flip,
    array_keys,
    array_is_list,
    array_map,
    array_reduce,
    array_slice,
    array_values,
    is_array,
    is_subclass_of
;

class AbstractRelentity implements RelentityInterface
{
    // Must be redefined by every child, checked in the constructor. A self-referencing
    // constant here would make PHP fail to instantiate children with closures in their constants
    public const string TABLE_NAME = '';
    public const string PRIMARY_KEY = '';
    public const array RELATIONS = [];
    public const string|array DEFAULT_SELECT = '*';
    public const array DEFAULT_WHERE = [];
    public const array DEFAULT_CONCEALING = [];
    public const array DEFAULT_ORDER = [];
    public const ?string DEFAULT_RETURNING = '*';
    public const array DEFAULT_RELATIONS = [];
    public const ?int DEFAULT_LIMIT = 100;
    public const ?AdapterInterface DEFAULT_ADAPTER = null;

    private string|array|SelectExpressionCollection $select = [];
    private array $values = [];
    private array $changes = [];
    private array $where = [];
    /** @var list<string> keys and paths given to concealing(), DEFAULT_CONCEALING not included */
    private array $concealing = [];
    /** @var list<string> keys and paths given to revealing() */
    private array $revealing = [];
    private array $order = [];
    private ?string $returning = null;
    private ?int $limit = null;
    private int $offset = 0;
    /**
     * Active relations keyed by alias, as complete declarations copied from RELATIONS:
     * [TargetClass::class, RelationClass::class, 'option' => value, ...]
     *
     * @var array<string, array>
     */
    private array $relations = [];
    private ?AdapterInterface $adapter = null;

    final protected function __construct(private ActionType $actionType, private mixed $parameters = null)
    {
        if (static::TABLE_NAME === '') {
            throw new RelentityException(static::class . ' must define TABLE_NAME');
        }
        if (static::PRIMARY_KEY === '') {
            throw new RelentityException(static::class . ' must define PRIMARY_KEY');
        }
        switch($actionType) {
            case ActionType::Fetch:
                $this->select = $parameters ?? static::DEFAULT_SELECT;
                break;
            case ActionType::Add:
                $this->values = ((array)$parameters) or throw new InvalidArgumentException("Missing data for add action");
                break;
            case ActionType::Change:
                $this->changes = ((array)$parameters) or throw new InvalidArgumentException("Missing data for change action");
                break;
        }
        $this->where = static::DEFAULT_WHERE;
        $this->order = static::DEFAULT_ORDER;
        $this->limit = static::DEFAULT_LIMIT;
        $this->returning = static::DEFAULT_RETURNING;
        $this->with(...static::DEFAULT_RELATIONS);
        $this->adapter = static::DEFAULT_ADAPTER;
    }
    public static function fetch(null|string|array|SelectExpressionCollection $columns = null): static
    {
        return new static(ActionType::Fetch, $columns);
    }
    public static function change(array $changes): static
    {
        return new static(ActionType::Change, $changes);
    }
    public static function add(array $values): static
    {
        return new static(ActionType::Add, $values);
    }
    public static function remove(): static
    {
        return new static(ActionType::Remove);
    }
    /**
     * A RELATIONS entry is either a target class, or [TargetClass::class, RelationClass::class, 'option' => value, ...],
     * where the relation class defaults to OneToMany and options are arguments of its constructor.
     *
     * @return array<string, RelationInterface>
     */
    protected function getRelations(): array {
        $relations = [];
        foreach ($this->relations as $alias => $declaration) {
            $location = static::class . "::RELATIONS['{$alias}']";
            [$target, $relationType] = $declaration;
            if (!is_subclass_of($target, RelentityInterface::class)) {
                throw new InvalidArgumentException("Position 0 of {$location} must be a " . RelentityInterface::class . ' class');
            }
            if (!is_subclass_of($relationType, RelationInterface::class)) {
                throw new InvalidArgumentException("Position 1 of {$location} must be a " . RelationInterface::class . ' class');
            }
            try {
                $relations[$alias] = new $relationType($target, ...array_slice($declaration, 2));
            } catch (Error $e) {
                // Unknown, missing or mistyped options
                throw new InvalidArgumentException("Invalid declaration of {$location}: {$e->getMessage()}", previous: $e);
            }
        }
        return $relations;
    }
    /**
     * A bare * would also expand to the column of every joined relation, which is already selected by the relation itself.
     */
    private function qualifyWildcard(mixed $columns): mixed
    {
        return match (true) {
            $columns === '*' => new Fragment('{#::#}.*', [DB::escapeIdentifier(static::TABLE_NAME)]),
            // Arrays nest, e.g. selecting(['*']) stores [['*']]
            is_array($columns) => array_map($this->qualifyWildcard(...), $columns),
            default => $columns,
        };
    }
    public function asQuery(
        null|array|string|SelectExpressionCollection $columns = null,
        ?string $returning = null,
    ): QueryBuilderInterface
    {
        switch ($this->actionType) {
            case ActionType::Fetch:
                $relations = $this->getRelations();
                $query = DB::select(match ($relations) {
                    [] => $columns ?: $this->select,
                    default => $this->qualifyWildcard($columns ?: $this->select),
                });
                foreach($relations as $alias => $relation) {
                    $relation->appendSelect($query, $this, $alias);
                }
                $query->from(static::TABLE_NAME);
                foreach($relations as $alias => $relation) {
                    $relation->appendJoin($query, $this, $alias);
                }
                break;
            case ActionType::Change:
                $query = DB::update(static::TABLE_NAME)->set($this->changes);
                break;
            case ActionType::Add:
                $query = DB::insert()->into(static::TABLE_NAME)->values($this->values);
                break;
            case ActionType::Remove:
                $query = DB::delete()->from(static::TABLE_NAME);
                break;
            default: throw new InvalidArgumentException('Uknown entity action');
        };
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
        if($this->actionType !== ActionType::Fetch && ($returning ?? $this->returning)) {
            $query->returning($returning ?? $this->returning);
        }
        // Only takes effect when this query is executed itself: as the target of a relation
        // the same list is applied in SQL by the relation, see AbstractRelation::buildRowsAsJsonb()
        $query->concealing($this->getConcealedColumns());
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
    /**
     * Keys and paths that are concealed in the end. Each of the following overrides the ones before it,
     * whatever the order of the calls: DEFAULT_CONCEALING, revealing(), concealing().
     * A plain key and its single-segment path, e.g. "token" and "$.token", are the same key.
     *
     * @return list<string>
     */
    public function getConcealedColumns(): array
    {
        $canonical = static fn(string $column): string => ConcealmentPath::fromString($column)->getCanonical();
        $concealed = [];
        foreach (static::DEFAULT_CONCEALING as $column) {
            $concealed[$canonical($column)] = $column;
        }
        foreach ($this->revealing as $column) {
            unset($concealed[$canonical($column)]);
        }
        foreach ($this->concealing as $column) {
            $concealed[$canonical($column)] = $column;
        }
        return array_values($concealed);
    }
    /**
     * Replaces the columns given to fetch(), or DEFAULT_SELECT, instead of adding to them.
     * Only applies to fetch().
     */
    public function selecting(string|array|SelectExpressionCollection ...$columns): static
    {
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
        $this->limit = $rows;
        if ($skipping !== null) {
            $this->offset = $skipping;
        }
        return $this;
    }
    public function skipping(int $rows): static
    {
        $this->offset = $rows;
        return $this;
    }
    public function execute(): void
    {
        $this->asQuery()->commit();
    }
    public function asCount(): int
    {
        return (clone $this)
            ->without(...array_keys($this->relations))
            ->sortedBy([])
            ->limitedTo(null, 0)
            ->asQuery('COUNT(*)')
            ->asValue(index: 0);
    }
    public function matching(array|ConditionCollection ...$filters): static
    {
        array_is_list($filters) or throw new InvalidArgumentException(__FUNCTION__ . '() does not accept named arguments');
        $this->where = [...$this->where, ...$filters];
        return $this;
    }
    /**
     * Replaces the current ordering, DEFAULT_ORDER included, instead of adding to it, and
     * removes it when called without arguments. Unlike matching(), with(), concealing() and
     * revealing(), which add to what is already set, repeated calls do not accumulate:
     * prepending to a default ordering would rarely be useful, and appending would have no
     * visible effect. Pass all criteria in a single call.
     */
    public function sortedBy(array|OrderCollection ...$criteria): static
    {
        array_is_list($criteria) or throw new InvalidArgumentException(__FUNCTION__ . '() does not accept named arguments');
        $this->order = $criteria;
        return $this;
    }
    public function with(string ...$relations): static
    {
        array_is_list($relations) or throw new InvalidArgumentException(__FUNCTION__ . '() does not accept named arguments');
        foreach ($relations as $alias) {
            if (isset($this->relations[$alias])) {
                continue;
            }
            $declaration = (array) (static::RELATIONS[$alias]
                ?? throw new InvalidArgumentException('Unknown relation ' . static::class . "::RELATIONS['{$alias}']"));
            // Target and relation class always take positions 0 and 1, so that options follow them
            $this->relations[$alias] = [
                $declaration[0] ?? null,
                $declaration[1] ?? OneToMany::class,
                ...array_diff_key($declaration, [0 => true, 1 => true]),
            ];
        }
        return $this;
    }
    public function without(string ...$relations): static
    {
        array_is_list($relations) or throw new InvalidArgumentException(__FUNCTION__ . '() does not accept named arguments');
        $this->relations = array_diff_key($this->relations, array_flip($relations));
        return $this;
    }
}
