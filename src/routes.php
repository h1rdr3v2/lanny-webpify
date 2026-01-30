<?php

use App\Controllers\MediaProcessingController;

$app->any('/mediatowebp', [MediaProcessingController::class, 'mediaToWebp']);
$app->any('/webptomedia', [MediaProcessingController::class, 'webpToMedia']);
