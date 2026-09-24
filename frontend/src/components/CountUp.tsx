import { useEffect, useRef, useState } from 'react'
import { prefersReducedMotion } from '@/lib/interactions'

/**
 * A figure that counts up to its value when it first appears or changes.
 *
 * Takes the already-formatted string ("£1,240.50", "82.5%", "14") and animates
 * only its first number, so currency symbols, units and precision come from the
 * formatter exactly as they would without the animation. The final frame is
 * the input string itself, character for character.
 *
 * Anything without a number in it ("—") is shown as it is. So is everything
 * for anybody who has asked for less motion.
 */

const NUMBER = /\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?/

const DURATION = 1100

function easeOutExpo(t: number): number {
  return t >= 1 ? 1 : 1 - Math.pow(2, -10 * t)
}

/** The frame being shown, and the value it is a frame of. */
interface Frame {
  of: string
  shown: string
}

export function CountUp({ value }: { value: string | number }) {
  const text = String(value)
  const [frame, setFrame] = useState<Frame>(() => ({
    of: text,
    shown: prefersReducedMotion() ? text : withNumber(text, () => 0),
  }))
  // The number currently on screen, so a change animates on from wherever the
  // last animation had reached.
  const onScreen = useRef<number>(0)

  useEffect(() => {
    const match = NUMBER.exec(text)
    if (match === null || prefersReducedMotion()) return

    const raw = match[0]
    const target = Number(raw.replace(/,/g, ''))
    const from = onScreen.current
    const start = performance.now()
    let handle = 0

    const tick = (now: number) => {
      const progress = Math.min(1, (now - start) / DURATION)

      if (progress >= 1) {
        onScreen.current = target
        setFrame({ of: text, shown: text })
        return
      }

      const current = from + (target - from) * easeOutExpo(progress)
      onScreen.current = current
      setFrame({ of: text, shown: withNumber(text, () => current) })
      handle = requestAnimationFrame(tick)
    }

    handle = requestAnimationFrame(tick)

    return () => cancelAnimationFrame(handle)
  }, [text])

  // Until the first frame of a new value is drawn, show the value itself.
  return <>{frame.of === text ? frame.shown : text}</>
}

/** The text with its first number replaced, keeping its precision and grouping. */
function withNumber(text: string, value: () => number): string {
  const match = NUMBER.exec(text)
  if (match === null) return text

  const raw = match[0]
  const decimals = (raw.split('.')[1] ?? '').length
  const formatted = value().toLocaleString('en-GB', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
    useGrouping: raw.includes(','),
  })

  return text.slice(0, match.index) + formatted + text.slice(match.index + raw.length)
}
