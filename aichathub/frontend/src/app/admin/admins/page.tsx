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
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogTrigger } from '@/components/ui/Dialog'
import apiClient from '@/lib/api-client'
import { formatDate } from '@/lib/utils'
import { describeError } from '@/lib/errors'
import type { AdminMeta, AdminUser, AdminUserSummary, Role } from '@/types'

export default function AdminAdminsPage() {
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [email, setEmail] = useState('')
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
      // No "create admin by email" endpoint exists — resolve the user_id via the
      // existing user-search filter first, reusing GET /auth/admin/users rather
      // than adding a new backend endpoint just for this lookup.
      const { data: userSearch } = await apiClient.get<{ users: AdminUserSummary[] }>(
        `/api/v1/auth/admin/users?email=${encodeURIComponent(email)}&per_page=1`
      )
      const match = userSearch.users.find((u) => u.email.toLowerCase() === email.toLowerCase())
      if (!match) throw new Error('NOT_FOUND')

      return apiClient.post('/api/v1/auth/admin/admins', { user_id: match.id, role })
    },
    onSuccess: () => {
      toast.success('Admin created.')
      queryClient.invalidateQueries({ queryKey: ['admin', 'admins'] })
      setOpen(false)
      setEmail('')
    },
    onError: (err: unknown) => {
      if (err instanceof Error && err.message === 'NOT_FOUND') {
        toast.error('No user found with that exact email.')
        return
      }
      toast.error(describeError(err, 'Could not create admin.').message)
    },
  })

  const updateRole = useMutation({
    mutationFn: async ({ id, role }: { id: string; role: string }) =>
      apiClient.patch(`/api/v1/auth/admin/admins/${id}`, { role }),
    onSuccess: () => {
      toast.success('Role updated.')
      queryClient.invalidateQueries({ queryKey: ['admin', 'admins'] })
    },
    onError: (err: unknown) => toast.error(describeError(err, 'Could not update role.').message),
  })

  const toggleActive = useMutation({
    mutationFn: async ({ id, is_active }: { id: string; is_active: boolean }) =>
      apiClient.patch(`/api/v1/auth/admin/admins/${id}`, { is_active }),
    onSuccess: () => {
      toast.success('Admin updated.')
      queryClient.invalidateQueries({ queryKey: ['admin', 'admins'] })
    },
    onError: (err: unknown) => toast.error(describeError(err, 'Could not update admin.').message),
  })

  const handleCreate = (e: FormEvent) => {
    e.preventDefault()
    if (!email) return
    createAdmin.mutate()
  }

  return (
    <div className="max-w-4xl space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Admins</h1>
          <p className="mt-1 text-sm text-muted-foreground">Grant admin roles to existing users.</p>
        </div>
        <Dialog open={open} onOpenChange={setOpen}>
          <DialogTrigger asChild>
            <Button>Add admin</Button>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Add admin</DialogTitle>
            </DialogHeader>
            <form onSubmit={handleCreate} className="space-y-4">
              <div className="space-y-1.5">
                <Label htmlFor="admin-email">User email</Label>
                <Input id="admin-email" type="email" required value={email} onChange={(e) => setEmail(e.target.value)} placeholder="user@example.com" />
                <p className="text-xs text-muted-foreground">Must match an existing user&apos;s email exactly.</p>
              </div>
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
            <p className="text-sm text-muted-foreground">Loading…</p>
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
