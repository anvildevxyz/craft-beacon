<?php

namespace anvildev\beacon\enums;

enum TrackingPlacement: string
{
    case Head = 'head';
    case BodyStart = 'bodyStart';
    case BodyEnd = 'bodyEnd';
}
