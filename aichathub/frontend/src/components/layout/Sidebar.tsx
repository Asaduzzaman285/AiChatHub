'use client'

import { useState } from 'react'
import { usePathname, useRouter } from 'next/navigation'
import {
  ChevronDown, ChevronRight, Folder, FolderPlus, LogOut, MessageSquare, Plus,
  Settings as SettingsIcon, Trash2,
} from 'lucide-react'
import { useAuthStore } from '@/stores/auth-store'
import { Logo } from '@/components/Logo'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/DropdownMenu'
import { SkeletonListItem } from '@/components/ui/Skeleton'
import { SessionRow } from '@/components/chat/SessionRow'
import { PrivateChatPopover } from '@/components/chat/PrivateChatPopover'
import { useChatSession } from '@/contexts/ChatSessionContext'
import type { SettingsTab } from '@/components/settings/SettingsModal'

/** Extracted out of (dashboard)/layout.tsx's DashboardShell so /welcome (a distinct page,
 * not a modal) can render inside the same persistent shell — same sidebar instance either
 * way, only the main content area to its right ever changes. */
export function Sidebar({ openSettings, onLogout }: {
  openSettings: (tab: SettingsTab) => void
  onLogout: () => void
}) {
  const router = useRouter()
  const pathname = usePathname()
  const { user } = useAuthStore()
  const {
    sessions, sessionsLoading, activeSessionId, setActiveSessionId, renameSession, deleteSession,
    projects, projectsLoading, createProject, renameProject, deleteProject,
  } = useChatSession()

  const [renamingId, setRenamingId] = useState<string | null>(null)
  const [renameValue, setRenameValue] = useState('')

  const [renamingProjectId, setRenamingProjectId] = useState<string | null>(null)
  const [renameProjectValue, setRenameProjectValue] = useState('')
  const [creatingProject, setCreatingProject] = useState(false)
  const [newProjectName, setNewProjectName] = useState('')
  const [expandedProjectIds, setExpandedProjectIds] = useState<Set<string>>(new Set())

  const startRename = (id: string, title: string) => {
    setRenamingId(id)
    setRenameValue(title)
  }

  const commitRename = () => {
    const title = renameValue.trim()
    if (!renamingId) return
    if (!title) { setRenamingId(null); return }
    renameSession.mutate({ id: renamingId, title })
    setRenamingId(null)
  }

  const confirmDelete = (id: string, title: string) => {
    if (window.confirm(`Delete "${title}"? This can't be undone.`)) {
      deleteSession.mutate(id)
    }
  }

  const openSession = (id: string) => {
    setActiveSessionId(id)
    router.push(`/chat?session=${id}`)
  }

  const toggleProject = (id: string) => {
    setExpandedProjectIds((prev) => {
      const next = new Set(prev)
      next.has(id) ? next.delete(id) : next.add(id)
      return next
    })
  }

  const commitNewProject = () => {
    const name = newProjectName.trim()
    setCreatingProject(false)
    setNewProjectName('')
    if (!name) return
    createProject.mutate({ name }, {
      onSuccess: (project) => setExpandedProjectIds((prev) => new Set(prev).add(project.id)),
    })
  }

  const commitProjectRename = () => {
    const name = renameProjectValue.trim()
    if (!renamingProjectId) return
    if (!name) { setRenamingProjectId(null); return }
    renameProject.mutate({ id: renamingProjectId, name })
    setRenamingProjectId(null)
  }

  // Sidebar's quick action only offers the safe default (keep every chat, just detach
  // them from the project) — the destructive "delete the project's chats too" option
  // needs its own explicit, harder-to-misclick confirmation, which belongs in a real
  // project settings view rather than a plain window.confirm (no way to tell a Cancel
  // from a modifier-key click with that API). Out of scope for this grouping-foundation
  // pass, same as the fuller ProjectModal itself.
  const confirmDeleteProject = (id: string, name: string) => {
    if (window.confirm(`Delete "${name}"? Its chats will be kept and moved out of the project.`)) {
      deleteProject.mutate({ id, mode: 'orphan' })
    }
  }

  const ungroupedSessions = sessions?.filter((s) => !s.project_id) ?? []

  return (
    <aside className="hidden w-64 shrink-0 border-r border-border bg-card sm:flex sm:flex-col">
      <div className="flex items-center gap-2 p-4">
        <Logo className="h-6 w-auto text-foreground" />
      </div>

      <div className="flex items-center gap-1 px-3 pb-2">
        <button
          onClick={() => { setActiveSessionId(null); router.push('/chat') }}
          className="flex flex-1 items-center gap-2 rounded-md border border-border px-3 py-2 text-sm font-medium transition-colors hover:bg-accent"
        >
          <Plus className="h-4 w-4" />
          New chat
        </button>

        <PrivateChatPopover
          trigger={
            <button
              aria-label="Start a private chat"
              title="Start a private chat"
              className="flex shrink-0 items-center justify-center rounded-md border border-border p-2 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            >
              <ChevronDown className="h-4 w-4" />
            </button>
          }
        />
      </div>

      <nav className="flex-1 overflow-y-auto px-3">
        {/* Projects */}
        <div className="mb-1 flex items-center justify-between px-2.5 pt-2">
          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground/70">Projects</span>
          <button
            onClick={() => setCreatingProject(true)}
            className="p-0.5 text-muted-foreground hover:text-foreground"
            aria-label="New project"
          >
            <FolderPlus className="h-3.5 w-3.5" />
          </button>
        </div>

        {creatingProject && (
          <input
            autoFocus
            value={newProjectName}
            onChange={(e) => setNewProjectName(e.target.value)}
            onBlur={commitNewProject}
            onKeyDown={(e) => {
              if (e.key === 'Enter') commitNewProject()
              if (e.key === 'Escape') { setCreatingProject(false); setNewProjectName('') }
            }}
            placeholder="Project name"
            className="mx-2.5 mb-1 w-[calc(100%-20px)] rounded border border-input bg-background px-1.5 py-1 text-sm"
          />
        )}

        {projectsLoading ? (
          <SkeletonListItem />
        ) : (
          projects?.map((project) => {
            const expanded = expandedProjectIds.has(project.id)
            const projectSessions = sessions?.filter((s) => s.project_id === project.id) ?? []
            return (
              <div key={project.id}>
                <div className="group flex items-center gap-1 rounded-md px-2.5 py-1.5 text-sm transition-colors hover:bg-accent">
                  <button onClick={() => toggleProject(project.id)} className="shrink-0 text-muted-foreground">
                    {expanded ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
                  </button>
                  <Folder className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                  {renamingProjectId === project.id ? (
                    <input
                      autoFocus
                      value={renameProjectValue}
                      onChange={(e) => setRenameProjectValue(e.target.value)}
                      onBlur={commitProjectRename}
                      onKeyDown={(e) => {
                        if (e.key === 'Enter') commitProjectRename()
                        if (e.key === 'Escape') setRenamingProjectId(null)
                      }}
                      className="flex-1 min-w-0 rounded border border-input bg-background px-1.5 py-0.5 text-sm"
                    />
                  ) : (
                    <button onClick={() => toggleProject(project.id)} className="flex-1 min-w-0 truncate text-left">
                      {project.name}
                    </button>
                  )}
                  <span className="shrink-0 text-[11px] text-muted-foreground/60">{project.sessions_count}</span>
                  {renamingProjectId !== project.id && (
                    <div className="flex shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                      <button
                        onClick={() => { setRenamingProjectId(project.id); setRenameProjectValue(project.name) }}
                        className="p-1 text-muted-foreground hover:text-foreground"
                        aria-label="Rename project"
                      >
                        <SettingsIcon className="h-3 w-3" />
                      </button>
                      <button
                        onClick={() => confirmDeleteProject(project.id, project.name)}
                        className="p-1 text-muted-foreground hover:text-destructive"
                        aria-label="Delete project"
                      >
                        <Trash2 className="h-3 w-3" />
                      </button>
                    </div>
                  )}
                </div>
                {expanded && (
                  <div className="ml-4 border-l border-border pl-1">
                    {projectSessions.length === 0 ? (
                      <p className="px-2.5 py-1.5 text-xs text-muted-foreground">No chats yet.</p>
                    ) : (
                      projectSessions.map((s) => (
                        <SessionRow
                          key={s.id}
                          session={s}
                          active={s.id === activeSessionId && pathname === '/chat'}
                          renaming={renamingId === s.id}
                          renameValue={renameValue}
                          onRenameValueChange={setRenameValue}
                          onOpen={() => openSession(s.id)}
                          onStartRename={() => startRename(s.id, s.title)}
                          onCommitRename={commitRename}
                          onCancelRename={() => setRenamingId(null)}
                          onDelete={() => confirmDelete(s.id, s.title)}
                        />
                      ))
                    )}
                  </div>
                )}
              </div>
            )
          })
        )}

        {/* Chats — ungrouped only, project chats render inside their own project above */}
        <div className="mb-1 mt-4 px-2.5">
          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground/70">Chats</span>
        </div>
        {sessionsLoading ? (
          <>{Array.from({ length: 6 }).map((_, i) => <SkeletonListItem key={i} />)}</>
        ) : !ungroupedSessions.length ? (
          <div className="p-6 text-center space-y-2">
            <MessageSquare className="h-8 w-8 mx-auto text-muted-foreground/40" />
            <p className="text-xs text-muted-foreground">No chats yet — start one above.</p>
          </div>
        ) : (
          ungroupedSessions.map((s) => (
            <SessionRow
              key={s.id}
              session={s}
              active={s.id === activeSessionId && pathname === '/chat'}
              renaming={renamingId === s.id}
              renameValue={renameValue}
              onRenameValueChange={setRenameValue}
              onOpen={() => openSession(s.id)}
              onStartRename={() => startRename(s.id, s.title)}
              onCommitRename={commitRename}
              onCancelRename={() => setRenamingId(null)}
              onDelete={() => confirmDelete(s.id, s.title)}
            />
          ))
        )}
      </nav>

      <div className="border-t border-border p-3">
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <button className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm outline-none transition-colors hover:bg-accent">
              <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                {user?.email?.[0]?.toUpperCase() ?? '?'}
              </div>
              <span className="min-w-0 flex-1 truncate text-left text-muted-foreground">{user?.email}</span>
              <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
            </button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="start" className="w-56">
            <DropdownMenuItem onClick={() => openSettings('account')}>
              <SettingsIcon className="h-4 w-4" />
              Settings
            </DropdownMenuItem>
            <DropdownMenuItem onClick={onLogout}>
              <LogOut className="h-4 w-4" />
              Sign out
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </aside>
  )
}
