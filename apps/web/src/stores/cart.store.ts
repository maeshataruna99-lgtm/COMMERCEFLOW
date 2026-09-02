import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

export interface CartLine {
  productId: number
  name: string
  priceCents: number
  quantity: number
}

export const useCartStore = defineStore('cart', () => {
  const lines = ref<CartLine[]>([])

  const totalItems = computed(() => lines.value.reduce((sum, l) => sum + l.quantity, 0))
  const totalCents = computed(() => lines.value.reduce((sum, l) => sum + l.priceCents * l.quantity, 0))

  function add(line: CartLine) {
    const existing = lines.value.find((l) => l.productId === line.productId)
    if (existing) {
      existing.quantity += line.quantity
    } else {
      lines.value.push({ ...line })
    }
  }

  function remove(productId: number) {
    lines.value = lines.value.filter((l) => l.productId !== productId)
  }

  function clear() {
    lines.value = []
  }

  return { lines, totalItems, totalCents, add, remove, clear }
})