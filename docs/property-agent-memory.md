# Property agent memory

Operator questions and updates submitted through property chat are now stored in
`agent_memories`. Each note belongs to an organization, property and author.
Guest questions and model replies are never saved as learned facts. Memory does
not modify property fields, reservations, channel settings or external systems.

Operator context retrieves recent notes plus older notes matching the current
question, within an 18,000-character budget. Notes include their timestamps and
are explicitly presented as manager statements/questions rather than verified
facts. Current imported booking/pricing data remains authoritative. Retrieval is
bounded, so the model is not promised perfect recall of every archived detail.
The same recall path feeds synchronous and deferred agents.

The chat's **Saved property memory** panel supports search, pagination and
forgetting notes. Forgetting soft-deletes the note and clears the current local
thread so it is not immediately resubmitted as browser history. Memories are
private to the author to avoid exposing one manager's financial or operational
information to another role. Existing guest-safe knowledge documents retain
their separate sharing controls. Messages from before this feature was deployed
cannot be recovered from browser-only chats.

Agent replies use safe Markdown rendering with paragraph/list spacing. Raw HTML
and remote images are not rendered. Long conversations send a bounded recent
history instead of exceeding the API's twenty-message validation limit.
