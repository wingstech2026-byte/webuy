<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Database\Seeder;
use RuntimeException;

class ProductionAdminSeeder extends Seeder
{
    public function run(): void
    {
        $phone = PhoneNumber::normalize(config('wibuy.admin.phone'));

        if ($phone === null || $phone === PhoneNumber::normalize('076000000')) {
            throw new RuntimeException('Set ADMIN_PHONE to the owner account phone number before seeding.');
        }

        $admin = User::firstOrNew(['phone' => $phone]);

        if ($admin->exists) {
            if (! $admin->is_admin || $admin->admin_role !== AdminRole::Owner) {
                throw new RuntimeException('The configured phone already belongs to another account.');
            }

            return;
        }

        $admin->forceFill([
            'name' => config('wibuy.admin.name'),
            'is_admin' => true,
            'admin_role' => AdminRole::Owner,
        ])->save();
    }
}
