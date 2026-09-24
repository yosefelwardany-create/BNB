import { useState } from 'react'
import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Segmented } from '@/components/Segmented'

/**
 * The segmented control is a radio group: one tab stop, the chosen option
 * announced as checked, and the arrow keys moving the choice.
 */
function Harness() {
  const [value, setValue] = useState('cards')

  return (
    <Segmented
      label="Layout"
      value={value}
      onChange={setValue}
      options={[
        { value: 'cards', label: 'Cards' },
        { value: 'table', label: 'Table' },
      ]}
    />
  )
}

describe('Segmented', () => {
  it('marks the chosen option as checked', () => {
    render(<Harness />)

    expect(screen.getByRole('radio', { name: 'Cards' })).toBeChecked()
    expect(screen.getByRole('radio', { name: 'Table' })).not.toBeChecked()
  })

  it('changes on click', async () => {
    render(<Harness />)

    await userEvent.click(screen.getByRole('radio', { name: 'Table' }))

    expect(screen.getByRole('radio', { name: 'Table' })).toBeChecked()
  })

  it('is one tab stop, and the arrow keys move the choice', async () => {
    render(<Harness />)

    await userEvent.tab()
    expect(screen.getByRole('radio', { name: 'Cards' })).toHaveFocus()

    await userEvent.keyboard('{ArrowRight}')
    expect(screen.getByRole('radio', { name: 'Table' })).toBeChecked()
    expect(screen.getByRole('radio', { name: 'Table' })).toHaveFocus()

    // It wraps round.
    await userEvent.keyboard('{ArrowRight}')
    expect(screen.getByRole('radio', { name: 'Cards' })).toBeChecked()
  })
})
