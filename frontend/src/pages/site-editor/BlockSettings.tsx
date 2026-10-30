import { useId } from 'react'
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react'
import type { BlockDefinition, BlockFieldDefinition, EditorState, SiteBlock, SiteLink, SitePage } from '@/api/types'
import { Segmented } from '@/components/Segmented'
import { ImageField, ImagesField } from './ImagePicker'
import { RichTextField } from './RichTextField'

export interface SettingsContext {
  images: EditorState['images']
  photos: EditorState['photos']
  homes: EditorState['homes']
  pages: SitePage[]
  upload: (file: File) => Promise<string>
}

const BACKGROUNDS: { value: SiteBlock['background']; label: string }[] = [
  { value: 'plain', label: 'Plain' },
  { value: 'tint', label: 'Soft tint' },
  { value: 'brand', label: 'Theme colour' },
]

/**
 * The selected block's settings, built from its definition: the same list
 * the server checks it against, in words a host uses.
 */
export function BlockSettings({
  block,
  definition,
  context,
  errors,
  onChange,
}: {
  block: SiteBlock
  definition: BlockDefinition
  context: SettingsContext
  /** The server's refusals for this block, by field name. */
  errors: Record<string, string>
  onChange: (patch: { background?: SiteBlock['background']; fields?: Record<string, unknown> }) => void
}) {
  return (
    <section className="editor-panel" aria-label={`${definition.label} settings`}>
      <div className="editor-panel__head">
        <h3>{definition.label}</h3>
      </div>
      <p className="small muted">{definition.description}</p>

      {definition.fields.map((field) => (
        <FieldControl
          key={field.name}
          field={field}
          value={block.fields[field.name]}
          error={errors[field.name]}
          context={context}
          onChange={(value) => onChange({ fields: { [field.name]: value } })}
        />
      ))}

      <div className="field">
        <span className="field__label">Background</span>
        <Segmented label="Background" value={block.background} options={BACKGROUNDS} onChange={(background) => onChange({ background })} />
      </div>
    </section>
  )
}

