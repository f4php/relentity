<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

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
 * A single related row as a JSON object (or null).
 * For a "belongs to" relation set both keys, e.g. 'sourceKey' => 'ownerUUID', 'targetKey' => 'userUUID'.
 */
class OneToOne extends AbstractRelation implements RelationInterface {

    public function appendJoin(QueryBuilderInterface $query, RelentityInterface $source, string $alias): QueryBuilderInterface {
        if ($this->join) {
            return ($this->join)($query, $source, $alias);
        }
        // More than one row would duplicate source rows
        $target = $this->target::fetch()->limitedTo(1);
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
                $alias => DB::select(
                        new Fragment('to_jsonb({#::#}.*) AS {#::#}', [
                            $query->escapeIdentifier(static::ROWS_ALIAS),
                            $query->escapeIdentifier(static::VALUE_ALIAS),
                        ])
                    )
                    ->from([static::ROWS_ALIAS => $target->asQuery()]),
            ])
            ->on('true');
    }
}
