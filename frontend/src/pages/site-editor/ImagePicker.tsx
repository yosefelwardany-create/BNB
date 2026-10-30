import { useRef, useState, type DragEvent } from 'react'
import { ImagePlus, Upload, X } from 'lucide-react'
import { ApiError } from '@/api/client'
import type { EditorState } from '@/api/types'
import { EditorDialog } from './EditorDialog'
import { pictureOf } from './photos'

type Images = EditorState['images']
type Photos = EditorState['photos']

/** What the server takes as a photo. */
const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp']

/**
 * Photos dropped on a field: uploaded one after another, each handed to
 * `onUploaded` as it is done. Any other file is refused, saying what is taken;
 * `room` says how many the field can take now.
 */
function useDropUpload(onUpload: (file: File) => Promise<string>, onUploaded: (ref: string) => void, room: () => number) {
  const [over, setOver] = useState(false)
  const [uploading, setUploading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const carriesFiles = (event: DragEvent) => Array.from(event.dataTransfer.types).includes('Files')

  async function take(files: File[]) {
    const space = room()
    if (space <= 0) {
      setError('This gallery is full.')
      return
    }

    const chosen = files.filter((file) => PHOTO_TYPES.includes(file.type))
    if (chosen.length === 0) {
      setError('Drop a JPEG, PNG or WebP photo.')
      return
    }

    setError(chosen.length > space ? `Only ${space} more ${space === 1 ? 'photo fits' : 'photos fit'}; the rest were left out.` : null)
    setUploading(true)
    try {
      for (const file of chosen.slice(0, space)) onUploaded(await onUpload(file))
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'The photo could not be uploaded.')
    } finally {
      setUploading(false)
    }
  }

  return {
    over,
    uploading,
    error,
    handlers: {
      onDragOver: (event: DragEvent) => {
        if (!carriesFiles(event)) return
        // Said yes to, or the browser opens the file instead.
        event.preventDefault()
        setOver(true)
      },
      onDragLeave: () => setOver(false),
      onDrop: (event: DragEvent) => {
        if (!carriesFiles(event)) return
        event.preventDefault()
        setOver(false)
        const files = Array.from(event.dataTransfer.files)
        if (files.length > 0) void take(files)
      },
    },
  }
}

function DropNote({ uploading, error }: { uploading: boolean; error: string | null }) {
  if (uploading) return <span className="field__hint" role="status">Uploading the photo…</span>
  if (error !== null) return <span className="field__error" role="alert">{error}</span>

  return <span className="field__hint">Or drop a photo here.</span>
}

/**
 * Choose a photo: one the site already has, one of its homes', or a new one
 * uploaded from the computer.
 */
export function PhotoLibrary({
  images,
  photos,
  onPick,
  onUpload,
  onClose,
}: {
  images: Images
  photos: Photos
  onPick: (ref: string) => void
  onUpload: (file: File) => Promise<string>
  onClose: () => void
}) {
  const input = useRef<HTMLInputElement>(null)
  const [uploading, setUploading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function upload(file: File) {
    setUploading(true)
    setError(null)

    try {
      onPick(await onUpload(file))
      onClose()
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'The photo could not be uploaded.')
    } finally {
      setUploading(false)
    }
  }

  return (
    <EditorDialog title="Choose a photo" description="JPEG, PNG or WebP, up to 8 MB." onClose={onClose} wide>
      <div className="row">
        <button type="button" className="btn btn--sm btn--primary" disabled={uploading} onClick={() => input.current?.click()}>
          <Upload size={14} aria-hidden /> {uploading ? 'Uploading…' : 'Upload a photo'}
        </button>
        <input
          ref={input}
          type="file"
          accept="image/jpeg,image/png,image/webp"
          hidden
          aria-label="Upload a photo"
          onChange={(event) => {
            const file = event.target.files?.[0]
            if (file) void upload(file)
            event.target.value = ''
          }}
        />
      </div>
      {error !== null && <p className="notice notice--error small" role="alert">{error}</p>}

      {images.length > 0 && (
        <>
          <h3 className="small strong">This site's photos</h3>
          <div className="photo-picker">
            {images.map((image) => (
              <button key={image.ref} type="button" className="photo-picker__item" onClick={() => { onPick(image.ref); onClose() }}>
                <img src={image.url} alt={image.alt ?? 'A photo of this site'} />
              </button>
            ))}
          </div>
        </>
      )}

      {photos.length > 0 && (
        <>
          <h3 className="small strong">Photos of the homes on this site</h3>
          <div className="photo-picker">
            {photos.map((photo) => (
              <button key={photo.ref} type="button" className="photo-picker__item" title={photo.listing} onClick={() => { onPick(photo.ref); onClose() }}>
                <img src={photo.url} alt={`A photo of ${photo.listing}`} />
              </button>
            ))}
          </div>
        </>
      )}

      {images.length === 0 && photos.length === 0 && <p className="small muted">No photos yet. Upload one to use it here.</p>}
    </EditorDialog>
  )
}

