<?php

return [

    'paths' => [
        resource_path('views'),
    ],

    /*
    | Do not use realpath() here: it returns false when the directory does not
    | exist yet (e.g. Docker image build), which breaks the Blade compiler.
    */

    'compiled' => env('VIEW_COMPILED_PATH', storage_path('framework/views')),

];
