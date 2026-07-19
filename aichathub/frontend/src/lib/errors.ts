import type { AxiosError } from 'axios'
import type { ApiError } from '@/types'

export interface ErrorInfo {
  // True when the client never got a response (timeout, dropped connection) — the request
  // may have still succeeded server-side. Seen live during registration: axios timed out
  // while the auth-service had already committed the user row. Never tell the user it
  // "failed" outright in this case, or they'll retry into a duplicate.
  ambiguous: boolean
  message: string
}

export function describeError(err: unknown, ambiguousMessage: string): ErrorInfo {
  const axiosErr = err as AxiosError<ApiError>

  if (!axiosErr?.response) {
    return { ambiguous: true, message: ambiguousMessage }
  }

  const { status, data } = axiosErr.response

  if (status === 422 && data?.errors) {
    const firstField = Object.values(data.errors)[0]
    return { ambiguous: false, message: Array.isArray(firstField) ? firstField[0] : (data.message ?? 'Please check your input.') }
  }

  if (status === 409) {
    return { ambiguous: false, message: data?.message ?? 'This already exists.' }
  }

  return { ambiguous: false, message: data?.message ?? 'Something went wrong. Please try again.' }
}