/** One photo field: the chosen photo, with Change and Remove. */
export function ImageField({
  id,
  label,
  value,
  images,
  photos,
  onChange,
  onUpload,
}: {
  id: string
  label: string
  value: string | null
  images: Images
  photos: Photos
  onChange: (ref: string | null) => void
  onUpload: (file: File) => Promise<string>
}) {
  const [open, setOpen] = useState(false)
  const picture = value === null ? null : pictureOf(value, images, photos)
  const dropped = useDropUpload(onUpload, onChange, () => 1)

  return (
    <div className={`field drop-field ${dropped.over ? 'is-over' : ''}`} role="group" aria-labelledby={`${id}-label`} {...dropped.handlers}>
      <span className="field__label" id={`${id}-label`}>{label}</span>
      <div className="image-field">
        {picture !== null ? (
          <img className="image-field__preview" src={picture} alt="" />
        ) : (
          <span className="image-field__empty" aria-hidden><ImagePlus size={20} /></span>
        )}
        <div className="stack stack--tight">
          <button type="button" className="btn btn--sm" onClick={() => setOpen(true)}>
            {value === null ? 'Choose a photo' : 'Change photo'}
          </button>
          {value !== null && (
            <button type="button" className="btn btn--ghost btn--sm" onClick={() => onChange(null)}>
              <X size={13} aria-hidden /> Remove
            </button>
          )}
        </div>
      </div>
      <DropNote uploading={dropped.uploading} error={dropped.error} />
      {open && <PhotoLibrary images={images} photos={photos} onPick={onChange} onUpload={onUpload} onClose={() => setOpen(false)} />}
    </div>
  )
}

/** Several photos, in order. */
export function ImagesField({
  id,
  label,
  value,
  max,
  images,
  photos,
  onChange,
  onUpload,
}: {
  id: string
  label: string
  value: string[]
  max: number
  images: Images
  photos: Photos
  /** The photos, or a change to make to them as they are when it is made. */
  onChange: (refs: string[] | ((current: string[]) => string[])) => void
  onUpload: (file: File) => Promise<string>
}) {
  const [open, setOpen] = useState(false)
  const dropped = useDropUpload(
    onUpload,
    (ref) => onChange((current) => {
      const photos = Array.isArray(current) ? current : []

      return photos.length >= max ? photos : [...photos, ref]
    }),
    () => max - value.length,
  )

  return (
    <div className={`field drop-field ${dropped.over ? 'is-over' : ''}`} role="group" aria-labelledby={`${id}-label`} {...dropped.handlers}>
      <span className="field__label" id={`${id}-label`}>{label}</span>
      <div className="photo-picker photo-picker--small">
        {value.map((ref, index) => {
          const picture = pictureOf(ref, images, photos)

          return (
            <span key={`${ref}-${index}`} className="photo-picker__item">
              {picture !== null ? <img src={picture} alt="" /> : <span className="image-field__empty" />}
              <button type="button" className="photo-picker__remove" aria-label={`Remove photo ${index + 1}`} onClick={() => onChange(value.filter((_, i) => i !== index))}>
                <X size={12} aria-hidden />
              </button>
            </span>
          )
        })}
      </div>
      {value.length < max && (
        <button type="button" className="btn btn--sm" onClick={() => setOpen(true)}>
          Add a photo
        </button>
      )}
      <span className="field__hint">{value.length} of {max} photos</span>
      {(value.length < max || dropped.error !== null) && <DropNote uploading={dropped.uploading} error={dropped.error} />}
      {open && (
        <PhotoLibrary images={images} photos={photos} onPick={(ref) => onChange([...value, ref].slice(0, max))} onUpload={onUpload} onClose={() => setOpen(false)} />
      )}
    </div>
  )
}
