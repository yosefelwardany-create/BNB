#!/usr/bin/env python3
"""
One HTTP endpoint per property, standing in front of a chat model.

Habitat can ask a property's own bot for a draft, but it asks over HTTP, and a
bot that lives in somebody's chat window has no URL. This is the missing piece:
it accepts Habitat's request, turns it into a prompt, puts that to a model, and
answers in the shape Habitat expects.

Run one process for all the properties. Each gets its own path — /yellow, /den,
/grey — and answers under a name read from that path, so adding a property means
pointing Habitat at a new path and nothing else. No list to keep in step, no
redeploy.

    PORT=8080 \\
    BRIDGE_TOKEN=a-long-random-string \\
    MODEL_BASE_URL=https://api.x.ai/v1 \\
    MODEL_API_KEY=xai-... \\
    MODEL_NAME=grok-4 \\
    python3 bridge.py

Deliberately stdlib only: nothing to install, nothing to keep up to date, and
one file to read before trusting it with a door code. It is a single-threaded
server sized for a handful of properties answering a question at a time, which
is what it is for — in front of real guest traffic it wants a real WSGI server.

## What it is careful about

**It answers in Habitat's shape, including the part that holds a draft back.**
`confidence` is what decides whether a reply can be sent without a person
reading it. This reports what the model says about its own certainty, and falls
back to 0 — never to a flattering guess — because an answer nobody has
established to be certain should wait for a human.

**It does not invent facts.** The prompt is built only from what Habitat sent.
Habitat decides what that is: arrival details are in it only where the booking
is paid and inside its window, so a question from somebody with no booking
arrives here with no door code in it. The system prompt says to answer from the
facts and to say when they do not cover the question, because a bot that fills
a gap plausibly is worse than one that admits it.

**The token is compared whole.** A prefix comparison on a bearer token leaks its
length and, with enough tries, more than that.
"""

from __future__ import annotations

import hmac
import json
import os
import sys
import threading
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

DEFAULT_PERSONA = "Warm, brief and specific. Never effusive."


def bot_for(path: str) -> dict[str, str]:
    """
    The bot serving this path.

    Any path works, and the name is read from it — `/light-green` answers as
    "Light Green". Deliberately no list to keep in step: a hard-coded one means
    editing Python, rebuilding and redeploying to add a property, which is three
    steps too many for something Habitat already knows the name of.

    A persona differs per bot and is the one thing worth configuring, through
    BOT_PERSONAS as JSON keyed by path:

        {"/den": "The Den is let by the room as well as whole.",
         "/yellow": "Mention the roof terrace where it is relevant."}

    Anything not named there gets BOT_PERSONA, or a sensible default. A persona is
    tone and local colour; it is never facts, which always come from Habitat so
    that correcting them in one place corrects them everywhere.
    """
    try:
        personas = json.loads(os.environ.get("BOT_PERSONAS", "{}"))
    except json.JSONDecodeError:
        print("WARNING: BOT_PERSONAS is not valid JSON; falling back to BOT_PERSONA.", file=sys.stderr)
        personas = {}

    return {
        "name": path.strip("/").replace("-", " ").replace("_", " ").title() or "the host",
        "persona": personas.get(path) or os.environ.get("BOT_PERSONA") or DEFAULT_PERSONA,
    }

# The intents Habitat recognises. Anything else it reads as `other`, which is
# never sent without a person — so guessing outside this list gains nothing.
INTENTS = [
    "amenity", "directions", "house_rules", "local_recommendation",
    "access", "booking_change", "payment", "complaint", "other",
]

SYSTEM = """You are {name}, answering a guest of one short-let property.

Answer ONLY from the facts given below. They are everything you are allowed to
know. If they do not cover the question, say that someone will follow up — do
not guess, and do not offer a refund, a discount, a date change or anything
else that costs money or changes a booking.

{persona}

Reply with JSON and nothing else:
{{"reply": "<what to say to the guest>",
  "intent": "<one of: {intents}>",
  "confidence": <0.0-1.0, how sure you are this answer is right and complete>}}

Be honest about confidence. Below 0.75 a person reads your draft before the
guest sees it, which is the correct outcome when you are unsure.
"""


