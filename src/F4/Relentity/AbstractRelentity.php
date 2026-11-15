<?php

declare(strict_types=1);

namespace F4\Relentity;

use Error;
use InvalidArgumentException;
use F4\DB;
use F4\DB\{
    AssignmentCollection,
    ConditionCollection,
    OrderCollection,
    QueryBuilderInterface,
    SelectExpressionCollection,
    Adapter\AdapterInterface,
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
    array_reduce,
    array_slice,
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
    public const array DEFAULT_REVEALING = [];
    public const array DEFAULT_ORDER = [];
    public const ?string DEFAULT_RETURNING = '*';
    public const array DEFAULT_RELATIONS = [];
    public const ?int DEFAULT_LIMIT = 100;
    public const ?AdapterInterface DEFAULT_ADAPTER = null;

    private string|array|SelectExpressionCollection $select = [];
    private string|array $insert = [];
    private string|array|AssignmentCollection $update = [];
    private array $where = [];
    private array $concealing = [];
    private array $revealing = [];
    private array|OrderCollection $order = [];
    private ?string $returning = null;
    private ?int $limit = null;
    private int $offset = 0;
    /**
     * Active relations as complete declarations, copied from RELATIONS and keyed by alias
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
            case ActionType::Insert:
                $this->insert = ((array)$parameters) or throw new InvalidArgumentException("Missing data for insert action");
                break;
            case ActionType::Update:
                $this->update = ((array)$parameters) or throw new InvalidArgumentException("Missing data for update action");;
                break;
        }
        $this->where = static::DEFAULT_WHERE;
        $this->concealing = static::DEFAULT_CONCEALING;
        $this->revealing = static::DEFAULT_REVEALING;
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
    public static function update(array $changes): static
    {
        return new static(ActionType::Update, $changes);
    }
    public static function insert(array $values): static
    {
        return new static(ActionType::Insert, $values);
    }
    public static function delete(): static
    {
        return new static(ActionType::Delete);
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
            $target = $declaration[0] ?? null;
            $relationType = $declaration[1] ?? OneToMany::class;
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
    public function asQuery(
        null|array|string|SelectExpressionCollection $columns = null,
        ?string $returning = null,
    ): QueryBuilderInterface
    {
        switch ($this->actionType) {
            case ActionType::Fetch:
                $query = DB::select($columns ?: $this->select);
                $relations = $this->getRelations();
                foreach($relations as $alias => $relation) {
                    $relation->appendSelect($query, $this, $alias);
                }
                $query->from(static::TABLE_NAME);
                foreach($relations as $alias => $relation) {
                    $relation->appendJoin($query, $this, $alias);
                }
                break;
            case ActionType::Update:
                $query = DB::update(static::TABLE_NAME)->set($this->update);
                break;
            case ActionType::Insert:
                $query = DB::insert()->into(static::TABLE_NAME)->values($this->insert);
                break;
            case ActionType::Delete:
                $query = DB::delete()->from(static::TABLE_NAME);
                break;
            default: throw new InvalidArgumentException('Uknown entity action');
        };
        if ($this->adapter) {
            $query->useAdapter($this->adapter);
        }
        if ($this->actionType !== ActionType::Insert && $this->where) {
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
        $query
            ->revealing($this->revealing)
            ->concealing($this->concealing)
        ;
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
     * Replaces the columns given to fetch(), or DEFAULT_SELECT, instead of adding to them.
     * Pass null to return to DEFAULT_SELECT. Only applies to fetch().
     */
    public function selecting(string|array|SelectExpressionCollection $columns): static
    {
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
            $this->relations[$alias] ??= (array) (static::RELATIONS[$alias]
                ?? throw new InvalidArgumentException('Unknown relation ' . static::class . "::RELATIONS['{$alias}']"));
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
