<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth.store'

const router = useRouter()
const authStore = useAuthStore()

const mode = ref<'login' | 'register'>('login')
const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const error = ref('')
const loading = ref(false)

async function submit() {
  error.value = ''
  loading.value = true
  try {
    if (mode.value === 'login') {
      await authStore.login(email.value, password.value)
    } else {
      await authStore.register({
        name: name.value,
        email: email.value,
        password: password.value,
        password_confirmation: passwordConfirmation.value,
      })
    }
    router.push('/')
  } catch (e: any) {
    error.value = e?.response?.data?.message ?? 'Terjadi kesalahan. Silakan coba lagi.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-slate-50 px-4">
    <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
      <div class="mb-6 text-center">
        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-brand-500 text-lg font-bold text-white">
          CF
        </div>
        <h1 class="mt-3 text-2xl font-bold text-slate-800">CommerceFlow</h1>
        <p class="mt-1 text-sm text-slate-500">
          {{ mode === 'login' ? 'Masuk ke akun Anda' : 'Buat akun baru' }}
        </p>
      </div>

      <div class="mb-6 grid grid-cols-2 rounded-xl bg-slate-100 p-1 text-sm font-medium">
        <button
          class="rounded-lg py-2 transition-colors"
          :class="mode === 'login' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500'"
          @click="mode = 'login'"
        >
          Masuk
        </button>
        <button
          class="rounded-lg py-2 transition-colors"
          :class="mode === 'register' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500'"
          @click="mode = 'register'"
        >
          Daftar
        </button>
      </div>

      <form class="space-y-4" @submit.prevent="submit">
        <div v-if="mode === 'register'">
          <label class="mb-1 block text-sm font-medium text-slate-700">Nama</label>
          <input
            v-model="name"
            type="text"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100"
          />
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Email</label>
          <input
            v-model="email"
            type="email"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100"
          />
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Password</label>
          <input
            v-model="password"
            type="password"
            required
            minlength="8"
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100"
          />
        </div>

        <div v-if="mode === 'register'">
          <label class="mb-1 block text-sm font-medium text-slate-700">Konfirmasi Password</label>
          <input
            v-model="passwordConfirmation"
            type="password"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100"
          />
        </div>

        <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{{ error }}</p>

        <button
          type="submit"
          :disabled="loading"
          class="w-full rounded-xl bg-brand-500 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-600 disabled:opacity-50"
        >
          {{ loading ? 'Memproses...' : mode === 'login' ? 'Masuk' : 'Daftar' }}
        </button>
      </form>
    </div>
  </div>
</template>