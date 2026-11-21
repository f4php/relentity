<?php

declare(strict_types=1);

namespace F4\Relentity;

use F4\DB\{
    ConditionCollection,
    OrderCollection,
    QueryBuilderInterface,
    SelectExpressionCollection,
};

use F4\Relentity\{
    ActionType,
};

interface RelentityInterface {
    // Must be redefined by every child, checked in the constructor. A self-referencing
    // constant here would make PHP fail to instantiate children with closures in their constants
    public const string TABLE_NAME = '';
    public const string PRIMARY_KEY = '';
    public const array DEFAULT_RELATIONS = [];
    final public const string COUNT_ALIAS = 'count';
    public static function add(array $values): static;
    public static function change(array $changes): static;
    public static function count(): static;
    public static function fetch(null|string|array|SelectExpressionCollection $columns = null): static;
    public static function remove(): static;
    public function asQuery(null|array|string|SelectExpressionCollection $columns = null,?string $returning = null,): QueryBuilderInterface;
    public function asRow(): ?array;
    public function asSQL(): string;
    public function asTable(): array;
    public function asValue(string|int $column = 0): mixed;
    public function concealing(string|array ...$columns): static;
    public function execute(): void;
    public function getActionType(): ActionType;
    public function getConcealedColumns(): array;
    public function getPrimaryKey(): string;
    public function getTableName(): string;
    public function joinedWith(string|array $table, null|string|array|ConditionCollection $condition = null, string $method='join'): static;
    public function limitedTo(?int $rows, ?int $skipping = null): static;
    public function matching(array|ConditionCollection ...$filters): static;
    public function revealing(string|array ...$columns): static;
    public function selecting(string|array|SelectExpressionCollection ...$columns): static;
    public function skipping(int $rows): static;
    public function sortedBy(array|OrderCollection ...$criteria): static;
    public function unlimited(): static;
    public function with(string|array ...$relations): static;
    public function without(string ...$relations): static;
}