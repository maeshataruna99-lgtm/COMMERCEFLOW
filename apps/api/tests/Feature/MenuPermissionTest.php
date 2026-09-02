<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_tree_supports_parent_child_hierarchy(): void
    {
        $inventory = Menu::create(['name' => 'Inventory', 'sort' => 1]);
        Menu::create(['name' => 'Stock', 'parent_id' => $inventory->id, 'sort' => 1]);

        $rootMenus = Menu::whereNull('parent_id')->with('children')->get();

        $this->assertCount(1, $rootMenus);
        $this->assertSame('Inventory', $rootMenus->first()->name);
        $this->assertCount(1, $rootMenus->first()->children);
        $this->assertSame('Stock', $rootMenus->first()->children->first()->name);
    }

    public function test_menu_item_requiring_permission_is_not_in_tree_for_user_without_it(): void
    {
        $permission = Permission::create(['name' => 'products.view']);
        $products = Menu::create(['name' => 'Products']);
        $products->permissions()->attach($permission);
        Menu::create(['name' => 'Dashboard']);

        $user = $this->makeUser();

        $visibleMenus = Menu::with('children')->get()
            ->filter(fn (Menu $menu) => $menu->visibleTo($user));

        $this->assertTrue($visibleMenus->contains('name', 'Dashboard'));
        $this->assertFalse($visibleMenus->contains('name', 'Products'));
    }

    public function test_menu_without_required_permissions_is_visible_to_anyone(): void
    {
        $menu = Menu::create(['name' => 'Dashboard']);

        $this->assertTrue($menu->visibleTo($this->makeUser()));
    }

    public function test_menu_requiring_permission_is_visible_to_user_holding_any_required_permission(): void
    {
        $permission = Permission::create(['name' => 'products.view']);
        $menu = Menu::create(['name' => 'Products']);
        $menu->permissions()->attach($permission);

        $role = Role::create(['name' => 'catalog_manager']);
        $role->permissions()->attach($permission);

        $user = $this->makeUser();
        $user->roles()->attach($role);

        $this->assertTrue($menu->visibleTo($user));
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Alice',
            'email' => 'a@example.com',
            'password_hash' => 'secret',
        ]);
    }
}
