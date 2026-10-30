import type { BlockDefinition } from '@/api/types'
import { blockIcon } from './blockIcons'
import { EditorDialog } from './EditorDialog'

/**
 * Every kind of block, each with its icon and a sentence, to put on the page.
 */
export function BlockLibrary({
  definitions,
  onPick,
  onClose,
}: {
  definitions: BlockDefinition[]
  onPick: (type: string) => void
  onClose: () => void
}) {
  return (
    <EditorDialog title="Add a block" description="Pick one; you can change everything in it afterwards." onClose={onClose} wide>
      <div className="block-library">
        {definitions.map((definition) => {
          const Icon = blockIcon(definition.icon)

          return (
            <button
              key={definition.type}
              type="button"
              className="block-library__item"
              onClick={() => {
                onPick(definition.type)
                onClose()
              }}
            >
              <span className="block-library__icon" aria-hidden>
                <Icon size={20} />
              </span>
              <span className="block-library__text">
                <span className="strong">{definition.label}</span>
                <span className="small muted">{definition.description}</span>
              </span>
            </button>
          )
        })}
      </div>
    </EditorDialog>
  )
}
