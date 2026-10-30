import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronLeft, ExternalLink, Redo2, Undo2 } from 'lucide-react'
import { api, ApiError } from '@/api/client'
import type { EditorState, SiteBlock, SiteContent, Website } from '@/api/types'
import { QueryState } from '@/components/QueryState'
import { BlockLibrary } from './BlockLibrary'
import { BlockSettings, type SettingsContext } from './BlockSettings'
import { BlocksPanel } from './BlocksPanel'
import { ConfirmDialog } from './EditorDialog'
import { HomesPanel } from './HomesPanel'
import { PagesPanel } from './PagesPanel'
import { PreviewPane } from './PreviewPane'
import { SiteDetailsPanel } from './SiteDetailsPanel'
import { ThemePanel } from './ThemePanel'
import { useAutosave, type SaveStatus } from './useAutosave'
import {
  addPage,
  adoptKept,
  createHistory,
  duplicateBlock,
  insertBlock,
  moveBlock,
  movePage,
  push,
  redo,
  removeBlock,
  removePage,
  sameDocument,
  undo,
  updateBlock,
  updatePage,
  type History,
} from './document'

/** The platform's editor of one site, at /websites/:websiteId/edit. */
export function SiteEditorPage() {
  const { websiteId = '' } = useParams()

  return (
    <SiteEditor
      apiBase={`platform/websites/${websiteId}/editor`}
      sitePath={`platform/websites/${websiteId}`}
      backTo="/websites"
      platform
    />
  )
}

type Tab = 'build' | 'theme' | 'homes' | 'details'

const TABS: { key: Tab; label: string; platformOnly?: boolean }[] = [
  { key: 'build', label: 'Pages' },
  { key: 'theme', label: 'Theme' },
  { key: 'homes', label: 'Homes', platformOnly: true },
  { key: 'details', label: 'Details', platformOnly: true },
]

/**
 * Drafts still being saved by an editor that was just closed, by where they
 * are saved. Opening the editor again waits for them, so it opens on the
 * draft as it now is rather than one version behind.
 */
const leaving = new Map<string, Promise<unknown>>()

const STATUS_TEXT: Record<SaveStatus, string> = {
  saved: 'All changes saved',
  pending: 'Saving…',
  saving: 'Saving…',
  error: 'Not saved — retrying',
  conflict: '',
  invalid: '',
}

/**
 * The page builder: the site's pages and blocks on the left, the real site
 * in the middle, the chosen block's settings on the right.
 *
 * Every change is a step that can be undone, and is saved as a draft a moment
 * later; guests see it only once it is published.
 */
export function SiteEditor({
  apiBase,
  sitePath,
  backTo,
  platform = false,
  saveDelay = 800,
}: {
  /** Where this editor's draft lives on the API. */
  apiBase: string
  /** The site itself on the API, for its details and homes. */
  sitePath: string
  backTo: string
  /** The platform owner, who also manages the site's homes and details here. */
  platform?: boolean
  saveDelay?: number
}) {
  const queryClient = useQueryClient()
  const state = useQuery({
    queryKey: ['site-editor', apiBase],
    queryFn: async () => {
      await leaving.get(apiBase)

      return api.get<{ data: EditorState }>(apiBase)
    },
    // The draft is this screen's to change; a refetch behind its back would
    // throw away what is on screen. Read afresh each time the screen opens.
    refetchOnWindowFocus: false,
    refetchOnMount: 'always',
    staleTime: Infinity,
  })
  // A draft kept from an earlier visit may be versions behind: the editor
  // opens only on one read since this screen opened.
  const [cachedAt] = useState(state.dataUpdatedAt)
  const fresh = state.data !== undefined && state.dataUpdatedAt > cachedAt

  return (
    <QueryState isLoading={!fresh && state.error === null} error={state.error} isEmpty={false}>
      {fresh && state.data !== undefined && (
        <Editor
          key={state.dataUpdatedAt}
          initial={state.data.data}
          apiBase={apiBase}
          sitePath={sitePath}
          backTo={backTo}
          platform={platform}
          saveDelay={saveDelay}
          reload={() => void queryClient.invalidateQueries({ queryKey: ['site-editor', apiBase] })}
        />
      )}
    </QueryState>
  )
}

