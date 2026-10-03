# Channel message imports

Hostex conversation list rows need not contain a property or reservation ID.
The importer reads the conversation detail and resolves its activity listing IDs
against this account's exact channel/listing mappings. Unmapped or conflicting
property anchors are skipped and counted as `unmapped_threads`; names are never
used to guess a match. Inquiry conversations can exist without a reservation.

Messages are ordered by source timestamp and deduplicated by account and source
message ID. Imported host messages are recorded as already delivered elsewhere.
Thread previews, waiting times and response times are rebuilt from original
message timestamps after imports, including duplicate-only refreshes. The inbox
displays the source channel from conversation details while retaining the channel
manager connection internally for routing.
Pulls suppress the new-inbound-message event so importing history cannot trigger
automatic guest replies or downstream webhooks. Live webhook behavior remains
unchanged.

After deploying this fix, run a full pull to backfill conversations omitted by
older successful imports. Incremental refreshes alone may omit that history.
