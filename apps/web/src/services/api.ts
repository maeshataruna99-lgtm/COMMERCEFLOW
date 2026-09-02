import axios, { type AxiosError, type AxiosRequestConfig } from 'axios'

export const ACCESS_TOKEN_KEY = 'cf_access_token'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '/api/v1',
  timeout: 10000,
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem(ACCESS_TOKEN_KEY)
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

api.interceptors.response.use(
  (response) => response,
  async (error: AxiosError) => {
    const original = error.config as (AxiosRequestConfig & { _retry?: boolean }) | undefined

    // Token expired → try a single refresh, then redirect to login on failure.
    if (error.response?.status === 401 && original && !original._retry && !original.url?.includes('/auth/login') && !original.url?.includes('/auth/refresh')) {
      original._retry = true
      try {
        const { data } = await axios.post('/api/v1/auth/refresh', null, {
          headers: { Authorization: `Bearer ${localStorage.getItem(ACCESS_TOKEN_KEY)}` },
        })
        localStorage.setItem(ACCESS_TOKEN_KEY, data.data.access_token)
        original.headers = { ...original.headers, Authorization: `Bearer ${data.data.access_token}` }
        return api(original)
      } catch {
        localStorage.removeItem(ACCESS_TOKEN_KEY)
        window.location.href = '/login'
      }
    }
    return Promise.reject(error)
  },
)

export default api