<?php

namespace App\Support;

class BuildTimeHosts
{
    /** @return list<string> */
    public static function normalize(array $hosts): array
    {
        if (count($hosts) > 128) {
            throw new \InvalidArgumentException('At most 128 build-time hosts may be configured.');
        }
        foreach ($hosts as $host) {
            if (! is_string($host) || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,252}\z/', $host)) {
                throw new \InvalidArgumentException('Build-time hosts must be container names, without ports, addresses or shell syntax.');
            }
        }
        $hosts = array_values(array_unique($hosts));
        sort($hosts, SORT_STRING);

        return $hosts;
    }

    /** @return list<string> */
    public static function parse(string $text): array
    {
        return self::normalize(array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $text)), fn ($name) => $name !== '')));
    }
}
