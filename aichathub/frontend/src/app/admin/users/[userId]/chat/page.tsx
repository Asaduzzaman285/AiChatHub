'use client'

import Link from 'next/link'
import { useParams } from 'next/navigation'
import { useQuery } from '@tanstack/react-query'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Skeleton } from '@/components/ui/Skeleton'
import apiClient from '@/lib/api-client'
import { formatDate } from '@/lib/utils'
import type { AdminMeta, ChatSession } from '@/types'

export default function AdminUserChatSessionsPage() {
  const { userId } = useParams<{ userId: string }>()

  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'chat-sessions', userId],
    queryFn: async () =>
      (await apiClient.get<{ chat_sessions: ChatSession[]; meta: AdminMeta }>(`/api/v1/sessions/admin/users/${userId}`)).data,
  })

  return (
    <div className="max-w-3xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Chat history</h1>
        <p className="mt-1 text-sm text-muted-foreground">Sessions for user {userId}.</p>
      </div>

      <Card>
        <CardHeader><CardTitle>{data?.meta.total ?? '…'} sessions</CardTitle></CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="space-y-2">
              {Array.from({ length: 5 }).map((_, i) => (
                <div key={i} className="flex items-center justify-between rounded-md border border-border p-3">
                  <div className="space-y-2">
                    <Skeleton className="h-4 w-40" />
                    <Skeleton className="h-3 w-56" />
                  </div>
                  <Skeleton className="h-5 w-14 rounded-full" />
                </div>
              ))}
            </div>
          ) : !data?.chat_sessions.length ? (
            <p className="text-sm text-muted-foreground">No chat sessions for this user.</p>
          ) : (
            <div className="space-y-2">
              {data.chat_sessions.map((s) => (
                <Link
                  key={s.id}
                  href={`/admin/users/${userId}/chat/${s.id}`}
                  className="flex items-center justify-between rounded-md border border-border p-3 text-sm transition-colors hover:bg-accent"
                >
                  <div>
                    <p className="font-medium">{s.title}</p>
                    <p className="text-muted-foreground">{s.message_count} messages · {formatDate(s.created_at)}</p>
                  </div>
                  <Badge variant={s.status === 'active' ? 'success' : 'neutral'}>{s.status}</Badge>
                </Link>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
