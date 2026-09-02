import { defineStore } from 'pinia'
import { ref } from 'vue'

export interface MenuItem {
  label: string
  route: string
  icon: string
}

const DEFAULT_MENUS: MenuItem[] = [
  { label: 'Home', route: '/', icon: '🏠' },
  { label: 'Products', route: '/products', icon: '📦' },
  { label: 'Cart', route: '/cart', icon: '🛒' },
  { label: 'Orders', route: '/orders', icon: '📋' },
  { label: 'Dashboard', route: '/dashboard', icon: '📊' },
  { label: 'Inventory', route: '/inventory', icon: '📚' },
]

export const useMenuStore = defineStore('menu', () => {
  const menus = ref<MenuItem[]>(DEFAULT_MENUS)

  async function loadFromApi() {
    // TODO: fetch from GET /api/v1/me/menus once the API auth plan lands.
    // For the scaffold, keep the static default set.
    menus.value = DEFAULT_MENUS
  }

  return { menus, loadFromApi }
})