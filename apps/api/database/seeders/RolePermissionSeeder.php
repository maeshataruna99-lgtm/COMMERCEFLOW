<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'products.view',
            'products.create',
            'products.update',
            'products.delete',
            'inventory.view',
            'inventory.adjust',
            'inventory.reserve',
            'orders.view',
            'orders.create',
            'orders.cancel',
            'orders.fulfill',
            'users.manage',
            'roles.manage',
            'permissions.manage',
        ];

        foreach ($permissions as $name) {
            Permission::updateOrCreate(['name' => $name]);
        }

        $permissionByName = Permission::pluck('id', 'name');

        $admin = Role::updateOrCreate(
            ['name' => 'admin'],
            ['description' => 'Full system administrator'],
        );
        $admin->permissions()->sync($permissionByName->all());

        $warehouse = Role::updateOrCreate(
            ['name' => 'warehouse'],
            ['description' => 'Warehouse and fulfillment staff'],
        );
        $warehouse->permissions()->sync([
            $permissionByName['products.view'],
            $permissionByName['inventory.view'],
            $permissionByName['inventory.adjust'],
            $permissionByName['inventory.reserve'],
            $permissionByName['orders.view'],
            $permissionByName['orders.fulfill'],
        ]);

        $customer = Role::updateOrCreate(
            ['name' => 'customer'],
            ['description' => 'Store customer with catalog browsing access'],
        );
        $customer->permissions()->sync([
            $permissionByName['products.view'],
        ]);
    }
}
