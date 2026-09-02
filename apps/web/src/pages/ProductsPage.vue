<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useCartStore } from '@/stores/cart.store'

const cartStore = useCartStore()

const products = ref([
  { id: 1, name: 'Wireless Headphones', price: 129900, stock: 25, emoji: '🎧' },
  { id: 2, name: 'Smart Watch', price: 249900, stock: 12, emoji: '⌚' },
  { id: 3, name: 'Mechanical Keyboard', price: 89900, stock: 40, emoji: '⌨️' },
  { id: 4, name: '4K Monitor', price: 349900, stock: 8, emoji: '🖥️' },
  { id: 5, name: 'USB-C Hub', price: 45900, stock: 60, emoji: '🔌' },
  { id: 6, name: 'Laptop Stand', price: 29900, stock: 34, emoji: '💻' },
  { id: 7, name: 'Bluetooth Speaker', price: 179900, stock: 19, emoji: '🔊' },
  { id: 8, name: 'Webcam 1080p', price: 69900, stock: 15, emoji: '📷' },
])

function formatPrice(cents: number): string {
  return `Rp ${(cents / 100).toLocaleString('id-ID')}`
}

function addToCart(p: { id: number; name: string; price: number }) {
  cartStore.add({ productId: p.id, name: p.name, priceCents: p.price, quantity: 1 })
}
</script>

<template>
  <div class="mx-auto max-w-6xl">
    <h2 class="mb-4 text-xl font-semibold text-slate-800">Katalog Produk</h2>

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
      <div
        v-for="p in products"
        :key="p.id"
        class="flex flex-col rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
      >
        <RouterLink :to="`/products/${p.id}`" class="flex-1">
          <div class="flex h-28 items-center justify-center rounded-lg bg-brand-50 text-5xl">
            {{ p.emoji }}
          </div>
          <p class="mt-3 line-clamp-1 text-sm font-medium text-slate-800">{{ p.name }}</p>
          <p class="mt-1 text-sm font-semibold text-brand-600">{{ formatPrice(p.price) }}</p>
          <p class="mt-1 text-xs text-slate-400">{{ p.stock }} tersedia</p>
        </RouterLink>
        <button
          class="mt-3 rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white transition-colors hover:bg-brand-600"
          @click="addToCart(p)"
        >
          Tambah ke Keranjang
        </button>
      </div>
    </div>
  </div>
</template>