<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\JetstreamServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    JetstreamServiceProvider::class,
];
