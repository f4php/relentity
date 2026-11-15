<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

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
 * Subclasses only decide which primary key names the key columns when they are omitted.
 */
abstract class AbstractToOne extends AbstractRelation implements RelationInterface {

    /**
     * Column name used for 'sourceKey' and 'targetKey' when they are omitted.
     */
    abstract protected function defaultKey(RelentityInterface $source): string;

    public function appendJoin(QueryBuilderInterface $query, RelentityInterface $source, string $alias): QueryBuilderInterface {
        if ($this->customJoinCallback) {
            return ($this->customJoinCallback)($query, $source, $alias);
        }
        // More than one row would duplicate source rows
        $target = $this->target::fetch()->limitedTo(1);
        if ($this->modifyTarget) {
            $target = ($this->modifyTarget)($target);
        }
        $sourceKey = $this->sourceKey ?? $this->defaultKey($source);
        $target->matching([
            new Fragment('{#::#}.{#::#} = {#::#}', [
                $query->escapeIdentifier($this->target::TABLE_NAME),
                $query->escapeIdentifier($this->targetKey ?? $this->defaultKey($source)),
                $this->buildSourceKeyReference($query, $source, $sourceKey),
            ]),
        ]);
        return $query
            ->leftJoinLateral([
                $alias => $this->buildJoinSubquery($query, $source, $sourceKey, $target, $this->buildRowsAsJsonb($query, $target)),
            ])
            ->on('true');
    }
}
