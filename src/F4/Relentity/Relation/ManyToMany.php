<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use Closure;
use InvalidArgumentException;

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

use function
    is_subclass_of
;

/**
 * Target rows linked through a link table, as a JSON list (or null), or their number with 'count' => true.
 * The link table is mandatory: 'through' is a table name or a Relentity class.
 */
class ManyToMany extends AbstractRelation implements RelationInterface {

    /**
     * @param class-string<RelentityInterface> $target
     * @param string|class-string<RelentityInterface> $through
     */
    public function __construct(
        string $target,
        protected string $through,
        ?string $sourceKey = null,
        ?string $targetKey = null,
        protected ?string $throughSourceKey = null,
        protected ?string $throughTargetKey = null,
        ?Closure $modifyTarget = null,
        protected ?Closure $modifyThrough = null,
        protected bool $count = false,
        ?Closure $customSelectCallback = null,
        ?Closure $customJoinCallback = null,
    ) {
        parent::__construct($target, $sourceKey, $targetKey, $modifyTarget, $customSelectCallback, $customJoinCallback);
        if ($modifyThrough && !is_subclass_of($through, RelentityInterface::class)) {
            throw new InvalidArgumentException('modifyThrough requires through to be a ' . RelentityInterface::class . ' class');
        }
    }

    public function appendJoin(QueryBuilderInterface $query, RelentityInterface $source, string $alias): QueryBuilderInterface {
        if ($this->customJoinCallback) {
            return ($this->customJoinCallback)($query, $source, $alias);
        }
        $throughIsEntity = is_subclass_of($this->through, RelentityInterface::class);
        $throughTable = match ($throughIsEntity) {
            true => $this->through::TABLE_NAME,
            default => $this->through,
        };
        $throughTargetKey = new Fragment('{#::#}.{#::#}', [
            $query->escapeIdentifier($throughTable),
            $query->escapeIdentifier($this->throughTargetKey ?? $this->target::PRIMARY_KEY),
        ]);
        $sourceKey = $this->sourceKey ?? $source::PRIMARY_KEY;
        $throughCondition = new Fragment('{#::#}.{#::#} = {#::#}', [
            $query->escapeIdentifier($throughTable),
            $query->escapeIdentifier($this->throughSourceKey ?? $source::PRIMARY_KEY),
            $this->buildSourceKeyReference($query, $source, $sourceKey),
        ]);
        if ($throughIsEntity) {
            // Every link counts, the link entity's DEFAULT_LIMIT does not apply
            $through = $this->through::fetch([$throughTargetKey])->limitedTo(null, 0);
            if ($this->modifyThrough) {
                $through = ($this->modifyThrough)($through);
            }
            $throughQuery = $through->matching([$throughCondition])->asQuery();
        } else {
            $throughQuery = DB::select($throughTargetKey)->from($throughTable)->where($throughCondition);
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
            new Fragment('{#::#}.{#::#} IN ({#::#})', [
                $query->escapeIdentifier($this->target::TABLE_NAME),
                $query->escapeIdentifier($this->targetKey ?? $this->target::PRIMARY_KEY),
                $throughQuery,
            ]),
        ]);
        return $query
            ->leftJoinLateral([
                $alias => $this->buildJoinSubquery($query, $source, $sourceKey, $target, match ($this->count) {
                    true => new Fragment('count(*)'),
                    default => new Fragment('jsonb_agg({#::#})', [$this->buildRowsAsJsonb($query, $target)]),
                }),
            ])
            ->on('true');
    }
}
