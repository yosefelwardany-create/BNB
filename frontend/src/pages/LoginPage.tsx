import { useRef, useState } from 'react'
import { AnimatePresence, motion } from 'motion/react'
import {
  ArrowRight,
  CalendarRange,
  Eye,
  EyeOff,
  KeyRound,
  Lock,
  Mail,
  ShieldCheck,
  Sparkles,
  Wallet,
} from 'lucide-react'
import { ApiError } from '@/api/client'
import { useAuth } from '@/lib/auth'
import { BrandMark } from '@/components/BrandMark'
import { ThemeToggle } from '@/components/ThemeToggle'
import { prefersReducedMotion } from '@/lib/interactions'

const FEATURES = [
  { icon: CalendarRange, title: 'One calendar, every channel', body: 'Availability and pricing checked for real, not guessed.' },
  { icon: Sparkles, title: 'Cleaning that follows the booking', body: 'The rota moves when the stay does.' },
  { icon: Wallet, title: 'Books that add up', body: 'A double-entry ledger, night by night, down to the owner statement.' },
]

const HEADLINE = ['Every', 'stay,', 'from', 'booking', 'to', 'owner', 'statement.']

export function LoginPage() {
  const { signIn, completeMfa } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [capsLock, setCapsLock] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [submitting, setSubmitting] = useState(false)

  // Set when the password was right and a code is still needed. Holding only
  // the reference is the point: it authorises nothing, and the password is not
  // kept around waiting for a second screen.
  const [challenge, setChallenge] = useState<string | null>(null)
  const [code, setCode] = useState('')

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      const result = await signIn(email, password)

      if (result.mfaRequired && result.reference !== undefined) {
        setChallenge(result.reference)
        setPassword('')
      }
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError(0, 'Unable to sign in.'))
    } finally {
      setSubmitting(false)
    }
  }

  async function handleChallenge(event: React.FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      await completeMfa(challenge ?? '', code)
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError(0, 'That code was not accepted.'))
      setCode('')
    } finally {
      setSubmitting(false)
    }
  }

  function trackCapsLock(event: React.KeyboardEvent<HTMLInputElement>) {
    setCapsLock(event.getModifierState('CapsLock'))
  }

  return (
    <div className="login">
      <BrandPanel />

      <main className="login__form-side">
        <div className="login__toolbar">
          <ThemeToggle />
        </div>

        <AnimatePresence mode="wait" initial={false}>
          {challenge !== null ? (
            <motion.div
              key="challenge"
              className="login__card"
              initial={{ opacity: 0, x: 40 }}
              animate={{ opacity: 1, x: 0 }}
              exit={{ opacity: 0, x: -40 }}
              transition={{ duration: 0.35, ease: [0.22, 1, 0.36, 1] }}
            >
              <div className="login__badge" aria-hidden="true">
                <ShieldCheck size={22} />
              </div>
              <h1>Two-factor authentication</h1>
              <p className="login__lede">
                Enter the six-digit code from your authenticator app, or one of your recovery codes.
              </p>

              {error !== null && (
                <div className="notice notice--error" role="alert">
                  {error.message}
                </div>
              )}

              <form onSubmit={(event) => void handleChallenge(event)} noValidate>
                <div className="field">
                  <label className="field__label" htmlFor="code">
                    Code
                  </label>
                  <input
                    id="code"
                    type="text"
                    className="login__code"
                    inputMode="text"
                    autoComplete="one-time-code"
                    autoFocus
                    required
                    value={code}
                    onChange={(event) => setCode(event.target.value)}
                  />
                  <span className="field__hint">
                    A recovery code works once and looks like <span className="mono">abcde-12345</span>.
                  </span>
                </div>

                <button
                  type="submit"
                  className="btn btn--primary login__submit"
                  disabled={submitting || code.trim() === ''}
                >
                  {submitting ? <span className="spinner" /> : null}
                  {submitting ? 'Checking…' : 'Sign in'}
                  {!submitting && <ArrowRight size={17} className="login__arrow" aria-hidden />}
                </button>

                <button
                  type="button"
                  className="btn btn--ghost btn--sm login__secondary"
                  onClick={() => {
                    setChallenge(null)
                    setCode('')
                    setError(null)
                  }}
                >
                  Start again
                </button>
              </form>
            </motion.div>
          ) : (
            <motion.div
              key="password"
              className="login__card"
              initial={{ opacity: 0, x: -40 }}
              animate={{ opacity: 1, x: 0 }}
              exit={{ opacity: 0, x: 40 }}
              transition={{ duration: 0.35, ease: [0.22, 1, 0.36, 1] }}
            >
              <div className="login__badge" aria-hidden="true">
                <KeyRound size={22} />
              </div>
              <h1>Welcome back</h1>
              <p className="login__lede">Sign in to Habitat property management.</p>

              {error !== null && !error.isValidation && (
                <div className="notice notice--error" role="alert">
                  {error.message}
                </div>
              )}

              <form onSubmit={(event) => void handleSubmit(event)} noValidate>
                <div className="field">
                  <label className="field__label" htmlFor="email">
                    Email
                  </label>
                  <div className="input-icon">
                    <Mail size={17} aria-hidden />
                    <input
                      id="email"
                      type="email"
                      autoComplete="username"
                      placeholder="you@company.com"
                      required
                      value={email}
                      onChange={(event) => setEmail(event.target.value)}
                      aria-invalid={error?.fieldError('email') !== undefined}
                    />
                  </div>
                  {error?.fieldError('email') !== undefined && (
                    <span className="field__error">{error.fieldError('email')}</span>
                  )}
                </div>

                <div className="field">
                  <label className="field__label" htmlFor="password">
                    Password
                  </label>
                  <div className="input-icon">
                    <Lock size={17} aria-hidden />
                    <input
                      id="password"
                      type={showPassword ? 'text' : 'password'}
                      autoComplete="current-password"
                      required
                      value={password}
                      onChange={(event) => setPassword(event.target.value)}
                      onKeyDown={trackCapsLock}
                      onKeyUp={trackCapsLock}
                    />
                    <button
                      type="button"
                      className="input-icon__action"
                      onClick={() => setShowPassword((value) => !value)}
                      aria-label={showPassword ? 'Hide password' : 'Show password'}
                      aria-pressed={showPassword}
                    >
                      {showPassword ? <EyeOff size={17} /> : <Eye size={17} />}
                    </button>
                  </div>
                  <AnimatePresence>
                    {capsLock && (
                      <motion.span
                        className="field__hint login__caps"
                        role="status"
                        initial={{ opacity: 0, y: -4 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0 }}
                      >
                        Caps Lock is on.
                      </motion.span>
                    )}
                  </AnimatePresence>
                  {error?.fieldError('password') !== undefined && (
                    <span className="field__error">{error.fieldError('password')}</span>
                  )}
                </div>

                <button type="submit" className="btn btn--primary login__submit" disabled={submitting}>
                  {submitting ? <span className="spinner" /> : null}
                  {submitting ? 'Signing in…' : 'Sign in'}
                  {!submitting && <ArrowRight size={17} className="login__arrow" aria-hidden />}
                </button>
              </form>
            </motion.div>
          )}
        </AnimatePresence>
      </main>
    </div>
  )
}

