/** Booking channel names are distinct from the integration transporting them. */
export function channelLabel(channel: string | null | undefined): string {
  const labels: Record<string, string> = {
    airbnb: 'Airbnb', 'booking.com': 'Booking.com', booking: 'Booking.com',
    vrbo: 'Vrbo', expedia: 'Expedia', agoda: 'Agoda', direct: 'Direct booking',
    hostex: 'Channel manager',
  }
  return channel ? labels[channel] ?? channel.replaceAll('_', ' ') : 'Booking channel'
}
