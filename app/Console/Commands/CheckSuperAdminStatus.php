<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CheckSuperAdminStatus extends Command
{
    protected $signature = 'auth:check-super-admin';
    protected $description = 'Check super admin user roles and permissions';

    public function handle()
    {
        $superAdmin = User::where('email', 'superadmin@example.com')->first();

        if (!$superAdmin) {
            $this->error('Super admin user not found!');
            return 1;
        }

        $this->info('=== Super Admin User Info ===');
        $this->line("User ID: {$superAdmin->id}");
        $this->line("Email: {$superAdmin->email}");
        $this->line("Tenant ID: " . ($superAdmin->tenant_id ?? 'NULL (Platform-level)'));
        $this->line("Guard: web (Filament uses web guard)");
        $this->line("");

        $this->info('=== Roles ===');
        $roles = $superAdmin->getRoleNames();
        foreach ($roles as $role) {
            $this->line("- {$role}");
        }
        $this->line("");

        $this->info('=== Permissions ===');
        $permissions = $superAdmin->getAllPermissions();
        if ($permissions->isEmpty()) {
            $this->line("No permissions assigned!");
        } else {
            foreach ($permissions as $permission) {
                $this->line("- {$permission->name}");
            }
        }

        return 0;
    }
}
