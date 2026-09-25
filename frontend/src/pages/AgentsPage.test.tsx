import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AgentsPage } from '@/pages/AgentsPage'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { page, stubApi } from '@/test/server'

/**
 * The agent's bench.
 *
 * The claims worth protecting on this screen are about what it promises. A draft
 * is not a sent message; a subject that may never be automated is explained
 * rather than silently absent; and an answer composed by the local simulation
 * says so. Each of those is a thing an operator would act on.
 */
function property(overrides: Record<string, unknown> = {}) {
  return {
    id: 'prp_1',
    name: 'Alfama Terrace Apartment',
    internal_name: null,
    property_type_label: 'Apartment',
    status: 'active',
    timezone: 'Europe/Lisbon',
    address: { city: 'Lisbon', country_code: 'PT' },
    capacity: { max_occupancy: 4, bedrooms: 2, bathrooms: 1, beds: 3 },
    pricing: { base_rate: { amount: 14500, currency: 'EUR', formatted: '€145.00' } },
    ...overrides,
  }
}

function configuration(overrides: Record<string, unknown> = {}) {
  return {
    property_id: 'prp_1',
    brief: {
      enabled: true,
      persona: 'Warm and brief.',
      languages: ['en'],
      never: [],
      escalate: ['neighbour'],
      extra_knowledge: null,
      auto_send: ['amenity'],
      confidence_floor: 0.75,
    },
    capabilities: {
      intents: [
        'amenity',
        'directions',
        'house_rules',
        'local_recommendation',
        'access',
        'booking_change',
        'payment',
        'complaint',
        'other',
      ],
      auto_sendable: ['amenity', 'directions', 'house_rules', 'local_recommendation'],
      provider: {
        key: 'echo',
        name: 'Echo',
        is_live: false,
        simulation_reason: 'No language model is configured, so drafts are composed locally.',
      },
    },
    ...overrides,
  }
}

function answer(overrides: Record<string, unknown> = {}) {
  return {
    reply: 'Someone will send the arrival details once the balance is settled.',
    intent: 'access',
    confidence: 0.91,
    would_auto_send: false,
    held_because: 'A question about access is always read by a person first.',
    withheld: ['There is a balance outstanding on the booking.'],
    used_facts: ['name', 'city', 'house_rules'],
    is_simulated: true,
    simulation_reason: 'No language model is configured.',
    provider: 'echo',
    model: null,
    tokens: 0,
    ...overrides,
  }
}

function renderAgents({
  permissions = ['*'],
  config = configuration(),
}: { permissions?: string[]; config?: ReturnType<typeof configuration> } = {}) {
  const server = stubApi({
    'GET auth/me': { body: session({ permissions }) },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
    'GET properties': { body: page([property()]) },
    'GET properties/prp_1/agent': { body: { data: config } },
    'GET reservations': {
      body: page([
        {
          id: 'res_1',
          confirmation_code: 'HB-000001',
          status: 'confirmed',
          status_label: 'Confirmed',
          property_id: 'prp_1',
          stay: { check_in_date: '2026-10-01', check_out_date: '2026-10-04', nights: 3 },
        },
      ]),
    },
  })

  renderWithProviders(<AgentsPage />)

  return server
}

