<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_permissions_derive_from_role_permissions(): void
    {
        $role = Role::create(['name' => 'admin']);
        $role->permissions()->attach(Permission::create(['name' => 'products.view']));
        Permission::create(['name' => 'products.delete']);

        $user = User::create([
            'name' => 'Alice',
            'email' => 'a@example.com',
            'password_hash' => 'secret',
        ]);
        $user->roles()->attach($role);

        $permissions = $user->roles->flatMap->permissions->pluck('name');

        $this->assertContains('products.view', $permissions);
        $this->assertNotContains('products.delete', $permissions);
    }

    public function test_role_has_permission_checks_permission_name(): void
    {
        $role = Role::create(['name' => 'admin']);
        $role->permissions()->attach(Permission::create(['name' => 'products.view']));

        $this->assertTrue($role->hasPermission('products.view'));
        $this->assertFalse($role->hasPermission('products.delete'));
    }

    public function test_users_email_is_unique(): void
    {
        User::create([
            'name' => 'Alice',
            'email' => 'a@example.com',
            'password_hash' => 'secret',
        ]);

        try {
            User::create([
                'name' => 'Bob',
                'email' => 'a@example.com',
                'password_hash' => 'secret',
            ]);
            $this->fail('Expected a unique constraint violation for duplicate email.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('23505', $e->getMessage());
        }
    }

    public function test_users_email_is_unique_case_insensitively(): void
    {
        User::create([
            'name' => 'Alice',
            'email' => 'a@example.com',
            'password_hash' => 'secret',
        ]);

        try {
            User::create([
                'name' => 'Bob',
                'email' => 'A@Example.COM',
                'password_hash' => 'secret',
            ]);
            $this->fail('Expected a unique constraint violation for case-variant email.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('23505', $e->getMessage());
        }
    }
}
