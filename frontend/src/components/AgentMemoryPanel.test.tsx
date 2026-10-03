import { expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AgentMemoryPanel } from './AgentMemoryPanel'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'
import { session } from '@/test/fixtures'

it('loads memory only on request and forgets a note through its property endpoint', async () => {
  let forgotten = false
  const onForget = vi.fn()
  const api = stubApi({
    'GET auth/me': { body: session() },
    'GET properties/prp_1/agent/memories': () => ({ body: { data: forgotten ? [] : [{ id: 'mem_1', content: 'The spare kettle is in the pantry.', created_at: '2026-10-03T10:00:00Z' }], last_page: 1 } }),
    'DELETE properties/prp_1/agent/memories/mem_1': () => { forgotten = true; return { body: { data: { forgotten: true } } } },
  })
  renderWithProviders(<AgentMemoryPanel propertyId="prp_1" onForget={onForget} />)
  expect(api.callsTo('GET', 'properties/prp_1/agent/memories')).toHaveLength(0)
  const user = userEvent.setup()
  await user.click(screen.getByRole('button', { name: 'Saved property memory' }))
  expect(await screen.findByText('The spare kettle is in the pantry.')).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'Forget this note' }))
  await waitFor(() => expect(onForget).toHaveBeenCalledOnce())
  expect(await screen.findByText('No saved notes yet.')).toBeInTheDocument()
})
