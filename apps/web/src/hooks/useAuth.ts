import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiFetch, getCsrfCookie } from '../lib/api'
import type { ResourceResponse, User } from '../types/api'

const currentUserKey = ['auth', 'current-user'] as const

export function useCurrentUser() {
  return useQuery({
    queryKey: currentUserKey,
    queryFn: async () => {
      const response = await apiFetch<ResourceResponse<User>>('/api/v1/me')
      return response.data
    },
  })
}

export function useLogin() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (credentials: {
      email: string
      password: string
      remember: boolean
    }) => {
      await getCsrfCookie()
      const response = await apiFetch<ResourceResponse<User>>(
        '/api/v1/auth/login',
        {
          method: 'POST',
          body: JSON.stringify(credentials),
        },
      )
      return response.data
    },
    onSuccess: (user) => {
      queryClient.setQueryData(currentUserKey, user)
    },
  })
}

export function useForgotPassword() {
  return useMutation({
    mutationFn: async (email: string) => {
      await getCsrfCookie()

      return apiFetch<{ message: string }>('/api/v1/auth/forgot-password', {
        method: 'POST',
        body: JSON.stringify({ email }),
      })
    },
  })
}

export function useResetPassword() {
  return useMutation({
    mutationFn: async (data: {
      token: string
      email: string
      password: string
      password_confirmation: string
    }) => {
      await getCsrfCookie()

      return apiFetch<{ message: string }>('/api/v1/auth/reset-password', {
        method: 'POST',
        body: JSON.stringify(data),
      })
    },
  })
}

export function useLogout() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () =>
      apiFetch<{ message: string }>('/api/v1/auth/logout', {
        method: 'POST',
      }),
    onSuccess: () => {
      queryClient.removeQueries({ queryKey: currentUserKey })
    },
  })
}

export function useChangePassword() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (data: {
      current_password: string
      password: string
      password_confirmation: string
      terminal_pin?: string
    }) => {
      const response = await apiFetch<ResourceResponse<User>>(
        '/api/v1/auth/change-password',
        {
          method: 'POST',
          body: JSON.stringify(data),
        },
      )
      return response.data
    },
    onSuccess: (user) => {
      queryClient.setQueryData(currentUserKey, user)
    },
  })
}
