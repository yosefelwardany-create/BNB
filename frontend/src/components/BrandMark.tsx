/**
 * The product mark: an open ring with a swoosh beneath it, after the lime
 * stroke under the "O" of the INSHARO wordmark.
 */
export function BrandMark({ size = 20 }: { size?: number }) {
  return (
    <span className="brand-mark" aria-hidden="true">
      <svg
        width={size}
        height={size}
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth={2.6}
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <circle cx="12" cy="10.5" r="6.2" />
        <path d="M4.6 19.4c4.4 2.6 11 2 15.6-3.6" />
      </svg>
    </span>
  )
}
