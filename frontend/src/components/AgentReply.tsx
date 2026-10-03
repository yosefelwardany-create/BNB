import { lazy, Suspense } from 'react'

const Markdown = lazy(() => import('react-markdown'))

/** Render formatting without executing HTML or loading model-supplied images. */
export function AgentReply({ text }: { text: string }) {
  return <div className="agent-reply"><Suspense fallback={<p style={{ whiteSpace: 'pre-wrap' }}>{text}</p>}><Markdown skipHtml disallowedElements={['img']} components={{
    a: ({ href, children }) => <a href={href} target="_blank" rel="noopener noreferrer">{children}</a>,
  }}>{text}</Markdown></Suspense></div>
}
