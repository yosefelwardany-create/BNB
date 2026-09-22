/**
 * A status chip.
 *
 * The colour comes from the server rather than a lookup table here, so a
 * status means the same thing and looks the same everywhere it appears.
 */
export function Chip({ label, colour = 'slate' }: { label: string; colour?: string }) {
  const known = ['emerald', 'amber', 'rose', 'sky', 'slate', 'zinc', 'indigo', 'orange']
  const safe = known.includes(colour) ? colour : 'slate'

  return <span className={`chip chip--${safe}`}>{label}</span>
}
