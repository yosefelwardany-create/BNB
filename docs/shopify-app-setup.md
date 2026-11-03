# Taking bookings on a client's Shopify store

A client's Shopify store can show a booking site's search bar, homes and
booking boxes, and take the guest's payment on the store's own checkout. The
money goes to the client's Shopify account. Habitat records it as money the
client holds, and their owner statement takes it off what you pay them.

Each client's store connects through **its own small Shopify app**. Shopify
lets an app made for private use (custom distribution) be installed on one
store only, or on stores of one Shopify Plus organisation, so one app cannot
serve several clients. Making one takes about five minutes per client.

## 1. Make an app for the client's store

1. Go to Shopify's **Dev Dashboard** (dev.shopify.com), signed in with your
   Shopify account, and create an app. Name it after the client, such as
   "Habitat bookings, Harbour Homes".
2. Choose **custom distribution**, and enter the client's store address
   (its `something.myshopify.com` address).
3. Give the app these **access scopes**, and nothing else:
   - `write_draft_orders`: make and delete the one-off orders for stays.
   - `read_orders`: see when they are paid or refunded.
4. Set the **redirect URL** (Shopify calls it the allowed redirection URL)
   to `https://<your Habitat address>/integrations/shopify/callback`.
5. If Shopify asks for an **app URL**, use `https://<your Habitat address>/app`.
6. If Shopify asks for the **compliance webhooks** (customer data request,
   customer redact, shop redact), use `https://<your Habitat address>/webhooks/shopify`
   for all three.
7. If Shopify asks about **protected customer data**, request access to
   order data. Habitat needs it to hear about paid orders.
8. Copy the app's **Client ID** and **Client secret**.

Nothing is set on Render: each store's keys are kept in Habitat, with its
secret encrypted and never shown again.

## 2. Connect the store in Habitat

1. In Habitat, open **Websites**, then the client's site, then **Edit**,
   then **Site details**, then **Shopify checkout**.
2. Fill in the three fields:
   - the store's Shopify address, such as `harbour-homes.myshopify.com`;
   - the app's **Client ID**;
   - the app's **Client secret**.
3. Approve the connection:
   - If you have staff access to the store, press **Connect Shopify** and
     approve the app on Shopify's screen.
   - Otherwise press **Copy install link** and send the link to the client.
     Someone with admin rights on the store opens it and approves the app.

   Use Habitat's link, not one the Dev Dashboard offers: Habitat's link is
   what brings the approval back to the site.
4. Shopify sends whoever approved it back to the editor, which says
   "Shopify store connected". The section then shows the store's name,
   address and currency.

Once connected:

- **Every booking on that site pays on the store's checkout.** Stripe is
  not used for that site.
- **Currency must match.** If the store sells in a different currency from
  the homes' prices, the section says so and guests are asked to get in
  touch until they match. Stripe is not used instead, and nor is it while
  the connection is broken.
- **The store's addresses are added for you.** Its `myshopify.com` address
  and its own domain go into the site's store addresses, so the store can
  show the site's pieces.
- **The keys are remembered.** The secret need not be typed again to
  connect the same app once more, for example after **Disconnect**.

## 3. Put the pieces on the store

Under **Site details**, then **On other websites**, copy:

1. **The script.** In Shopify, go to **Online Store**, then **Themes**, then
   **Edit code**, and open `layout/theme.liquid`. Paste the script just
   before `</body>`, and save.
2. **The pieces.** Wherever a piece should appear, add a **Custom Liquid**
   section, or edit a page's HTML, and paste its tag:
   - the search bar;
   - the homes grid;
   - a booking box for one home, which suits a page about that home.

In the **Homes** tab you can give each home its own page on the store. The
store's homes grid then sends guests to that page instead of the pop-up.

## 4. Running it day to day

- **Refunds are made in Shopify.** Habitat cannot give back money the
  client's store took. Refund the order in Shopify; Habitat records the
  refund against the booking within seconds. A cancelled booking paid on
  Shopify says, under **Reservations**, how much to refund there under the
  cancellation policy, with a link to the order.
- **A payment that needs a person** (the nights were taken before it
  arrived, the booking was cancelled, or the amount was not what was asked)
  is noted on the booking, shown in the bell to everyone who can change
  bookings, and emailed to the site's contact address.
- **Owner statements** take the money the client received through Shopify
  off what they are paid, as "Already received through Shopify", once the
  stay has begun. A refund made later is added back on the next statement.
  The fixed monthly fee per home is charged on the statement, never on
  Shopify.
- **Store only.** Once the store is connected, the **Store only** switch in
  the Shopify section sends the site's own pages at Habitat's address to
  the store: a home's page goes to its page on the store when the **Homes**
  tab names one. The pieces, the pop-up and guests' booking pages keep
  working. If the connection breaks, the pages keep pointing to the store
  until the switch is turned off.

## 5. Try it

Use a free Shopify **development store**, with Shopify's test payments
(the "Bogus Gateway") turned on:

1. Search, choose a home, press **Book**, and enter the guest's details.
   You land on the store's checkout, with one line for the stay at
   Habitat's price.
2. Pay with Shopify's test card. Habitat confirms the booking within a
   few seconds, and the guest receives Habitat's confirmation email as
   well as Shopify's receipt.
3. Leave a second booking unpaid until its hold runs out (the site's hold
   time). Its link on Shopify should then stop working.

Things to confirm on that first run, which the code relies on but which
have not been tried against a real store yet:

- **No shipping step.** The checkout skips it, because the line needs no
  shipping.
- **Habitat's references reach the order.** The paid order shows
  `habitat_checkout` and `habitat_order` among its additional details.
- **Habitat hears about the payment.** Habitat confirms the booking when
  the order is paid.
- **Habitat's install link works for the app.** It installs the app made
  for the store, and Shopify sends the approval back.
- **Lapsed holds lose their link.** An unpaid order's link stops working
  once the hold lapses.
- **Development stores are limited** to five new orders a minute.
