<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useCartStore } from '@/stores/cart.store'

const cartStore = useCartStore()

function formatPrice(cents: number): string {
  return `Rp ${(cents / 100).toLocaleString('id-ID')}`
}
</script>

<template>
  <div class="mx-auto max-w-3xl">
    <h2 class="mb-4 text-xl font-semibold text-slate-800">Keranjang Belanja</h2>

    <div v-if="cartStore.lines.length === 0" class="rounded-xl border border-slate-200 bg-white p-10 text-center shadow-sm">
      <p class="text-slate-500">Keranjang Anda masih kosong.</p>
      <RouterLink to="/products" class="mt-4 inline-block rounded-lg bg-brand-500 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-600">
        Mulai Belanja
      </RouterLink>
    </div>

    <template v-else>
      <div class="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white shadow-sm">
        <div v-for="line in cartStore.lines" :key="line.productId" class="flex items-center gap-4 p-4">
          <div class="flex-1">
            <p class="font-medium text-slate-800">{{ line.name }}</p>
            <p class="text-sm text-slate-500">{{ line.quantity }} × {{ formatPrice(line.priceCents) }}</p>
          </div>
          <p class="font-semibold text-slate-800">{{ formatPrice(line.priceCents * line.quantity) }}</p>
          <button
            class="text-sm text-red-500 hover:text-red-600"
            @click="cartStore.remove(line.productId)"
          >
            Hapus
          </button>
        </div>
      </div>

      <div class="mt-4 flex items-center justify-between rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div>
          <p class="text-sm text-slate-500">Total</p>
          <p class="text-xl font-bold text-slate-800">{{ formatPrice(cartStore.totalCents) }}</p>
        </div>
        <button class="rounded-xl bg-brand-500 px-6 py-3 font-semibold text-white hover:bg-brand-600">
          Checkout
        </button>
      </div>
    </template>
  </div>
</template>