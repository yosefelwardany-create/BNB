import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from '@/lib/auth'
import { useClientView } from '@/lib/clientView'
import { AppLayout } from '@/components/AppLayout'
import { BrandMark } from '@/components/BrandMark'
import { OwnerLayout } from '@/components/OwnerLayout'
import { LoginPage } from '@/pages/LoginPage'
import { RegisterPage } from '@/pages/RegisterPage'
import { ForgotPasswordPage, ResetPasswordPage } from '@/pages/PasswordResetPage'
import { AcceptInvitationPage } from '@/pages/AcceptInvitationPage'
import { DashboardPage } from '@/pages/DashboardPage'
import { PropertiesPage } from '@/pages/PropertiesPage'
import { AgentsPage } from '@/pages/AgentsPage'
import { ReservationsPage } from '@/pages/ReservationsPage'
import { CalendarPage } from '@/pages/CalendarPage'
import { GuestsPage } from '@/pages/GuestsPage'
import { OperationsPage } from '@/pages/OperationsPage'
import { InboxPage } from '@/pages/InboxPage'
import { FinancialsPage } from '@/pages/FinancialsPage'
import { ChannelsPage } from '@/pages/ChannelsPage'
import { RevenuePage } from '@/pages/RevenuePage'
import { ReportsPage } from '@/pages/ReportsPage'
import { OwnersPage } from '@/pages/OwnersPage'
import { ReviewsPage } from '@/pages/ReviewsPage'
import { SettingsPage } from '@/pages/SettingsPage'
import { AccountsPage } from '@/pages/AccountsPage'
import { OwnerOverviewPage } from '@/pages/owner/OwnerOverviewPage'
import { OwnerPropertiesPage } from '@/pages/owner/OwnerPropertiesPage'
import { OwnerCalendarPage } from '@/pages/owner/OwnerCalendarPage'
import { OwnerMoneyPage } from '@/pages/owner/OwnerMoneyPage'

export function App() {
  const { session, loading } = useAuth()
  const clientView = useClientView()

  if (loading) {
    return (
      <div className="auth">
        <div className="splash">
          <BrandMark size={26} />
          <span className="splash__word">Habitat</span>
          <span className="muted small">Loading…</span>
        </div>
      </div>
    )
  }

  if (session === null) {
    return (
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/register" element={<RegisterPage />} />
        <Route path="/forgot-password" element={<ForgotPasswordPage />} />
        <Route path="/reset-password" element={<ResetPasswordPage />} />
        {/*
          Reachable signed out by definition — the invitation is how somebody
          without an account gets one.
        */}
        <Route path="/invitations/:token" element={<AcceptInvitationPage />} />
        <Route path="*" element={<Navigate to="/login" replace />} />
      </Routes>
    )
  }

  /*
    The platform owner always gets the owner workspace, whatever portal any
    membership of theirs says. They operate every client account from it, with
    the sidebar choosing which; the client portal below is for clients.
  */
  if (session.is_platform_admin) {
    // The client view of the selected account: the client's own screens, read
    // through the same portal endpoints, with a switch back. Needs an account.
    if (clientView && session.organization !== null) {
      return <OwnerRoutes preview />
    }

    return <TenantRoutes />
  }

  /*
    A client gets their own read-only screens, not the management interface.

    Decided by the membership's portal rather than by a role name or a
    permission count: the server records which portal a person belongs to and
    returns it at sign-in. This is a courtesy, not the control. A client's role
    holds no permissions and the server refuses every write from a client
    membership, so typing a management URL gets the server's refusal rather
    than a screen that works.
  */
  if (session.membership?.default_portal === 'owner') {
    return <OwnerRoutes />
  }

  return <TenantRoutes />
}

function OwnerRoutes({ preview = false }: { preview?: boolean }) {
  return (
    <OwnerLayout preview={preview}>
      <Routes>
        <Route path="/" element={<OwnerOverviewPage />} />
        <Route path="/properties" element={<OwnerPropertiesPage />} />
        <Route path="/properties/:propertyId" element={<OwnerPropertiesPage />} />
        <Route path="/calendar" element={<OwnerCalendarPage />} />
        <Route path="/money" element={<OwnerMoneyPage />} />
        {/*
          Anything else belongs to the management interface, which is not theirs
          to see. Sent to their overview rather than shown a dead end.
        */}
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </OwnerLayout>
  )
}

function TenantRoutes() {
  const { session } = useAuth()

  // Every route is reachable by anyone signed in; what they may actually do is
  // decided by the server on each request, and the navigation hides what a role
  // does not include. A screen reached directly shows the server's own refusal
  // rather than a guess made here.
  //
  // A platform owner with no account selected (no client accounts exist yet)
  // has nothing for the operational screens to be about, so everything but
  // Accounts sends them there to create the first one.
  const needsAccount = session?.is_platform_admin === true && session.organization === null

  if (needsAccount) {
    return (
      <AppLayout>
        <Routes>
          <Route path="/accounts" element={<AccountsPage />} />
          <Route path="*" element={<Navigate to="/accounts" replace />} />
        </Routes>
      </AppLayout>
    )
  }

  return (
    <AppLayout>
      <Routes>
        {session?.is_platform_admin === true && <Route path="/accounts" element={<AccountsPage />} />}
        {/* The old console's address. Everything it did lives in the workspace now. */}
        <Route path="/platform/*" element={<Navigate to="/" replace />} />
        <Route path="/" element={<DashboardPage />} />
        <Route path="/calendar" element={<CalendarPage />} />
        <Route path="/reservations" element={<ReservationsPage />} />
        <Route path="/inbox" element={<InboxPage />} />
        <Route path="/operations" element={<OperationsPage />} />
        <Route path="/properties" element={<PropertiesPage />} />
        <Route path="/agent" element={<AgentsPage />} />
        <Route path="/guests" element={<GuestsPage />} />
        <Route path="/owners" element={<OwnersPage />} />
        <Route path="/reviews" element={<ReviewsPage />} />
        <Route path="/channels" element={<ChannelsPage />} />
        <Route path="/financials" element={<FinancialsPage />} />
        <Route path="/revenue" element={<RevenuePage />} />
        <Route path="/reports" element={<ReportsPage />} />
        <Route path="/settings" element={<SettingsPage />} />
        <Route path="/login" element={<Navigate to="/" replace />} />
        <Route
          path="*"
          element={
            <div className="empty">
              <div className="empty__title">Page not found</div>
              <p>That screen does not exist yet.</p>
            </div>
          }
        />
      </Routes>
    </AppLayout>
  )
}
