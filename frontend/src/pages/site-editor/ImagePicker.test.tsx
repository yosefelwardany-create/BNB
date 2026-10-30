import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { ImageField, ImagesField } from './ImagePicker'

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
    const onChange = vi.fn()
    render(<ImagesField id="gallery" label="Photos" value={['img:01a']} max={12} images={[]} photos={[]} onChange={onChange} onUpload={vi.fn().mockResolvedValue('img:01b')} />)

    drop(screen.getByRole('group', { name: 'Photos' }), photo('pool.png', 'image/png'))

    await waitFor(() => expect(onChange).toHaveBeenCalledWith(['img:01a', 'img:01b']))
  })
})
