import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from '@/lib/auth'
import { AppLayout } from '@/components/AppLayout'
import { BrandMark } from '@/components/BrandMark'
import { PlatformLayout } from '@/components/PlatformLayout'
import { LoginPage } from '@/pages/LoginPage'
import { DashboardPage } from '@/pages/DashboardPage'
import { PropertiesPage } from '@/pages/PropertiesPage'
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
import { SubscriptionPage } from '@/pages/SubscriptionPage'
import { PlatformOverviewPage } from '@/pages/platform/PlatformOverviewPage'
import { PlatformTenantsPage } from '@/pages/platform/PlatformTenantsPage'
import { PlatformPlansPage } from '@/pages/platform/PlatformPlansPage'
import { PlatformPeoplePage } from '@/pages/platform/PlatformPeoplePage'
import { PlatformAnnouncementsPage } from '@/pages/platform/PlatformAnnouncementsPage'
import { PlatformHealthPage } from '@/pages/platform/PlatformHealthPage'
import { PlatformSessionsPage } from '@/pages/platform/PlatformSessionsPage'
import { PlatformAuditPage } from '@/pages/platform/PlatformAuditPage'
import { PlatformSettingsPage } from '@/pages/platform/PlatformSettingsPage'

export function App() {
  const { session, loading } = useAuth()

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
        <Route path="*" element={<Navigate to="/login" replace />} />
      </Routes>
    )
  }

  return (
    <Routes>
      {/*
        The platform console is a separate shell, not a section of the tenant
        interface. It runs outside any organization and governs all of them, so
        sharing the tenant layout — with its organization switcher and its
        tenant navigation — would misrepresent what the operator is looking at.

        Guarded here as a courtesy only. The server answers every route under
        /api/v1/platform with a 404 unless the caller holds the platform
        administration flag, so a user who types the URL sees an empty console
        rather than one that works.
      */}
      {session.is_platform_admin && (
        <Route path="/platform/*" element={<PlatformRoutes />} />
      )}

      {/*
        A platform administrator with no membership anywhere has no tenant
        interface to show — every screen in it is about an organization they do
        not belong to. Sending them to the console is the only coherent
        destination, and without this they landed on an empty shell with a blank
        company name.
      */}
      {session.is_platform_admin && session.organization === null && (
        <Route path="*" element={<Navigate to="/platform" replace />} />
      )}

      <Route path="*" element={<TenantRoutes />} />
    </Routes>
  )
}

function PlatformRoutes() {
  return (
    <PlatformLayout>
      <Routes>
        <Route path="/" element={<PlatformOverviewPage />} />
        <Route path="/tenants" element={<PlatformTenantsPage />} />
        <Route path="/plans" element={<PlatformPlansPage />} />
        <Route path="/people" element={<PlatformPeoplePage />} />
        <Route path="/announcements" element={<PlatformAnnouncementsPage />} />
        <Route path="/health" element={<PlatformHealthPage />} />
        <Route path="/sessions" element={<PlatformSessionsPage />} />
        <Route path="/audit" element={<PlatformAuditPage />} />
        <Route path="/settings" element={<PlatformSettingsPage />} />
        <Route
          path="*"
          element={
            <div className="empty">
              <div className="empty__title">Page not found</div>
            </div>
          }
        />
      </Routes>
    </PlatformLayout>
  )
}

function TenantRoutes() {
  // Every route is reachable by anyone signed in; what they may actually do is
  // decided by the server on each request, and the navigation hides what a role
  // does not include. A screen reached directly shows the server's own refusal
  // rather than a guess made here.
  return (
    <AppLayout>
      <Routes>
        <Route path="/" element={<DashboardPage />} />
        <Route path="/calendar" element={<CalendarPage />} />
        <Route path="/reservations" element={<ReservationsPage />} />
        <Route path="/inbox" element={<InboxPage />} />
        <Route path="/operations" element={<OperationsPage />} />
        <Route path="/properties" element={<PropertiesPage />} />
        <Route path="/guests" element={<GuestsPage />} />
        <Route path="/owners" element={<OwnersPage />} />
        <Route path="/reviews" element={<ReviewsPage />} />
        <Route path="/channels" element={<ChannelsPage />} />
        <Route path="/financials" element={<FinancialsPage />} />
        <Route path="/revenue" element={<RevenuePage />} />
        <Route path="/reports" element={<ReportsPage />} />
        <Route path="/subscription" element={<SubscriptionPage />} />
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
