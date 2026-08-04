'use client'

import { useEffect, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Label } from '@/components/ui/Label'
import { Badge } from '@/components/ui/Badge'
import { SkeletonTableRows } from '@/components/ui/Skeleton'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogTrigger } from '@/components/ui/Dialog'
import apiClient from '@/lib/api-client'
import { formatDate } from '@/lib/utils'
import { describeError } from '@/lib/errors'
import type { AdminMeta, AdminUser, AdminUserSummary, Role } from '@/types'

export default function AdminAdminsPage() {
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [mode, setMode] = useState<'promote' | 'create'>('promote')
  const [email, setEmail] = useState('')
  const [name, setName] = useState('')
  const [password, setPassword] = useState('')
  const [role, setRole] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'admins'],
    queryFn: async () => (await apiClient.get<{ admins: AdminUser[]; meta: AdminMeta }>('/api/v1/auth/admin/admins')).data,
  })

  const { data: roles } = useQuery({
    queryKey: ['admin', 'roles'],
    queryFn: async () => (await apiClient.get<{ roles: Role[] }>('/api/v1/auth/admin/roles')).data.roles,
  })

  useEffect(() => {
    if (!role && roles?.length) setRole(roles[0].name)
  }, [role, roles])

  const createAdmin = useMutation({
    mutationFn: async () => {
      if (mode === 'create') {
        return apiClient.post('/api/v1/auth/admin/admins', { mode: 'create', name, email, password, role })
      }

      // 'promote' mode — no "create admin by email" endpoint for this case, so
      // resolve the user_id via the existing user-search filter first, reusing
      // GET /auth/admin/users rather than adding a new backend endpoint just for
      // this lookup.
      const { data: userSearch } = await apiClient.get<{ users: AdminUserSummary[] }>(
        `/api/v1/auth/admin/users?email=${encodeURIComponent(email)}&per_page=1`
      )
      const match = userSearch.users.find((u) => u.email.toLowerCase() === email.toLowerCase())
      if (!match) throw new Error('NOT_FOUND')

      return apiClient.post('/api/v1/auth/admin/admins', { mode: 'promote', user_id: match.id, role })
    },
    onSuccess: () => {
      toast.success('Admin created.')
      queryClient.invalidateQueries({ queryKey: ['admin', 'admins'] })
      setOpen(false)
      setEmail('')
      setName('')
      setPassword('')
    },
    onError: (err: unknown) => {
      if (err instanceof Error && err.message === 'NOT_FOUND') {
        toast.error("We couldn't find a user with that exact email — double-check it and try again.")
        return
      }
      toast.error(describeError(err, "We didn't hear back in time — check the admins list before trying again.").message)
    },
  })

  const updateRole = useMutation({
    mutationFn: async ({ id, role }: { id: string; role: string }) =>
      apiClient.patch(`/api/v1/auth/admin/admins/${id}`, { role }),
    onSuccess: () => {
      toast.success('Role updated.')
      queryClient.invalidateQueries({ queryKey: ['admin', 'admins'] })
    },
    onError: (err: unknown) => toast.error(describeError(err, "We didn't hear back in time — check the admin's role before trying again.").message),
  })

  const toggleActive = useMutation({
    mutationFn: async ({ id, is_active }: { id: string; is_active: boolean }) =>
      apiClient.patch(`/api/v1/auth/admin/admins/${id}`, { is_active }),
    onSuccess: () => {
      toast.success('Admin updated.')
      queryClient.invalidateQueries({ queryKey: ['admin', 'admins'] })
    },
    onError: (err: unknown) => toast.error(describeError(err, "We didn't hear back in time — check the admin's status before trying again.").message),
  })

  const handleCreate = (e: FormEvent) => {
    e.preventDefault()
    if (!email) return
    if (mode === 'create') {
      if (!name.trim()) { toast.error('Please enter a name for this admin.'); return }
      if (password.length < 8) { toast.error('Password must be at least 8 characters.'); return }
    }
    createAdmin.mutate()
  }

  return (
    <div className="max-w-4xl space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Admins</h1>
          <p className="mt-1 text-sm text-muted-foreground">Promote an existing user, or create a brand-new admin account.</p>
        </div>
        <Dialog open={open} onOpenChange={(next) => { setOpen(next); if (next) setMode('promote') }}>
          <DialogTrigger asChild>
            <Button>Add admin</Button>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Add admin</DialogTitle>
            </DialogHeader>
            <div className="flex gap-1 rounded-md bg-muted p-1 text-sm">
              <button
                type="button"
                onClick={() => setMode('promote')}
                className={`flex-1 rounded px-3 py-1.5 font-medium transition-colors ${mode === 'promote' ? 'bg-card shadow-sm' : 'text-muted-foreground hover:text-foreground'}`}
              >
                Promote existing user
              </button>
              <button
                type="button"
                onClick={() => setMode('create')}
                className={`flex-1 rounded px-3 py-1.5 font-medium transition-colors ${mode === 'create' ? 'bg-card shadow-sm' : 'text-muted-foreground hover:text-foreground'}`}
              >
                Create new admin
              </button>
            </div>
            <form onSubmit={handleCreate} className="space-y-4">
              {mode === 'create' && (
                <div className="space-y-1.5">
                  <Label htmlFor="admin-name">Name</Label>
                  <Input id="admin-name" required value={name} onChange={(e) => setName(e.target.value)} placeholder="Jane Doe" />
                </div>
              )}
              <div className="space-y-1.5">
                <Label htmlFor="admin-email">{mode === 'create' ? 'Email' : 'User email'}</Label>
                <Input id="admin-email" type="email" required value={email} onChange={(e) => setEmail(e.target.value)} placeholder="user@example.com" />
                {mode === 'promote' && (
                  <p className="text-xs text-muted-foreground">Must match an existing user&apos;s email exactly.</p>
                )}
              </div>
              {mode === 'create' && (
                <div className="space-y-1.5">
                  <Label htmlFor="admin-password">Password</Label>
                  <Input id="admin-password" type="password" required value={password} onChange={(e) => setPassword(e.target.value)} placeholder="Min 8 characters" />
                  <p className="text-xs text-muted-foreground">
                    This account is created with no wallet or subscription — it&apos;s a pure admin, not a consumer account.
                  </p>
                </div>
              )}
              <div className="space-y-1.5">
                <Label htmlFor="admin-role">Role</Label>
                <Select id="admin-role" value={role} onChange={(e) => setRole(e.target.value)}>
                  {roles?.map((r) => (
                    <option key={r.id} value={r.name}>{r.name}</option>
                  ))}
                </Select>
              </div>
              <DialogFooter>
                <Button type="submit" disabled={createAdmin.isPending}>
                  {createAdmin.isPending ? 'Creating…' : 'Create'}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>

      <Card>
        <CardHeader><CardTitle>{data?.meta.total ?? '…'} admins</CardTitle></CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <tbody><SkeletonTableRows columns={6} /></tbody>
              </table>
            </div>
          ) : !data?.admins.length ? (
            <p className="text-sm text-muted-foreground">No admins yet.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-muted-foreground">
                    <th className="pb-2 font-medium">Name</th>
                    <th className="pb-2 font-medium">Email</th>
                    <th className="pb-2 font-medium">Role</th>
                    <th className="pb-2 font-medium">Status</th>
                    <th className="pb-2 font-medium">Since</th>
                    <th className="pb-2 font-medium text-right">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {data.admins.map((a) => (
                    <tr key={a.id} className="border-b border-border last:border-0">
                      <td className="py-2">{a.user.name}</td>
                      <td className="py-2 text-muted-foreground">{a.user.email}</td>
                      <td className="py-2">
                        <Select
                          className="w-auto py-1"
                          value={a.role}
                          disabled={updateRole.isPending}
                          onChange={(e) => updateRole.mutate({ id: a.id, role: e.target.value })}
                        >
                          {roles?.map((r) => (
                            <option key={r.id} value={r.name}>{r.name}</option>
                          ))}
                        </Select>
                      </td>
                      <td className="py-2">
                        <Badge variant={a.is_active ? 'success' : 'destructive'}>{a.is_active ? 'active' : 'revoked'}</Badge>
                      </td>
                      <td className="py-2 text-muted-foreground">{formatDate(a.created_at)}</td>
                      <td className="py-2 text-right">
                        <Button
                          variant={a.is_active ? 'destructive' : 'outline'}
                          className="px-2.5 py-1.5 text-xs"
                          disabled={toggleActive.isPending}
                          onClick={() => toggleActive.mutate({ id: a.id, is_active: !a.is_active })}
                        >
                          {a.is_active ? 'Revoke' : 'Reactivate'}
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
