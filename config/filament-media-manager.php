<?php

return [

    // Filesystem disk where originals and thumbnails are stored.
    'disk' => env('FILAMENT_MEDIA_MANAGER_DISK', 'public'),

    // Base directory on the disk. Each media item lives in {directory}/{ulid}/.
    'directory' => 'media',

    // Allowed mime types for upload. null accepts any file type.
    'accepted_mime_types' => null,

    // Maximum upload size in kilobytes.
    'max_file_size' => 51200,

    'thumbnail' => [
        'width' => 600,
        'format' => 'webp',
        'quality' => 70,
        // true: dispatch GenerateThumbnail to the queue; false: run synchronously during upload.
        'queue' => true,
        // Queue name to dispatch to (null = default queue).
        'queue_name' => null,
    ],

    // Grid items loaded per "Load more" chunk.
    'per_page' => 30,
];