def prompt_for(bot: dict[str, str], payload: dict) -> list[dict[str, str]]:
    """Habitat's request as messages for a chat model."""
    facts = json.dumps(payload.get("facts") or {}, indent=2, ensure_ascii=False)
    stay = payload.get("stay")
    guest = (payload.get("guest") or {}).get("name")

    context = [f"FACTS ABOUT THE PROPERTY:\n{facts}"]

    if stay:
        context.append(f"THIS GUEST'S STAY:\n{json.dumps(stay, indent=2, ensure_ascii=False)}")
    else:
        context.append("THIS GUEST HAS NO BOOKING. Share nothing that depends on one.")

    if guest:
        context.append(f"The guest is {guest}.")

    # Habitat's own instruction for this turn — which includes telling the bot
    # what it may not share, when the booking is not entitled to it.
    if payload.get("instruction"):
        context.append(f"HABITAT'S INSTRUCTION FOR THIS REPLY:\n{payload['instruction']}")

    messages = [{
        "role": "system",
        "content": SYSTEM.format(
            name=bot["name"],
            persona=bot["persona"],
            intents=", ".join(INTENTS),
        ) + "\n\n" + "\n\n".join(context),
    }]

    # Earlier turns, so a follow-up means what it says.
    for turn in payload.get("history") or []:
        messages.append({
            "role": "assistant" if turn.get("role") == "host" else "user",
            "content": str(turn.get("body", "")),
        })

    messages.append({"role": "user", "content": str(payload.get("question", ""))})

    return messages


def ask_model(messages: list[dict[str, str]]) -> str:
    """
    Put the prompt to the model and return its raw text.

    An OpenAI-shaped chat-completions call, which is what xAI, together.ai,
    Groq, a local Ollama and most others serve. Only three things ever need
    changing for a different provider: MODEL_BASE_URL, MODEL_API_KEY, MODEL_NAME.
    """
    base = os.environ.get("MODEL_BASE_URL", "").rstrip("/")
    key = os.environ.get("MODEL_API_KEY", "")

    if not base or not key:
        raise RuntimeError(
            "MODEL_BASE_URL and MODEL_API_KEY are not set, so there is no model to ask."
        )

    body = json.dumps({
        "model": os.environ.get("MODEL_NAME", "grok-4"),
        "messages": messages,
        "temperature": float(os.environ.get("MODEL_TEMPERATURE", "0.3")),
    }).encode()

    request = urllib.request.Request(
        f"{base}/chat/completions",
        data=body,
        headers={"Authorization": f"Bearer {key}", "Content-Type": "application/json"},
        method="POST",
    )

    with urllib.request.urlopen(request, timeout=float(os.environ.get("MODEL_TIMEOUT", "25"))) as response:
        answered = json.loads(response.read())

    return answered["choices"][0]["message"]["content"]


def interpret(raw: str) -> dict:
    """
    The model's text as Habitat's three fields.

    A model told to answer in JSON mostly does, and sometimes wraps it in a code
    fence or a sentence. What is never fabricated is confidence: text that
    carries none comes back as 0, so the draft waits for a person. Reporting a
    number the model did not give would be inventing the one value that decides
    whether a guest sees this unread.
    """
    text = raw.strip()

    if text.startswith("```"):
        text = text.split("```")[1]
        text = text[4:] if text.lower().startswith("json") else text
        text = text.strip()

    try:
        start, end = text.index("{"), text.rindex("}") + 1
        parsed = json.loads(text[start:end])
    except (ValueError, json.JSONDecodeError):
        return {"reply": raw.strip(), "intent": "other", "confidence": 0.0}

    reply = parsed.get("reply") or parsed.get("text") or parsed.get("message") or ""
    intent = parsed.get("intent")
    confidence = parsed.get("confidence")

    return {
        "reply": str(reply).strip() or raw.strip(),
        "intent": intent if intent in INTENTS else "other",
        "confidence": min(1.0, max(0.0, float(confidence))) if isinstance(confidence, (int, float)) else 0.0,
    }


def answer_for(bot: dict[str, str], payload: dict) -> dict:
    """
    The bot's answer, or a failure in the shape Habitat records.

    Errors come back as data rather than as an exception because both ways of
    being called need them: the synchronous caller turns them into an HTTP
    status, and the callback has nowhere to put a status, so it posts the reason
    and Habitat shows it beside the question.
    """
    try:
        return interpret(ask_model(prompt_for(bot, payload)))
    except urllib.error.HTTPError as e:
        # The model's own words. They are the most useful thing on the screen of
        # whoever is wondering why their bot went quiet.
        return {"error": f"The model answered {e.code}: {e.read()[:300].decode(errors='replace')}"}
    except Exception as e:  # noqa: BLE001 — the reason has to reach Habitat
        return {"error": f"{type(e).__name__}: {e}"}


