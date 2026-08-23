'use client'

import { Bot, Layers, GitCompare, FolderKanban, Zap, Gauge } from 'lucide-react'

const FEATURES = [
  { icon: Bot, title: '20+ Leading AI Models', body: 'Access GPT, Claude, Gemini and more from one workspace.' },
  { icon: Layers, title: 'Every Format, One Chat', body: 'Upload images, PDFs, audio, and video — Alveta reads it all.' },
  { icon: GitCompare, title: 'Compare & Choose', body: 'Run one prompt across multiple models and pick the response that works best.' },
  { icon: FolderKanban, title: 'Project Workspaces', body: 'Keep chats, files, prompts, and outputs organized in project-based workspaces.' },
  { icon: Zap, title: 'Auto Mode', body: 'Describe your goal, Alveta recommends the right model automatically.' },
  { icon: Gauge, title: 'Smart Usage Control', body: 'Track usage by top-tag, budgeting, and manage your AI spend — all in one place.' },
]

export function WelcomeFeatureGrid() {
  return (
    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
      {FEATURES.map((f) => (
        <div key={f.title} className="rounded-xl border border-border bg-card p-4">
          <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10 text-primary">
            <f.icon className="h-4 w-4" />
          </div>
          <h3 className="mt-2.5 text-sm font-semibold text-foreground">{f.title}</h3>
          <p className="mt-1 text-xs text-muted-foreground">{f.body}</p>
        </div>
      ))}
    </div>
  )
}
