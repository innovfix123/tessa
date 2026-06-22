<?php

namespace App\Helpers;

use Carbon\Carbon;

class DateHelper
{
    private const TZ = 'Asia/Kolkata';

    public static function now(): Carbon
    {
        return Carbon::now(self::TZ);
    }

    public static function today(): Carbon
    {
        return Carbon::today(self::TZ);
    }

    public static function parse(string $date): Carbon
    {
        return Carbon::parse($date, self::TZ);
    }
}
