import type { User } from '@/types'

/** '*' wildcard (platform_admin) or an exact permission string match — mirrors every backend AdminGateMiddleware's check. */
export function hasPermission(user: User | null, permission: string): boolean {
  if (!user?.admin_permissions) return false
  return user.admin_permissions.includes('*') || user.admin_permissions.includes(permission)
}

/**
 * Every permission string actually gated somewhere across the 6 backend
 * services (grep the `admin.gate:` middleware params in each service's
 * routes/api.php to re-derive this list if it drifts) — feeds the roles
 * page's permission checklist. '*' (full access) is handled separately as
 * its own toggle, not listed here.
 */
export const ALL_PERMISSIONS: { group: string; permissions: { value: string; label: string }[] }[] = [
  {
    group: 'Platform',
    permissions: [
      { value: 'dashboard.view', label: 'View dashboards' },
      { value: 'admins.manage', label: 'Manage admins & roles' },
      { value: 'audit_logs.view', label: 'View audit logs' },
    ],
  },
  {
    group: 'Users',
    permissions: [
      { value: 'users.view', label: 'View users' },
      { value: 'users.suspend', label: 'Suspend / unsuspend users' },
      { value: 'chat_logs.view', label: "View users' chat history" },
    ],
  },
  {
    group: 'Subscriptions & Packages',
    permissions: [
      { value: 'subscriptions.view', label: 'View subscriptions' },
      { value: 'packages.manage', label: 'Create & edit packages' },
    ],
  },
  {
    group: 'Payments',
    permissions: [
      { value: 'payments.view', label: 'View transactions' },
      { value: 'payments.refund', label: 'Issue refunds' },
    ],
  },
  {
    group: 'Wallet',
    permissions: [
      { value: 'wallet.view', label: 'View wallet ledger' },
      { value: 'wallet.adjust', label: 'Manually adjust balances' },
    ],
  },
  {
    group: 'AI Usage',
    permissions: [{ value: 'ai_usage.view', label: 'View AI usage logs' }],
  },
  {
    group: 'AI Models',
    permissions: [{ value: 'models.manage', label: 'Create & edit AI models' }],
  },
]
