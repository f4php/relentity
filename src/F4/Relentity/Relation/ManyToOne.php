<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use RuntimeException;

use F4\DB;
use F4\DB\{
    Fragment,
    QueryBuilderInterface,
};

use F4\Relentity\{
    Relation\AbstractRelation,
    Relation\RelationInterface,
};

class ManyToOne extends AbstractRelation implements RelationInterface {

    public function appendJoinExpression(QueryBuilderInterface $query, string $alias): QueryBuilderInterface {
        if(empty($this->source)) {
            throw new RuntimeException('Cannot determine source relentity, a withSource() call on relation instance is required');
        }
        $target = clone ($this->target);
        return $query
            ->leftJoinLateral(
                new Fragment('({#::#}) AS {#::#}', [
                    DB::select(new Fragment('to_jsonb({#::#}.*) AS {#::#}', [
                        $query->escapeIdentifier(self::SUBQUERY_ALIAS),
                        $query->escapeIdentifier(static::RELATION_ROWS_ALIAS),
                    ]))
                        ->from([
                            self::SUBQUERY_ALIAS => $target
                                ->matching([
                                    // TODO: complex and custom join statements
                                    new Fragment('{#::#}.{#::#} = {#::#}.{#::#}', [
                                        $query->escapeIdentifier($this->source->getTableName()),
                                        $query->escapeIdentifier($this->sourceKey ?? $target->getPrimaryKey()),
                                        $query->escapeIdentifier($target->getTableName()),
                                        $query->escapeIdentifier($this->targetKey ?? $target->getPrimaryKey()),
                                    ])
                                ])
                                ->limitedTo(1)
                                ->asQuery(),
                        ]),
                    $query->escapeIdentifier($alias),
                ])
            )
            ->on('true');
    }
}
