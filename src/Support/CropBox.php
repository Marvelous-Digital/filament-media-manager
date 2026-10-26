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
        float $zoom = 1.0,
    ): array {
        ['width' => $cropW, 'height' => $cropH] = self::coverSize($sourceW, $sourceH, $targetW, $targetH);

        // Zoom crops a smaller same-aspect region, keeping the cover fit (no letterboxing).
        $zoom = max(1.0, $zoom);
        $cropW = (int) round($cropW / $zoom);
        $cropH = (int) round($cropH / $zoom);

        $x = self::clamp((int) round($focusX * $sourceW - $cropW / 2), 0, $sourceW - $cropW);
        $y = self::clamp((int) round($focusY * $sourceH - $cropH / 2), 0, $sourceH - $cropH);

        return ['x' => $x, 'y' => $y, 'width' => $cropW, 'height' => $cropH];
    }

    /**
     * The largest region of the source that matches the target aspect ratio (the pre-zoom cover crop).
     *
     * @return array{width: int, height: int}
     */
    public static function coverSize(int $sourceW, int $sourceH, int $targetW, int $targetH): array
    {
        $targetAspect = $targetW / $targetH;
        $sourceAspect = $sourceW / $sourceH;

        if ($sourceAspect > $targetAspect) {
            // Source is wider than target: height-limited.
            return ['width' => (int) round($sourceH * $targetAspect), 'height' => $sourceH];
        }

        // Source is taller/narrower: width-limited.
        return ['width' => $sourceW, 'height' => (int) round($sourceW / $targetAspect)];
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
