import { defineStore } from 'pinia'
import { ref } from 'vue'
import api, { ACCESS_TOKEN_KEY } from '@/services/api'

export interface CurrentUser {
  id: number
  name: string
  email: string
  roles: string[]
  permissions: string[]
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<CurrentUser | null>(null)
  const token = ref<string | null>(localStorage.getItem(ACCESS_TOKEN_KEY))
  const menus = ref<any[]>([])

  const isAuthenticated = () => !!token.value

  function setSession(accessToken: string, userData?: CurrentUser) {
    token.value = accessToken
    localStorage.setItem(ACCESS_TOKEN_KEY, accessToken)
    if (userData) user.value = userData
  }

  async function login(email: string, password: string) {
    const { data } = await api.post('/auth/login', { email, password })
    setSession(data.data.access_token)
    await loadMe()
    return data.data.user
  }

  async function register(payload: { name: string; email: string; password: string; password_confirmation: string }) {
    const { data } = await api.post('/auth/register', payload)
    setSession(data.data.access_token)
    await loadMe()
    return data.data.user
  }

  async function loadMe() {
    const { data } = await api.get('/me')
    user.value = data.data.user
  }

  async function loadMenus() {
    const { data } = await api.get('/me/menus')
    menus.value = data.data.menus
  }

  async function logout() {
    try {
      await api.post('/auth/logout')
    } catch {
      // ignore logout errors
    }
    token.value = null
    user.value = null
    menus.value = []
    localStorage.removeItem(ACCESS_TOKEN_KEY)
  }

  return { user, token, menus, isAuthenticated, login, register, loadMe, loadMenus, logout, setSession }
})