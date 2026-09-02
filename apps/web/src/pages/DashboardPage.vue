<script setup lang="ts">
const stats = [
  { label: 'Total Pesanan', value: '128', delta: '+12%' },
  { label: 'Pendapatan', value: 'Rp 45,6 jt', delta: '+8%' },
  { label: 'Produk Aktif', value: '86', delta: '+3' },
  { label: 'Stok Menipis', value: '5', delta: '-2' },
]

const recentOrders = [
  { id: 'ORD-20260902-003', customer: 'Budi S.', status: 'RESERVED', total: 249900 },
  { id: 'ORD-20260902-004', customer: 'Ani W.', status: 'PAID', total: 89900 },
  { id: 'ORD-20260902-005', customer: 'Candra', status: 'PENDING', total: 129900 },
]

function formatPrice(cents: number): string {
  return `Rp ${(cents / 100).toLocaleString('id-ID')}`
}
</script>

<template>
  <div class="space-y-6">
    <h2 class="text-xl font-semibold text-slate-800">Dashboard</h2>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
      <div v-for="s in stats" :key="s.label" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-sm text-slate-500">{{ s.label }}</p>
        <p class="mt-1 text-2xl font-bold text-slate-800">{{ s.value }}</p>
        <p class="mt-1 text-xs font-medium text-emerald-600">{{ s.delta }}</p>
      </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
      <h3 class="border-b border-slate-100 px-4 py-3 font-semibold text-slate-800">Pesanan Terbaru</h3>
      <table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
          <tr>
            <th class="px-4 py-3">Order</th>
            <th class="px-4 py-3">Customer</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3 text-right">Total</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="o in recentOrders" :key="o.id">
            <td class="px-4 py-3 font-medium text-slate-800">{{ o.id }}</td>
            <td class="px-4 py-3 text-slate-500">{{ o.customer }}</td>
            <td class="px-4 py-3">
              <span class="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700">
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