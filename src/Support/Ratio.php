<?php

namespace MarvelousDigital\FilamentMediaManager\Support;

class Ratio
{
    public static function reduce(int $width, int $height): string
    {
        if ($width <= 0 || $height <= 0) {
            return "{$width}:{$height}";
        }

        $divisor = self::greatestCommonDivisor($width, $height);

        return ($width / $divisor) . ':' . ($height / $divisor);
    }

    private static function greatestCommonDivisor(int $a, int $b): int
    {
        return $b === 0 ? $a : self::greatestCommonDivisor($b, $a % $b);
    }
}
