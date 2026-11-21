<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\Relation\{Many, RelationInterface};

/**
 * A relation that depends on a property of the source, as in the README
 */
class PeriodEmployee extends BareEmployee
{
    public private(set) ?string $from = null;
    public private(set) ?string $to = null;

    public const array RELATIONS = [
        'timesheets' => static function (PeriodEmployee $employees): RelationInterface {
            $timesheets = Timesheet::fetch();
            if ($employees->from !== null && $employees->to !== null) {
                $timesheets->matching(
                    ['"date" >= {#}' => $employees->from],
                    ['"date" <= {#}' => $employees->to],
                );
            }
            return Many::of($timesheets);
        },
    ];

    public function inPeriod(string $from, string $to): static
    {
        $this->from = $from;
        $this->to = $to;
        return $this;
    }
}
