# The bot bridge

Habitat can ask a property's own bot for a draft, and it asks over HTTP. A bot
that lives in somebody's chat window has no URL, so this is the piece in between:
it takes Habitat's request, turns it into a prompt, puts that to a chat model, and
answers in the shape Habitat expects.

One process serves every property. Each gets its own path and its own persona:

```
POST https://your-bridge.onrender.com/yellow     → answers as Yellow
POST https://your-bridge.onrender.com/den        → answers as Den
```

The personas live in `BOTS` at the top of `bridge.py`. Everything factual comes
from Habitat, per request — the bridge never holds a copy of a property, so
correcting a door code in Habitat corrects what the bot is told next time.

## Deploying it on Render

The repository already deploys Habitat there, so this is a second service from the
same repository:

```yaml
  - type: web
    name: habitat-bot-bridge
    runtime: docker
    dockerfilePath: ./tools/bot-bridge/Dockerfile
    dockerContext: ./tools/bot-bridge
    plan: starter
    healthCheckPath: /yellow     # GET answers without spending a model call
    envVars:
      - key: BRIDGE_TOKEN        # Habitat sends this; see below
        generateValue: true
      - key: MODEL_BASE_URL
        value: https://api.x.ai/v1
      - key: MODEL_API_KEY
        sync: false              # entered once, in the dashboard
      - key: MODEL_NAME
        value: grok-4
```

`BRIDGE_TOKEN` with `generateValue: true` lets Render invent it; read it out of
the dashboard afterwards and paste it into Habitat.

## Settings

| | |
|---|---|
| `BRIDGE_TOKEN` | What Habitat must send as `Authorization: Bearer …`. **Set it.** Without it, anything that finds the URL can ask your bots questions and read the property facts back out of the answers; the process says so loudly on boot. |
| `MODEL_BASE_URL` | The chat-completions API. `https://api.x.ai/v1` for xAI. |
| `MODEL_API_KEY` | That provider's key. |
| `MODEL_NAME` | The model, e.g. `grok-4`. |
| `MODEL_TEMPERATURE` | Default `0.3`. Low, because this answers factual questions from a fixed set of facts. |
| `MODEL_TIMEOUT` | Seconds, default 25. Habitat gives up at 20 by default, so there is no point waiting longer than it will. |

The model call is an OpenAI-shaped `POST {base}/chat/completions`, which is what
xAI, Groq, together.ai and a local Ollama all serve. Switching provider is those
three variables and nothing else.

**One caveat, stated rather than buried:** the exact request shape for xAI could
not be verified from the environment this was written in — the egress proxy blocks
`docs.x.ai`. It is written to the OpenAI-compatible shape xAI documents itself as
serving. If the first real call comes back 400 or 404, the single function to
adjust is `ask_model`, and the error that reaches Habitat will quote the
provider's own words.

## Checking it

`GET` any bot's path. It spends no model call:

```
$ curl https://your-bridge.onrender.com/yellow
{"ok": true, "bots": ["/blue", "/den", …], "model_configured": true, "token_required": true}
```

Then ask it something the way Habitat does:

```
$ curl -X POST https://your-bridge.onrender.com/yellow \
    -H "Authorization: Bearer $BRIDGE_TOKEN" \
    -H 'Content-Type: application/json' \
    -d '{"question":"Is there a lift?","facts":{"name":"Yellow Room","city":"Lisbon"}}'

{"reply": "…", "intent": "amenity", "confidence": 0.9}
```

## What it is careful about

**Confidence is never invented.** It is the number that decides whether a reply
can go to a guest without a person reading it. The bridge reports what the model
says about its own certainty and falls back to `0.0` — which holds the draft —
rather than to a flattering guess. An answer nobody has established to be certain
should wait for a human.

**The facts are Habitat's, not the bridge's.** Arrival details — door code, wifi
password, access notes — are in the request only where the booking is confirmed,
paid and inside its window. A question from somebody with no booking arrives here
with none of them, and the prompt says so explicitly, along with Habitat's own
instruction about what to say instead.

**The token is compared whole**, with `hmac.compare_digest`. A comparison that
stops at the first wrong character tells an attacker how much of it they have.

## What it is not

A production web server. `ThreadingHTTPServer` from the standard library is sized
for a handful of properties answering a question at a time, which is what this is
for. In front of real volume it wants gunicorn or uvicorn and a framework; the
logic would port in an afternoon, and the dependency-free single file is worth
more while the bots are being proven.