def call_back(url: str, bot: dict[str, str], payload: dict) -> None:
    """
    Answer a question Habitat already stopped waiting for.

    The point of the slow road: the model gets as long as it needs, because
    nothing is holding a socket open. The URL is single-use and carries its own
    credential, so there is no token to configure here and nothing to retry
    against if it has already been spent.
    """
    answer = answer_for(bot, payload)

    body = json.dumps(answer).encode()
    request = urllib.request.Request(
        url,
        data=body,
        headers={"Content-Type": "application/json"},
        method="POST",
    )

    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            print(f"  -> called back {response.status} ({len(body)} bytes)", flush=True)
    except urllib.error.HTTPError as e:
        # 404 here is the ordinary ending for an answer that took too long: the
        # window closed. Worth printing, not worth retrying — Habitat has
        # already shown the operator that nothing came back in time.
        print(f"  -> callback refused {e.code}: {e.read()[:200].decode(errors='replace')}", flush=True)
    except Exception as e:  # noqa: BLE001
        print(f"  -> callback failed: {type(e).__name__}: {e}", flush=True)


class Bridge(BaseHTTPRequestHandler):
    server_version = "habitat-bot-bridge/1.1"

    def do_POST(self) -> None:  # noqa: N802 — BaseHTTPRequestHandler's naming
        path = self.path.split("?")[0].rstrip("/") or "/"
        bot = bot_for(path)

        if not self.authorised():
            return self.fail(401, "Wrong or missing bearer token.")

        try:
            length = int(self.headers.get("Content-Length") or 0)
            payload = json.loads(self.rfile.read(length) or b"{}")
        except (ValueError, json.JSONDecodeError):
            return self.fail(400, "That was not JSON.")

        if not str(payload.get("question", "")).strip():
            return self.fail(400, "No question was sent.")

        callback = (payload.get("callback") or {}).get("url")

        print(
            f"[{bot['name']}] {payload.get('question')!r}"
            f" (property {(payload.get('property') or {}).get('id')},"
            f" {len(payload.get('facts') or {})} facts,"
            f" {'with' if payload.get('stay') else 'no'} booking"
            f"{', answering later' if callback else ''})",
            flush=True,
        )

        if callback:
            # Accepted, not answered.
            #
            # Habitat is not waiting, so taking the question and returning is the
            # whole contract here. The model then gets as long as it needs —
            # minutes, if that is what the question costs — instead of racing a
            # socket timeout that throws the work away at twenty seconds.
            threading.Thread(
                target=call_back,
                args=(callback, bot, payload),
                daemon=True,
            ).start()

            return self.respond(202, {"accepted": True, "bot": bot["name"]})

        answer = answer_for(bot, payload)

        if "error" in answer:
            return self.fail(502, answer["error"])

        self.respond(200, answer)

    def do_GET(self) -> None:  # noqa: N802
        """So a deploy can be health-checked without spending a model call."""
        path = self.path.split("?")[0].rstrip("/") or "/"

        self.respond(200, {
            "ok": True,
            # Echoed back so a health check on /yellow confirms the name that
            # path will answer under, rather than only that something is up.
            "bot": bot_for(path)["name"],
            "model_configured": bool(os.environ.get("MODEL_API_KEY")),
            "token_required": bool(os.environ.get("BRIDGE_TOKEN")),
        })

    def authorised(self) -> bool:
        expected = os.environ.get("BRIDGE_TOKEN", "")

        if not expected:
            return True

        # compare_digest, not ==: a comparison that stops at the first wrong
        # character tells an attacker how much of the token they have right.
        return hmac.compare_digest(
            self.headers.get("Authorization", ""),
            f"Bearer {expected}",
        )

    def respond(self, status: int, body: dict) -> None:
        out = json.dumps(body).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(out)))
        self.end_headers()
        self.wfile.write(out)

    def fail(self, status: int, message: str) -> None:
        print(f"  -> {status} {message}", flush=True)
        self.respond(status, {"error": message})

    def log_message(self, *args: object) -> None:
        """Quiet: what matters is logged above, without the guest's words."""


if __name__ == "__main__":
    port = int(os.environ.get("PORT", "8080"))

    if not os.environ.get("BRIDGE_TOKEN"):
        print(
            "WARNING: BRIDGE_TOKEN is not set, so anything that finds this URL can "
            "ask your bots questions and read the facts in the answers.",
            file=sys.stderr,
        )

    print(
        f"bot bridge listening on :{port}. Any path serves a bot named after it "
        "— point Habitat at /yellow, /den, /grey and so on.",
        flush=True,
    )
    ThreadingHTTPServer(("0.0.0.0", port), Bridge).serve_forever()
