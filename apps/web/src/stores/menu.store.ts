import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth.store'

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

const ICONS: Record<string, string> = {
  dashboard: '📊',
  products: '📦',
  inventory: '📚',
  orders: '📋',
  payments: '💳',
  users: '👥',
  roles: '🔐',
  permissions: '🛡️',
  reports: '📈',
  stock: '📦',
  reservations: '🔒',
  adjustments: '⚖️',
  movements: '↔️',
  home: '🏠',
  cart: '🛒',
}

export const useMenuStore = defineStore('menu', () => {
  const menus = ref<MenuItem[]>(DEFAULT_MENUS)

  function flatten(items: any[], depth = 0): MenuItem[] {
    const out: MenuItem[] = []
    for (const item of items ?? []) {
      const route = item.route ?? '#'
      out.push({
        label: item.name,
        route,
        icon: ICONS[String(item.name ?? '').toLowerCase()] ?? '•',
      })
      out.push(...flatten(item.children ?? [], depth + 1))
    }
    return out
  }

  async function loadFromApi() {
    const auth = useAuthStore()
    if (!auth.isAuthenticated()) {
      menus.value = DEFAULT_MENUS
      return
    }
    try {
      await auth.loadMenus()
      const apiMenus = flatten(auth.menus)
      menus.value = apiMenus.length > 0 ? apiMenus : DEFAULT_MENUS
    } catch {
      menus.value = DEFAULT_MENUS
    }
  }

  return { menus, loadFromApi }
})