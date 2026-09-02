<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/services/api'
import { useCartStore } from '@/stores/cart.store'

const cartStore = useCartStore()

interface Product {
  id: number
  name: string
  price_cents: number
  available: number
}

const products = ref<Product[]>([])
const loading = ref(true)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/products')
    products.value = data.data.products.data
  } catch (e: any) {
    error.value = e?.response?.data?.message ?? 'Gagal memuat produk.'
  } finally {
    loading.value = false
  }
}

function formatPrice(cents: number): string {
  return `Rp ${(cents / 100).toLocaleString('id-ID')}`
}

function addToCart(p: Product) {
  cartStore.add({ productId: p.id, name: p.name, priceCents: p.price_cents, quantity: 1 })
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-6xl">
    <h2 class="mb-4 text-xl font-semibold text-slate-800">Katalog Produk</h2>

    <p v-if="loading" class="text-slate-500">Memuat produk...</p>
    <p v-else-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{{ error }}</p>

    <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
      <div
        v-for="p in products"
        :key="p.id"
        class="flex flex-col rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
      >
        <RouterLink :to="`/products/${p.id}`" class="flex-1">
          <div class="flex h-28 items-center justify-center rounded-lg bg-brand-50 text-5xl">📦</div>
          <p class="mt-3 line-clamp-1 text-sm font-medium text-slate-800">{{ p.name }}</p>
          <p class="mt-1 text-sm font-semibold text-brand-600">{{ formatPrice(p.price_cents) }}</p>
          <p class="mt-1 text-xs text-slate-400">{{ p.available }} tersedia</p>
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