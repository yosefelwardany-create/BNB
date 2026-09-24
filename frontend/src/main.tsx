import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter } from 'react-router-dom'
import { MotionConfig } from 'motion/react'
import { AuthProvider } from '@/lib/auth'
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

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      // Operational data changes constantly; a short stale window keeps the
      // interface fresh without hammering the API on every render.
      staleTime: 30_000,
      refetchOnWindowFocus: true,
      retry: (failureCount, error) => {
        // A permission or validation failure will not succeed on retry.
        const status = (error as { status?: number }).status
        if (status !== undefined && status >= 400 && status < 500) return false
        return failureCount < 2
      },
    },
  },
})

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    {/* Animation follows the operating system's reduced-motion setting. */}
    <MotionConfig reducedMotion="user">
      <QueryClientProvider client={queryClient}>
        {/* The admin app is served from /app, so routing is based there. */}
        <BrowserRouter basename="/app">
          <AuthProvider>
            <App />
          </AuthProvider>
        </BrowserRouter>
      </QueryClientProvider>
      <Toaster />
    </MotionConfig>
  </StrictMode>,
)
