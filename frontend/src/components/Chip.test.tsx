import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { Chip } from '@/components/Chip'

/**
 * The status chip.
 *
 * Its colour comes from the server so a status looks the same everywhere it
 * appears — which means the one thing to protect is that an unfamiliar colour
 * degrades to a neutral chip rather than to an unstyled one nobody can read.
 */
describe('Chip', () => {
  it('uses the colour the server gave it', () => {
    render(<Chip label="Captured" colour="emerald" />)

    const chip = screen.getByText('Captured')

    expect(chip).toHaveClass('chip', 'chip--emerald')
  })

  it('falls back to neutral for a colour it has no style for', () => {
    // A new status added server-side must not render as a chip with no
    // background, which reads as broken rather than as unfamiliar.
    render(<Chip label="Disputed" colour="fuchsia" />)

    expect(screen.getByText('Disputed')).toHaveClass('chip--slate')
  })

  it('is neutral when no colour is given at all', () => {
    render(<Chip label="Draft" />)

    expect(screen.getByText('Draft')).toHaveClass('chip--slate')
  })
})
