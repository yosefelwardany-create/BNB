import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/api/client'

export function AgentMemoryPanel({ propertyId, onForget }: { propertyId: string; onForget?: () => void }) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [filter, setFilter] = useState('')
  const [page, setPage] = useState(1)
  const client = useQueryClient()
  const notes = useQuery({
    queryKey: ['agent-memories', propertyId, filter, page],
    queryFn: () => api.get<{ data: { id: string; content: string; created_at: string }[]; last_page: number }>(`properties/${propertyId}/agent/memories`, { search: filter, page }),
    enabled: open,
  })
  const forget = useMutation({
    mutationFn: (id: string) => api.delete(`properties/${propertyId}/agent/memories/${id}`),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: ['agent-memories', propertyId] })
      onForget?.()
    },
  })
  return <section className="agent-memory small">
    <button type="button" className="btn btn--sm btn--ghost" aria-expanded={open} onClick={() => setOpen(!open)}>Saved property memory</button>
    {open && <div className="stack p-2">
      <p className="muted">Your messages are saved for this property across chats. Only you and this property's agent use these notes; they are not shared with guests. Questions are kept as context, not treated as facts.</p>
      <form className="row" onSubmit={(event) => { event.preventDefault(); setFilter(search); setPage(1) }}>
        <input aria-label="Search saved memory" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Find something you told the agent" />
        <button className="btn btn--sm" type="submit">Search</button>
      </form>
      {notes.isPending && <p>Loading saved memory…</p>}
      {(notes.isError || forget.isError) && <p role="alert">Saved memory could not be updated. Please try again.</p>}
      {notes.data?.data.length === 0 && <p>No saved notes yet.</p>}
      {notes.data?.data.map((note) => <div key={note.id} className="bordered p-2 stack">
        <time className="faint">{new Date(note.created_at).toLocaleString()}</time>
        <p className="agent-memory__content">{note.content}</p>
        <button type="button" className="btn btn--sm btn--ghost" disabled={forget.isPending} onClick={() => forget.mutate(note.id)}>Forget this note</button>
      </div>)}
      <div className="row">
        {page > 1 && <button className="btn btn--sm" onClick={() => setPage(page - 1)}>Previous notes</button>}
        {page < (notes.data?.last_page ?? 1) && <button className="btn btn--sm" onClick={() => setPage(page + 1)}>Older notes</button>}
      </div>
    </div>}
  </section>
}
