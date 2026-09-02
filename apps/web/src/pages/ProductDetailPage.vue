<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { useCartStore } from '@/stores/cart.store'

const route = useRoute()
const cartStore = useCartStore()

const catalog = [
  { id: 1, name: 'Wireless Headphones', price: 129900, stock: 25, emoji: '🎧', desc: 'Headphone nirkabel dengan noise cancellation.' },
  { id: 2, name: 'Smart Watch', price: 249900, stock: 12, emoji: '⌚', desc: 'Jam tangan pintar dengan pelacak kesehatan.' },
  { id: 3, name: 'Mechanical Keyboard', price: 89900, stock: 40, emoji: '⌨️', desc: 'Keyboard mekanikal hot-swappable.' },
  { id: 4, name: '4K Monitor', price: 349900, stock: 8, emoji: '🖥️', desc: 'Monitor 4K UHD 27 inci.' },
  { id: 5, name: 'USB-C Hub', price: 45900, stock: 60, emoji: '🔌', desc: 'Hub USB-C 7-in-1.' },
  { id: 6, name: 'Laptop Stand', price: 29900, stock: 34, emoji: '💻', desc: 'Stand laptop aluminium.' },
  { id: 7, name: 'Bluetooth Speaker', price: 179900, stock: 19, emoji: '🔊', desc: 'Speaker portabel 20W.' },
  { id: 8, name: 'Webcam 1080p', price: 69900, stock: 15, emoji: '📷', desc: 'Webcam Full HD 1080p.' },
]

const product = computed(() => catalog.find((p) => p.id === Number(route.params.id)) ?? catalog[0])

function formatPrice(cents: number): string {
  return `Rp ${(cents / 100).toLocaleString('id-ID')}`
}

function addToCart() {
  cartStore.add({ productId: product.value.id, name: product.value.name, priceCents: product.value.price, quantity: 1 })
}
</script>

<template>
  <div class="mx-auto max-w-4xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    <RouterLink to="/products" class="text-sm font-medium text-brand-600 hover:text-brand-700">
      ← Kembali ke katalog
    </RouterLink>

    <div class="mt-4 grid gap-6 sm:grid-cols-2">
      <div class="flex h-64 items-center justify-center rounded-xl bg-brand-50 text-8xl">
        {{ product.emoji }}
      </div>
      <div>
        <h2 class="text-2xl font-bold text-slate-800">{{ product.name }}</h2>
        <p class="mt-2 text-slate-600">{{ product.desc }}</p>
        <p class="mt-4 text-2xl font-semibold text-brand-600">{{ formatPrice(product.price) }}</p>
        <p class="mt-1 text-sm text-slate-400">{{ product.stock }} tersedia</p>

        <button
          class="mt-6 w-full rounded-xl bg-brand-500 px-4 py-3 font-semibold text-white transition-colors hover:bg-brand-600"
          @click="addToCart"
        >
          Tambah ke Keranjang
        </button>
      </div>
    </div>
  </div>
</template>