<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use Closure;

use F4\DB;
use F4\DB\{
    Fragment,
    QueryBuilderInterface,
};
use F4\Relentity\{
    RelentityInterface,
    Relation\AbstractRelation,
    Relation\RelationInterface,
};

/**
 * Related rows as a JSON list (or null when there are none), or their number with 'count' => true.
 */
class OneToMany extends AbstractRelation implements RelationInterface {

    /**
     * @param class-string<RelentityInterface> $target
     */
    public function __construct(
        string $target,
        ?string $sourceKey = null,
        ?string $targetKey = null,
        ?Closure $modifyTarget = null,
        protected bool $count = false,
        ?Closure $customSelectCallback = null,
        ?Closure $customJoinCallback = null,
    ) {
        parent::__construct($target, $sourceKey, $targetKey, $modifyTarget, $customSelectCallback, $customJoinCallback);
    }

    public function appendJoin(QueryBuilderInterface $query, RelentityInterface $source, string $alias): QueryBuilderInterface {
        if ($this->customJoinCallback) {
            return ($this->customJoinCallback)($query, $source, $alias);
        }
        $target = $this->target::fetch();
        if ($this->count) {
            // The target's DEFAULT_LIMIT must not cap a count
            $target->limitedTo(null, 0);
        }
        if ($this->modifyTarget) {
            $target = ($this->modifyTarget)($target);
        }
        $target->matching([
            new Fragment('{#::#}.{#::#} = {#::#}.{#::#}', [
                $query->escapeIdentifier($this->target::TABLE_NAME),
                $query->escapeIdentifier($this->targetKey ?? $source::PRIMARY_KEY),
                $query->escapeIdentifier($source::TABLE_NAME),
                $query->escapeIdentifier($this->sourceKey ?? $source::PRIMARY_KEY),
            ]),
        ]);
        return $query
            ->leftJoinLateral([
                $alias => DB::select(match ($this->count) {
                        true => new Fragment('count(*) AS {#::#}', [
                            $query->escapeIdentifier($alias),
                        ]),
                        default => new Fragment('jsonb_agg(to_jsonb({#::#}.*)) AS {#::#}', [
                            $query->escapeIdentifier(static::ROWS_ALIAS),
                            $query->escapeIdentifier($alias),
                        ]),
                    })
                    ->from([static::ROWS_ALIAS => $target->asQuery()]),
            ])
            ->on('true');
    }
}
