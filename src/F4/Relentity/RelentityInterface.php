<?php

declare(strict_types=1);

namespace F4\Relentity;

use F4\DB\{
    ConditionCollection,
    OrderCollection,
    QueryBuilderInterface,
    SelectExpressionCollection,
};

interface RelentityInterface {
    public const string TABLE_NAME = self::TABLE_NAME;
    public const string PRIMARY_KEY = self::PRIMARY_KEY;
    public static function fetch(null|string|array|SelectExpressionCollection $select = null): static;
    public static function insert(array $values): static;
    public static function update(array $changes): static;
    public static function delete(): static;
    public function asCount(): int;
    public function asQuery(null|array|string|SelectExpressionCollection $selectExpression = null): QueryBuilderInterface;
    public function asRow(): ?array;
    public function asSQL(): string;
    public function asTable(): array;
    public function asValue(string|int $index = 0): mixed;
    public function concealing(string|array ...$columns): static;
    public function execute(): void;
    public function limit(?int $limit, ?int $offset = null): static;
    public function matching(array|ConditionCollection ...$filter): static;
    public function offset(int $offset): static;
    public function revealing(string|array ...$columns): static;
    public function sortedBy(array|OrderCollection ...$order): static;
    public function with(string ...$relations): static;
    public function without(string ...$relations): static;
}