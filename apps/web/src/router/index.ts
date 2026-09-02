import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth.store'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/pages/LoginPage.vue'),
  },
  {
    path: '/',
    component: () => import('@/layouts/AppLayout.vue'),
    children: [
      { path: '', name: 'home', component: () => import('@/pages/HomePage.vue') },
      { path: 'products', name: 'products', component: () => import('@/pages/ProductsPage.vue') },
      { path: 'products/:id', name: 'product-detail', component: () => import('@/pages/ProductDetailPage.vue') },
      { path: 'cart', name: 'cart', component: () => import('@/pages/CartPage.vue'), meta: { requiresAuth: true } },
      { path: 'orders', name: 'orders', component: () => import('@/pages/OrdersPage.vue'), meta: { requiresAuth: true } },
      { path: 'dashboard', name: 'dashboard', component: () => import('@/pages/DashboardPage.vue'), meta: { requiresAuth: true } },
      { path: 'inventory', name: 'inventory', component: () => import('@/pages/InventoryPage.vue'), meta: { requiresAuth: true } },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
  if (!to.meta.requiresAuth) return true

  const auth = useAuthStore()
  if (!auth.isAuthenticated()) {
    return { name: 'login' }
  }

  try {
    if (!auth.user) await auth.loadMe()
  } catch {
    await auth.logout()
    return { name: 'login' }
  }
  return true
})

export default router