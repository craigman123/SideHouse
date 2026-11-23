<?php

namespace App\Support;

final class LikePattern
{
    public static function contains(string $value): string
    {
        $escaped = str_replace(
            ['!', '\\', '%', '_'],
            ['!!', '!\\', '!%', '!_'],
            mb_strtolower(trim($value)),
        );

        return '%'.$escaped.'%';
    }
}
