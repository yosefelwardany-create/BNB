<?php

use App\Providers\AppServiceProvider;
use App\Providers\DomainServiceProvider;
use App\Providers\IntegrationServiceProvider;

return [
    AppServiceProvider::class,
    DomainServiceProvider::class,
    IntegrationServiceProvider::class,
];
