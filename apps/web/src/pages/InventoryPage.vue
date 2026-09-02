<script setup lang="ts">
const items = [
  { product: 'Wireless Headphones', physical: 25, reserved: 3, available: 22 },
  { product: 'Smart Watch', physical: 12, reserved: 1, available: 11 },
  { product: 'Mechanical Keyboard', physical: 40, reserved: 5, available: 35 },
  { product: '4K Monitor', physical: 8, reserved: 2, available: 6 },
  { product: 'USB-C Hub', physical: 60, reserved: 0, available: 60 },
  { product: 'Laptop Stand', physical: 34, reserved: 4, available: 30 },
  { product: 'Bluetooth Speaker', physical: 19, reserved: 1, available: 18 },
  { product: 'Webcam 1080p', physical: 15, reserved: 0, available: 15 },
]
</script>

<template>
  <div class="space-y-4">
    <h2 class="text-xl font-semibold text-slate-800">Inventori</h2>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
      <table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
          <tr>
            <th class="px-4 py-3">Produk</th>
            <th class="px-4 py-3 text-right">Physical</th>
            <th class="px-4 py-3 text-right">Reserved</th>
            <th class="px-4 py-3 text-right">Available</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="i in items" :key="i.product">
            <td class="px-4 py-3 font-medium text-slate-800">{{ i.product }}</td>
            <td class="px-4 py-3 text-right text-slate-600">{{ i.physical }}</td>
            <td class="px-4 py-3 text-right text-amber-600">{{ i.reserved }}</td>
            <td class="px-4 py-3 text-right font-semibold text-emerald-600">{{ i.available }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>