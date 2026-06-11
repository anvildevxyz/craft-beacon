<?php

namespace anvildev\beacon\models;

use craft\base\Model;

/**
 * @phpstan-type SchemaConfig array{type:string, mapping?:array<string,string>, when?:string}
 */
class SchemaBundle extends Model
{
    public string $entryTypeHandle = '';

    /** @var list<SchemaConfig> */
    public array $schemas = [];
}
