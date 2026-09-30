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

function listing(overrides: Record<string, unknown> = {}) {
  return {
    id: 'lst_1',
    property_id: 'prp_1',
    name: 'Alfama Terrace Apartment',
    status: 'draft',
    is_primary: true,
    is_bookable: false,
    inventory_scope: 'property',
    title: 'Alfama Terrace Apartment',
    currency: 'EUR',
    pricing: {
      base_rate: { amount: 14500, currency: 'EUR', formatted: '€145.00' },
      cleaning_fee: { amount: 6500, currency: 'EUR', formatted: '€65.00' },
      minimum_nights: 2,
      maximum_nights: null,
    },
    overridden_fields: [],
    published_at: null,
    ...overrides,
  }
}

function renderProperties(permissions: string[] = ['*']) {
  const server = stubApi({
    'GET auth/me': { body: session({ permissions }) },
    'GET organization/announcements': { body: { data: [], meta: { maintenance_notice: null } } },
    'GET properties': { body: page([property()]) },
    'GET amenities': { body: { data: [{ id: 'amn_1', key: 'wifi', name: 'Wi-Fi', category: null, is_highlight: true, is_mappable: true }] } },
    'GET properties/prp_1': { body: { data: property() } },
    'GET properties/prp_1/photos': { body: { data: [] } },
    'GET properties/prp_1/readiness': { body: { ready: true, blockers: [], status: 'active' } },
    'GET properties/prp_1/listings': { body: { data: [listing()] } },
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

describe('the fields a listing actually needs', () => {
  it('offers everything an operator has to type in without a channel', async () => {
    renderProperties()

    await userEvent.click(await screen.findByRole('button', { name: /New property/ }))

    // The ones that were missing, each of which the API has always accepted.
    for (const label of [
      'Summary',
      'Description',
      'Minimum nights',
      'Wi-Fi network',
      'Wi-Fi password',
      'Door code',
      'Arrival instructions',
    ]) {
      expect(screen.getByLabelText(new RegExp(label))).toBeDefined()
    }

    // A group of checkboxes rather than one control, so it is named as a group.
    expect(screen.getByRole('group', { name: 'Amenities' })).toBeInTheDocument()
  })

  it('accepts a 28-night minimum, which some cities require', async () => {
    const server = renderProperties()
    server.on('POST properties', { status: 201, body: { data: property() } })

    await userEvent.click(await screen.findByRole('button', { name: /New property/ }))
    await userEvent.type(screen.getByLabelText(/Name/), 'Long let')
    await userEvent.type(screen.getByLabelText('Minimum nights'), '28')
    await userEvent.click(screen.getByRole('button', { name: 'Create property' }))

    expect(server.callsTo('POST', 'properties')[0]?.body).toMatchObject({ minimum_nights: '28' })
  })

  it('sends amenities as a list of ids', async () => {
    const server = renderProperties()
    server.on('POST properties', { status: 201, body: { data: property() } })

    await userEvent.click(await screen.findByRole('button', { name: /New property/ }))
    await userEvent.type(screen.getByLabelText(/Name/), 'With wifi')
    await userEvent.click(await screen.findByLabelText('Wi-Fi'))
    await userEvent.click(screen.getByRole('button', { name: 'Create property' }))

    expect(server.callsTo('POST', 'properties')[0]?.body).toMatchObject({ amenity_ids: ['amn_1'] })
  })

  it('waits for the full record before seeding the edit form', async () => {
    const server = renderProperties()
    server.on('GET properties/prp_1', {
      body: {
        data: property({
          content: { summary: 'A tiled terrace above the rooftops.', description: null, house_rules: null, check_in_instructions: null, check_out_instructions: null },
        }),
      },
    })

    await userEvent.click((await screen.findAllByRole('button', { name: /Edit/ }))[0]!)

    // The list row carries no summary. Seeding from it would show this empty and
    // read as "no summary" rather than "not in this response".
    expect(await screen.findByLabelText('Summary')).toHaveValue('A tiled terrace above the rooftops.')
  })
})

/**
 * The two steps between adding a property and taking money for it.
 *
 * Both endpoints have always existed and neither had a control, which is how a
 * property added through the interface ended up unbookable: a draft with no
 * listing, refused by the booking form with a message about a state the person
 * had no way to leave.
 */
describe('putting a property on sale', () => {
  it('shows the listing a new property comes with', async () => {
    renderProperties()

    await userEvent.click((await screen.findAllByRole('button', { name: /Edit/ }))[0]!)

    const panel = (await screen.findByText('Listings')).closest('.field') as HTMLElement

    expect(within(panel).getByText(/Alfama Terrace Apartment/)).toBeInTheDocument()
    expect(within(panel).getByText('draft')).toBeInTheDocument()
  })

  it('publishes that listing', async () => {
    const server = renderProperties()
    server.on('POST listings/lst_1/publish', { body: { data: listing({ status: 'published' }) } })

    await userEvent.click((await screen.findAllByRole('button', { name: /Edit/ }))[0]!)
    await userEvent.click(await screen.findByRole('button', { name: 'Publish' }))

    expect(server.callsTo('POST', 'listings/lst_1/publish')).toHaveLength(1)
  })

  it('says what the server says when publishing is refused', async () => {
    const server = renderProperties()
    server.on('POST listings/lst_1/publish', {
      status: 422,
      body: { message: 'This listing cannot be published: a photograph is required.', errors: {} },
    })

    await userEvent.click((await screen.findAllByRole('button', { name: /Edit/ }))[0]!)
    await userEvent.click(await screen.findByRole('button', { name: 'Publish' }))

    // The server's own sentence names what is missing; a generic "could not be
    // published" would send the person looking in the wrong place.
    expect(await screen.findByText(/a photograph is required/)).toBeInTheDocument()
  })

  it('adds a second listing for a place let more than one way', async () => {
    const server = renderProperties()
    server.on('POST properties/prp_1/listings', { status: 201, body: { data: listing({ id: 'lst_2' }) } })

    await userEvent.click((await screen.findAllByRole('button', { name: /Edit/ }))[0]!)
    await userEvent.type(await screen.findByLabelText('New listing name'), 'Garden room only')
    await userEvent.click(screen.getByRole('button', { name: 'Add listing' }))

    expect(server.callsTo('POST', 'properties/prp_1/listings')[0]?.body).toEqual({
      name: 'Garden room only',
    })
  })

  it('lists what activation still needs, from the server', async () => {
    const server = renderProperties()
    server.on('GET properties/prp_1/readiness', {
      body: { ready: false, blockers: ['a base nightly rate is required'], status: 'draft' },
    })

    await userEvent.click((await screen.findAllByRole('button', { name: /Edit/ }))[0]!)

    expect(await screen.findByText('a base nightly rate is required')).toBeInTheDocument()
    // Offering the button anyway would produce a refusal the person has already
    // been shown the reason for.
    expect(screen.getByRole('button', { name: 'Activate' })).toBeDisabled()
  })

  it('activates a property that is ready', async () => {
    const server = renderProperties()
    server.on('GET properties/prp_1/readiness', { body: { ready: true, blockers: [], status: 'draft' } })
    server.on('POST properties/prp_1/activate', { body: { data: property() } })

    await userEvent.click((await screen.findAllByRole('button', { name: /Edit/ }))[0]!)
    await userEvent.click(await screen.findByRole('button', { name: 'Activate' }))

    expect(server.callsTo('POST', 'properties/prp_1/activate')).toHaveLength(1)
  })
})

/**
 * Editing a listing, and taking one off the books.
 *
 * The delicate part is not the buttons. It is that a listing inherits from its
 * property, so an edit form has to show an inherited field as *empty* — with what
 * it inherits as the hint — rather than pre-filled with the inherited value. A
 * form that pre-filled them would look identical either way and the first save
 * would quietly copy the property's wording and prices onto the listing as
 * overrides; from then on, correcting the property would stop reaching it, with
 * nothing on screen having said so.
 */
function detail(overrides: Record<string, unknown> = {}) {
  return {
    ...listing(),
    own_title: null,
    overridden_fields: [],
    resolved: {
      base_rate: 14500,
      cleaning_fee: 6500,
      minimum_nights: 2,
      max_occupancy: 4,
      description: 'A tiled terrace above the rooftops.',
      check_in_time: '15:00',
      instant_book: true,
    },
    ...overrides,
  }
}

async function openListings() {
  await userEvent.click((await screen.findAllByRole('button', { name: /Edit/ }))[0]!)

  return (await screen.findByText('Listings')).closest('.field') as HTMLElement
}

/**
 * The listing's own dialog, scoped.
 *
 * The property's dialog is still open behind it and has fields with the same
 * labels — "Base rate per night" exists in both — so an unscoped query picks
 * whichever is first in the document, which is the property's.
 */
async function openListingDialog(panel: HTMLElement) {
  await userEvent.click(within(panel).getByRole('button', { name: /Edit/ }))

  return await screen.findByRole('dialog', { name: /Edit listing/ })
}

describe('editing a listing', () => {
  it('shows an inherited field empty, with what it inherits as the hint', async () => {
    const server = renderProperties()
    server.on('GET listings/lst_1', { body: { data: detail() } })

    const dialog = await openListingDialog(await openListings())

    expect(within(dialog).getByLabelText('Base rate per night')).toHaveValue(null)
    expect(within(dialog).getByText(/Empty follows the property: 145.00/)).toBeInTheDocument()
  })

  it('shows a field the listing owns filled in', async () => {
    const server = renderProperties()
    server.on('GET listings/lst_1', {
      body: { data: detail({ overridden_fields: ['base_rate'] }) },
    })

    const dialog = await openListingDialog(await openListings())

    expect(within(dialog).getByLabelText('Base rate per night')).toHaveValue(145)
  })

  it('does not put the property’s name in the title box', async () => {
    const server = renderProperties()
    // `title` is the display title and falls back to the property's name, so a
    // form seeded from it would claim this listing has a title of its own.
    server.on('GET listings/lst_1', {
      body: { data: detail({ title: 'Alfama Terrace Apartment', own_title: null }) },
    })

    const dialog = await openListingDialog(await openListings())

    expect(within(dialog).getByLabelText('Title guests see')).toHaveValue('')
  })

  it('sends only what was changed', async () => {
    const server = renderProperties()
    server.on('GET listings/lst_1', { body: { data: detail() } })
    server.on('PATCH listings/lst_1', { body: { data: detail() } })

    const dialog = await openListingDialog(await openListings())

    await userEvent.type(within(dialog).getByLabelText('Base rate per night'), '165')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save listing' }))

    // One field. Everything left alone keeps following the property.
    expect(server.callsTo('PATCH', 'listings/lst_1')[0]?.body).toEqual({ base_rate: 16500 })
  })
})

describe('taking a listing off the books', () => {
  it('asks first, and says what happens to the bookings', async () => {
    const server = renderProperties()
    server.on('GET properties/prp_1/listings', {
      body: { data: [listing(), listing({ id: 'lst_2', name: 'Garden room only', is_primary: false })] },
    })

    const panel = await openListings()
    await userEvent.click(within(panel).getAllByRole('button', { name: /Remove/ })[1]!)

    const confirm = await screen.findByRole('alertdialog', { name: 'Remove listing' })

    expect(within(confirm).getByText(/bookings, statements and published history stay/)).toBeInTheDocument()
    // Nothing sent until they say so.
    expect(server.callsTo('DELETE', 'listings/lst_2')).toHaveLength(0)
  })

  it('archives it, with the reason, once confirmed', async () => {
    const server = renderProperties()
    server.on('GET properties/prp_1/listings', {
      body: { data: [listing(), listing({ id: 'lst_2', name: 'Garden room only', is_primary: false })] },
    })
    server.on('DELETE listings/lst_2', { body: { message: 'Listing archived.' } })

    const panel = await openListings()
    await userEvent.click(within(panel).getAllByRole('button', { name: /Remove/ })[1]!)

    await userEvent.type(
      await screen.findByLabelText('Why it is being removed'),
      'Stopped letting the room',
    )
    await userEvent.click(screen.getByRole('button', { name: 'Take it off the books' }))

    expect(server.callsTo('DELETE', 'listings/lst_2')[0]?.body).toEqual({
      reason: 'Stopped letting the room',
    })
  })

  it('shows the server’s refusal when it is the last one', async () => {
    const server = renderProperties()
    server.on('DELETE listings/lst_1', {
      status: 422,
      body: {
        message:
          'Alfama Terrace Apartment is still active and this is its only listing, so archiving it would leave nothing to book.',
        errors: {},
      },
    })

    const panel = await openListings()
    await userEvent.click(within(panel).getByRole('button', { name: /Remove/ }))
    await userEvent.click(await screen.findByRole('button', { name: 'Take it off the books' }))

    expect(await screen.findByText(/would leave nothing to book/)).toBeInTheDocument()
  })

  it('offers an archived listing back', async () => {
    const server = renderProperties()
    server.on('GET properties/prp_1/listings', {
      body: {
        data: [
          listing(),
          listing({ id: 'lst_2', name: 'Garden room only', is_primary: false, status: 'archived' }),
        ],
      },
    })
    server.on('POST listings/lst_2/restore', { body: { data: listing({ id: 'lst_2', status: 'paused' }) } })

    const panel = await openListings()

    // An archived row offers nothing but coming back: editing or publishing
    // something that is off the books is not a thing to offer.
    expect(within(panel).getAllByRole('button', { name: /Remove/ })).toHaveLength(1)

    await userEvent.click(within(panel).getByRole('button', { name: 'Bring back' }))

    expect(server.callsTo('POST', 'listings/lst_2/restore')).toHaveLength(1)
  })

  it('offers neither to somebody who may only look', async () => {
    renderProperties(['properties.view', 'properties.update', 'listings.view'])

    const panel = await openListings()

    expect(within(panel).queryByRole('button', { name: /Remove/ })).not.toBeInTheDocument()
    expect(within(panel).queryByRole('button', { name: /^Edit/ })).not.toBeInTheDocument()
  })
})
