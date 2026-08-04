'use client'

import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Label } from '@/components/ui/Label'
import { Badge } from '@/components/ui/Badge'
import { Skeleton } from '@/components/ui/Skeleton'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogTrigger } from '@/components/ui/Dialog'
import apiClient from '@/lib/api-client'
import { formatDate } from '@/lib/utils'
import { describeError } from '@/lib/errors'
import { ALL_PERMISSIONS } from '@/lib/permissions'
import type { Role } from '@/types'

interface RoleFormState {
  name: string
  fullAccess: boolean
  permissions: string[]
}

const EMPTY_FORM: RoleFormState = { name: '', fullAccess: false, permissions: [] }

function RoleFormDialog({ role, trigger }: { role?: Role; trigger: React.ReactNode }) {
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<RoleFormState>(
    role ? { name: role.name, fullAccess: role.permissions.includes('*'), permissions: role.permissions.filter((p) => p !== '*') } : EMPTY_FORM
  )

  const save = useMutation({
    mutationFn: async () => {
      const permissions = form.fullAccess ? ['*'] : form.permissions
      const body = { name: form.name, permissions }
      return role
        ? apiClient.patch(`/api/v1/auth/admin/roles/${role.id}`, body)
        : apiClient.post('/api/v1/auth/admin/roles', body)
    },
    onSuccess: () => {
      toast.success(role ? 'Role updated.' : 'Role created.')
      queryClient.invalidateQueries({ queryKey: ['admin', 'roles'] })
      setOpen(false)
    },
    onError: (err: unknown) => toast.error(describeError(err, "We didn't hear back in time — check the roles list before trying again.").message),
  })

  const togglePermission = (value: string) => {
    setForm((f) => ({
      ...f,
      permissions: f.permissions.includes(value) ? f.permissions.filter((p) => p !== value) : [...f.permissions, value],
    }))
  }

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (!form.name.trim()) {
      toast.error('Please enter a name for this role.')
      return
    }
    if (!form.fullAccess && form.permissions.length === 0) {
      toast.error('Select at least one permission, or grant full access.')
      return
    }
    save.mutate()
  }

  return (
    <Dialog open={open} onOpenChange={(next) => { setOpen(next); if (next) setForm(role ? { name: role.name, fullAccess: role.permissions.includes('*'), permissions: role.permissions.filter((p) => p !== '*') } : EMPTY_FORM) }}>
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{role ? 'Edit role' : 'Create role'}</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="role-name">Name</Label>
            <Input id="role-name" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="e.g. billing_support" />
          </div>

          <label className="flex items-center gap-2 text-sm font-medium">
            <input type="checkbox" checked={form.fullAccess} onChange={(e) => setForm({ ...form, fullAccess: e.target.checked })} />
            Full access (all current and future permissions)
          </label>

          {!form.fullAccess && (
            <div className="max-h-72 space-y-4 overflow-y-auto rounded-md border border-border p-3">
              {ALL_PERMISSIONS.map((group) => (
                <div key={group.group}>
                  <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{group.group}</p>
                  <div className="space-y-1.5">
                    {group.permissions.map((perm) => (
                      <label key={perm.value} className="flex items-center gap-2 text-sm">
                        <input
                          type="checkbox"
                          checked={form.permissions.includes(perm.value)}
                          onChange={() => togglePermission(perm.value)}
                        />
                        {perm.label}
                      </label>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          )}

          <DialogFooter>
            <Button type="submit" disabled={save.isPending}>{save.isPending ? 'Saving…' : 'Save'}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

export default function AdminRolesPage() {
  const queryClient = useQueryClient()

  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'roles'],
    queryFn: async () => (await apiClient.get<{ roles: Role[] }>('/api/v1/auth/admin/roles')).data.roles,
  })

  const remove = useMutation({
    mutationFn: async (id: string) => apiClient.delete(`/api/v1/auth/admin/roles/${id}`),
    onSuccess: () => {
      toast.success('Role deleted.')
      queryClient.invalidateQueries({ queryKey: ['admin', 'roles'] })
    },
    onError: (err: unknown) => toast.error(describeError(err, "We didn't hear back in time — check whether the role was actually deleted before retrying.").message),
  })

  return (
    <div className="max-w-3xl space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Roles</h1>
          <p className="mt-1 text-sm text-muted-foreground">Define reusable permission sets — editing a role updates every admin on it immediately.</p>
        </div>
        <RoleFormDialog trigger={<Button>Create role</Button>} />
      </div>

      <Card>
        <CardHeader><CardTitle>{data?.length ?? '…'} roles</CardTitle></CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="space-y-3">
              {Array.from({ length: 3 }).map((_, i) => (
                <div key={i} className="flex items-center justify-between rounded-md border border-border p-3">
                  <div className="space-y-2">
                    <Skeleton className="h-4 w-32" />
                    <Skeleton className="h-3 w-48" />
                  </div>
                  <Skeleton className="h-7 w-16" />
                </div>
              ))}
            </div>
          ) : !data?.length ? (
            <p className="text-sm text-muted-foreground">No roles yet.</p>
          ) : (
            <div className="space-y-3">
              {data.map((role) => (
                <div key={role.id} className="flex items-center justify-between rounded-md border border-border p-3">
                  <div>
                    <p className="font-medium">{role.name}</p>
                    <p className="text-sm text-muted-foreground">
                      {role.permissions.includes('*') ? 'Full access' : `${role.permissions.length} permission${role.permissions.length === 1 ? '' : 's'}`}
                      {' · '}{role.admin_count} admin{role.admin_count === 1 ? '' : 's'}
                      {' · '}since {formatDate(role.created_at)}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    {role.permissions.includes('*') && <Badge variant="success">*</Badge>}
                    <RoleFormDialog role={role} trigger={<Button variant="outline" className="px-2.5 py-1.5 text-xs">Edit</Button>} />
                    <Button
                      variant="destructive"
                      className="px-2.5 py-1.5 text-xs"
                      disabled={remove.isPending || role.admin_count > 0}
                      title={role.admin_count > 0 ? 'Reassign admins off this role before deleting it' : undefined}
                      onClick={() => remove.mutate(role.id)}
                    >
                      Delete
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
