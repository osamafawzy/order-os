<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Orders\Database\Seeders\DemoOrdersSeeder;
use Modules\Payments\Database\Seeders\DemoPaymentsSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoOrdersSeeder::class,
            DemoPaymentsSeeder::class,
        ]);
    }
}
