import { describe, expect, it, vi } from 'vitest'
import { useState } from 'react'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { ImageField, ImagesField } from './ImagePicker'

/** A gallery that keeps its photos as the editor does, applying each change to what is there now. */
function Gallery({ initial, max = 12, onUpload }: { initial: string[]; max?: number; onUpload: (file: File) => Promise<string> }) {
  const [value, setValue] = useState(initial)

  return (
    <>
      <ImagesField
        id="gallery" label="Photos" value={value} max={max} images={[]} photos={[]}
        onChange={(next) => setValue((current) => (typeof next === 'function' ? next(current) : next))}
        onUpload={onUpload}
      />
      <output aria-label="Chosen">{value.join(',')}</output>
      <button type="button" onClick={() => setValue((current) => current.slice(1))}>Take the first out</button>
    </>
  )
}

function multiDrop(target: HTMLElement, files: File[]) {
  const dataTransfer = { files, types: ['Files'], dropEffect: 'none' }
  fireEvent.dragOver(target, { dataTransfer })
  fireEvent.drop(target, { dataTransfer })
}

const photo = (name = 'garden.jpg', type = 'image/jpeg') => new File(['x'], name, { type })

function drop(target: HTMLElement, file: File) {
  const dataTransfer = { files: [file], types: ['Files'], dropEffect: 'none' }
  const over = fireEvent.dragOver(target, { dataTransfer })
  fireEvent.drop(target, { dataTransfer })

  return over
}

describe('dropping a photo on a photo field', () => {
  it('uploads a photo dropped on the field, and chooses it', async () => {
    const onUpload = vi.fn().mockResolvedValue('img:01new')
    const onChange = vi.fn()
    render(<ImageField id="hero" label="Photo" value={null} images={[]} photos={[]} onChange={onChange} onUpload={onUpload} />)

    const field = screen.getByRole('group', { name: 'Photo' })
    // Allowed to drop at all: the browser is told so on drag over.
    expect(drop(field, photo())).toBe(false)

    await waitFor(() => expect(onChange).toHaveBeenCalledWith('img:01new'))
    expect(onUpload).toHaveBeenCalledWith(expect.objectContaining({ name: 'garden.jpg' }))
  })

  it('refuses a file that is not a photo, and says what is taken', () => {
    const onUpload = vi.fn()
    render(<ImageField id="hero" label="Photo" value={null} images={[]} photos={[]} onChange={vi.fn()} onUpload={onUpload} />)

    drop(screen.getByRole('group', { name: 'Photo' }), photo('menu.pdf', 'application/pdf'))

    expect(onUpload).not.toHaveBeenCalled()
    expect(screen.getByRole('alert')).toHaveTextContent('Drop a JPEG, PNG or WebP photo.')
  })

  it('adds a photo dropped on a gallery to the end', async () => {
    render(<Gallery initial={['img:01a']} onUpload={vi.fn().mockResolvedValue('img:01b')} />)

    drop(screen.getByRole('group', { name: 'Photos' }), photo('pool.png', 'image/png'))

    await waitFor(() => expect(screen.getByRole('status', { name: 'Chosen' })).toHaveTextContent('img:01a,img:01b'))
  })

  it('adds photos to the gallery as it is when each upload ends, every photo dropped', async () => {
    const uploads: ((ref: string) => void)[] = []
    const onUpload = vi.fn(() => new Promise<string>((resolve) => uploads.push(resolve)))
    render(<Gallery initial={['img:01a', 'img:01b']} onUpload={onUpload} />)
    const field = screen.getByRole('group', { name: 'Photos' })

    multiDrop(field, [photo('c.jpg'), photo('d.png', 'image/png')])
    await waitFor(() => expect(uploads).toHaveLength(1))

    // Changed while the photos upload: nothing put back, nothing lost.
    fireEvent.click(screen.getByRole('button', { name: 'Take the first out' }))
    uploads[0]!('img:01c')
    await waitFor(() => expect(uploads).toHaveLength(2))
    uploads[1]!('img:01d')

    await waitFor(() => expect(screen.getByRole('status', { name: 'Chosen' })).toHaveTextContent('img:01b,img:01c,img:01d'))
  })

  it('refuses a photo dropped on a full gallery', () => {
    const onUpload = vi.fn()
    render(<Gallery initial={['img:01a', 'img:01b']} max={2} onUpload={onUpload} />)

    multiDrop(screen.getByRole('group', { name: 'Photos' }), [photo()])

    expect(onUpload).not.toHaveBeenCalled()
    expect(screen.getByRole('alert')).toHaveTextContent('This gallery is full.')
  })
})
