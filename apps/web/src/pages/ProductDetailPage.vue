<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import api from '@/services/api'
import { useCartStore } from '@/stores/cart.store'

const route = useRoute()
const cartStore = useCartStore()

interface Product {
  id: number
  name: string
  description: string | null
  price_cents: number
  available: number
}

const product = ref<Product | null>(null)
const loading = ref(true)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/products/${route.params.id}`)
    product.value = data.data.product
  } catch (e: any) {
    error.value = e?.response?.data?.message ?? 'Produk tidak ditemukan.'
  } finally {
    loading.value = false
  }
}

function formatPrice(cents: number): string {
  return `Rp ${(cents / 100).toLocaleString('id-ID')}`
}

function addToCart() {
  if (!product.value) return
  cartStore.add({ productId: product.value.id, name: product.value.name, priceCents: product.value.price_cents, quantity: 1 })
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-4xl">
    <RouterLink to="/products" class="text-sm font-medium text-brand-600 hover:text-brand-700">
      ← Kembali ke katalog
    </RouterLink>

    <p v-if="loading" class="mt-4 text-slate-500">Memuat produk...</p>
    <p v-else-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{{ error }}</p>

    <div v-else-if="product" class="mt-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
      <div class="grid gap-6 sm:grid-cols-2">
        <div class="flex h-64 items-center justify-center rounded-xl bg-brand-50 text-8xl">📦</div>
        <div>
          <h2 class="text-2xl font-bold text-slate-800">{{ product.name }}</h2>
          <p class="mt-2 text-slate-600">{{ product.description }}</p>
          <p class="mt-4 text-2xl font-semibold text-brand-600">{{ formatPrice(product.price_cents) }}</p>
          <p class="mt-1 text-sm text-slate-400">{{ product.available }} tersedia</p>

          <button
            class="mt-6 w-full rounded-xl bg-brand-500 px-4 py-3 font-semibold text-white transition-colors hover:bg-brand-600"
            @click="addToCart"
          >
            Tambah ke Keranjang
          </button>
        </div>
      </div>
    </div>
  </div>
</template>