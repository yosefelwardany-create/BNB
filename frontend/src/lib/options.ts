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
    options: (query.data?.data ?? []).map((listing) => ({
      value: listing.id,
      label: listing.title,
    })),
    isLoading: query.isLoading,
  }
}
