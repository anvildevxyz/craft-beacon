<?php

namespace anvildev\beacon\enums;

enum RedirectStatusCode: int
{
    case MovedPermanently = 301;
    case Found = 302;
    case TemporaryRedirect = 307;
    case PermanentRedirect = 308;
}
