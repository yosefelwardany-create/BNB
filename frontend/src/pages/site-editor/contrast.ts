/** The WCAG contrast of two colours written #rrggbb. */
export function contrast(a: string, b: string): number {
  const luminance = (hex: string) => {
    const channels = [1, 3, 5].map((start) => {
      const c = parseInt(hex.slice(start, start + 2), 16) / 255

      return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
    })

    return 0.2126 * channels[0]! + 0.7152 * channels[1]! + 0.0722 * channels[2]!
  }

  const [la, lb] = [luminance(a), luminance(b)]

  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05)
}

/** Whether a button of this colour can carry readable text, light or dark. */
export function readableButton(colour: string): boolean {
  return Math.max(contrast(colour, '#ffffff'), contrast(colour, '#1d2327')) >= 4.5
}
