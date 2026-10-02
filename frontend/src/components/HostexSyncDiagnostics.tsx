import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { api } from '@/api/client'

export function HostexSyncDiagnostics({ accountId }: { accountId: string }) {
  const [copied, setCopied] = useState(false)
  const [copyFailed, setCopyFailed] = useState(false)
  const report = useMutation({
    mutationFn: () => api.get<{ data: Record<string, unknown> }>(`channels/${accountId}/diagnostics`),
    onSuccess: () => { setCopied(false); setCopyFailed(false) },
  })
  const text = report.data ? JSON.stringify(report.data.data, null, 2) : ''

  async function copy() {
    try {
      await navigator.clipboard.writeText(text)
      setCopied(true)
      setCopyFailed(false)
    } catch {
      setCopyFailed(true)
    }
  }

  return <div className="mt-1" style={{ maxWidth: 520 }}>
    <button type="button" className="btn btn--ghost btn--sm" disabled={report.isPending} onClick={() => report.mutate()}>
      {report.isPending ? 'Loading diagnostics…' : 'Inspect sync'}
    </button>
    {report.isError && <p className="small danger" role="alert">Could not load diagnostics. Retry Inspect sync.</p>}
    {report.data && <details open className="small">
      <summary>Sync diagnostics</summary>
      <p>Cached pricing and photo formats. Credentials, guest information and image links are excluded. This does not start another Pull.</p>
      <button type="button" className="btn btn--sm" onClick={() => { void copy() }}>{copied ? 'Copied' : 'Copy report'}</button>
      {copyFailed && <p role="status">Select the report below and copy it manually.</p>}
      <textarea aria-label="Sync diagnostics report" readOnly value={text} rows={8} style={{ width: '100%', fontFamily: 'monospace' }} onFocus={(event) => event.target.select()} />
    </details>}
  </div>
}
