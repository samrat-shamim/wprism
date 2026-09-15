<?php
declare(strict_types=1);

// Run through wp eval-file on an owned pair. Native geometry backs the generic
// source-area budget premise; the offline materializer suite pins admission.
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
foreach (['image_resize_dimensions', 'wp_constrain_dimensions'] as $hook) {
    if (has_filter($hook)) throw new RuntimeException('native geometry requires closed resize filters');
}
$cases = 0;
foreach ([[1, 1], [16384, 1], [1, 16384], [2048, 2048]] as [$sourceWidth, $sourceHeight]) {
    foreach ([[16384, 16384], [800, 9999], [9999, 800], [0, 16384], [16384, 0], [1024, 1024]] as [$width, $height]) {
        foreach ([false, true, ['left', 'top'], ['right', 'bottom']] as $crop) {
            $geometry = image_resize_dimensions($sourceWidth, $sourceHeight, $width, $height, $crop);
            if ($geometry !== false) {
                [$outputWidth, $outputHeight] = [$geometry[4], $geometry[5]];
                if ($outputWidth < 1 || $outputHeight < 1
                    || $outputWidth * $outputHeight > $sourceWidth * $sourceHeight
                    || ($width > 0 && $outputWidth > $width)
                    || ($height > 0 && $outputHeight > $height)) {
                    throw new RuntimeException('native Core geometry exceeds its source or requested dimensions');
                }
            }
            $cases++;
        }
    }
}
echo json_encode(['native_geometry_cases' => $cases], JSON_THROW_ON_ERROR), "\n";
