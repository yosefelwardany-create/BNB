import { useMemo, useState } from 'react'

/**
 * Open/close state for a dialog that both creates and edits.
 *
 * `editing` carries the record being changed, or null when creating, which is
 * the only difference between the two modes as far as a caller is concerned.
 */
export function useRecordDialog<T>() {
  const [state, setState] = useState<{ open: boolean; editing: T | null }>({
    open: false,
    editing: null,
  })

  return useMemo(
    () => ({
      isOpen: state.open,
      editing: state.editing,
      create: () => setState({ open: true, editing: null }),
      edit: (record: T) => setState({ open: true, editing: record }),
      close: () => setState({ open: false, editing: null }),
    }),
    [state],
  )
}
