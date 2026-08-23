'use client'

import { formatDistanceToNow } from 'date-fns'
import { Clock, Pencil, Trash2 } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { ChatSession } from '@/types'

/** Extracted because it's now genuinely rendered in two places — the flat "Chats" list
 * and inside each expanded project — not a speculative abstraction. */
export function SessionRow({
  session, active, renaming, renameValue, onRenameValueChange, onOpen, onStartRename, onCommitRename,
  onCancelRename, onDelete,
}: {
  session: ChatSession
  active: boolean
  renaming: boolean
  renameValue: string
  onRenameValueChange: (v: string) => void
  onOpen: () => void
  onStartRename: () => void
  onCommitRename: () => void
  onCancelRename: () => void
  onDelete: () => void
}) {
  return (
    <div
      className={cn(
        'group flex items-center gap-1 rounded-md px-2.5 py-2 text-sm transition-colors hover:bg-accent',
        active && 'bg-accent'
      )}
    >
      {renaming ? (
        <input
          autoFocus
          value={renameValue}
          onChange={(e) => onRenameValueChange(e.target.value)}
          onBlur={onCommitRename}
          onKeyDown={(e) => {
            if (e.key === 'Enter') onCommitRename()
            if (e.key === 'Escape') onCancelRename()
          }}
          className="flex-1 min-w-0 rounded border border-input bg-background px-1.5 py-0.5 text-sm"
        />
      ) : (
        <button onClick={onOpen} className="flex-1 min-w-0 truncate text-left">
          {session.title}
        </button>
      )}
      {/* Persistent (not hover-gated like rename/delete below) — this is informational,
          not an action, so it shouldn't disappear on mouse-out. */}
      {session.is_private && session.expires_at && (
        <span
          className="flex shrink-0 items-center text-muted-foreground"
          title={`Deletes ${formatDistanceToNow(new Date(session.expires_at), { addSuffix: true })}`}
        >
          <Clock className="h-3 w-3" />
        </span>
      )}
      {!renaming && (
        <div className="flex shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
          <button onClick={onStartRename} className="p-1 text-muted-foreground hover:text-foreground" aria-label="Rename chat">
            <Pencil className="h-3.5 w-3.5" />
          </button>
          <button onClick={onDelete} className="p-1 text-muted-foreground hover:text-destructive" aria-label="Delete chat">
            <Trash2 className="h-3.5 w-3.5" />
          </button>
        </div>
      )}
    </div>
  )
}
