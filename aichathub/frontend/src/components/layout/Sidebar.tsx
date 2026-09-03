'use client'

import { useState } from 'react'
import { usePathname, useRouter } from 'next/navigation'
import {
  ChevronDown, ChevronLeft, ChevronRight, Folder, FolderPlus, LogOut, MessageSquare, Plus,
  Settings as SettingsIcon, Trash2,
} from 'lucide-react'
import { useAuthStore } from '@/stores/auth-store'
import { Logo } from '@/components/Logo'
import { SkeletonListItem } from '@/components/ui/Skeleton'
import { SessionRow } from '@/components/chat/SessionRow'
import { PrivateChatPopover } from '@/components/chat/PrivateChatPopover'
import { useChatSession } from '@/contexts/ChatSessionContext'
import { useAvailableModels } from '@/hooks/useAvailableModels'
import { useSidebarCollapsed } from '@/hooks/useSidebarCollapsed'
import { cn } from '@/lib/utils'
import type { SettingsTab } from '@/components/settings/SettingsModal'

/** Extracted out of (dashboard)/layout.tsx's DashboardShell so /welcome (a distinct page,
 * not a modal) can render inside the same persistent shell — same sidebar instance either
 * way, only the main content area to its right ever changes. */
export function Sidebar({ openSettings, onLogout, isPrivate }: {
  openSettings: (tab: SettingsTab) => void
  onLogout: () => void
  // Re-themes the sidebar alongside chat/page.tsx's own incognito palette when the
  // active session is private — applied directly on this component's own root
  // (bg-card is already explicit here, not just inherited), same reasoning as the
  // chat panel's own incognito comment: a CSS-variable redefinition further up the
  // tree can't repaint an ancestor's already-resolved background on its own.
  isPrivate?: boolean
}) {
  const router = useRouter()
  const pathname = usePathname()
  const { user } = useAuthStore()
  const {
    sessions, sessionsLoading, activeSessionId, setActiveSessionId, renameSession, deleteSession,
    projects, projectsLoading, createProject, renameProject, deleteProject, createSession,
  } = useChatSession()
  const { availableModels } = useAvailableModels()
  const { collapsed, toggle: toggleCollapsed } = useSidebarCollapsed()

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

  // Empty projects previously had no way to start a chat at all — "No chats yet." was
  // just plain text, no action (confirmed live). createSession already supports
  // projectId (see ChatSessionContext's CreateSessionParams), just nothing here called
  // it with one. Navigation waits for the mutation's own onSuccess (which is what
  // actually sets activeSessionId) rather than firing immediately — routing to /chat
  // while activeSessionId is still null would let chat/page.tsx's own auto-create
  // effect race this and spin up a second, unwanted ungrouped session first.
  const createChatInProject = (projectId: string) => {
    if (availableModels.length === 0) return
    createSession.mutate(
      { modelId: availableModels[0].id, projectId },
      { onSuccess: () => router.push('/chat') }
    )
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

  // Collapsed: an icon-only rail (logo mark, New chat, Settings, avatar) — the
  // session/project list has no sensible icon-only form (it's text-titled), so it
  // just hides entirely, same pattern as Slack/Notion's collapsed sidebars. Settings
  // sits directly above the avatar/sign-out block, not up near New chat — it's about
  // the account shown right below it, not the chat actions above.
  if (collapsed) {
    return (
      <aside className={cn('hidden w-16 shrink-0 flex-col items-center gap-1 border-r border-border bg-card py-4 sm:flex', isPrivate && 'incognito')}>
        <Logo iconOnly className="h-6 w-6 text-foreground" />

        {/* Same position as the expanded state's collapse button (right next to the
            logo) — it used to sit down here instead, after the flex-1 spacer, so the
            toggle visibly jumped from top to bottom depending on which state you were
            in (confirmed live: "unpredictable interaction, loss of muscle memory"). */}
        <button
          onClick={toggleCollapsed}
          aria-label="Expand sidebar"
          title="Expand sidebar"
          className="mb-3 flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
        >
          <ChevronRight className="h-4 w-4" />
        </button>

        <button
          onClick={() => { setActiveSessionId(null); router.push('/chat') }}
          aria-label="New chat"
          title="New chat"
          className="flex h-9 w-9 items-center justify-center rounded-md border border-border text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
        >
          <Plus className="h-4 w-4" />
        </button>

        <div className="flex-1" />

        <button
          onClick={() => openSettings('account')}
          aria-label="Settings"
          title="Settings"
          className="mb-1 flex h-9 w-9 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
        >
          <SettingsIcon className="h-4 w-4" />
        </button>

        <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
          {user?.name?.[0]?.toUpperCase() ?? '?'}
        </div>
        <button
          onClick={onLogout}
          aria-label="Sign out"
          title="Sign out"
          className="mt-1 flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-destructive"
        >
          <LogOut className="h-3.5 w-3.5" />
        </button>
      </aside>
    )
  }

  return (
    <aside className={cn('hidden w-64 shrink-0 flex-col border-r border-border bg-card sm:flex', isPrivate && 'incognito')}>
      <div className="flex items-center justify-between gap-2 p-4">
        <Logo className="h-6 w-auto text-foreground" />
        <button
          onClick={toggleCollapsed}
          aria-label="Collapse sidebar"
          title="Collapse sidebar"
          className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
        >
          <ChevronLeft className="h-4 w-4" />
        </button>
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
                      {/* Only the empty-project state (below) had a way to start a chat —
                          once a project had at least one, that action disappeared
                          entirely with nothing to replace it (confirmed live: "after
                          creating one chat, there is no option to open a new chat").
                          Same createChatInProject() used there. */}
                      <button
                        onClick={() => createChatInProject(project.id)}
                        disabled={createSession.isPending}
                        className="p-1 text-muted-foreground hover:text-foreground disabled:opacity-50"
                        aria-label="New chat in this project"
                        title="New chat in this project"
                      >
                        <Plus className="h-3 w-3" />
                      </button>
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
                      <button
                        onClick={() => createChatInProject(project.id)}
                        disabled={createSession.isPending}
                        className="flex w-full items-center gap-1.5 px-2.5 py-1.5 text-xs text-muted-foreground transition-colors hover:text-foreground disabled:opacity-50"
                      >
                        <Plus className="h-3 w-3" />
                        New chat
                      </button>
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

      {/* Settings — its own row now, not buried in the username dropdown (a new user
          had no reason to expect it there), and sits directly above the account row
          it's about, not up near the chat actions at the top. */}
      <div className="px-3 pt-2">
        <button
          onClick={() => openSettings('account')}
          className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
        >
          <SettingsIcon className="h-4 w-4" />
          Settings
        </button>
      </div>

      {/* This is just identity + the one remaining action, so a direct button
          replaces the old single-purpose dropdown (Radix's useControllableState only
          fires onOpenChange from real user interaction anyway; a dropdown for one
          item was never buying anything). */}
      <div className="flex items-center gap-2 border-t border-border p-3">
        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
          {user?.name?.[0]?.toUpperCase() ?? '?'}
        </div>
        <span className="min-w-0 flex-1 truncate text-left text-sm text-muted-foreground">{user?.name}</span>
        <button
          onClick={onLogout}
          aria-label="Sign out"
          title="Sign out"
          className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-destructive"
        >
          <LogOut className="h-4 w-4" />
        </button>
      </div>
    </aside>
  )
}
