import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from '@/lib/auth'
import { AppLayout } from '@/components/AppLayout'
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

export function App() {
  const { session, loading } = useAuth()

  if (loading) {
    return (
      <div className="auth">
        <div className="row">
          <span className="spinner" />
          <span className="muted">Loading…</span>
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

  // Every route is reachable by anyone signed in; what they may actually do
  // is decided by the server on each request, and the navigation hides what a
  // role does not include. A screen reached directly shows the server's own
  // refusal rather than a guess made here.
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
