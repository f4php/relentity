<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use F4\Relentity\{
    Relation\AbstractRelation,
    Relation\RelationInterface,
};

use F4\DB\{
    Fragment,
    QueryBuilderInterface,
};

class OneToMany extends AbstractRelation implements RelationInterface {
    public function appendJoin(QueryBuilderInterface $query): QueryBuilderInterface {
        return match($this->joinCallback) {
            // A simple-identifier key is delimited by the builder itself: ({subquery}) AS "alias"
            null => $query->leftJoinLateral([
                $this->targetAlias =>
                    $this->target->asQuery([
                        new Fragment('jsonb_agg(to_jsonb({#::#}.*)) AS {#::#}', [
                            $query->escapeIdentifier($this->target::TABLE_NAME),
                            $query->escapeIdentifier(static::DEFAULT_JSONB_ALIAS),
                        ]),
                    ])
                    ->where(
                        new Fragment('{#::#}.{#::#} = {#::#}.{#::#}', [
                            $query->escapeIdentifier($this->source::TABLE_NAME),
                            $query->escapeIdentifier($this->source::PRIMARY_KEY),
                            $query->escapeIdentifier($this->target::TABLE_NAME),
                            $query->escapeIdentifier($this->source::PRIMARY_KEY),
                        ]),
                    ),
                ])
                ->on('true'),
            default => ($this->joinCallback)($query),
        };
    }
}