# Client demo script

A 30-minute walkthrough of Habitat for a prospective client: a property owner
who would hand their short-term rentals to us to run. Times are a guide. Lines
in quotes are what you say; the rest is what you click.

## Before the call

- Sign in as the platform owner. In the sidebar's **Managing** selector, pick
  **Bogota Colombia**: ten properties across Bogotá, with stays from six weeks
  ago to a month ahead, cleans, an inbox and revenue.
- Open a second, private window signed in as that account's client login, or
  plan to use **See this account as its client does** from the account menu.
- Check that the property agent answers in the Agents chat. If no AI key is
  configured, every answer is labelled **Simulated**. Either say so up front or
  fix the key before the call.
- Pick one property to come back to throughout. The Chapinero Alto studio
  (BC-001) works well: it has a smart lock, a camera note and a full amenity list.
- Turn on dark or light theme to match the room, then close every other tab.

## 1. The pitch (2 min)

> "You own the properties. We run them: bookings, pricing, guests, cleaning,
> money. Habitat is the system we run them on. You get a portal that shows you
> exactly what's happening and what you've earned, and you never have to log
> in to five different tools."

> "One thing to know before I show you anything: if something on screen is a
> simulation, the screen says so. We never show you a fake integration as if
> it were real."

## 2. The workspace: one view of the whole portfolio (3 min)

**Dashboard**

- Point to **The week ahead**: arrivals, departures and turnovers for the next
  seven days, in the property's own timezone.
- Point to **Awaiting payment**.

> "This is what I look at every morning: who arrives, who leaves, what needs
> cleaning in between, and who still owes money."

**Account selector**

- Open the **Managing** selector in the sidebar.

> "Every client's account is fully separate. When I switch, the whole screen
> changes. Your data and another owner's never mix, and the system checks that
> on every request, not just on the screen."

- Press `Ctrl/⌘ K` to show the command palette.

## 3. Properties and channels (4 min)

**Properties**

- Open the Chapinero studio: photos, rooms, amenities, check-in method, house
  rules, cancellation policy.

> "A property can't be put on sale until it's actually ready: photos, address,
> timezone, price. If something's missing, it lists everything at once instead
> of one error at a time."

**Channels**

- Show **Connections** and **Mapped listings**.

> "We distribute through Hostex, which already has the Airbnb, Booking.com and
> Vrbo partner agreements. When you connect, we import your listings, photos,
> calendar, bookings and guest conversations automatically, and keep them
> refreshed every few minutes."

- Point to the **Simulated** chip on any non-Hostex channel.

> "Airbnb direct, Booking.com direct and the others show 'Simulated' because
> they need partner agreements we route through Hostex instead. The system
> tells you that on the connection itself."

> "We never guess which listing is which. If a match is ambiguous, a person
> confirms it, because a wrong match means a double booking."

## 4. Calendar and reservations (4 min)

**Calendar**

- Show the multi-property grid. Hover an occupied cell to show the guest name.
- Open **Block dates** and close a night or two for an owner stay.

> "Owner stays, maintenance, anything: block it here. You can't block over a
> booking; it tells you which booking is in the way."

**Reservations**

- Open an upcoming stay. Show the price broken down night by night, plus
  fees and taxes, and the payment status.

> "Every booking runs through a real availability check and a real pricing
> engine. The system takes a lock while it books, so the same night can't be
> sold twice, even under load."

- If they ask about pricing: rate plans, rules with floors and ceilings,
  per-day overrides, promotions, fees and taxes.

## 5. Guests and the inbox (3 min)

**Inbox**

- Open a conversation with a guest waiting. Point to the waiting time and the
  channel the guest wrote from.

> "Every guest conversation is in one place. When I reply, it goes back to the
> guest's own Airbnb or Booking.com inbox, wherever they wrote from."

- Show saved replies and templates.

**Guests** and **Reviews** (brief)

> "One record per guest, even across channels. Duplicates get merged, so we
> know a returning guest. Reviews from every channel are on one scale."

## 6. The property agent (6 min, the highlight)

**Agents**: select the Chapinero studio.

> "Every property has its own AI assistant. It knows that flat: its rules,
> its quirks, who the cleaner is. It answers guests and helps us run it."

**Ask it something as the manager** in the chat:

- *"How did this property do last month?"*: it answers with occupancy, ADR,
  revenue, in the right currency.
