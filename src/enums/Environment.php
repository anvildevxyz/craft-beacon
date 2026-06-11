<?php

namespace anvildev\beacon\enums;

enum Environment: string
{
    case Production = 'production';
    case Staging = 'staging';
    case Dev = 'dev';
}
