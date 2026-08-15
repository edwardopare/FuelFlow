const JSON_HEADERS = {
  Accept: 'application/json',
  'Content-Type': 'application/json',
}

export class ApiError extends Error {
  readonly status: number
  readonly errors?: Record<string, string[]>

  constructor(
    message: string,
    status: number,
    errors?: Record<string, string[]>,
  ) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }
}

function getCookie(name: string): string | null {
  const prefix = `${name}=`
  const encodedValue = document.cookie
    .split('; ')
    .find((entry) => entry.startsWith(prefix))
    ?.slice(prefix.length)

  return encodedValue ? decodeURIComponent(encodedValue) : null
}

export async function getCsrfCookie(): Promise<void> {
  const response = await fetch('/sanctum/csrf-cookie', {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  })

  if (!response.ok) {
    throw new ApiError('Unable to establish a secure session.', response.status)
  }
}

export async function apiFetch<T>(
  path: string,
  options: RequestInit = {},
): Promise<T> {
  const method = options.method?.toUpperCase() ?? 'GET'
  const csrfToken = getCookie('XSRF-TOKEN')
  const headers = new Headers(options.headers)
  const isFormData = options.body instanceof FormData

  Object.entries(JSON_HEADERS).forEach(([key, value]) => {
    if (key === 'Content-Type' && isFormData) {
      return
    }
    if (!headers.has(key)) {
      headers.set(key, value)
    }
  })

  if (!['GET', 'HEAD'].includes(method) && csrfToken) {
    headers.set('X-XSRF-TOKEN', csrfToken)
  }

  const response = await fetch(path, {
    ...options,
    credentials: 'include',
    headers,
  })

  if (response.status === 204) {
    return undefined as T
  }

  const payload = (await response.json().catch(() => null)) as {
    message?: string
    errors?: Record<string, string[]>
  } | null

  if (!response.ok) {
    throw new ApiError(
      payload?.message ?? 'The request could not be completed.',
      response.status,
      payload?.errors,
    )
  }

  return payload as T
}
