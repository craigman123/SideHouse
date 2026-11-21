<?php

namespace App\Support;

final class Mask
{
    public static function email(?string $email): ?string
    {
        if ($email === null || $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);
        $prefix = mb_strlen($local) <= 3
            ? mb_substr($local, 0, 1)
            : mb_substr($local, 0, 3);

        return $prefix.'******@'.$domain;
    }
}
