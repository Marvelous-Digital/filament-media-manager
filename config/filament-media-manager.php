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

    // Database tables. Prefixed so they don't clash with spatie/laravel-medialibrary's `media` table.
    // Set these before running the migrations.
    'table_names' => [
        'media' => 'media_manager_media',
        'folders' => 'media_manager_folders',
        'field_values' => 'media_manager_field_values',
        'conversions' => 'media_manager_conversions',
    ],
];
