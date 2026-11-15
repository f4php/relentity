<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use Closure;

use F4\DB\{
    Fragment,
    QueryBuilderInterface,
};
use F4\Relentity\{
    RelentityInterface,
    Relation\RelationInterface,
};

/**
 * Constructor arguments are the options of a RELATIONS entry. Subclasses that add
 * options must repeat the shared ones in their constructor, so that named options resolve.
 */
abstract class AbstractRelation implements RelationInterface {

    protected const string VALUE_ALIAS = 'relation_value';
    protected const string ROWS_ALIAS = 'relation_rows';

    /**
     * @param class-string<RelentityInterface> $target
     */
    public function __construct(
        protected string $target,
        protected ?string $sourceKey = null,
        protected ?string $targetKey = null,
        protected ?Closure $modifyTarget = null,
        protected ?Closure $customSelectCallback = null,
        protected ?Closure $customJoinCallback = null,
    ) {}

    public function appendSelect(QueryBuilderInterface $query, RelentityInterface $source, string $alias): QueryBuilderInterface {
        return match ($this->customSelectCallback) {
            null => $query->select(
                new Fragment('{#::#}.{#::#} AS {#::#}', [
                    $query->escapeIdentifier($alias),
                    $query->escapeIdentifier(static::VALUE_ALIAS),
                    $query->escapeIdentifier($alias),
                ])
            ),
            default => ($this->customSelectCallback)($query, $source, $alias),
        };
    }

    abstract public function appendJoin(QueryBuilderInterface $query, RelentityInterface $source, string $alias): QueryBuilderInterface;
}
