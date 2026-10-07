<?php

namespace Jothamlec\OffsiteBackup\Support;

class SystemUser
{
    public function current(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());

            if (is_array($info)) {
                return $info['name'];
            }
        }

        return get_current_user() ?: 'unknown';
    }

    public static function ownerName(int $uid): string
    {
        if (function_exists('posix_getpwuid')) {
            $info = posix_getpwuid($uid);

            if (is_array($info)) {
                return $info['name'];
            }
        }

        return (string) $uid;
    }
}
