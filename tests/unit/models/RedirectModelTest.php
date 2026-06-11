<?php

namespace anvildev\beacon\tests\unit\models;

use anvildev\beacon\enums\RedirectType;
use anvildev\beacon\models\ImportResult;
use anvildev\beacon\models\Redirect;
use PHPUnit\Framework\TestCase;

class RedirectModelTest extends TestCase
{
    public function testRedirectHoldsResolvedTarget(): void
    {
        $r = new Redirect(
            id: 1,
            siteId: 1,
            sourceUri: '/old',
            targetUri: '/new',
            statusCode: 301,
            type: RedirectType::Exact->value,
            resolvedTarget: '/new',
        );
        $this->assertSame('/new', $r->resolvedTarget);
        $this->assertSame(301, $r->statusCode);
    }

    public function testImportResultTracksCounts(): void
    {
        $result = new ImportResult(insertedCount: 47, skippedCount: 3, errors: [
            ['lineNumber' => 12, 'reason' => 'invalid statusCode'],
        ]);
        $this->assertSame(47, $result->insertedCount);
        $this->assertSame(3, $result->skippedCount);
        $this->assertCount(1, $result->errors);
        $this->assertSame(12, $result->errors[0]['lineNumber']);
    }
}