function Editor({
  initial,
  apiBase,
  sitePath,
  backTo,
  platform,
  saveDelay,
  reload,
}: {
  initial: EditorState
  apiBase: string
  sitePath: string
  backTo: string
  platform: boolean
  saveDelay: number
  reload: () => void
}) {
  const queryClient = useQueryClient()
  const [history, setHistory] = useState<History>(() => createHistory(initial.content))
  const [tab, setTab] = useState<Tab>('build')
  const [pageId, setPageId] = useState(initial.content.pages[0]?.id ?? '')
  const [blockId, setBlockId] = useState<string | null>(null)
  const [library, setLibrary] = useState(false)
  const [confirmDiscard, setConfirmDiscard] = useState(false)
  const [unpublished, setUnpublished] = useState(initial.unpublished_changes)
  const [notice, setNotice] = useState<string | null>(null)
  const [previewVersion, setPreviewVersion] = useState(0)
  const [images, setImages] = useState(initial.images)
  const version = useRef(initial.version)
  // The document as it stands, for callbacks that outlive a render.
  const presentRef = useRef(initial.content)

  const doc = history.present

  useEffect(() => {
    presentRef.current = history.present
  }, [history.present])
  const page = doc.pages.find((candidate) => candidate.id === pageId) ?? doc.pages[0]!
  const block = page.blocks.find((candidate) => candidate.id === blockId) ?? null
  const definition = block === null ? undefined : initial.definitions.find((candidate) => candidate.type === block.type)

  // A refusal points at its block: the page and the block it is about are
  // opened, and its fields show why.
  const pointAt = useCallback((error: ApiError) => {
    const path = Object.keys(error.errors)[0]
    const match = path?.match(/^pages\.(\d+)\.blocks\.(\d+)/)
    if (match === null || match === undefined) return

    const refusedPage = presentRef.current.pages[Number(match[1])]
    const refusedBlock = refusedPage?.blocks[Number(match[2])]
    if (refusedPage === undefined || refusedBlock === undefined) return

    setTab('build')
    setPageId(refusedPage.id)
    setBlockId(refusedBlock.id)
  }, [])

  const autosave = useAutosave(
    useCallback(
      async (content: SiteContent) => {
        const result = await api.put<{ data: { version: number; unpublished_changes: boolean; content: SiteContent } }>(`${apiBase}/draft`, {
          content,
          version: version.current,
        })
        version.current = result.data.version
        setUnpublished(result.data.unpublished_changes)
        setPreviewVersion((n) => n + 1)
        // Formatted text as it was kept, where it has not been changed since.
        setHistory((current) => {
          const present = adoptKept(current.present, content, result.data.content, initial.definitions)

          return present === current.present ? current : { ...current, present }
        })
      },
      [apiBase, initial.definitions],
    ),
    saveDelay,
    pointAt,
  )
  const { schedule, flush } = autosave

  // Leaving saves what is still waiting.
  useEffect(() => () => {
    const saving = flush()
    leaving.set(apiBase, saving)
    void saving.finally(() => {
      if (leaving.get(apiBase) === saving) leaving.delete(apiBase)
    })
  }, [apiBase, flush])

  const publish = useMutation({
    mutationFn: async () => {
      // Only the draft on screen goes live: if it could not be saved, nothing does.
      if (!(await flush())) throw new ApiError(0, 'Nothing was published, because your latest changes are not saved.')

      return api.post<{ data: EditorState }>(`${apiBase}/publish`, { version: version.current })
    },
    onSuccess: (result) => {
      setUnpublished(result.data.unpublished_changes)
      setNotice('Published. Guests see your changes now.')
    },
    onError: (error) => {
      if (error instanceof ApiError && error.isValidation) pointAt(error)
    },
  })
  const resetPublish = publish.reset

  const refusal = autosave.status === 'invalid'
    ? autosave.error
    : publish.error instanceof ApiError && publish.error.isValidation ? publish.error : null

  const fieldErrors = useMemo(() => {
    if (refusal === null || block === null) return {}

    const pageIndex = doc.pages.findIndex((candidate) => candidate.id === page.id)
    const blockIndex = page.blocks.findIndex((candidate) => candidate.id === block.id)
    const prefix = `pages.${pageIndex}.blocks.${blockIndex}.fields.`

    return Object.fromEntries(
      Object.entries(refusal.errors)
        .filter(([path]) => path.startsWith(prefix))
        .map(([path, messages]) => [path.slice(prefix.length).split('.')[0]!, messages[0] ?? '']),
    )
  }, [refusal, block, doc, page])

  /**
   * One step: shown at once, saved a moment later, undoable. Made from the
   * document as it stands, so a photo that finishes uploading later does not
   * undo what was changed meanwhile. Changes to the same `key` moments apart
   * are one step.
   */
  const change = useCallback(
    (update: (current: SiteContent) => SiteContent, key?: string) => {
      const next = update(presentRef.current)
      if (sameDocument(next, presentRef.current)) return

      presentRef.current = next
      const at = Date.now()
      setHistory((current) => push(current, next, key, at))
      setNotice(null)
      resetPublish()
      schedule(next)
    },
    [schedule, resetPublish],
  )

  const step = (move: (current: History) => History) => {
    const next = move(history)
    if (next === history) return

    presentRef.current = next.present
    setHistory(next)
    schedule(next.present)
  }

  const preview = useQuery({
    queryKey: ['site-editor-preview', apiBase, page.id],
    queryFn: () => api.get<{ data: { url: string } }>(`${apiBase}/preview`, page.kind === 'home' ? undefined : { page: page.id }),
    // A preview address lasts two hours; a fresh one well before then.
    refetchInterval: 90 * 60 * 1000,
    staleTime: 90 * 60 * 1000,
  })
  const previewUrl = preview.data?.data.url ?? (page.kind === 'home' ? initial.preview_url : undefined)

  const discard = useMutation({
    mutationFn: () => api.post<{ data: EditorState }>(`${apiBase}/discard`),
    onSuccess: (result) => {
      autosave.reset()
      version.current = result.data.version
      setHistory(createHistory(result.data.content))
      setUnpublished(false)
      setBlockId(null)
      setPageId(result.data.content.pages[0]?.id ?? '')
      setPreviewVersion((n) => n + 1)
      setNotice('Your changes were discarded. The draft is what guests see.')
    },
  })

  const upload = useCallback(
    async (file: File) => {
      const form = new FormData()
      form.append('file', file)
      const result = await api.post<{ data: EditorState['images'][number] }>(`${apiBase}/images`, form)
      setImages((current) => [...current, result.data])

      return result.data.ref
    },
    [apiBase],
  )

  const context: SettingsContext = { images, photos: initial.photos, homes: initial.homes, pages: doc.pages, upload }

  // The site as a panel saved it, for the next time a panel opens.
  const siteSaved = (website: Website) => {
    queryClient.setQueryData(['platform-website', sitePath], { data: website })
    setPreviewVersion((n) => n + 1)
  }

  // Ctrl+Z and Ctrl+Shift+Z, outside the text the person is typing in.
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      const target = event.target as HTMLElement | null
      if (target?.closest('input, textarea, select, [contenteditable="true"]')) return
      if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 'z') return

      event.preventDefault()
      step(event.shiftKey ? redo : undo)
    }

    window.addEventListener('keydown', onKey)

    return () => window.removeEventListener('keydown', onKey)
  })

  const tabs = TABS.filter((candidate) => platform || !candidate.platformOnly)

  return (
    <div className="site-editor">
      <header className="site-editor__bar">
        <div className="row">
          <Link to={backTo} className="btn btn--ghost btn--sm" aria-label="Back to websites">
            <ChevronLeft size={15} aria-hidden /> Websites
          </Link>
          <div className="site-editor__title">
            <span className="strong">{initial.website.name}</span>
            <SaveState status={autosave.status} error={autosave.error} onReload={reload} />
          </div>
        </div>
        <div className="row">
          <button type="button" className="btn btn--ghost btn--icon" aria-label="Undo" title="Undo (Ctrl+Z)" disabled={history.past.length === 0} onClick={() => step(undo)}>
            <Undo2 size={16} aria-hidden />
          </button>
          <button type="button" className="btn btn--ghost btn--icon" aria-label="Redo" title="Redo (Ctrl+Shift+Z)" disabled={history.future.length === 0} onClick={() => step(redo)}>
            <Redo2 size={16} aria-hidden />
          </button>
          {initial.website.status === 'live' && (
            <a className="btn btn--ghost btn--sm" href={initial.website.public_url} target="_blank" rel="noreferrer">
              <ExternalLink size={14} aria-hidden /> View site
            </a>
          )}
          <button type="button" className="btn btn--sm" disabled={!unpublished || discard.isPending} onClick={() => setConfirmDiscard(true)}>
            Discard changes
          </button>
          <button
            type="button"
            className="btn btn--sm btn--primary"
            disabled={publish.isPending || autosave.status === 'conflict'}
            onClick={() => publish.mutate()}
          >
            {publish.isPending ? 'Publishing…' : 'Publish'}
          </button>
        </div>
      </header>

      {(notice !== null || publish.error !== null) && (
        <div className={publish.error !== null ? 'notice notice--error site-editor__notice' : 'notice notice--info site-editor__notice'} role="status">
          {publish.error !== null ? (publish.error instanceof ApiError ? publish.error.message : 'The site could not be published.') : notice}
        </div>
      )}

      <div className="site-editor__body">
        <aside className="site-editor__left">
          <div className="site-editor__tabs" role="tablist" aria-label="Editor">
            {tabs.map((candidate) => (
              <button
                key={candidate.key}
                type="button"
                role="tab"
                aria-selected={tab === candidate.key}
                className={tab === candidate.key ? 'is-active' : ''}
                onClick={() => setTab(candidate.key)}
              >
                {candidate.label}
              </button>
            ))}
          </div>

          {tab === 'build' && (
            <>
              <PagesPanel
                pages={doc.pages}
                selectedId={page.id}
                onSelect={(id) => {
                  setPageId(id)
                  setBlockId(null)
                }}
                onMove={(from, to) => change((current) => movePage(current, from, to))}
                onAdd={(title) => {
                  let added = ''
                  change((current) => {
                    const next = addPage(current, title)
                    added = next.pages[next.pages.length - 1]!.id

                    return next
                  })
                  setPageId(added)
                  setBlockId(null)
                }}
                onRename={(id, title) => change((current) => updatePage(current, id, { title }), `page:${id}:title`)}
                onToggleMenu={(id, inMenu) => change((current) => updatePage(current, id, { in_menu: inMenu }))}
                onRemove={(id) => {
                  change((current) => removePage(current, id))
                  if (id === page.id) setPageId(doc.pages[0]!.id)
                }}
              />
              <BlocksPanel
                page={page}
                definitions={initial.definitions}
                selectedId={blockId}
                onSelect={setBlockId}
                onMove={(from, to) => change((current) => moveBlock(current, page.id, from, to))}
                onDuplicate={(id) => change((current) => duplicateBlock(current, page.id, id))}
                onRemove={(id) => {
                  change((current) => removeBlock(current, page.id, id))
                  if (id === blockId) setBlockId(null)
                }}
                onAdd={() => setLibrary(true)}
              />
            </>
          )}

          {tab === 'theme' && (
            <ThemePanel
              content={doc}
              themes={initial.themes}
              fontPairs={initial.font_pairs}
              buttons={initial.buttons}
              onChange={(patch) =>
                change(
                  (current) => ({ ...current, theme: patch.theme ?? current.theme, style: { ...current.style, ...patch.style } }),
                  `style:${patch.theme === undefined ? '' : 'theme'}:${Object.keys(patch.style ?? {}).join(',')}`,
                )
              }
            />
          )}

          {tab === 'homes' && platform && (
            <WithSite sitePath={sitePath}>{(website) => <HomesPanel website={website} onSaved={siteSaved} />}</WithSite>
          )}

          {tab === 'details' && platform && (
            <WithSite sitePath={sitePath}>{(website) => <SiteDetailsPanel website={website} onSaved={siteSaved} />}</WithSite>
          )}
        </aside>

        <main className="site-editor__preview">
          <PreviewPane url={previewUrl} version={previewVersion} title={`Preview of ${page.title}`} />
        </main>

        <aside className="site-editor__right">
          {block !== null && definition !== undefined ? (
            <BlockSettings
              key={block.id}
              block={block}
              definition={definition}
              context={context}
              errors={fieldErrors}
              onChange={(patch: { background?: SiteBlock['background']; fields?: Record<string, unknown> }) =>
                change(
                  (current) => updateBlock(current, page.id, block.id, patch),
                  `${block.id}:${[...Object.keys(patch.fields ?? {}), ...(patch.background === undefined ? [] : ['background'])].join(',')}`,
                )
              }
            />
          ) : (
            <div className="editor-panel editor-panel--empty">
              <h3>Change a block</h3>
              <p className="small muted">Pick a block on the left to change its words, photos and colours, or add a new one.</p>
              <button type="button" className="btn btn--sm" onClick={() => setLibrary(true)}>
                Add a block
              </button>
            </div>
          )}
        </aside>
      </div>

      {library && (
        <BlockLibrary
          definitions={initial.definitions}
          onPick={(type) => {
            const index = block === null ? page.blocks.length : page.blocks.findIndex((candidate) => candidate.id === block.id) + 1
            let added: string | null = null
            change((current) => {
              const next = insertBlock(current, page.id, type, index, initial.definitions)
              added = next.pages.find((candidate) => candidate.id === page.id)?.blocks[index]?.id ?? null

              return next
            })
            setBlockId(added)
          }}
          onClose={() => setLibrary(false)}
        />
      )}

      {confirmDiscard && (
        <ConfirmDialog
          title="Discard your changes?"
          body="The draft goes back to what guests see now. This cannot be undone."
          confirmLabel="Yes, discard"
          onConfirm={() => discard.mutate()}
          onClose={() => setConfirmDiscard(false)}
        />
      )}
    </div>
  )
}

