<?php

namespace App\Contracts;

use App\Enums\SerproCapabilityDriver;

interface ResolvesSerproCapabilityDriver
{
    public function forCapability(string $capability): SerproCapabilityDriver;
}
