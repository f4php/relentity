<?php

declare(strict_types=1);

namespace F4\Tests\DB;

use F4\DB\Adapter\AdapterInterface;
use F4\DB\PreparedStatement;

/**
 * Quotes identifiers with backticks, to show which adapter rendered a statement
 */
final class BacktickAdapter implements AdapterInterface
{
    private MockAdapter $escaper;

    public function __construct()
    {
        $this->escaper = new MockAdapter();
    }
    public function execute(PreparedStatement $statement, ?int $stopAfter = null): mixed
    {
        return [];
    }
    public function discardConnection(): void {}
    public function enumerateParameters(int $index): string
    {
        return $this->escaper->enumerateParameters($index);
    }
    public function getEscapedBinary(string $value): string
    {
        return $this->escaper->getEscapedBinary($value);
    }
    public function getEscapedIdentifier(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }
    public function getEscapedValue(mixed $value): string
    {
        return $this->escaper->getEscapedValue($value);
    }
}
