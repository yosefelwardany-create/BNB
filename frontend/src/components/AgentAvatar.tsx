import { useState } from 'react'
import { BotAvatarGlyph } from '@/components/BotAvatarGlyph'
import { hasBotAvatar } from '@/lib/botAvatars'

/**
 * The agent's face, wherever it appears.
 *
 * Three sources, in order of how deliberate each one is:
 *
 *  1. **A face chosen from the picker.** Drawn inline, so it cannot fail to
 *     load. First because choosing one is the most recent, most explicit act.
 *  2. **A linked image**, for properties set up before the picker existed or
 *     using a real photograph. Kept working rather than migrated: somebody put
 *     that link there on purpose.
 *  3. **The bot's initial**, which is what an operator was already writing on a
 *     whiteboard.
 *
 * A linked image that fails is remembered for the life of the component, so a
 * dead URL on a list of fifty cards retries nothing and settles on the letter.
 */
export function AgentAvatar({
  avatar,
  url,
  initial,
  size = 32,
}: {
  avatar?: string | null
  url?: string | null
  initial?: string | null
  size?: number
}) {
  const [failedUrl, setFailedUrl] = useState<string | null>(null)

  if (hasBotAvatar(avatar)) {
    return (
      <BotAvatarGlyph avatar={avatar} size={size} className="property-card__agent-avatar" />
    )
  }

  if (url && failedUrl !== url) {
    return (
      <img
        className="property-card__agent-avatar"
        src={url}
        alt=""
        width={size}
        height={size}
        loading="lazy"
        referrerPolicy="no-referrer"
        onError={() => setFailedUrl(url)}
      />
    )
  }

  return (
    <span className="property-card__agent-avatar" aria-hidden="true">
      {initial ?? '?'}
    </span>
  )
}
