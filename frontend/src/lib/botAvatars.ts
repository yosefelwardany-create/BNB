/**
 * The faces an agent can wear, as data.
 *
 * The drawing lives beside this in `components/BotAvatarGlyph`; this file holds
 * only the palette and device for each one, so importing the catalogue costs no
 * component and a screen can ask what exists without rendering anything.
 *
 * Drawn by {@link BotAvatarGlyph} as inline SVG rather than fetched as images,
 * for three reasons that
 * all matter on the property list: there is no request to fail, so a card never
 * shows a broken picture; they stay crisp at 28px on a card and at 56px in the
 * picker; and they cost nothing to add to a page already rendering fifty rows.
 *
 * Each face is a saturated disc with a pale device on it. The disc colour does
 * the recognising at card size — an operator picks Alex out by "the orange one"
 * long before they read the name — and the device makes two similar hues
 * distinguishable to somebody who cannot tell them apart by colour. Every device
 * is used exactly twice across the set, always on hues from opposite sides of
 * the wheel.
 *
 * The keys come from the server, which is the authority on which faces exist.
 * Anything it sends that is missing here simply has no drawing, and the caller
 * falls back to the bot's initial — so adding a face to the catalogue cannot
 * blank a card on a frontend that has not caught up.
 */
export interface Face {
  /** The disc. */
  bg: string
  /** The device drawn on it. */
  fg: string
  device: Device
}

export type Device =
  | 'dots'
  | 'visor'
  | 'cyclops'
  | 'square'
  | 'arc'
  | 'antenna'
  | 'headphones'
  | 'oval'
  | 'bolt'
  | 'grid'

const FACES: Record<string, Face> = {
  nova: { bg: '#6366f1', fg: '#eef2ff', device: 'antenna' },
  pixel: { bg: '#0ea5e9', fg: '#f0f9ff', device: 'grid' },
  echo: { bg: '#14b8a6', fg: '#ecfdf5', device: 'visor' },
  juno: { bg: '#a855f7', fg: '#faf5ff', device: 'dots' },
  sable: { bg: '#64748b', fg: '#f8fafc', device: 'oval' },
  lumen: { bg: '#f59e0b', fg: '#fffbeb', device: 'bolt' },
  maple: { bg: '#ef4444', fg: '#fef2f2', device: 'arc' },
  cobalt: { bg: '#2563eb', fg: '#eff6ff', device: 'square' },
  ember: { bg: '#f97316', fg: '#fff7ed', device: 'cyclops' },
  fern: { bg: '#22c55e', fg: '#f0fdf4', device: 'headphones' },
  onyx: { bg: '#334155', fg: '#f1f5f9', device: 'visor' },
  clay: { bg: '#b45309', fg: '#fffbeb', device: 'dots' },
  indigo: { bg: '#4338ca', fg: '#eef2ff', device: 'cyclops' },
  saffron: { bg: '#ca8a04', fg: '#fefce8', device: 'grid' },
  birch: { bg: '#84cc16', fg: '#1a2e05', device: 'oval' },
  slate: { bg: '#475569', fg: '#f1f5f9', device: 'antenna' },
  coral: { bg: '#f43f5e', fg: '#fff1f2', device: 'arc' },
  moss: { bg: '#15803d', fg: '#f0fdf4', device: 'square' },
  dune: { bg: '#d97706', fg: '#fffbeb', device: 'headphones' },
  frost: { bg: '#06b6d4', fg: '#ecfeff', device: 'bolt' },
}

export function hasBotAvatar(key?: string | null): boolean {
  return typeof key === 'string' && key in FACES
}

/** Every key this build can draw, for a picker that wants to filter. */
export function drawableBotAvatars(): string[] {
  return Object.keys(FACES)
}

export function botFace(key?: string | null): Face | undefined {
  return typeof key === 'string' ? FACES[key] : undefined
}

