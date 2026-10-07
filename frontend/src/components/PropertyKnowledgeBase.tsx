import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { NotebookPen, Pencil, Trash2 } from "lucide-react";
import { api, ApiError } from "@/api/client";
import type { PropertyKnowledgeEntry } from "@/api/types";

/**
 * The property's own knowledge base.
 *
 * Facts the team keeps in Habitat itself — who cleans, who fixes things, how
 * the place runs. The agent adds to it and corrects it when a manager tells it
 * something in the chat, and every colleague's chat reads it. One entry per
 * topic, so a correction replaces the old fact rather than sitting beside it.
 * Staff only: none of it reaches a guest.
 */
export function PropertyKnowledgeBase({
    propertyId,
    mayEdit,
}: {
    propertyId: string;
    mayEdit: boolean;
}) {
    const queryClient = useQueryClient();
    // An entry's id while it is edited, or 'new' while one is added.
    const [editing, setEditing] = useState<string | null>(null);

    const entries = useQuery({
        queryKey: ["property-knowledge", propertyId],
        queryFn: () =>
            api.get<{ data: PropertyKnowledgeEntry[] }>(
                `properties/${propertyId}/agent/knowledge`,
            ),
    });

    const invalidate = () =>
        void queryClient.invalidateQueries({
            queryKey: ["property-knowledge", propertyId],
        });

    const remove = useMutation({
        mutationFn: (id: string) =>
            api.delete(`properties/${propertyId}/agent/knowledge/${id}`),
        onSuccess: invalidate,
    });

    const rows = entries.data?.data ?? [];

    return (
        <section className="card mb-3">
            <header className="card__header row row--between">
                <h2>
                    <NotebookPen size={16} aria-hidden /> Knowledge base
                </h2>
                {mayEdit && editing === null && (
                    <button
                        type="button"
                        className="btn btn--sm"
                        onClick={() => setEditing("new")}
                    >
                        Add an entry
                    </button>
                )}
            </header>

            <div className="card__body stack">
                <p className="small muted">
                    What the team knows about this property. Tell the agent in
                    the chat — “the cleaner is Irish, 416 576 4750” — and it
                    saves it here; every manager’s chat can then use it. Never
                    shown to guests.
                </p>

                {rows.length === 0 &&
                    editing !== "new" &&
                    !entries.isPending && (
                        <p className="small faint">Nothing saved yet.</p>
                    )}

                {rows.map((entry) =>
                    editing === entry.id ? (
                        <EntryForm
                            key={entry.id}
                            propertyId={propertyId}
                            entry={entry}
                            onDone={() => {
                                setEditing(null);
                                invalidate();
                            }}
                            onCancel={() => setEditing(null)}
                        />
                    ) : (
                        <div
                            key={entry.id}
                            className="row row--between bordered p-2"
                        >
                            <span className="stack stack--tight">
                                <strong>{entry.topic}</strong>
                                <span className="knowledge-entry__content">
                                    {entry.content}
                                </span>
                                <span className="small faint">
                                    {entry.source === "agent"
                                        ? `Saved by the agent${entry.updated_by !== null ? ` for ${entry.updated_by}` : ""}`
                                        : `Written by ${entry.updated_by ?? "a colleague"}`}
                                    {entry.updated_at !== null &&
                                        ` · ${new Date(entry.updated_at).toLocaleDateString()}`}
                                </span>
                            </span>

                            {mayEdit && editing === null && (
                                <span className="row">
                                    <button
                                        type="button"
                                        className="btn btn--sm btn--ghost"
                                        aria-label={`Edit ${entry.topic}`}
                                        onClick={() => setEditing(entry.id)}
                                    >
                                        <Pencil size={14} aria-hidden />
                                    </button>
                                    <button
                                        type="button"
                                        className="btn btn--sm btn--ghost"
                                        aria-label={`Delete ${entry.topic}`}
                                        disabled={remove.isPending}
                                        onClick={() => remove.mutate(entry.id)}
                                    >
                                        <Trash2 size={14} aria-hidden />
                                    </button>
                                </span>
                            )}
                        </div>
                    ),
                )}

                {remove.error !== null && (
                    <p className="field__error small" role="alert">
                        {remove.error instanceof ApiError
                            ? remove.error.message
                            : "That could not be deleted."}
                    </p>
                )}

                {editing === "new" && (
                    <EntryForm
                        propertyId={propertyId}
                        onDone={() => {
                            setEditing(null);
                            invalidate();
                        }}
                        onCancel={() => setEditing(null)}
                    />
                )}
            </div>
        </section>
    );
}

function EntryForm({
    propertyId,
    entry,
    onDone,
    onCancel,
}: {
    propertyId: string;
    entry?: PropertyKnowledgeEntry;
    onDone: () => void;
    onCancel: () => void;
}) {
    const [topic, setTopic] = useState(entry?.topic ?? "");
    const [content, setContent] = useState(entry?.content ?? "");
    const id = entry?.id ?? "new";

    const save = useMutation({
        mutationFn: () =>
            entry === undefined
                ? api.post(`properties/${propertyId}/agent/knowledge`, {
                      topic,
                      content,
                  })
                : api.patch(
                      `properties/${propertyId}/agent/knowledge/${entry.id}`,
                      { topic, content },
                  ),
        onSuccess: onDone,
    });

    return (
        <form
            className="stack bordered p-2"
            onSubmit={(event) => {
                event.preventDefault();
                save.mutate();
            }}
        >
            {save.error !== null && (
                <p className="field__error small" role="alert">
                    {save.error instanceof ApiError
                        ? save.error.message
                        : "That could not be saved."}
                </p>
            )}

            <div className="field">
                <label
                    className="field__label"
                    htmlFor={`knowledge-topic-${id}`}
                >
                    Topic
                </label>
                <input
                    id={`knowledge-topic-${id}`}
                    type="text"
                    maxLength={120}
                    placeholder="Cleaner"
                    value={topic}
                    onChange={(event) => setTopic(event.target.value)}
                />
            </div>

            <div className="field">
                <label
                    className="field__label"
                    htmlFor={`knowledge-content-${id}`}
                >
                    What to know
                </label>
                <textarea
                    id={`knowledge-content-${id}`}
                    rows={3}
                    maxLength={2000}
                    value={content}
                    onChange={(event) => setContent(event.target.value)}
                />
            </div>

            <div className="row row--between">
                <button
                    type="button"
                    className="btn btn--sm btn--ghost"
                    onClick={onCancel}
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    className="btn btn--sm btn--primary"
                    disabled={
                        save.isPending ||
                        topic.trim() === "" ||
                        content.trim() === ""
                    }
                >
                    {save.isPending ? "Saving…" : "Save"}
                </button>
            </div>
        </form>
    );
}
