<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use Closure;

use F4\DB\{
    Fragment,
    QueryBuilderInterface,
    Concealment\ConcealmentPath,
};
use F4\Relentity\{
    RelentityInterface,
    Relation\RelationInterface,
};

use function
    count
;

/**
 * Constructor arguments are the options of a RELATIONS entry. Subclasses that add
 * options must repeat the shared ones in their constructor, so that named options resolve.
 */
abstract class AbstractRelation implements RelationInterface {

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
                // The joined subquery names its only column after the relation alias
                new Fragment('{#::#}.{#::#}', [
                    $query->escapeIdentifier($alias),
                    $query->escapeIdentifier($alias),
                ])
            ),
            default => ($this->customSelectCallback)($query, $source, $alias),
        };
    }

    /**
     * Related rows as JSON objects, without the keys the target conceals: the target is only
     * a subquery here, so concealment the query builder applies to executed queries never reaches it.
     * Must be called once the target is final, i.e. after modifyTarget.
     */
    protected function rowsAsJsonb(QueryBuilderInterface $query, RelentityInterface $target): Fragment {
        $keys = [];
        $paths = [];
        foreach ($target->getConcealedColumns() as $column) {
            $segments = ConcealmentPath::fromString($column)->getSegments();
            if (count($segments) === 1) {
                $keys[] = $segments[0];
            } else {
                $paths[] = $segments;
            }
        }
        $expression = 'to_jsonb({#::#}.*)';
        $parameters = [$query->escapeIdentifier(static::ROWS_ALIAS)];
        // The casts are required, bound parameters alone would leave the operators ambiguous
        if ($keys) {
            $expression .= ' - ARRAY[{#,...#}]::text[]';
            $parameters[] = $keys;
        }
        // Unlike a key, a path does not descend into JSON lists
        foreach ($paths as $segments) {
            $expression .= ' #- ARRAY[{#,...#}]::text[]';
            $parameters[] = $segments;
        }
        return new Fragment($expression, $parameters);
    }

    abstract public function appendJoin(QueryBuilderInterface $query, RelentityInterface $source, string $alias): QueryBuilderInterface;
}
