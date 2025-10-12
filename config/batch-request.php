<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum Batch Size
    |--------------------------------------------------------------------------
    |
    | This value determines the maximum number of requests that can be
    | included in a single batch request. Adjust this value based on
    | your application's requirements and server resources.
    |
    */
    'max_batch_size' => env('BATCH_REQUEST_MAX_SIZE', 50),
];

