import { describe, expect, it } from 'vitest'
import { useState } from 'react'
import { act, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { RichTextField } from './RichTextField'

function Field({ outside }: { outside: string }) {
  const [value, setValue] = useState('<div>Our story</div>')

  return (
    <>
      <span id="label">Words</span>
      <RichTextField labelledBy="label" value={value} onChange={setValue} />
      <button type="button" onClick={() => setValue(outside)}>Saved elsewhere</button>
      <button type="button">Somewhere else</button>
    </>
  )
}

describe('RichTextField', () => {
  it('leaves the words being typed alone, and takes the new value once left', async () => {
    const user = userEvent.setup()
    render(<Field outside="<p>Our story</p>" />)
    const box = screen.getByRole('textbox', { name: 'Words' })

    box.focus()
    // A save answered while the box is in use: written in, it would move the caret.
    act(() => screen.getByRole('button', { name: 'Saved elsewhere' }).click())
    expect(box.innerHTML).toBe('<div>Our story</div>')

    await user.click(screen.getByRole('button', { name: 'Somewhere else' }))
    expect(box.innerHTML).toBe('<p>Our story</p>')
  })
})
