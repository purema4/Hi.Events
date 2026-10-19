<?php

namespace HiEvents\Helper;

class AppVersion
{
    public static function get(): string
    {
        return trim(@file_get_contents(base_path('VERSION')) ?: 'unknown');
    }
}
