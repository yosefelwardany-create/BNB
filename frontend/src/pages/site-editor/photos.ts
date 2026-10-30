import type { EditorState } from '@/api/types'

/** A reference's picture, from the site's own photos or its homes'. */
export function pictureOf(ref: string, images: EditorState['images'], photos: EditorState['photos']): string | null {
  return images.find((image) => image.ref === ref)?.url ?? photos.find((photo) => photo.ref === ref)?.url ?? null
}