function FieldControl({
  field,
  value,
  error,
  context,
  onChange,
}: {
  field: BlockFieldDefinition
  value: unknown
  error?: string
  context: SettingsContext
  onChange: (value: unknown) => void
}) {
  const id = useId()
  const message = error !== undefined && <span className="field__error" role="alert">{error}</span>
  const hint = field.hint !== undefined && <span className="field__hint">{field.hint}</span>

  switch (field.kind) {
    case 'text': {
      const text = typeof value === 'string' ? value : ''
      const long = (field.max ?? 0) > 160

      return (
        <div className="field">
          <span className="row row--between">
            <label className="field__label" htmlFor={id}>{field.label}</label>
            {field.max !== undefined && <span className={`small ${text.length > field.max ? 'text-rose' : 'faint'}`}>{text.length}/{field.max}</span>}
          </span>
          {long ? (
            <textarea id={id} className="input" rows={4} value={text} onChange={(event) => onChange(event.target.value)} />
          ) : (
            <input id={id} className="input" value={text} onChange={(event) => onChange(event.target.value)} />
          )}
          {hint}
          {message}
        </div>
      )
    }

    case 'rich_text':
      return (
        <div className="field">
          <span className="field__label" id={`${id}-label`}>{field.label}</span>
          <RichTextField labelledBy={`${id}-label`} value={typeof value === 'string' ? value : ''} onChange={onChange} />
          {message}
        </div>
      )

    case 'toggle':
      return (
        <div className="field field--inline">
          <button
            type="button"
            role="switch"
            aria-checked={value === true}
            className={`switch ${value === true ? 'is-on' : ''}`}
            onClick={() => onChange(value !== true)}
          >
            <span className="switch__thumb" aria-hidden />
            <span className="switch__label">{field.label}</span>
          </button>
          {message}
        </div>
      )

    case 'choice': {
      const options = field.options ?? []
      const current = typeof value === 'string' ? value : typeof field.default === 'string' ? field.default : ''

      return (
        <div className="field">
          <span className="field__label" id={`${id}-label`}>{field.label}</span>
          {options.length <= 3 ? (
            <Segmented label={field.label} value={current} options={options} onChange={onChange} />
          ) : (
            <select className="input" aria-labelledby={`${id}-label`} value={current} onChange={(event) => onChange(event.target.value)}>
              {options.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </select>
          )}
          {hint}
          {message}
        </div>
      )
    }

    case 'image':
      return (
        <>
          <ImageField
            id={id}
            label={field.label}
            value={typeof value === 'string' ? value : null}
            images={context.images}
            photos={context.photos}
            onChange={onChange}
            onUpload={context.upload}
          />
          {message}
        </>
      )

    case 'images':
      return (
        <>
          <ImagesField
            id={id}
            label={field.label}
            value={Array.isArray(value) ? value.filter((ref): ref is string => typeof ref === 'string') : []}
            max={field.max_items ?? 12}
            images={context.images}
            photos={context.photos}
            onChange={onChange}
            onUpload={context.upload}
          />
          {message}
        </>
      )

    case 'items':
      return <ItemsField field={field} value={Array.isArray(value) ? (value as Record<string, unknown>[]) : []} context={context} onChange={onChange} error={error} />

    case 'link':
      return <LinkField field={field} value={isLink(value) ? value : null} pages={context.pages} onChange={onChange} error={error} />

    case 'listings': {
      const chosen = Array.isArray(value) ? value.filter((id): id is string => typeof id === 'string') : []

      return (
        <fieldset className="field">
          <legend className="field__label">{field.label}</legend>
          {hint}
          {context.homes.length === 0 && <span className="small muted">No homes are on this site yet. Add them under Homes.</span>}
          {context.homes.map((home) => (
            <label key={home.listing_id} className="row small">
              <input
                type="checkbox"
                checked={chosen.includes(home.listing_id)}
                onChange={() => onChange(chosen.includes(home.listing_id) ? chosen.filter((id) => id !== home.listing_id) : [...chosen, home.listing_id])}
              />
              {home.title}
            </label>
          ))}
          {message}
        </fieldset>
      )
    }
  }
}

function isLink(value: unknown): value is SiteLink {
  return typeof value === 'object' && value !== null && 'kind' in value
}

const LINK_KINDS: { value: SiteLink['kind']; label: string; placeholder?: string }[] = [
  { value: 'page', label: 'A page of this site' },
  { value: 'search', label: 'The search page' },
  { value: 'email', label: 'An email address', placeholder: 'hello@example.com' },
  { value: 'phone', label: 'A phone number', placeholder: '+351 210 000 000' },
  { value: 'url', label: 'Another website', placeholder: 'https://' },
]

function LinkField({
  field,
  value,
  pages,
  error,
  onChange,
}: {
  field: BlockFieldDefinition
  value: SiteLink | null
  pages: SitePage[]
  error?: string
  onChange: (value: SiteLink | null) => void
}) {
  const id = useId()
  const link: SiteLink = value ?? { label: '', kind: 'search', target: '' }
  const kind = LINK_KINDS.find((option) => option.value === link.kind)

  return (
    <fieldset className="field link-field">
      <legend className="field__label">{field.label}</legend>
      <label className="field" htmlFor={`${id}-label`}>
        <span className="small">Button text</span>
        <input
          id={`${id}-label`}
          className="input"
          maxLength={40}
          placeholder="No button"
          value={link.label}
          onChange={(event) => onChange(event.target.value === '' && value === null ? null : { ...link, label: event.target.value })}
        />
      </label>
      {link.label !== '' && (
        <>
          <label className="field" htmlFor={`${id}-kind`}>
            <span className="small">Goes to</span>
            <select
              id={`${id}-kind`}
              className="input"
              value={link.kind}
              onChange={(event) => onChange({ ...link, kind: event.target.value as SiteLink['kind'], target: event.target.value === 'page' ? (pages[0]?.id ?? '') : '' })}
            >
              {LINK_KINDS.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </select>
          </label>
          {link.kind === 'page' && (
            <select className="input" aria-label="Which page" value={link.target} onChange={(event) => onChange({ ...link, target: event.target.value })}>
              {pages.map((page) => (
                <option key={page.id} value={page.id}>{page.title}</option>
              ))}
            </select>
          )}
          {kind?.placeholder !== undefined && (
            <input
              className="input"
              aria-label={kind.label}
              placeholder={kind.placeholder}
              value={link.target}
              onChange={(event) => onChange({ ...link, target: event.target.value })}
            />
          )}
        </>
      )}
      {error !== undefined && <span className="field__error" role="alert">{error}</span>}
    </fieldset>
  )
}

function ItemsField({
  field,
  value,
  context,
  error,
  onChange,
}: {
  field: BlockFieldDefinition
  value: Record<string, unknown>[]
  context: SettingsContext
  error?: string
  onChange: (value: Record<string, unknown>[]) => void
}) {
  const itemFields = field.item_fields ?? []
  const max = field.max_items ?? 10
  const blank = () => Object.fromEntries(itemFields.map((item) => [item.name, item.default]))

  function move(from: number, to: number) {
    if (to < 0 || to >= value.length) return
    const next = [...value]
    const [item] = next.splice(from, 1)
    next.splice(to, 0, item as Record<string, unknown>)
    onChange(next)
  }

  return (
    <fieldset className="field items-field">
      <legend className="field__label">{field.label}</legend>
      {value.map((item, index) => (
        <div key={index} className="items-field__item">
          <div className="row row--between">
            <span className="small strong">{index + 1}</span>
            <span className="row">
              <button type="button" className="btn btn--ghost btn--icon" aria-label={`Move ${field.label.toLowerCase()} ${index + 1} up`} disabled={index === 0} onClick={() => move(index, index - 1)}>
                <ArrowUp size={13} aria-hidden />
              </button>
              <button type="button" className="btn btn--ghost btn--icon" aria-label={`Move ${field.label.toLowerCase()} ${index + 1} down`} disabled={index === value.length - 1} onClick={() => move(index, index + 1)}>
                <ArrowDown size={13} aria-hidden />
              </button>
              <button type="button" className="btn btn--ghost btn--icon" aria-label={`Remove ${field.label.toLowerCase()} ${index + 1}`} onClick={() => onChange(value.filter((_, i) => i !== index))}>
                <Trash2 size={13} aria-hidden />
              </button>
            </span>
          </div>
          {itemFields.map((itemField) => (
            <FieldControl
              key={itemField.name}
              field={itemField}
              value={item[itemField.name]}
              context={context}
              onChange={(next) => onChange(value.map((row, i) => (i === index ? { ...row, [itemField.name]: next } : row)))}
            />
          ))}
        </div>
      ))}
      {value.length < max && (
        <button type="button" className="btn btn--sm" onClick={() => onChange([...value, blank()])}>
          <Plus size={13} aria-hidden /> Add
        </button>
      )}
      {error !== undefined && <span className="field__error" role="alert">{error}</span>}
    </fieldset>
  )
}
