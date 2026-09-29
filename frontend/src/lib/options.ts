import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { Listing, Paginated, Property } from '@/api/types'

/**
 * The property and listing pickers every manual-entry form needs.
 *
 * Shared rather than repeated because a form that offers a property the server
 * will refuse — archived, or belonging to somebody else — turns a piece of data
 * entry into a validation error the person cannot act on. One query, one filter,
 * one place to fix it.
 */
export interface Option {
  value: string
  label: string
}

export function usePropertyOptions(): { options: Option[]; isLoading: boolean } {
  const query = useQuery({
    queryKey: ['properties', { for: 'picker' }],
    queryFn: () => api.get<Paginated<Property>>('properties', { per_page: 100 }),
    // These barely change during a session, and every form on the screen wants
    // the same list.
    staleTime: 5 * 60 * 1000,
  })

  return {
    options: (query.data?.data ?? []).map((property) => ({
      value: property.id,
      label: property.name,
    })),
    isLoading: query.isLoading,
  }
}

export function useListingOptions(): { options: Option[]; isLoading: boolean } {
  const query = useQuery({
    queryKey: ['listings', { for: 'picker' }],
    queryFn: () => api.get<Paginated<Listing>>('listings', { per_page: 100 }),
    staleTime: 5 * 60 * 1000,
  })

  return {
    options: listingOptions(query.data?.data ?? []),
    isLoading: query.isLoading,
  }
}

/**
 * Listings as a person can actually tell apart.
 *
 * The obvious label — the listing's guest-facing title — is wrong here, and was
 * wrong in a way that made the picker unusable. That title falls back to the
 * property's name when a listing has none of its own, which is right for a guest
 * and right for a channel: the offer is the flat. But it means every listing of
 * one property carries the same title, so a property with two showed as two
 * identical entries, and picking between them was guessing. What came back was
 * that the picker had duplicates in it and things were missing — the missing ones
 * being the listings hidden behind a repeated label.
 *
 * So the label is built here instead:
 *
 *  - the property's name, because that is what somebody booking is thinking of;
 *  - the listing's own name too, but only where a property has more than one,
 *    since "Alfama Terrace — Alfama Terrace" is noise;
 *  - and a short piece of the id in the last resort, where two listings of one
 *    property have been given the same name. Ugly, and better than two options
 *    that read identically.
 *
 * A property that cannot be booked yet is marked rather than hidden. Hiding it
 * would repeat the original bug in a new form — a property the person can see in
 * their portfolio and cannot find here, with no reason given. The availability
 * engine refuses a draft, so saying so in the label is what saves the round trip.
 */
export function listingOptions(listings: Listing[]): Option[] {
  const perProperty = new Map<string, number>()

  for (const listing of listings) {
    perProperty.set(listing.property_id, (perProperty.get(listing.property_id) ?? 0) + 1)
  }

  const used = new Map<string, number>()

  const options = listings.map((listing) => {
    const property = listing.property
    // Falling back to the title keeps a label on a response that did not carry
    // the property, rather than an option labelled with nothing at all.
    const base = property?.name ?? listing.title ?? listing.name
    const shared = (perProperty.get(listing.property_id) ?? 0) > 1

    let label = shared && listing.name !== base ? `${base} · ${listing.name}` : base

    // Two listings of one property, named the same. Still has to be pickable.
    const seen = used.get(label) ?? 0
    used.set(label, seen + 1)

    if (seen > 0) {
      label = `${label} (${listing.id.slice(-6)})`
    }

    if (property !== undefined && property.status !== 'active') {
      label = `${label} — property is ${property.status}`
    }

    return { value: listing.id, label }
  })

  return options.sort((a, b) => a.label.localeCompare(b.label))
}
