<?php

return [
    /*
     * Load the till's migrations from the package (the tables of the PC till, unchanged). Turn off only when an app
     * publishes them (`php artisan vendor:publish --tag=sylloo-pos-core-migrations`).
     */
    'migrations' => true,

    /*
     * A fresh start (LocalDataReset) also removes these folders under storage_path(), e.g. a till's compiled code
     * cache. The till's own data, files and settings are always removed.
     */
    'reset' => [
        'storage_folders' => [],
    ],
];
