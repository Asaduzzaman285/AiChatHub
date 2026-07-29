/** Where a just-authenticated user should land — admins go to their dashboard, not the end-user chat UI. */
export function postLoginPath(user: { is_admin?: boolean } | null | undefined): string {
  return user?.is_admin ? '/admin' : '/chat'
}
