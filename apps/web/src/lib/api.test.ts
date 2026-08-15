import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ApiError,
  AUTHENTICATION_REQUIRED_EVENT,
  apiFetch,
} from './api'

afterEach(() => {
  vi.unstubAllGlobals()
})

function unauthorizedResponse() {
  return new Response(JSON.stringify({ message: 'Unauthenticated.' }), {
    status: 401,
    headers: { 'Content-Type': 'application/json' },
  })
}

describe('apiFetch authentication expiry', () => {
  it('notifies the tenant shell when an authenticated request returns 401', async () => {
    const listener = vi.fn()
    window.addEventListener(AUTHENTICATION_REQUIRED_EVENT, listener)
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(unauthorizedResponse()))

    await expect(
      apiFetch('/api/v1/users', { method: 'POST', body: '{}' }),
    ).rejects.toBeInstanceOf(ApiError)

    expect(listener).toHaveBeenCalledOnce()
    expect((listener.mock.calls[0][0] as CustomEvent).detail).toEqual({
      scope: 'tenant',
    })
    window.removeEventListener(AUTHENTICATION_REQUIRED_EVENT, listener)
  })

  it('notifies only the vendor shell for vendor requests', async () => {
    const listener = vi.fn()
    window.addEventListener(AUTHENTICATION_REQUIRED_EVENT, listener)
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(unauthorizedResponse()))

    await expect(
      apiFetch('/api/v1/vendor/organizations', { method: 'POST', body: '{}' }),
    ).rejects.toBeInstanceOf(ApiError)

    expect((listener.mock.calls[0][0] as CustomEvent).detail).toEqual({
      scope: 'vendor',
    })
    window.removeEventListener(AUTHENTICATION_REQUIRED_EVENT, listener)
  })

  it('does not recursively notify when the current-user check returns 401', async () => {
    const listener = vi.fn()
    window.addEventListener(AUTHENTICATION_REQUIRED_EVENT, listener)
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(unauthorizedResponse()))

    await expect(apiFetch('/api/v1/me')).rejects.toBeInstanceOf(ApiError)

    expect(listener).not.toHaveBeenCalled()
    window.removeEventListener(AUTHENTICATION_REQUIRED_EVENT, listener)
  })
})
