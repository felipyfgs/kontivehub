<?php

use App\Providers\ApiRateLimitServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    ApiRateLimitServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
];
