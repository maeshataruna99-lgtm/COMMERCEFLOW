<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $permId = static fn (string $name): int => Permission::where('name', $name)->firstOrFail()->id;

        $menu = function (array $item, ?int $parentId = null): Menu {
            return Menu::updateOrCreate(
                ['name' => $item['name'], 'parent_id' => $parentId],
                ['route' => $item['route'] ?? null, 'sort' => $item['sort'] ?? 0],
            );
        };

        $dashboard = $menu(['name' => 'Dashboard', 'route' => '/', 'sort' => 1]);
        $products = $menu(['name' => 'Products', 'route' => '/products', 'sort' => 2]);
        $inventory = $menu(['name' => 'Inventory', 'route' => '/inventory', 'sort' => 3]);

        $stock = $menu(['name' => 'Stock', 'route' => '/inventory/stock', 'sort' => 1], $inventory->id);
        $reservations = $menu(['name' => 'Reservations', 'route' => '/inventory/reservations', 'sort' => 2], $inventory->id);
        $adjustments = $menu(['name' => 'Adjustments', 'route' => '/inventory/adjustments', 'sort' => 3], $inventory->id);
        $movements = $menu(['name' => 'Movements', 'route' => '/inventory/movements', 'sort' => 4], $inventory->id);

        $orders = $menu(['name' => 'Orders', 'route' => '/orders', 'sort' => 4]);
        $payments = $menu(['name' => 'Payments', 'route' => '/payments', 'sort' => 5]);
        $users = $menu(['name' => 'Users', 'route' => '/users', 'sort' => 6]);
        $roles = $menu(['name' => 'Roles', 'route' => '/roles', 'sort' => 7]);
        $permissions = $menu(['name' => 'Permissions', 'route' => '/permissions', 'sort' => 8]);
        $reports = $menu(['name' => 'Reports', 'route' => '/reports', 'sort' => 9]);

        $products->permissions()->sync([$permId('products.view')]);

        $inventory->permissions()->sync([$permId('inventory.view')]);
        $stock->permissions()->sync([$permId('inventory.view')]);
        $reservations->permissions()->sync([$permId('inventory.reserve')]);
        $adjustments->permissions()->sync([$permId('inventory.adjust')]);
        $movements->permissions()->sync([$permId('inventory.view')]);

        $orders->permissions()->sync([$permId('orders.view')]);
        $payments->permissions()->sync([$permId('orders.view')]);
        $users->permissions()->sync([$permId('users.manage')]);
        $roles->permissions()->sync([$permId('roles.manage')]);
        $permissions->permissions()->sync([$permId('permissions.manage')]);
        $reports->permissions()->sync([$permId('orders.view')]);

        $dashboard->permissions()->sync([]);
    }
}
