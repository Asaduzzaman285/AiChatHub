'use client'

import { useParams } from 'next/navigation'
import { useQuery } from '@tanstack/react-query'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Skeleton } from '@/components/ui/Skeleton'
import apiClient from '@/lib/api-client'
import { formatDate } from '@/lib/utils'
import type { AdminMeta, ChatMessage } from '@/types'

export default function AdminChatMessagesPage() {
  const { sessionId } = useParams<{ userId: string; sessionId: string }>()

  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'chat-messages', sessionId],
    queryFn: async () =>
      (await apiClient.get<{ messages: ChatMessage[]; meta: AdminMeta }>(`/api/v1/sessions/admin/${sessionId}/messages`)).data,
  })

  return (
    <div className="max-w-3xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Session messages</h1>
        <p className="mt-1 text-sm text-muted-foreground">Read-only transcript.</p>
      </div>

      <Card>
        <CardHeader><CardTitle>{data?.meta.total ?? '…'} messages</CardTitle></CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="space-y-3">
              {Array.from({ length: 4 }).map((_, i) => (
                <div key={i} className="rounded-md border border-border p-3">
                  <div className="mb-2 flex items-center justify-between">
                    <Skeleton className="h-3 w-16" />
                    <Skeleton className="h-3 w-24" />
                  </div>
                  <Skeleton className="h-3 w-full" />
                  <Skeleton className="mt-1.5 h-3 w-2/3" />
                </div>
              ))}
            </div>
          ) : !data?.messages.length ? (
            <p className="text-sm text-muted-foreground">No messages in this session.</p>
          ) : (
            <div className="space-y-3">
              {data.messages.map((m) => (
                <div key={m.id} className="rounded-md border border-border p-3 text-sm">
                  <div className="mb-1 flex items-center justify-between text-xs text-muted-foreground">
                    <span className="font-medium capitalize">{m.role}</span>
                    <span>{formatDate(m.created_at)}</span>
                  </div>
                  <p className="whitespace-pre-wrap">{m.content}</p>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
