<?php

namespace Modules\Auth\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Auth\Models\User;

/**
 * Two accounts on purpose: the second is the canary for scope leaks. An
 * endpoint that shows one person's orders to the other fails visibly in the
 * demo data rather than in production.
 */
class DemoUserSeeder extends Seeder
{
    public const EMAIL = 'demo@order-os.test';

    public const OTHER_EMAIL = 'other@order-os.test';

    public const PASSWORD = 'password123';

    public function run(): void
    {
        foreach ([self::EMAIL => 'Demo User', self::OTHER_EMAIL => 'Other User'] as $email => $name) {
            User::query()->updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => self::PASSWORD,
                'email_verified_at' => now(),
            ]);
        }
    }
}
