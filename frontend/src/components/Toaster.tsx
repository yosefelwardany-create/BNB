import { useEffect, useSyncExternalStore } from 'react'
import { AnimatePresence, motion } from 'motion/react'
import { Check, X } from 'lucide-react'
import {
  TOAST_DURATION,
  currentToasts,
  dismissToast,
  subscribeToasts,
  type Toast,
} from '@/lib/toast'

/** Renders the toasts raised through `toast()` in the bottom corner. */
export function Toaster() {
  const items = useSyncExternalStore(subscribeToasts, currentToasts, currentToasts)

  return (
    <div className="toaster" role="status" aria-live="polite">
      <AnimatePresence initial={false}>
        {items.map((item) => (
          <ToastCard key={item.id} item={item} />
        ))}
      </AnimatePresence>
    </div>
  )
}

function ToastCard({ item }: { item: Toast }) {
  useEffect(() => {
    const timer = window.setTimeout(() => dismissToast(item.id), TOAST_DURATION)
    return () => window.clearTimeout(timer)
  }, [item.id])

  return (
    <motion.div
      layout
      className={`toast toast--${item.tone}`}
      initial={{ opacity: 0, y: 24, scale: 0.9 }}
      animate={{ opacity: 1, y: 0, scale: 1 }}
      exit={{ opacity: 0, x: 60, scale: 0.95, transition: { duration: 0.2 } }}
      transition={{ type: 'spring', stiffness: 420, damping: 30 }}
      onClick={() => dismissToast(item.id)}
    >
      <span className="toast__icon" aria-hidden="true">
        {item.tone === 'error' ? <X size={15} strokeWidth={2.6} /> : <Check size={15} strokeWidth={2.6} />}
      </span>
      <div>
        <div className="toast__title">{item.title}</div>
        {item.body !== undefined && <div className="toast__body">{item.body}</div>}
      </div>
      <motion.span
        className="toast__timer"
        initial={{ scaleX: 1 }}
        animate={{ scaleX: 0 }}
        transition={{ duration: TOAST_DURATION / 1000, ease: 'linear' }}
      />
    </motion.div>
  )
}