function SaveState({ status, error, onReload }: { status: SaveStatus; error: ApiError | null; onReload: () => void }) {
  if (status === 'conflict') {
    return (
      <span className="site-editor__status site-editor__status--warn" role="alert">
        {error?.message ?? 'Someone else changed this site.'}{' '}
        <button type="button" className="btn btn--sm" onClick={onReload}>
          Reload
        </button>
      </span>
    )
  }

  if (status === 'invalid') {
    return (
      <span className="site-editor__status site-editor__status--warn" role="alert">
        Not saved: {error?.message ?? 'something on the page needs changing.'}
      </span>
    )
  }

  return (
    <span className={`site-editor__status ${status === 'error' ? 'site-editor__status--warn' : ''}`} role="status">
      {STATUS_TEXT[status]}
    </span>
  )
}

/** The site itself, for the panels that change it directly. */
function WithSite({ sitePath, children }: { sitePath: string; children: (website: Website) => ReactNode }) {
  const site = useQuery({
    queryKey: ['platform-website', sitePath],
    queryFn: () => api.get<{ data: Website }>(sitePath),
  })

  return (
    <QueryState isLoading={site.isLoading} error={site.error} isEmpty={false}>
      {site.data !== undefined && children(site.data.data)}
    </QueryState>
  )
}
