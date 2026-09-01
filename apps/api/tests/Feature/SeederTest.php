<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_creates_roles_with_permissions(): void
    {
        $this->seed();

        $roles = Role::pluck('name')->all();
        $this->assertContains('admin', $roles);
        $this->assertContains('warehouse', $roles);
        $this->assertContains('customer', $roles);

        $admin = Role::where('name', 'admin')->first();
        $this->assertTrue($admin->hasPermission('products.view'));
        $this->assertTrue($admin->hasPermission('products.create'));
        $this->assertTrue($admin->hasPermission('inventory.view'));
        $this->assertTrue($admin->hasPermission('orders.view'));
        $this->assertTrue($admin->hasPermission('users.manage'));

        $warehouse = Role::where('name', 'warehouse')->first();
        $this->assertTrue($warehouse->hasPermission('inventory.view'));
        $this->assertTrue($warehouse->hasPermission('orders.view'));
        $this->assertFalse($warehouse->hasPermission('users.manage'));
    }

    public function test_seeding_creates_hierarchical_menu_tree(): void
    {
        $this->seed();

        $names = Menu::pluck('name')->all();
        $this->assertContains('Dashboard', $names);
        $this->assertContains('Products', $names);
        $this->assertContains('Inventory', $names);
        $this->assertContains('Orders', $names);
        $this->assertContains('Payments', $names);
        $this->assertContains('Users', $names);
        $this->assertContains('Roles', $names);
        $this->assertContains('Permissions', $names);
        $this->assertContains('Reports', $names);

        $inventory = Menu::where('name', 'Inventory')->first();
        $children = $inventory->children->pluck('name')->all();
        $this->assertContains('Stock', $children);
        $this->assertContains('Reservations', $children);
        $this->assertContains('Adjustments', $children);
        $this->assertContains('Movements', $children);
    }

    public function test_seeding_links_menu_items_to_permissions(): void
    {
        $this->seed();

        $products = Menu::where('name', 'Products')->first();
        $this->assertTrue($products->permissions()->where('name', 'products.view')->exists());

        $reservations = Menu::where('name', 'Reservations')->first();
        $this->assertTrue($reservations->permissions()->where('name', 'inventory.reserve')->exists());
    }

    public function test_seeding_is_idempotent(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(3, Role::count());
        $this->assertSame(14, Permission::count());
        $this->assertSame(13, Menu::count());
    }
}
