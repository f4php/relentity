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
    public const string TABLE_NAME = '';
    public const string PRIMARY_KEY = '';
    public static function fetch(null|string|array|SelectExpressionCollection $columns = null): static;
    public static function insert(array $values): static;
    public static function update(array $changes): static;
    public static function delete(): static;
    public function asCount(): int;
    public function asQuery(
        null|array|string|SelectExpressionCollection $columns = null,
        ?string $returning = null,
    ): QueryBuilderInterface;
    public function asRow(): ?array;
    public function asSQL(): string;
    public function asTable(): array;
    public function asValue(string|int $column = 0): mixed;
    public function concealing(string|array ...$columns): static;
    public function execute(): void;
    public function limitedTo(?int $rows, ?int $skipping = null): static;
    public function unlimited(): static;
    public function matching(array|ConditionCollection ...$filters): static;
    public function skipping(int $rows): static;
    public function revealing(string|array ...$columns): static;
    public function selecting(string|array|SelectExpressionCollection $columns): static;
    public function sortedBy(array|OrderCollection ...$criteria): static;
    public function with(string ...$relations): static;
    public function without(string ...$relations): static;
}