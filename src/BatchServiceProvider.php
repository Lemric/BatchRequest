<?php

namespace Lemric\BatchRequest;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Lemric\BatchRequest\Http\BatchRequest;

class BatchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::post('batch.request', function (Request $request) {
            return (new BatchRequest())->handle($request);
        });
    }
}
