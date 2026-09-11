<?php

declare(strict_types=1);

namespace App\Support;

class TrafficHelper
{
    public static function trafficConvert(int $byte): string|int
    {
        $kb = 1024;
        $mb = 1048576;
        $gb = 1073741824;
        if ($byte > $gb) {
            return round($byte / $gb, 2) . ' GB';
        } elseif ($byte > $mb) {
            return round($byte / $mb, 2) . ' MB';
        } elseif ($byte > $kb) {
            return round($byte / $kb, 2) . ' KB';
        } elseif ($byte < 0) {
            return 0;
        }

        return round($byte, 2) . ' B';
    }
}
