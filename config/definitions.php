<?php

use App\Services\MediaProcessingService;

return [
    MediaProcessingService::class => function ($container) {
        return new MediaProcessingService(
            s3_default_region: getenv('AWS_DEFAULT_REGION') ?: 'us-east-1',
            s3_access_key_id: getenv('AWS_ACCESS_KEY_ID'),
            s3_secret_access_key: getenv('AWS_SECRET_ACCESS_KEY')
        );
    },
];
