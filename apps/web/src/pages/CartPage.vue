<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/services/api'

interface CartLine {
  id: number
  product_id: number
  name: string
  price_cents: number
  quantity: number
  line_total_cents: number
}

const items = ref<CartLine[]>([])
const totalCents = ref(0)
const loading = ref(true)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/cart')
    items.value = data.data.cart.items
    totalCents.value = data.data.cart.total_cents
  } catch (e: any) {
    error.value = e?.response?.data?.message ?? 'Gagal memuat keranjang.'
  } finally {
    loading.value = false
  }
}

async function removeItem(id: number) {
  try {
    await api.delete(`/cart/items/${id}`)
    await load()
  } catch (e: any) {
    error.value = e?.response?.data?.message ?? 'Gagal menghapus item.'
  }
}

function formatPrice(cents: number): string {
  return `Rp ${(cents / 100).toLocaleString('id-ID')}`
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-3xl">
    <h2 class="mb-4 text-xl font-semibold text-slate-800">Keranjang Belanja</h2>

    <p v-if="loading" class="text-slate-500">Memuat keranjang...</p>
    <p v-else-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{{ error }}</p>

    <div v-else-if="items.length === 0" class="rounded-xl border border-slate-200 bg-white p-10 text-center shadow-sm">
      <p class="text-slate-500">Keranjang Anda masih kosong.</p>
      <RouterLink to="/products" class="mt-4 inline-block rounded-lg bg-brand-500 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-600">
        Mulai Belanja
      </RouterLink>
    </div>

    <template v-else>
      <div class="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white shadow-sm">
        <div v-for="line in items" :key="line.id" class="flex items-center gap-4 p-4">
          <div class="flex-1">
            <p class="font-medium text-slate-800">{{ line.name }}</p>
            <p class="text-sm text-slate-500">{{ line.quantity }} × {{ formatPrice(line.price_cents) }}</p>
          </div>
          <p class="font-semibold text-slate-800">{{ formatPrice(line.line_total_cents) }}</p>
          <button
            class="text-sm text-red-500 hover:text-red-600"
            @click="removeItem(line.id)"
          >
            Hapus
          </button>
        </div>
      </div>

      <div class="mt-4 flex items-center justify-between rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div>
          <p class="text-sm text-slate-500">Total</p>
          <p class="text-xl font-bold text-slate-800">{{ formatPrice(totalCents) }}</p>
        </div>
        <button class="rounded-xl bg-brand-500 px-6 py-3 font-semibold text-white hover:bg-brand-600">
          Checkout
        </button>
      </div>
    </template>
  </div>
</template>