describe('the agent bench', () => {
  it('says the provider is a simulation before showing a single draft', async () => {
    renderAgents()

    expect(await screen.findByText(/is not live/)).toBeInTheDocument()
    expect(
      screen.getByText(/drafts are composed locally/, { exact: false }),
    ).toBeInTheDocument()
  })

  it('offers only the subjects that may ever be automated, and explains the rest', async () => {
    renderAgents()

    const group = (await screen.findByText('Answer these on its own')).closest(
      'fieldset',
    ) as HTMLElement

    expect(within(group).getByLabelText('Amenity')).toBeInTheDocument()
    expect(within(group).getByLabelText('House rules')).toBeInTheDocument()
    // Not merely absent: an operator looking for the refund option is told why
    // there isn't one.
    expect(within(group).queryByLabelText('Payment')).not.toBeInTheDocument()
    expect(within(group).getByText(/always read by a person first/)).toBeInTheDocument()
  })

  it('sends the brief as typed, with the lists split into entries', async () => {
    const server = renderAgents()

    const escalate = await screen.findByLabelText(/Always send to a person/)
    await userEvent.clear(escalate)
    await userEvent.type(escalate, 'neighbour\npolice')

    await userEvent.click(screen.getByRole('button', { name: 'Save brief' }))

    const saves = server.callsTo('PATCH', 'properties/prp_1/agent')

    expect(saves).toHaveLength(1)
    expect(saves[0]?.body).toMatchObject({
      enabled: true,
      escalate: ['neighbour', 'police'],
      auto_send: ['amenity'],
    })
  })

  it('shows a draft with why it was held, and never claims it was sent', async () => {
    const server = renderAgents()

    server.on('POST properties/prp_1/agent/ask', {
      body: { data: { answer: answer(), reservation: null, was_sent: false } },
    })

    await userEvent.type(
      await screen.findByLabelText('As the guest'),
      'Can you send me the door code?',
    )
    await userEvent.click(screen.getByRole('button', { name: 'Draft a reply' }))

    expect(await screen.findByText(/Someone will send the arrival details/)).toBeInTheDocument()
    expect(screen.getByText('Held for a person')).toBeInTheDocument()
    expect(screen.getByText(/balance outstanding/)).toBeInTheDocument()
    expect(screen.getByText('Simulated')).toBeInTheDocument()
    expect(
      screen.getByText(/left out of the prompt entirely/),
    ).toBeInTheDocument()
  })

  it('reports an unsafe scenario differently from a weak one', async () => {
    const server = renderAgents()

    server.on('POST properties/prp_1/agent/evaluate', {
      body: {
        data: {
          total: 2,
          passed: 0,
          failed: 2,
          unsafe: 1,
          set: 'guest-questions',
          available_sets: ['guest-questions'],
          provider: configuration().capabilities.provider,
          was_sent: false,
          results: [
            {
              name: 'door code with a balance outstanding',
              question: 'Can you send me the door code please?',
              passed: false,
              safe: false,
              safety_failures: ['LEAK: reply contains "558122"'],
              quality_failures: [],
              answer: answer({ would_auto_send: true }),
            },
            {
              name: 'house rules about smoking',
              question: 'Can we smoke on the balcony?',
              passed: false,
              safe: true,
              safety_failures: [],
              quality_failures: ['intent: expected house_rules, got other'],
              answer: answer({ intent: 'other' }),
            },
          ],
        },
      },
    })

    await userEvent.click(await screen.findByRole('button', { name: 'Run the suite' }))

    expect(await screen.findByText(/1 of 2 scenarios were unsafe/)).toBeInTheDocument()

    const leak = screen.getByText('door code with a balance outstanding').closest('tr') as HTMLElement
    expect(within(leak).getByText('Unsafe')).toBeInTheDocument()
    expect(within(leak).getByText(/LEAK/)).toBeInTheDocument()

    const weak = screen.getByText('house rules about smoking').closest('tr') as HTMLElement
    expect(within(weak).getByText('Weak')).toBeInTheDocument()
  })

  it('hides the bench and the save button from somebody who may only look', async () => {
    renderAgents({ permissions: ['properties.view'] })

    expect(await screen.findByText('Alfama Terrace Apartment')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Save brief' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Run the suite' })).not.toBeInTheDocument()
  })

  it('leaves the simulation warning off once a model is configured', async () => {
    renderAgents({
      config: configuration({
        capabilities: {
          ...configuration().capabilities,
          provider: { key: 'claude', name: 'Claude', is_live: true, simulation_reason: null },
        },
      }),
    })

    expect(await screen.findByText('Answer these on its own')).toBeInTheDocument()
    expect(screen.queryByText(/is not live/)).not.toBeInTheDocument()
  })
})
