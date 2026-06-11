<?php

namespace anvildev\beacon\tests\unit\helpers;

use anvildev\beacon\helpers\Json;
use PHPUnit\Framework\TestCase;

class JsonTest extends TestCase
{
    public function testDecodeStringList(): void
    {
        $this->assertSame(['/blog/', '/news/'], Json::decodeStringList('["/blog/","/news/"]'));
    }

    public function testDecodeStringListHandlesInvalid(): void
    {
        $this->assertSame([], Json::decodeStringList('not json'));
        $this->assertSame([], Json::decodeStringList(null));
        $this->assertSame([], Json::decodeStringList(''));
    }

    public function testDecodeStringListFiltersNonStrings(): void
    {
        $this->assertSame(['/blog/'], Json::decodeStringList('["/blog/", 42, true, null]'));
    }

    public function testEncodeProducesJson(): void
    {
        $this->assertSame('{"a":1}', Json::encode(['a' => 1]));
    }

    public function testEncodeHonoursFlags(): void
    {
        $this->assertSame('"a/b"', Json::encode('a/b', JSON_UNESCAPED_SLASHES));
        // Default flags (0) leave the slash escaped, matching raw json_encode().
        $this->assertSame('"a\/b"', Json::encode('a/b'));
    }

    public function testEncodeThrowsOnFailure(): void
    {
        // NAN cannot be represented in JSON; JSON_THROW_ON_ERROR turns the
        // silent `false` into an exception.
        $this->expectException(\JsonException::class);
        Json::encode(NAN);
    }

    public function testDecodeAssocFromJsonObjectAndArray(): void
    {
        $this->assertSame(['a' => 1], Json::decodeAssoc('{"a":1}'));
        $this->assertSame([1, 2], Json::decodeAssoc('[1,2]'));
    }

    public function testDecodeAssocPassesThroughArray(): void
    {
        $this->assertSame(['x' => 1], Json::decodeAssoc(['x' => 1]));
    }

    public function testDecodeAssocReturnsNullForScalarsNullAndInvalid(): void
    {
        $this->assertNull(Json::decodeAssoc('"foo"'));
        $this->assertNull(Json::decodeAssoc('42'));
        $this->assertNull(Json::decodeAssoc('not json'));
        $this->assertNull(Json::decodeAssoc(''));
        $this->assertNull(Json::decodeAssoc(null));
        $this->assertNull(Json::decodeAssoc(42));
    }
}
