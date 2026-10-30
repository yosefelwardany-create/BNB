import { useCallback, useEffect, useRef, type RefObject } from 'react'

/** What the overlay in the preview says was done on the page. */
export type FromPage =
  | { type: 'ready' }
  | { type: 'select'; block: string }
  | { type: 'text'; block: string; field: string; value: string }
  | { type: 'move'; block: string; to: number }
  | { type: 'duplicate'; block: string }
  | { type: 'remove'; block: string }
  | { type: 'insert-at'; index: number }
  | { type: 'undo' }
  | { type: 'redo' }

/** What the editor tells the page to show. */
export type ToPage =
  | { type: 'replace'; block: string; html: string }
  | { type: 'order'; blocks: string[] }
  | { type: 'select'; block: string | null }

/**
 * The editor's side of the talk with its preview. A message is taken only
 * from that frame, at the preview's own origin, and only in a shape the
 * overlay sends; messages go only to that frame and that origin.
 */
export function usePreviewBridge(
  frame: RefObject<HTMLIFrameElement | null>,
  origin: string | null,
  onMessage: (message: FromPage) => void,
): (message: ToPage) => void {
  const handler = useRef(onMessage)

  useEffect(() => {
    handler.current = onMessage
  }, [onMessage])

  useEffect(() => {
    function receive(event: MessageEvent) {
      const view = frame.current?.contentWindow
      if (origin === null || view === null || view === undefined || event.source !== view || event.origin !== origin) return

      const message = parse(event.data)
      if (message !== null) handler.current(message)
    }

    window.addEventListener('message', receive)

    return () => window.removeEventListener('message', receive)
  }, [frame, origin])

  return useCallback(
    (message: ToPage) => {
      if (origin !== null) frame.current?.contentWindow?.postMessage({ source: 'habitat-editor', ...message }, origin)
    },
    [frame, origin],
  )
}

function parse(data: unknown): FromPage | null {
  if (typeof data !== 'object' || data === null) return null

  const message = data as Record<string, unknown>
  if (message.source !== 'habitat-overlay') return null

  const text = (key: string) => typeof message[key] === 'string'
  const number = (key: string) => typeof message[key] === 'number' && Number.isInteger(message[key]) && message[key] >= 0

  switch (message.type) {
    case 'ready':
    case 'undo':
    case 'redo':
      return { type: message.type }
    case 'select':
    case 'duplicate':
    case 'remove':
      return text('block') ? { type: message.type, block: message.block as string } : null
    case 'text':
      return text('block') && text('field') && text('value')
        ? { type: 'text', block: message.block as string, field: message.field as string, value: message.value as string }
        : null
    case 'move':
      return text('block') && number('to') ? { type: 'move', block: message.block as string, to: message.to as number } : null
    case 'insert-at':
      return number('index') ? { type: 'insert-at', index: message.index as number } : null
    default:
      return null
  }
}
