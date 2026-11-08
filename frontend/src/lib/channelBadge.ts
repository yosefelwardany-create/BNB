import { channelLabel } from '@/lib/channelLabel'

/**
 * The letter and colour a booking's channel is marked with on the calendar:
 * where the guest made the booking, at a glance.
 */
const BADGES: Record<string, { letter: string; tone: string }> = {
  website: { letter: 'W', tone: 'website' },
  airbnb: { letter: 'A', tone: 'airbnb' },
  'booking.com': { letter: 'B', tone: 'booking' },
  expedia: { letter: 'E', tone: 'expedia' },
  vrbo: { letter: 'V', tone: 'vrbo' },
  direct: { letter: 'D', tone: 'direct' },
}

export function channelBadge(channel: string | null | undefined): { letter: string; tone: string } {
  return (channel ? BADGES[channel] : undefined) ?? { letter: 'C', tone: 'other' }
}

/**
 * A booking channel's name: ours for the channels we know, and otherwise the
 * name the channel manager gave, such as "Hotels.com".
 */
export function bookingChannelName(channel: string | null | undefined, name?: string | null): string {
  if (channel && (channel in BADGES || channel === 'channel_manager')) return channelLabel(channel)

  return name ?? channelLabel(channel)
}
