import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { CountUp } from '@/components/CountUp'

/**
 * The counting figure.
 *
 * The animation is decoration; the figure is not. Wherever motion is off —
 * which includes this test environment, having no media queries at all — the
 * value must be exactly what the formatter produced, from the first render.
 */
describe('CountUp', () => {
  it('shows a formatted amount exactly as given', () => {
    render(<CountUp value="€2,418.50" />)

    expect(screen.getByText('€2,418.50')).toBeInTheDocument()
  })

  it('shows a plain number', () => {
    render(<CountUp value={14} />)

    expect(screen.getByText('14')).toBeInTheDocument()
  })

  it('leaves text without a number alone', () => {
    render(<CountUp value="—" />)

    expect(screen.getByText('—')).toBeInTheDocument()
  })

  it('follows a change of value', () => {
    const { rerender } = render(<CountUp value="22.5%" />)

    rerender(<CountUp value="30%" />)

    expect(screen.getByText('30%')).toBeInTheDocument()
  })
})
