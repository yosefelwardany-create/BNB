import { botFace, type Face } from '@/lib/botAvatars'

/**
 * One face, at whatever size the caller needs.
 *
 * Returns null for a key with no drawing so the caller can fall back rather than
 * render an empty circle, which would read as an agent that is there but broken.
 */
export function BotAvatarGlyph({
  avatar,
  size = 32,
  className,
}: {
  avatar?: string | null
  size?: number
  className?: string
}) {
  const face = botFace(avatar)

  if (face === undefined) return null

  return (
    <svg
      className={className}
      width={size}
      height={size}
      viewBox="0 0 64 64"
      role="presentation"
      aria-hidden="true"
      focusable="false"
    >
      <circle cx="32" cy="32" r="32" fill={face.bg} />
      {device(face)}
    </svg>
  )
}

function device(face: Face) {
  const { fg, bg } = face

  switch (face.device) {
    case 'dots':
      return (
        <g fill={fg}>
          <circle cx="23" cy="28" r="5" />
          <circle cx="41" cy="28" r="5" />
          <rect x="24" y="41" width="16" height="4" rx="2" />
        </g>
      )

    case 'visor':
      return (
        <g>
          <rect x="13" y="23" width="38" height="16" rx="8" fill={fg} />
          <circle cx="25" cy="31" r="3" fill={bg} />
          <circle cx="39" cy="31" r="3" fill={bg} />
        </g>
      )

    case 'cyclops':
      return (
        <g>
          <circle cx="32" cy="29" r="12" fill={fg} />
          <circle cx="32" cy="29" r="5" fill={bg} />
          <rect x="25" y="45" width="14" height="3.5" rx="1.75" fill={fg} />
        </g>
      )

    case 'square':
      return (
        <g fill={fg}>
          <rect x="19" y="22" width="11" height="13" rx="3.5" />
          <rect x="34" y="22" width="11" height="13" rx="3.5" />
          <rect x="24" y="42" width="16" height="4" rx="2" />
        </g>
      )

    case 'arc':
      // Closed, upturned eyes. The friendliest of the set.
      return (
        <g stroke={fg} strokeWidth="4" strokeLinecap="round" fill="none">
          <path d="M17 31 q6 -8 12 0" />
          <path d="M35 31 q6 -8 12 0" />
          <path d="M24 41 q8 7 16 0" />
        </g>
      )

    case 'antenna':
      return (
        <g fill={fg}>
          <rect x="30.5" y="8" width="3" height="9" rx="1.5" />
          <circle cx="32" cy="7" r="4" />
          <circle cx="24" cy="32" r="4.5" />
          <circle cx="40" cy="32" r="4.5" />
          <rect x="25" y="44" width="14" height="3.5" rx="1.75" />
        </g>
      )

    case 'headphones':
      return (
        <g fill={fg}>
          <rect x="6" y="24" width="8" height="17" rx="4" />
          <rect x="50" y="24" width="8" height="17" rx="4" />
          <circle cx="25" cy="30" r="4.5" />
          <circle cx="39" cy="30" r="4.5" />
          <rect x="25" y="42" width="14" height="3.5" rx="1.75" />
        </g>
      )

    case 'oval':
      return (
        <g fill={fg}>
          <ellipse cx="24" cy="30" rx="4.5" ry="8" />
          <ellipse cx="40" cy="30" rx="4.5" ry="8" />
          <rect x="26" y="45" width="12" height="3.5" rx="1.75" />
        </g>
      )

    case 'bolt':
      // No eyes at all — the one silhouette nobody confuses with another.
      return <path d="M36 10 L19 36 h10 l-3 18 L45 28 H35 Z" fill={fg} />

    case 'grid':
      return (
        <g fill={fg}>
          <rect x="18" y="20" width="11" height="11" rx="2.5" />
          <rect x="35" y="20" width="11" height="11" rx="2.5" />
          <rect x="18" y="35" width="11" height="11" rx="2.5" />
          <rect x="35" y="35" width="11" height="11" rx="2.5" />
        </g>
      )
  }
}
