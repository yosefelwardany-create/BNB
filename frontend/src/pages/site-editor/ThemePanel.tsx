import type { EditorOption, SiteContent } from '@/api/types'
import { Segmented } from '@/components/Segmented'
import { readableButton } from './contrast'

const DESCRIPTIONS: Record<string, string> = {
  coastal: 'Light and airy, rounded corners, serif headings.',
  classic: 'Elegant serif type, thin lines, square corners.',
  bold: 'Big type, strong bands of colour, wide spacing.',
}

/**
 * The site's look: one of the themes, then its colours, fonts and buttons.
 * Switching theme keeps every block and every word.
 */
export function ThemePanel({
  content,
  themes,
  fontPairs,
  buttons,
  onChange,
}: {
  content: SiteContent
  themes: EditorOption[]
  fontPairs: EditorOption[]
  buttons: EditorOption[]
  onChange: (patch: { theme?: string; style?: Partial<SiteContent['style']> }) => void
}) {
  const style = content.style

  return (
    <section className="editor-panel" aria-label="Theme">
      <div className="editor-panel__head">
        <h3>Theme</h3>
      </div>

      <div className="theme-cards" role="radiogroup" aria-label="Theme">
        {themes.map((theme) => {
          const checked = theme.key === content.theme

          return (
            <button
              key={theme.key}
              type="button"
              role="radio"
              aria-checked={checked}
              className={`theme-card theme-card--${theme.key} ${checked ? 'is-selected' : ''}`}
              onClick={() => onChange({ theme: theme.key })}
            >
              <span className="theme-card__sample" aria-hidden style={{ background: style.primary }}>
                <span className="theme-card__heading">Aa</span>
                <span className="theme-card__button" style={{ background: style.accent }} />
              </span>
              <span className="strong">{theme.label}</span>
              <span className="small muted">{DESCRIPTIONS[theme.key] ?? ''}</span>
            </button>
          )
        })}
      </div>

      <div className="grid grid--two">
        <label className="field">
          <span className="field__label">Main colour</span>
          <span className="colour-field">
            <input type="color" value={style.primary} onChange={(event) => onChange({ style: { primary: event.target.value } })} aria-label="Main colour" />
            <code>{style.primary}</code>
          </span>
        </label>
        <label className="field">
          <span className="field__label">Button colour</span>
          <span className="colour-field">
            <input type="color" value={style.accent} onChange={(event) => onChange({ style: { accent: event.target.value } })} aria-label="Button colour" />
            <code>{style.accent}</code>
          </span>
        </label>
      </div>
      {!readableButton(style.accent) && (
        <p className="notice notice--warning small" role="status">
          Text on this button colour is hard to read. Choose a darker or a brighter colour.
        </p>
      )}

      <label className="field">
        <span className="field__label">Fonts</span>
        <select className="input" value={style.fonts} onChange={(event) => onChange({ style: { fonts: event.target.value } })}>
          {fontPairs.map((pair) => (
            <option key={pair.key} value={pair.key}>{pair.label}</option>
          ))}
        </select>
      </label>

      <div className="field">
        <span className="field__label">Button shape</span>
        <Segmented
          label="Button shape"
          value={style.buttons}
          options={buttons.map((button) => ({ value: button.key, label: button.label }))}
          onChange={(value) => onChange({ style: { buttons: value } })}
        />
      </div>
    </section>
  )
}
