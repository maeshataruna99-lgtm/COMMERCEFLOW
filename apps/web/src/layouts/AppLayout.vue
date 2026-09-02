<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink, RouterView } from 'vue-router'
import { useMenuStore } from '@/stores/menu.store'
import { useCartStore } from '@/stores/cart.store'

const menuStore = useMenuStore()
const cartStore = useCartStore()
const mobileOpen = ref(false)
</script>

<template>
  <div class="flex h-screen overflow-hidden bg-slate-50">
    <!-- Sidebar -->
    <aside
      :class="[
        'fixed inset-y-0 left-0 z-40 w-64 transform bg-white shadow-sm transition-transform duration-200 lg:static lg:translate-x-0',
        mobileOpen ? 'translate-x-0' : '-translate-x-full',
      ]"
    >
      <div class="flex h-16 items-center gap-2 border-b border-slate-100 px-5">
        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-500 text-sm font-bold text-white">
          CF
        </div>
        <span class="text-lg font-semibold text-slate-800">CommerceFlow</span>
      </div>

      <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
        <RouterLink
          v-for="item in menuStore.menus"
          :key="item.route"
          :to="item.route"
          class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-brand-50 hover:text-brand-700"
          active-class="bg-brand-50 text-brand-700"
        >
          <span class="text-base">{{ item.icon }}</span>
          <span>{{ item.label }}</span>
        </RouterLink>
      </nav>

      <div class="border-t border-slate-100 px-5 py-4 text-xs text-slate-400">
        CommerceFlow v0.1.0
      </div>
    </aside>

    <!-- Overlay (mobile) -->
    <div
      v-if="mobileOpen"
      class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden"
      @click="mobileOpen = false"
    />

    <!-- Main column -->
    <div class="flex min-w-0 flex-1 flex-col">
      <header class="flex h-16 shrink-0 items-center gap-4 border-b border-slate-100 bg-white px-4 lg:px-6">
        <button
          class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 lg:hidden"
          @click="mobileOpen = true"
        >
          <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        </button>

        <h1 class="text-lg font-semibold text-slate-800">Storefront</h1>

        <div class="ml-auto flex items-center gap-4">
          <RouterLink
            to="/cart"
            class="relative rounded-lg p-2 text-slate-500 transition-colors hover:bg-brand-50 hover:text-brand-700"
          >
            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z"
              />
            </svg>
            <span
              v-if="cartStore.totalItems > 0"
              class="absolute -right-1 -top-1 flex h-5 w-5 items-center justify-center rounded-full bg-brand-500 text-[11px] font-semibold text-white"
            >
              {{ cartStore.totalItems }}
            </span>
          </RouterLink>

          <RouterLink
            to="/dashboard"
            class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 transition-colors hover:bg-brand-50"
          >
            Dashboard
          </RouterLink>
        </div>
      </header>

      <main class="flex-1 overflow-y-auto p-4 lg:p-6">
        <RouterView />
      </main>
    </div>
  </div>
</template>