<?php

declare(strict_types=1);

namespace F4\Relentity;

use InvalidArgumentException;
use F4\DB;
use F4\DB\{
    AssignmentCollection,
    ConditionCollection,
    Fragment,
    OrderCollection,
    QueryBuilderInterface,
    SelectExpressionCollection,
    Adapter\AdapterInterface,
};
use F4\Relentity\{
    ActionType,
    RelentityInterface,
    Exception\RelentityException,
    Relation\RelationInterface,
};

use function
    array_keys,
    array_map,
    sprintf
;

class AbstractRelentity implements RelentityInterface
{
    public const string TABLE_NAME = self::TABLE_NAME;
    public const string PRIMARY_KEY = self::PRIMARY_KEY;
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
    private array $relations = [];
    private ?AdapterInterface $adapter = null;

    final protected function __construct(private ActionType $actionType, private mixed $parameters = null)
    {
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
        $this->relations = static::DEFAULT_RELATIONS;
        $this->adapter = static::DEFAULT_ADAPTER;
    }
    public static function fetch(null|string|array|SelectExpressionCollection $select = null): static
    {
        return new static(ActionType::Fetch, $select);
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
    protected function getRelations(): array {
        return array_map(
            callback: function(string $alias): RelationInterface {
                if(!($target = static::RELATIONS[$alias][0] ?? null)) {
                    throw new InvalidArgumentException("Unknown relation entity for {$alias}");
                }
                if(!($relationType = static::RELATIONS[$alias][1] ?? null)) {
                    throw new InvalidArgumentException("Unknown relation type for {$alias}");
                }
                return new $relationType(
                    source: $this,
                    target: $target::fetch(),
                    targetAlias: $alias,
                );
            },
            array: $this->relations,
        );
    }
    public function asQuery(
        null|array|string|SelectExpressionCollection $selectExpression = null,
        ?string $returning = null,
    ): QueryBuilderInterface
    {
        switch ($this->actionType) {
            case ActionType::Fetch:
                $query = DB::select($selectExpression ?: $this->select);
                $relations = $this->getRelations();
                foreach($relations as $relation) {
                    $relation->appendSelect($query);
                }
                $query->from(static::TABLE_NAME);
                foreach($relations as $relation) {
                    $relation->appendJoin($query);
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
    public function asValue(string|int $index = 0): mixed
    {
        return $this->asQuery()->asValue($index);
    }
    public function concealing(...$columns): static
    {
        $this->concealing = array_reduce(
            array: $columns,
            callback: fn(array $carry, mixed $column): array => [...$carry, ...(array) $column],
            initial: $this->concealing,
        );
        return $this;
    }
    public function revealing(...$columns): static
    {
        $this->revealing = array_reduce(
            array: $columns,
            callback: fn(array $carry, mixed $column): array => [...$carry, ...(array) $column],
            initial: $this->revealing,
        );
        return $this;
    }
    public function limit(?int $limit, ?int $offset = null): static
    {
        $this->limit = $limit;
        if ($offset !== null) {
            $this->offset = $offset;
        }
        return $this;
    }
    public function offset(int $offset): static
    {
        $this->offset = $offset;
        return $this;
    }
    public function execute(): void
    {
        $this->asQuery()->commit();
    }
    public function asCount(): int
    {
        return $this
            ->asQuery('COUNT(*)')
            ->asValue(index: 0);
    }
    public function matching(array|ConditionCollection ...$filters): static
    {
        $this->where = [...$this->where, ...$filters];
        return $this;
    }
    public function sortedBy(array|OrderCollection ...$order): static
    {
        $this->order = $order;
        return $this;
    }
    public function with(string ...$relations): static
    {
        $this->relations = [
            ...$this->relations,
            ...$relations,
        ];
        return $this;
    }
    public function without(string ...$relations): static
    {
        $this->relations = array_diff_key(
            $this->relations,
            $relations,
        );
        return $this;
    }
}
