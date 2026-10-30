import { useRef, type RefObject } from 'react'

/**
 * The real site, rendered from the draft, beside the editor.
 *
 * Reloaded after every save, keeping its scroll position, so the page under
 * the editor's hands is the page guests will get.
 */
/** A phone's width, for a look at the page as most guests get it. */
const PHONE_WIDTH = 390

export function PreviewPane({
  frameRef,
  url,
  version,
  title,
  device = 'desktop',
}: {
  /** The frame, for the editor to talk with the page in it. */
  frameRef?: RefObject<HTMLIFrameElement | null>
  url: string | undefined
  version: number
  title: string
  device?: 'desktop' | 'phone'
}) {
  // Where the reader was, and on which page, noted as they scroll: by the
  // time a fresh preview loads, the old frame is gone and cannot be asked.
  const scroll = useRef({ page: '', y: 0 })

  if (url === undefined) {
    return <div className="site-preview site-preview--loading" aria-busy="true">Loading the preview…</div>
  }

  // The page's own address; its signature changes when the address is renewed.
  const page = url.split('?')[0] ?? url

  return (
    <div className={`site-preview ${device === 'phone' ? 'site-preview--phone' : ''}`} style={device === 'phone' ? { width: PHONE_WIDTH } : undefined}>
      <iframe
        key={`${url}#${version}`}
        ref={frameRef}
        className="site-preview__frame"
        title={title}
        src={url}
        onLoad={(event) => {
          const view = event.currentTarget.contentWindow
          if (view === null) return

          try {
            view.scrollTo(0, scroll.current.page === page ? scroll.current.y : 0)
            view.addEventListener('scroll', () => {
              scroll.current = { page, y: view.scrollY }
            }, { passive: true })
          } catch {
            // A page of another origin keeps its own scroll.
          }
        }}
      />
    </div>
  )
}
