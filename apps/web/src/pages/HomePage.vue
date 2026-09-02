<script setup lang="ts">
import { RouterLink } from 'vue-router'

const featured = [
  { id: 1, name: 'Wireless Headphones', price: 129900, emoji: '🎧' },
  { id: 2, name: 'Smart Watch', price: 249900, emoji: '⌚' },
  { id: 3, name: 'Mechanical Keyboard', price: 89900, emoji: '⌨️' },
  { id: 4, name: '4K Monitor', price: 349900, emoji: '🖥️' },
]

function formatPrice(cents: number): string {
  return `Rp ${(cents / 100).toLocaleString('id-ID')}`
}
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-8">
    <section
      class="rounded-2xl bg-gradient-to-br from-brand-500 to-brand-700 px-8 py-12 text-white shadow-lg shadow-brand-200"
    >
      <h2 class="text-3xl font-bold">Selamat datang di CommerceFlow</h2>
      <p class="mt-2 max-w-xl text-brand-100">
        Platform e-commerce dengan inventori real-time, reservasi stok, dan manajemen pesanan
        dalam satu tempat.
      </p>
      <RouterLink
        to="/products"
        class="mt-6 inline-block rounded-xl bg-white px-5 py-2.5 text-sm font-semibold text-brand-700 transition-transform hover:scale-105"
      >
        Jelajahi Produk
      </RouterLink>
    </section>

    <section>
      <div class="mb-4 flex items-center justify-between">
        <h3 class="text-xl font-semibold text-slate-800">Produk Unggulan</h3>
        <RouterLink to="/products" class="text-sm font-medium text-brand-600 hover:text-brand-700">
          Lihat semua →
        </RouterLink>
      </div>

      <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        <RouterLink
          v-for="p in featured"
          :key="p.id"
          :to="`/products/${p.id}`"
          class="group rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md"
        >
          <div class="flex h-28 items-center justify-center rounded-lg bg-brand-50 text-5xl">
            {{ p.emoji }}
          </div>
          <p class="mt-3 line-clamp-1 text-sm font-medium text-slate-800">{{ p.name }}</p>
          <p class="mt-1 text-sm font-semibold text-brand-600">{{ formatPrice(p.price) }}</p>
        </RouterLink>
      </div>
    </section>
  </div>
</template>