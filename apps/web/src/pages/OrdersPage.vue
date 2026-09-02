<script setup lang="ts">
const orders = [
  { id: 'ORD-20260901-001', status: 'PAID', total: 129900, date: '2026-09-01' },
  { id: 'ORD-20260901-002', status: 'SHIPPED', total: 89900, date: '2026-09-01' },
  { id: 'ORD-20260902-003', status: 'RESERVED', total: 249900, date: '2026-09-02' },
]

function formatPrice(cents: number): string {
  return `Rp ${(cents / 100).toLocaleString('id-ID')}`
}
</script>

<template>
  <div class="mx-auto max-w-3xl">
    <h2 class="mb-4 text-xl font-semibold text-slate-800">Pesanan Saya</h2>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
      <table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
          <tr>
            <th class="px-4 py-3">Order</th>
            <th class="px-4 py-3">Tanggal</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3 text-right">Total</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="o in orders" :key="o.id">
            <td class="px-4 py-3 font-medium text-slate-800">{{ o.id }}</td>
            <td class="px-4 py-3 text-slate-500">{{ o.date }}</td>
            <td class="px-4 py-3">
              <span
                class="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700"
              >
                {{ o.status }}
              </span>
            </td>
            <td class="px-4 py-3 text-right font-semibold text-slate-800">{{ formatPrice(o.total) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>