<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\Relation\{Many, RelationInterface};

/**
 * A relation that depends on a property of the source, as in the README
 */
class PeriodEmployee extends BareEmployee
{
    public ?array $period = null;

    public const array RELATIONS = [
        'timesheets' => static function (PeriodEmployee $employees): RelationInterface {
            $timesheets = Timesheet::fetch();
            if ($employees->period !== null) {
                $timesheets->matching(['"date" BETWEEN {#} AND {#}' => $employees->period]);
            }
            return Many::of($timesheets);
        },
    ];

    public function inPeriod(string $from, string $to): static
    {
        $this->period = [$from, $to];
        return $this;
    }
}
