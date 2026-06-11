<?php

namespace anvildev\beacon\enums;

enum RedirectType: string
{
    case Exact = 'exact';
    case Glob = 'glob';
    case Regex = 'regex';
}
