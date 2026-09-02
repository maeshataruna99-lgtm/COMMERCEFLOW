import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  {
    path: '/',
    component: () => import('@/layouts/AppLayout.vue'),
    children: [
      { path: '', name: 'home', component: () => import('@/pages/HomePage.vue') },
      { path: 'products', name: 'products', component: () => import('@/pages/ProductsPage.vue') },
      { path: 'products/:id', name: 'product-detail', component: () => import('@/pages/ProductDetailPage.vue') },
      { path: 'cart', name: 'cart', component: () => import('@/pages/CartPage.vue') },
      { path: 'orders', name: 'orders', component: () => import('@/pages/OrdersPage.vue') },
      { path: 'dashboard', name: 'dashboard', component: () => import('@/pages/DashboardPage.vue') },
      { path: 'inventory', name: 'inventory', component: () => import('@/pages/InventoryPage.vue') },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

export default router