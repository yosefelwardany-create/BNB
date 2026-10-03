# Automatic Hostex property import

New Hostex connections default to **Automatically create new Hostex properties**.
After successful connection, the UI starts a read-only pull. Existing accounts can
enable the same option in Channels settings. It enables no outbound capability.

The pull discovers stable source IDs, refreshes listing metadata and prices,
creates unmapped local drafts, hydrates their native fields and photos, reads
the Hostex master availability calendar, then imports reservations, guests and
transaction snapshots against those mappings. Repeated pulls reuse the same
records. Existing mappings remain authoritative; names are never used to relink
properties. Automatic creation does not publish or activate local inventory.

Available fields populate the normal property form. Optional content, room
counts and amenities are imported only from supported, validated values.
Missing source values are listed in the editor, and unchecked amenities are
described as unknown when Hostex has not supplied a complete readable list.
Local edits survive future imports. Local documents, contacts, owners and
private operational instructions are not invented from listing data.

Timezones come from a valid supplied timezone or the property's coordinates,
using the offline, pinned geographic lookup in `tools/timezone`. Canada is not
treated as one timezone. Existing inherited organization defaults can be repaired;
explicit local choices remain overrides. Unresolved new imports use a provisional
UTC storage value, visibly require timezone review and cannot be activated until
that is resolved. Complete Canadian postal addresses can also fill city,
province, postal code and country; ambiguous addresses are left for review.

`GET /availabilities` supplies the master property calendar. This differs from
the existing per-channel listing calendar. Source-unavailable dates are shown
as booked when a known reservation occupies them, otherwise as unavailable in
Hostex; the source cannot always distinguish a manual block from an unimported
booking. These dates prevent new local sales. Existing imported stays are still
recorded. A later explicit available value can reopen a source-blocked date,
but cannot clear a local block or reservation. Omitted dates retain their last
snapshot and refresh timestamp. Private Hostex remarks are not copied.

## Sources

- [Hostex property availability](https://api-doc.hostex.io/reference/query-availabilities)
- [Hostex listings](https://api-doc.hostex.io/reference/query-listings)
- [Geographic timezone lookup](https://github.com/evansiroky/node-geo-tz)

The last source documents geographic boundary ambiguities. The application only
accepts one valid land timezone; it does not choose arbitrarily between results.
