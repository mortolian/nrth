<?php

return [

    /*
     * Financial documents (invoice PDFs, receipts) must not live on the
     * public disk. Logos stay on the `public` collection disk in registerMediaCollections().
     */
    'disk_name' => env('MEDIA_DISK', 'local'),

];
