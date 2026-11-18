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

        /*
         * Tables a fresh start leaves as they are (`migrations` always stays). The Android till adds its device store
         * (`phone_settings`), which holds the activation.
         */
        'kept_tables' => ['migrations'],
    ],

    /*
     * The queue each kind of work goes on, and the workers the till starts and stops. The PC till runs a worker per
     * queue; a till with one worker (NativePHP Mobile runs only the default queue) maps every name to `default`.
     */
    'queues' => [
        'push' => 'push',
        'pull' => 'pull',
        'images' => 'images',
        'default' => 'default',
    ],
];
