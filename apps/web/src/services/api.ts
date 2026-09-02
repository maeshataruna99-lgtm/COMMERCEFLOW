import axios from 'axios'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '/api/v1',
  timeout: 10000,
})

// TODO: JWT auth interceptor lands with the API auth plan.
// api.interceptors.request.use((config) => {
//   const token = localStorage.getItem('cf_access_token')
//   if (token) config.headers.Authorization = `Bearer ${token}`
//   return config
// })

export default api