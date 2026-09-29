import { Link } from 'react-router-dom'
import { BrandMark } from '@/components/BrandMark'
import { ThemeToggle } from '@/components/ThemeToggle'

/**
 * The frame every signed-out screen sits in.
 *
 * Sign-in has an elaborate brand panel beside it; the screens that lead into or
 * out of it — registering, resetting a password, accepting an invitation — do
 * not need the sales pitch, but they do need to look like the same product.
 * Sharing the card and the background rather than the whole layout is what makes
 * that true without either page having to own the other's markup.
 */
export function AuthShell({
  title,
  lede,
  badge,
  children,
  footer,
}: {
  title: string
  lede?: string
  badge?: React.ReactNode
  children: React.ReactNode
  footer?: React.ReactNode
}) {
  return (
    <div className="login login--single">
      <main className="login__form-side">
        <div className="login__toolbar">
          <ThemeToggle />
        </div>

        <div className="login__card">
          <Link to="/login" className="login__brand-top" aria-label="Habitat">
            <BrandMark size={26} />
            <span className="login__wordmark">Habitat</span>
          </Link>

          {badge !== undefined && (
            <div className="login__badge" aria-hidden="true">
              {badge}
            </div>
          )}

          <h1>{title}</h1>
          {lede !== undefined && <p className="login__lede">{lede}</p>}

          {children}

          {footer !== undefined && <div className="login__footer small muted">{footer}</div>}
        </div>
      </main>
    </div>
  )
}
