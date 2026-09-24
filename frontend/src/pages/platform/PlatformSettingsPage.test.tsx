import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { PlatformSettingsPage } from '@/pages/platform/PlatformSettingsPage'
import { session } from '@/test/fixtures'
import { renderWithProviders } from '@/test/render'
import { stubApi } from '@/test/server'

/**
 * Platform configuration.
 *
 * The form is built from the server's own definitions, so the console cannot
 * invent a setting nothing reads. What these protect is the form's state: the
 * stored values come from the query and what somebody has typed is laid over
 * them, so a refetch cannot silently replace an edit in progress and a save
 * cannot post a value the server never sent.
 */
const SETTINGS = [
  {
    key: 'support_email',
    type: 'string',
    value: 'help@habitat.test',
    default: null,
    description: 'Shown to customers whose trial has expired.',
  },
  {
    key: 'require_mfa_for_platform_admins',
    type: 'boolean',
    value: false,
    default: false,
    description: 'Turn this on once every platform administrator has enrolled.',
  },
  {
    key: 'trial_days',
    type: 'integer',
    value: 14,
    default: 14,
    description: 'How long a new organization may trade before a plan is needed.',
  },
]

function renderSettings() {
  const server = stubApi({
    'GET auth/me': { body: session({ is_platform_admin: true }) },
    'GET platform/settings': { body: { data: SETTINGS } },
    'PUT platform/settings': { body: { data: SETTINGS } },
  })

  renderWithProviders(<PlatformSettingsPage />)

  return server
}

describe('PlatformSettingsPage', () => {
  it('shows the values the server holds', async () => {
    renderSettings()

    expect(await screen.findByLabelText('support email')).toHaveValue('help@habitat.test')
    expect(screen.getByLabelText('trial days')).toHaveValue(14)
    expect(screen.getByLabelText(/require mfa/i)).not.toBeChecked()
  })

  it('keeps what was typed', async () => {
    renderSettings()

    const field = await screen.findByLabelText('support email')

    await userEvent.clear(field)
    await userEvent.type(field, 'support@habitat.test')

    expect(field).toHaveValue('support@habitat.test')
  })

  it('sends the edited value together with the ones left alone', async () => {
    const server = renderSettings()

    await userEvent.click(await screen.findByLabelText(/require mfa/i))
    await userEvent.click(screen.getByRole('button', { name: 'Save settings' }))

    await screen.findByText('Settings saved.')

    const [call] = server.callsTo('PUT', 'platform/settings')
    const body = call?.body as { settings: Record<string, unknown> }

    expect(body.settings.require_mfa_for_platform_admins).toBe(true)
    // An untouched setting must travel as the server's own value, not as
    // undefined — a partial save that blanks what nobody edited is the failure
    // this form is shaped to avoid.
    expect(body.settings.support_email).toBe('help@habitat.test')
    expect(body.settings.trial_days).toBe(14)
  })

  it('says that nothing here holds a secret', async () => {
    renderSettings()

    // Credentials live in the environment. A console that displayed them would
    // turn one compromised operator account into every integration.
    expect(await screen.findByText(/Nothing here holds a secret/)).toBeInTheDocument()
  })
})
