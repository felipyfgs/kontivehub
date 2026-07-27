<?php

namespace Database\Seeders;

use Database\Seeders\Development\DevelopmentCertificateSeeder;
use Database\Seeders\Development\DevelopmentSeeder;
use Database\Seeders\Testing\TestingBaselineSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use LogicException;

final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('DatabaseSeeder permitido somente em local/testing.');
        }

        $this->call(ReferenceDataSeeder::class);

        if (app()->environment('testing')) {
            $this->call(TestingBaselineSeeder::class);

            return;
        }

        $this->call([
            DevelopmentSeeder::class,
            DevelopmentCertificateSeeder::class,
        ]);
    }
}
