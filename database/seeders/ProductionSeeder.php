<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Models\User;
use App\Support\PhoneNumber;

/**
 * For the live site: the admin account (ADMIN_PHONE / ADMIN_NAME) and the product categories only.
 * No demo shops, products, orders or reviews. Safe to run again.
 */
class ProductionSeeder extends DatabaseSeeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['phone' => PhoneNumber::normalize(config('wibuy.admin.phone'))],
            ['name' => config('wibuy.admin.name'), 'is_admin' => true],
        );
        $admin->forceFill(['admin_role' => AdminRole::Owner])->save();

        $this->categories();

        $this->command?->info("Admin login: {$admin->display_phone}. Categories ready.");
    }
}