- *"The cleaner is Rosa, +57 300 123 4567, she has the spare key."*: show the
  receipt, then open the **Knowledge base** card. The fact is saved under a
  topic. Correcting it later replaces it rather than contradicting it.

**Show the safety design.** This is what wins trust:

> "Four checks stand between a guest's question and an automatic reply."

1. **Entitlement.** "The door code and wifi password are only given to the AI
   when a guest has a confirmed, paid booking inside the check-in window.
   Otherwise the AI never sees them, so no trick question can get them out."
2. **Escalation words.** "You choose topics that always go to a person, like
   neighbours or damage."
3. **Topics.** "Only four kinds of question can ever be answered
   automatically: amenities, directions, house rules, local tips. Refunds and
   cancellations always come to a person. That's fixed in the code, not a
   setting someone can switch on by mistake."
4. **Confidence.** "If it isn't sure, it waits for us."

- Show the **activity log**: every question and answer, with whether it was
  sent automatically or by a person.
- Show the **action queue**: the agent can *propose* a reply, blocking dates or
  a rate change, but a person approves. Cancelling a booking can never be
  automatic.

> "Automatic replies are off until we turn them on for a property and a
> topic. Day one, it only drafts."

Optional: **Helpers** (who to call for the boiler) and linking a Google Doc
house manual the agent reads.

## 7. Operations: cleaning and tasks (2 min)

**Operations**

- Show the board: turnover cleans created from bookings, priorities,
  checklists with photo requirements.

> "Every checkout creates a clean. If the booking moves, the clean moves with
> it. If it cancels, the clean goes away. Nobody has to remember."

## 8. Money (3 min)

**Financials**

- Show payments, expenses (**Record an expense**) and owner statements.

> "Everything goes through proper double-entry accounting. At the end of the
> month you get an owner statement that adds up night by night, as a PDF you
> can keep."

**Revenue**

- Show **On the books**, **By source**, **By property**, **Day by day**:
  occupancy, ADR, RevPAR, pace.

> "We never add up different currencies, and we never invent an exchange
> rate. If a number can't be verified, it says so instead of showing you a
> wrong total."

**Reports** (brief): scheduled reports by email, webhook or stored file, plus
CSV export.

## 9. The client's own view (3 min)

Switch to the client login, or use **See this account as its client does**.

> "This is what you see. It's read-only: you can't break anything, and nobody
> can change your data by accident from here."

- **Overview**: "Your revenue this month", by property, trading.
- **Properties**: their homes and listing details.
- **Calendar**: who's staying, and when.
- **Money**: **Revenue after commission**, **Statements**, **Payouts**.

> "Our fee is 10% of accommodation revenue. Not of cleaning fees, not of
> taxes. You see the gross, our commission and what's yours, per property."

> "If anything about a number isn't final yet, like a refund still being
> worked out or a rate missing, it's flagged rather than shown as zero."

## 10. Close (1 min)

> "So: we run everything, you see everything. Your listings come in from
> Hostex automatically, each property gets its own assistant with strict
> safety rules, cleans follow the bookings, and every month you get a
> statement that adds up."

> "Next step: connect your Hostex account. Importing is read-only, so nothing
> changes on your listings until we agree it should. Then we set up your
> client login."

## Questions to expect

| Question | Answer |
|---|---|
| Is my data separate from other owners'? | Yes. Every account is isolated, and the server refuses a request that would cross accounts. |
| Can the AI give my door code to the wrong person? | No. It's only given the code for a confirmed, paid booking inside the check-in window. |
| Will the AI cancel bookings or change prices on its own? | It can propose; a person approves. Cancelling is never automatic. |
| Does connecting change my Airbnb listings? | No. Import is read-only. Pushing rates or availability is a separate switch, off by default. |
| What does it cost? | A 10% commission on accommodation revenue. No subscriptions or plans. |
| Can I get my statements? | Yes, as PDFs in the Money section, kept with a checksum so the copy can't be disputed. |
| Payments and smart locks? | The full flow is built; the live provider connects per client. Until then the screen labels it simulated. |
| Is two-factor sign-in available? | Yes, authenticator-app codes with recovery codes. |

## Don't do this live

- Don't push rates or availability to a real Hostex connection.
- Don't send a real guest message from a live account.
- Don't enable automatic replies on a live property during the demo.
- If a screen says **Simulated**, own it ("this is the honest label") rather
  than skip past it.
