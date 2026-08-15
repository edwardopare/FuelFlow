import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiFetch, getCsrfCookie } from '../lib/api'
import type { ResourceResponse, VendorUser } from '../types/api'

const vendorUserKey = ['vendor-auth', 'current-user'] as const

export function useCurrentVendorUser() {
  return useQuery({
    queryKey: vendorUserKey,
    queryFn: async () => {
      const response = await apiFetch<ResourceResponse<VendorUser>>(
        '/api/v1/vendor/me',
      )
      return response.data
    },
    retry: false,
  })
}

export function useVendorLogin() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (credentials: {
      email: string
      password: string
      remember: boolean
    }) => {
      await getCsrfCookie()
      const response = await apiFetch<ResourceResponse<VendorUser>>(
        '/api/v1/vendor/auth/login',
        { method: 'POST', body: JSON.stringify(credentials) },
      )
      return response.data
    },
    onSuccess: (user) => queryClient.setQueryData(vendorUserKey, user),
  })
}

export function useVendorLogout() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () =>
      apiFetch<{ message: string }>('/api/v1/vendor/auth/logout', {
        method: 'POST',
      }),
    onSuccess: () => queryClient.removeQueries({ queryKey: vendorUserKey }),
  })
}

export function useVendorChangePassword() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (data: {
      current_password: string
      password: string
      password_confirmation: string
    }) => {
      const response = await apiFetch<ResourceResponse<VendorUser>>(
        '/api/v1/vendor/auth/change-password',
        { method: 'POST', body: JSON.stringify(data) },
      )
      return response.data
    },
    onSuccess: (user) => queryClient.setQueryData(vendorUserKey, user),
  })
}
