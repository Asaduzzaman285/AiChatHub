import type { AxiosError } from 'axios'
import type { ApiError } from '@/types'

// Toast wording convention (applies to every toast.error/toast.success in the app, not
// just this file's own fallback strings): full sentences, sentence case, friendly-but-plain
// tone. Say what happened, and — for errors — what to do next where that's useful
// ("...check X before trying again" rather than a bare "Failed."). Prefer describeError()'s
// ambiguousMessage param over a hand-rolled string so ambiguous network failures (see below)
// get consistent, non-alarmist wording across pages.

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
