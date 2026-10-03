import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { AgentReply } from './AgentReply'

describe('AgentReply', () => {
  it('separates paragraphs and renders lists and emphasis', async () => {
    const { container } = render(<AgentReply text={'## Property details\n\nSaved for next time.\n\n- **Parking:** behind the house\n- Check out at 11\n\nAsk me about arrivals.'} />)
    expect(await screen.findByRole('heading', { name: 'Property details' })).toBeInTheDocument()
    expect(screen.getAllByRole('listitem')).toHaveLength(2)
    expect(container.querySelector('strong')).toHaveTextContent('Parking:')
    expect(container.querySelectorAll('p')).toHaveLength(2)
  })
  it('does not execute HTML, load remote images, or allow javascript links', () => {
    const { container } = render(<AgentReply text={'<script>alert(1)</script>\n\n![tracking](https://example.com/pixel)\n\n[bad](javascript:alert%281%29)'} />)
    expect(container.querySelector('script')).toBeNull()
    expect(container.querySelector('img')).toBeNull()
    expect(container.querySelector('a')?.getAttribute('href')).not.toContain('javascript:')
  })
})
