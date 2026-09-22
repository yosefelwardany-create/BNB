import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from '@/lib/auth'
import { AppLayout } from '@/components/AppLayout'
import { LoginPage } from '@/pages/LoginPage'
import { DashboardPage } from '@/pages/DashboardPage'
import { PropertiesPage } from '@/pages/PropertiesPage'
import { ReservationsPage } from '@/pages/ReservationsPage'
import { CalendarPage } from '@/pages/CalendarPage'
import { GuestsPage } from '@/pages/GuestsPage'

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

  return (
    <AppLayout>
      <Routes>
        <Route path="/" element={<DashboardPage />} />
        <Route path="/calendar" element={<CalendarPage />} />
        <Route path="/reservations" element={<ReservationsPage />} />
        <Route path="/properties" element={<PropertiesPage />} />
        <Route path="/guests" element={<GuestsPage />} />
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
