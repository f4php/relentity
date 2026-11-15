<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use Closure;

use F4\DB;
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

    /**
     * Name of the only column of the joined subquery, selected under the relation alias.
     */
    protected const string ROWS_COLUMN = 'relation_rows';

    /**
     * A target stored in the source table shadows the source inside its own subquery, so the
     * source key is exposed to it under these names instead, see buildJoinSubquery().
     */
    protected const string SOURCE_KEY_ALIAS = 'relation_source';
    protected const string SOURCE_KEY_COLUMN = 'key';

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
                    $query->escapeIdentifier(static::ROWS_COLUMN),
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
    protected function buildRowsAsJsonb(QueryBuilderInterface $query, RelentityInterface $target): Fragment {
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
        // The subquery of target rows is aliased by the target table name
        $parameters = [$query->escapeIdentifier($target::TABLE_NAME)];
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

    protected function isSelfReferencing(RelentityInterface $source): bool {
        return $this->target::TABLE_NAME === $source::TABLE_NAME;
    }

    /**
     * The source key column, as the target query must refer to it.
     */
    protected function buildSourceKeyReference(QueryBuilderInterface $query, RelentityInterface $source, string $sourceKey): Fragment {
        return new Fragment('{#::#}.{#::#}', match ($this->isSelfReferencing($source)) {
            true => [
                $query->escapeIdentifier(static::SOURCE_KEY_ALIAS),
                $query->escapeIdentifier(static::SOURCE_KEY_COLUMN),
            ],
            default => [
                $query->escapeIdentifier($source::TABLE_NAME),
                $query->escapeIdentifier($sourceKey),
            ],
        });
    }

    /**
     * The joined subquery: $expression selected from the target rows, which are aliased by the target table name.
     * The target query must be correlated through buildSourceKeyReference() of the same $sourceKey.
     */
    protected function buildJoinSubquery(
        QueryBuilderInterface $query,
        RelentityInterface $source,
        string $sourceKey,
        RelentityInterface $target,
        Fragment $expression,
    ): QueryBuilderInterface {
        $select = DB::select(new Fragment('{#::#} AS {#::#}', [
            $expression,
            $query->escapeIdentifier(static::ROWS_COLUMN),
        ]));
        $rows = [$this->target::TABLE_NAME => $target->asQuery()];
        if (!$this->isSelfReferencing($source)) {
            return $select->from($rows);
        }
        // Selected outside of the target query, where the table name still refers to the source
        return $select
            ->from([
                static::SOURCE_KEY_ALIAS => DB::select(new Fragment('{#::#}.{#::#} AS {#::#}', [
                    $query->escapeIdentifier($source::TABLE_NAME),
                    $query->escapeIdentifier($sourceKey),
                    $query->escapeIdentifier(static::SOURCE_KEY_COLUMN),
                ])),
            ])
            ->crossJoinLateral($rows);
    }

    abstract public function appendJoin(QueryBuilderInterface $query, RelentityInterface $source, string $alias): QueryBuilderInterface;
}
