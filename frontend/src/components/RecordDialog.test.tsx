import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ApiError } from '@/api/client'
import { RecordDialog } from '@/components/RecordDialog'
import type { FieldSpec } from '@/components/RecordDialog'

/**
 * The form every screen creates and edits through.
 *
 * Three behaviours carry real consequence and are worth pinning: an edit must
 * not send back fields nobody touched, money must reach the API in the integer
 * minor units the whole platform stores, and the server's complaint about a
 * field has to appear on that field.
 */
const FIELDS: FieldSpec[] = [
  { name: 'name', label: 'Name', type: 'text', required: true },
  { name: 'city', label: 'City', type: 'text' },
  { name: 'base_rate', label: 'Base rate', type: 'money' },
]

function open(props: Partial<React.ComponentProps<typeof RecordDialog>> = {}) {
  const onSubmit = vi.fn()

  render(
    <RecordDialog
      title="New property"
      fields={FIELDS}
      onSubmit={onSubmit}
      onClose={vi.fn()}
      {...props}
    />,
  )

  return onSubmit
}

describe('the record dialog', () => {
  it('sends only what was filled in when creating', async () => {
    const onSubmit = open()

    await userEvent.type(screen.getByLabelText(/Name/), 'Alfama Terrace')
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    // An empty optional field is left out rather than sent as an empty string,
    // which several of the API's validators read as a malformed value rather
    // than as "not set".
    expect(onSubmit).toHaveBeenCalledWith({ name: 'Alfama Terrace' })
  })

  it('sends only what changed when editing', async () => {
    const onSubmit = open({
      initial: { name: 'Alfama Terrace', city: 'Lisbon', base_rate: 14500 },
    })

    await userEvent.clear(screen.getByLabelText('City'))
    await userEvent.type(screen.getByLabelText('City'), 'Cascais')
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    // The name and the rate are untouched, so they are not resent — otherwise a
    // stale form would quietly revert a field a colleague had since corrected.
    expect(onSubmit).toHaveBeenCalledWith({ city: 'Cascais' })
  })

  it('converts money to the minor units the platform stores', async () => {
    const onSubmit = open()

    await userEvent.type(screen.getByLabelText(/Name/), 'x')
    await userEvent.type(screen.getByLabelText('Base rate'), '145.50')
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(onSubmit).toHaveBeenCalledWith({ name: 'x', base_rate: 14550 })
  })

  it('shows money in whole units when editing, and leaves it alone if untouched', async () => {
    const onSubmit = open({ initial: { name: 'x', city: '', base_rate: 14500 } })

    expect(screen.getByLabelText('Base rate')).toHaveValue(145)

    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(onSubmit).toHaveBeenCalledWith({})
  })

  it('puts the server’s complaint on the field it belongs to', () => {
    open({
      error: new ApiError(422, 'The given data was invalid.', {
        name: ['A property with this name already exists.'],
      }),
    })

    const field = screen.getByLabelText(/Name/).closest('.field') as HTMLElement

    expect(field).toHaveTextContent('A property with this name already exists.')
    // Not also as a banner: one complaint, in one place, next to the input.
    expect(screen.queryByRole('alert', { name: /given data/ })).not.toBeInTheDocument()
  })

  it('shows a message that belongs to no field as a banner', () => {
    open({ error: new ApiError(409, 'Those dates already have reservations.') })

    expect(screen.getByRole('alert')).toHaveTextContent('Those dates already have reservations.')
  })

  it('closes on escape', async () => {
    const onClose = vi.fn()

    render(<RecordDialog title="x" fields={FIELDS} onSubmit={vi.fn()} onClose={onClose} />)
    await userEvent.keyboard('{Escape}')

    expect(onClose).toHaveBeenCalled()
  })
})

/**
 * A picker with nothing in it.
 *
 * An empty dropdown is a dead end that does not explain itself — the person
 * cannot tell whether the records are missing, still loading, or hidden from
 * them. The reservation form spent a release in exactly that state, and what
 * came back was "it doesn't show any property or listing available".
 */
describe('a picker with nothing to pick', () => {
  const EMPTY: FieldSpec[] = [
    {
      name: 'listing_id',
      label: 'Listing',
      type: 'select',
      required: true,
      options: [],
      emptyHint: <>Add a property first.</>,
    },
  ]

  it('says why instead of offering an empty dropdown', () => {
    open({ fields: EMPTY })

    expect(screen.getByText('Add a property first.')).toBeInTheDocument()
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument()
  })

  it('goes back to being a dropdown as soon as there is something to choose', () => {
    open({
      fields: [{ ...EMPTY[0]!, options: [{ value: 'lst_1', label: 'Alfama Terrace' }] } as FieldSpec],
    })

    expect(screen.getByLabelText(/Listing/)).toBeInstanceOf(HTMLSelectElement)
  })

  it('still offers the dropdown when no explanation was written for it', () => {
    // Without a hint there is nothing better to show, and swallowing the control
    // would be worse than an empty one.
    open({
      fields: [
        { name: 'listing_id', label: 'Listing', type: 'select', required: true, options: [] },
      ],
    })

    expect(screen.getByLabelText(/Listing/)).toBeInstanceOf(HTMLSelectElement)
  })
})
