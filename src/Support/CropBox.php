<?php

namespace MarvelousDigital\FilamentMediaManager\Support;

class CropBox
{
    /**
     * @return array{x: int, y: int, width: int, height: int}
     */
    public static function for(
        int $sourceW,
        int $sourceH,
        int $targetW,
        int $targetH,
        float $focusX,
        float $focusY,
    ): array {
        $targetAspect = $targetW / $targetH;
        $sourceAspect = $sourceW / $sourceH;

        if ($sourceAspect > $targetAspect) {
            // Source is wider than target: height-limited.
            $cropH = $sourceH;
            $cropW = (int) round($sourceH * $targetAspect);
        } else {
            // Source is taller/narrower: width-limited.
            $cropW = $sourceW;
            $cropH = (int) round($sourceW / $targetAspect);
        }

        $x = self::clamp((int) round($focusX * $sourceW - $cropW / 2), 0, $sourceW - $cropW);
        $y = self::clamp((int) round($focusY * $sourceH - $cropH / 2), 0, $sourceH - $cropH);

        return ['x' => $x, 'y' => $y, 'width' => $cropW, 'height' => $cropH];
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
