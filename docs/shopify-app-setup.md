# Taking bookings on a client's Shopify store

A client's Shopify store can show a booking site's search bar, homes and
booking boxes, and take the guest's payment on the store's own checkout. The
money goes to the client's Shopify account. Habitat records it as money the
client holds, and their owner statement takes it off what you pay them.

You set this up once for the platform, then once for each client's store.

## 1. Create the platform's Shopify app (once)

1. Go to Shopify's **Dev Dashboard** (dev.shopify.com), signed in with your
   Shopify account, and create an app. Name it something like
   "Habitat bookings".
2. Choose **custom distribution**, not the App Store. Only the stores you
   name can install the app. Add each client's store there (its
   `something.myshopify.com` address) when you connect it.
3. Give the app these **access scopes**, and nothing else:
   - `write_draft_orders`: make and delete the one-off orders for stays.
   - `read_orders`: see when they are paid or refunded.
4. Set the **redirect URL** (Shopify calls it the allowed redirection URL) to:
   `https://<your Habitat address>/integrations/shopify/callback`
5. If Shopify asks for an **app URL**, use `https://<your Habitat address>/app`.
6. If Shopify asks for the **compliance webhooks** (customer data request,
   customer redact, shop redact), use:
   `https://<your Habitat address>/webhooks/shopify` for all three.
7. Copy the app's **Client ID** and **Client secret**.

## 2. Give Habitat the app's keys (once)

On Render, open the `habitat-api` service, then **Environment**, and add:

- `SHOPIFY_CLIENT_ID`: the Client ID
- `SHOPIFY_CLIENT_SECRET`: the Client secret

Add the same two to the worker and scheduler services. Save, and let Render
redeploy.

Until both keys are set, the site editor says what is missing and offers no
**Connect Shopify** button.

## 3. Connect a client's store

1. In Habitat, open **Websites**, then the client's site, then **Edit**,
   then **Site details**, then **Shopify checkout**.
2. Type the store's Shopify address, such as `harbour-homes.myshopify.com`.
3. Approve the connection:
   - If you have staff access to the store, press **Connect Shopify** and
     approve the app on Shopify's screen.
   - Otherwise press **Copy install link** and send the link to the client.
     Someone with admin rights on the store opens it and approves the app.
4. Shopify sends whoever approved it back to the editor, which says
   "Shopify store connected". The section then shows the store's name,
   address and currency.

Once connected:

- **Every booking on that site pays on the store's checkout.** Stripe is
  not used for that site.
- **Currency must match.** If the store sells in a different currency from
  the homes' prices, the section says so and bookings stay on Stripe.
- **The store's addresses are added for you.** Its `myshopify.com` address
  and its own domain go into the site's store addresses, so the store can
  show the site's pieces.

## 4. Put the pieces on the store

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
- **The booking reference reaches the order.** The paid order shows the
  booking reference (`habitat_checkout`) among its additional details.
- **Habitat hears about the payment.** Habitat confirms the booking when
  the order is paid.
- **Lapsed holds lose their link.** An unpaid order's link stops working
  once the hold lapses.
- **Development stores are limited** to five new orders a minute.
