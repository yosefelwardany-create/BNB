import { expect, it } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { PropertyKnowledgeBase } from './PropertyKnowledgeBase'
import type { PropertyKnowledgeEntry } from '@/api/types'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'
import { session } from '@/test/fixtures'

const cleaner: PropertyKnowledgeEntry = {
  id: 'kn_1',
  topic: 'Cleaner',
  content: 'Irish, 416 576 4750. Schedule 3 days before cleaning.',
  source: 'agent',
  updated_by: 'Yosef E',
  updated_at: '2026-10-07T10:00:00Z',
}

it('shows what the agent saved, and lets a manager correct, add and delete entries', async () => {
  let entries: PropertyKnowledgeEntry[] = [cleaner]
  const api = stubApi({
    'GET auth/me': { body: session() },
    'GET properties/prp_1/agent/knowledge': () => ({ body: { data: entries } }),
    'PATCH properties/prp_1/agent/knowledge/kn_1': () => {
      entries = [{ ...cleaner, content: 'Maria, 416 000 1111', source: 'manual' }]
      return { body: { data: entries[0] } }
    },
    'POST properties/prp_1/agent/knowledge': () => {
      entries = [...entries, { ...cleaner, id: 'kn_2', topic: 'Spare key', content: 'Concierge' }]
      return { status: 201, body: { data: entries[1] } }
    },
    'DELETE properties/prp_1/agent/knowledge/kn_2': () => {
      entries = entries.filter((entry) => entry.id !== 'kn_2')
      return { body: { data: { deleted: true } } }
    },
  })
  const user = userEvent.setup()

  renderWithProviders(<PropertyKnowledgeBase propertyId="prp_1" mayEdit />)

  expect(await screen.findByText(cleaner.content)).toBeInTheDocument()
  expect(screen.getByText(/Saved by the agent for Yosef E/)).toBeInTheDocument()

  await user.click(screen.getByRole('button', { name: 'Edit Cleaner' }))
  const text = screen.getByLabelText('What to know')
  await user.clear(text)
  await user.type(text, 'Maria, 416 000 1111')
  await user.click(screen.getByRole('button', { name: 'Save' }))
  expect(await screen.findByText('Maria, 416 000 1111')).toBeInTheDocument()
  expect(api.callsTo('PATCH', 'properties/prp_1/agent/knowledge/kn_1')[0]?.body).toEqual({
    topic: 'Cleaner',
    content: 'Maria, 416 000 1111',
  })

  await user.click(screen.getByRole('button', { name: 'Add an entry' }))
  await user.type(screen.getByLabelText('Topic'), 'Spare key')
  await user.type(screen.getByLabelText('What to know'), 'Concierge')
  await user.click(screen.getByRole('button', { name: 'Save' }))
  expect(await screen.findByText('Spare key')).toBeInTheDocument()

  await user.click(screen.getByRole('button', { name: 'Delete Spare key' }))
  await waitFor(() => expect(screen.queryByText('Spare key')).not.toBeInTheDocument())
})

it('is read-only for somebody who may not edit the property', async () => {
  stubApi({
    'GET auth/me': { body: session() },
    'GET properties/prp_1/agent/knowledge': { body: { data: [cleaner] } },
  })

  renderWithProviders(<PropertyKnowledgeBase propertyId="prp_1" mayEdit={false} />)

  expect(await screen.findByText(cleaner.content)).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Add an entry' })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Edit Cleaner' })).not.toBeInTheDocument()
})