/**
 * The forest half of the screen: the brand, what the product does, and a
 * canopy of leaves that leans towards the pointer.
 */
function BrandPanel() {
  const panel = useRef<HTMLElement>(null)

  function onPointerMove(event: React.PointerEvent<HTMLElement>) {
    if (panel.current === null || prefersReducedMotion()) return
    const rect = panel.current.getBoundingClientRect()
    panel.current.style.setProperty('--px', `${(event.clientX - rect.left) / rect.width - 0.5}`)
    panel.current.style.setProperty('--py', `${(event.clientY - rect.top) / rect.height - 0.5}`)
  }

  return (
    <aside className="login__brand" ref={panel} onPointerMove={onPointerMove} aria-label="About Habitat">
      <div className="login__orb login__orb--one" aria-hidden="true" />
      <div className="login__orb login__orb--two" aria-hidden="true" />
      <Canopy />

      <div className="login__brand-top">
        <BrandMark size={22} />
        <span className="login__wordmark">Habitat</span>
      </div>

      <div className="login__pitch">
        <h2 className="login__headline">
          {HEADLINE.map((word, index) => (
            <motion.span
              key={word}
              className="login__word"
              initial={{ opacity: 0, y: 28, filter: 'blur(8px)' }}
              animate={{ opacity: 1, y: 0, filter: 'blur(0px)' }}
              transition={{ delay: 0.15 + index * 0.07, duration: 0.6, ease: [0.22, 1, 0.36, 1] }}
            >
              {word}{' '}
            </motion.span>
          ))}
        </h2>
        <motion.p
          className="login__sub"
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          transition={{ delay: 0.75, duration: 0.6 }}
        >
          Reservations, operations, guest messages and the money — in one place, for every property
          you run.
        </motion.p>

        <ul className="login__features">
          {FEATURES.map((feature, index) => (
            <motion.li
              key={feature.title}
              initial={{ opacity: 0, x: -24 }}
              animate={{ opacity: 1, x: 0 }}
              transition={{ delay: 0.9 + index * 0.12, duration: 0.5, ease: [0.22, 1, 0.36, 1] }}
            >
              <span className="login__feature-icon" aria-hidden="true">
                <feature.icon size={18} />
              </span>
              <span>
                <strong>{feature.title}</strong>
                <span>{feature.body}</span>
              </span>
            </motion.li>
          ))}
        </ul>
      </div>
    </aside>
  )
}

function Canopy() {
  const leaves = [
    { x: 70, y: -4, r: -28, s: 1.5, d: 0 },
    { x: 96, y: 26, r: 34, s: 1.1, d: 1.2 },
    { x: 94, y: 62, r: -12, s: 1.5, d: 0.6 },
    { x: 84, y: 98, r: 58, s: 1.3, d: 2 },
    { x: 104, y: 86, r: 140, s: 1.1, d: 1.6 },
  ]

  return (
    <div className="login__canopy" aria-hidden="true">
      {leaves.map((leaf, index) => (
        <svg
          key={index}
          className="login__leaf"
          viewBox="0 0 120 120"
          style={
            {
              left: `${leaf.x}%`,
              top: `${leaf.y}%`,
              '--r': `${leaf.r}deg`,
              '--s': leaf.s,
              '--d': `${leaf.d}s`,
              '--depth': index + 1,
            } as React.CSSProperties
          }
        >
          <defs>
            <linearGradient id={`leaf-${index}`} x1="0" y1="1" x2="1" y2="0">
              <stop offset="0" stopColor="#344c0c" />
              <stop offset="0.6" stopColor="#586440" />
              <stop offset="1" stopColor="#c9d64b" stopOpacity="0.85" />
            </linearGradient>
          </defs>
          <path d="M10 110C10 55 45 15 112 8c-6 62-44 100-102 102Z" fill={`url(#leaf-${index})`} />
          <path d="M10 110 96 24" stroke="#1e271b" strokeOpacity="0.45" strokeWidth="2" fill="none" />
          <path
            d="M34 86 30 60M52 68l-2-26M70 50l-1-20M34 86l26-2M52 68l24-3M70 50l20-2"
            stroke="#1e271b"
            strokeOpacity="0.3"
            strokeWidth="1.4"
            fill="none"
          />
        </svg>
      ))}
    </div>
  )
}
