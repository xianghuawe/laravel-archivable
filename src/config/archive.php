<?php

return [
    'default_chunk_size' => 1000,

    'db' => env('ARCHIVE_DB_CONNECTION', 'archive'),

    'model_paths' => [
        app_path('Models'),
    ],
];
