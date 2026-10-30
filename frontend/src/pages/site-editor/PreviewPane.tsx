import { useEffect, useRef } from 'react'

/**
 * The real site, rendered from the draft, beside the editor.
 *
 * Reloaded after every save, keeping its scroll position, so the page under
 * the editor's hands is the page guests will get.
 */
export function PreviewPane({ url, version, title }: { url: string | undefined; version: number; title: string }) {
  const frame = useRef<HTMLIFrameElement>(null)
  const scroll = useRef(0)

  // Where the reader was, read just before the frame is replaced.
  useEffect(() => {
    const current = frame.current

    return () => {
      try {
        scroll.current = current?.contentWindow?.scrollY ?? scroll.current
      } catch {
        // Not readable: the frame went somewhere else.
      }
    }
  }, [url, version])

  if (url === undefined) {
    return <div className="site-preview site-preview--loading" aria-busy="true">Loading the preview…</div>
  }

  return (
    <div className="site-preview">
      <iframe
        key={`${url}#${version}`}
        ref={frame}
        className="site-preview__frame"
        title={title}
        src={url}
        onLoad={() => {
          try {
            frame.current?.contentWindow?.scrollTo(0, scroll.current)
          } catch {
            // A page of another origin keeps its own scroll.
          }
        }}
      />
    </div>
  )
}
