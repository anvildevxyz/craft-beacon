<?php

namespace anvildev\beacon\tests\unit\Tracking;

use anvildev\beacon\enums\Environment;
use anvildev\beacon\services\EnvironmentMapper;
use PHPUnit\Framework\TestCase;

final class EnvironmentMapperTest extends TestCase
{
    /**
     * @dataProvider canonicalEnvCases
     */
    public function testCanonicalize(string $input, Environment $expected): void
    {
        $this->assertSame($expected, EnvironmentMapper::canonicalize($input));
    }

    /** @return array<int, array{0:string, 1:Environment}> */
    public static function canonicalEnvCases(): array
    {
        return [
            ['production', Environment::Production],
            ['live', Environment::Production],
            ['prod', Environment::Production],
            ['staging', Environment::Staging],
            ['stage', Environment::Staging],
            ['preprod', Environment::Staging],
            ['test', Environment::Staging],
            ['dev', Environment::Dev],
            ['development', Environment::Dev],
            ['local', Environment::Dev],
            ['weird-name', Environment::Production],
            ['', Environment::Production],
            ['Production', Environment::Production],
        ];
    }
}
