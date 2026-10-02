import { useState } from 'react'

export function AgentAvatar({ url, initial }: { url?: string | null; initial?: string | null }) {
  const [failedUrl, setFailedUrl] = useState<string | null>(null)
  return url && failedUrl !== url ? (
    <img className="property-card__agent-avatar" src={url} alt="" width={32} height={32}
      loading="lazy" referrerPolicy="no-referrer" onError={() => setFailedUrl(url)} />
  ) : (
    <span className="property-card__agent-avatar" aria-hidden="true">{initial ?? '?'}</span>
  )
}
