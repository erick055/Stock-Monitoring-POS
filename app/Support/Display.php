<?php

namespace App\Support;

class Display
{
    public static function money(float|int|string|null $amount): string
    {
        $value = round((float) str_replace(',', '', (string) $amount), 2);

        return ($value < 0 ? '-' : '').'₱'.number_format(abs($value), 2);
    }

    public static function count(float|int|string|null $amount, string $singular, ?string $plural = null, int $decimals = 0): string
    {
        $value = (float) str_replace(',', '', (string) $amount);

        return number_format($value, $decimals).' '.(abs($value) === 1.0 ? $singular : ($plural ?? $singular.'s'));
    }
}
