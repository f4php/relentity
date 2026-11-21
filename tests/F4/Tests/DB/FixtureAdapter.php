<?php

declare(strict_types=1);

namespace F4\Tests\DB;

use F4\DB\Adapter\AdapterInterface;
use F4\DB\PreparedStatement;

/**
 * Escapes like MockAdapter, records every executed statement and returns preset rows,
 * so that tests can observe what happens to a result after it leaves the adapter.
 */
final class FixtureAdapter implements AdapterInterface
{
    /** @var list<array<string, mixed>> */
    public static array $rows = [];
    /** @var list<PreparedStatement> */
    public static array $statements = [];

    private MockAdapter $escaper;

    public function __construct()
    {
        $this->escaper = new MockAdapter();
    }
    public static function reset(array $rows = []): void
    {
        self::$rows = $rows;
        self::$statements = [];
    }
    public function execute(PreparedStatement $statement, ?int $stopAfter = null): mixed
    {
        self::$statements[] = $statement;
        return match ($stopAfter) {
            null => self::$rows,
            default => array_slice(self::$rows, 0, $stopAfter),
        };
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
        return $this->escaper->getEscapedIdentifier($value);
    }
    public function getEscapedValue(mixed $value): string
    {
        return $this->escaper->getEscapedValue($value);
    }
}
