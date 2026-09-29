import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { PropertiesPage } from '@/pages/PropertiesPage'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { page, stubApi } from '@/test/server'

/**
 * Creating and editing a property from the screen.
 *
 * This existed only through the API until now, which made the platform unusable
 * for an operator with no channel connected: every record a channel would have
 * imported had to be typed in, and there was nowhere to type it.
 */
function property(overrides: Record<string, unknown> = {}) {
  return {
    id: 'prp_1',
    name: 'Alfama Terrace Apartment',
    internal_name: null,
    display_name: 'Alfama Terrace Apartment',
    slug: 'alfama',
    property_type: 'apartment',
    property_type_label: 'Apartment',
    rental_kind: 'entire_place',
    status: 'active',
    is_multi_unit: false,
    tracks_availability_per_unit: false,
    portfolio_id: null,
    address: {
      line_1: 'Rua dos Remédios 84',
      line_2: null,
      city: 'Lisbon',
      state: null,
      postal_code: '1100-443',
      country_code: 'PT',
      latitude: null,
      longitude: null,
    },
    timezone: 'Europe/Lisbon',
    currency: 'EUR',
    local_time: '10:00',
    capacity: { bedrooms: 2, bathrooms: 1, beds: 3, max_occupancy: 4, max_pets: 0 },
    pricing: {
      base_rate: { amount: 14500, currency: 'EUR', formatted: '€145.00' },
      cleaning_fee: { amount: 6500, currency: 'EUR', formatted: '€65.00' },
      minimum_nights: 2,
      maximum_nights: null,
      instant_book: true,
    },
    created_at: null,
    ...overrides,
  }
}

function renderProperties(permissions: string[] = ['*']) {
  const server = stubApi({
    'GET auth/me': { body: session({ permissions }) },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
    'GET properties': { body: page([property()]) },
  })

  renderWithProviders(<PropertiesPage />)

  return server
}

describe('entering a property by hand', () => {
  it('creates one from the screen', async () => {
    const server = renderProperties()
    server.on('POST properties', { status: 201, body: { data: property() } })

    await userEvent.click(await screen.findByRole('button', { name: /New property/ }))
    await userEvent.type(screen.getByLabelText(/Name/), 'Baixa Riverside')
    await userEvent.selectOptions(screen.getByLabelText(/Type/), 'apartment')
    await userEvent.type(screen.getByLabelText(/Base rate/), '165')
    await userEvent.click(screen.getByRole('button', { name: 'Create property' }))

    const [created] = server.callsTo('POST', 'properties')

    // Money reaches the API in the minor units it stores everywhere else.
    expect(created?.body).toMatchObject({
      name: 'Baixa Riverside',
      property_type: 'apartment',
      base_rate: 16500,
    })
  })

  it('edits one without resending what nobody touched', async () => {
    const server = renderProperties()
    server.on('PATCH properties/prp_1', { body: { data: property() } })

    await userEvent.click((await screen.findAllByRole('button', { name: /Edit/ }))[0]!)
    await userEvent.clear(screen.getByLabelText('Sleeps'))
    await userEvent.type(screen.getByLabelText('Sleeps'), '6')
    await userEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    const [saved] = server.callsTo('PATCH', 'properties/prp_1')

    expect(saved?.body).toEqual({ max_occupancy: '6' })
  })

  it('shows the server’s validation error against the field', async () => {
    const server = renderProperties()
    server.on('POST properties', {
      status: 422,
      body: { message: 'Invalid.', errors: { name: ['That name is already taken.'] } },
    })

    await userEvent.click(await screen.findByRole('button', { name: /New property/ }))
    await userEvent.type(screen.getByLabelText(/Name/), 'Alfama Terrace Apartment')
    await userEvent.click(screen.getByRole('button', { name: 'Create property' }))

    const field = (await screen.findByLabelText(/Name/)).closest('.field') as HTMLElement

    expect(within(field).getByText('That name is already taken.')).toBeInTheDocument()
  })

  it('offers nothing to somebody who may only look', async () => {
    renderProperties(['properties.view'])

    expect(await screen.findByText('Alfama Terrace Apartment')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /New property/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Edit/ })).not.toBeInTheDocument()
  })
})
