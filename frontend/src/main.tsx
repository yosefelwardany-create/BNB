import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { MotionConfig } from 'motion/react'
import { AuthProvider } from '@/lib/auth'
import { OrganizationScopedQueries } from '@/lib/OrganizationScopedQueries'
import { initTheme } from '@/lib/theme'
import { installInteractions } from '@/lib/interactions'
import { Toaster } from '@/components/Toaster'
import { App } from '@/App'
import '@fontsource-variable/plus-jakarta-sans'
import '@fontsource-variable/outfit'
import '@/styles/app.css'

// Before the first render, so the page never paints in the wrong theme.
initTheme()
installInteractions()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    {/* Animation follows the operating system's reduced-motion setting. */}
    <MotionConfig reducedMotion="user">
      {/* The admin app is served from /app, so routing is based there. */}
      <BrowserRouter basename="/app">
        <AuthProvider>
          {/* One query cache per selected account: see the component. */}
          <OrganizationScopedQueries>
            <App />
          </OrganizationScopedQueries>
        </AuthProvider>
      </BrowserRouter>
      <Toaster />
    </MotionConfig>
  </StrictMode>,
)
