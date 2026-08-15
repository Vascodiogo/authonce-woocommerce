# AuthOnce for WooCommerce — Merchant Guide

**Plugin version:** 0.6.3
**Requires:** WooCommerce with the **Cart and Checkout blocks** (not the legacy shortcode-based cart/checkout)
**Network:** Base (currently testnet — Base Sepolia)

This guide covers everything you need to set up and run AuthOnce subscription payments through your WooCommerce store.

---

## 1. What this plugin does

AuthOnce lets your customers pay for subscriptions with USDC or EURC directly from their own crypto wallet — non-custodial, meaning your funds and your customers' funds never pass through AuthOnce or WooCommerce. The plugin:

- Adds "Pay with Crypto (USDC/EURC)" as a payment option at checkout
- Keeps your WooCommerce product price automatically in sync with the real price set on your AuthOnce merchant dashboard
- Automatically marks WooCommerce orders as **Processing** once a subscription payment is confirmed on-chain
- Shows customers a clear note on the price they're paying, including any introductory pricing

---

## 2. Before you start: create the product on AuthOnce first

**Every AuthOnce product must exist on your AuthOnce merchant dashboard *before* you link it to a WooCommerce product.** The WooCommerce side never creates or prices a subscription on its own — it only links to and displays a real AuthOnce product.

1. Log into your AuthOnce merchant dashboard.
2. Create the product (name, price, billing interval, accepted tokens, and optionally introductory pricing).
3. Note the **product slug** shown on that product — this is a short identifier like `pro-plan`, not the full pay link.

> ⚠️ **Common mistake:** the slug is **not** the same as the pay link URL. If your AuthOnce product page shows `https://authonce.io/pay/yourhandle/pro-plan`, the slug you need is just `pro-plan` — the last segment only. Pasting the full URL into the WooCommerce field below will cause a "Product not found" error for customers.

---

## 3. Setting up the matching WooCommerce product

1. In WordPress, go to **Products → Add New** (or edit an existing product).
2. Fill in the product name as normal. The **price field is not the source of truth** — see Section 4 — but set it to roughly the right value so listings look sensible before the first sync runs.
3. Scroll to the **Product data** panel, open the **General** tab.
4. Find the field labeled **AuthOnce Product Slug**.
5. Paste **only the slug** (e.g. `pro-plan`) — not the full URL.
6. Click **Update** (or **Publish** for a new product).

On save, the plugin immediately fetches the real price, interval, and token info from AuthOnce and syncs them into WooCommerce. If you see "Not yet confirmed against AuthOnce" after saving, double-check the slug and that a merchant handle is configured under your AuthOnce payment gateway settings.

---

## 4. How pricing sync works

Once a product is linked, **AuthOnce is the single source of truth for price.** This means:

- Editing the price directly in WooCommerce has no lasting effect — it will be overwritten by the real AuthOnce price the next time the product is saved, and again automatically every hour in the background.
- To change a price, change it on your **AuthOnce dashboard**, not in WooCommerce.
- The product edit screen shows a live status box confirming the real price, token, and how long ago it was last confirmed — use this to verify sync is working.
- If you've set up introductory pricing or a yearly option on AuthOnce, that's shown to shoppers automatically on the product page, cart, and checkout — no separate setup needed in WooCommerce.

---

## 5. What customers see

- **Product & shop pages:** price shown in the real crypto token (e.g. "29.00 USDC" instead of a dollar sign), with introductory pricing called out if configured.
- **Cart & Checkout:** a note appears near the order summary confirming the product is paid via AuthOnce, in which token, and any intro pricing terms.
- **Checkout payment method:** "Pay with Crypto (USDC/EURC)" — selecting it and placing the order redirects the customer to a secure AuthOnce pay page to connect their wallet and confirm.
- **Quantity:** AuthOnce subscription products are limited to **quantity 1**. A subscription has no concept of "buying 2 at once" — the checkbox to increase quantity is automatically disabled for these products.

---

## 6. Order status after payment

Once a customer completes payment, their WooCommerce order automatically updates to **Processing** — this happens on its own via a background check, usually within a few seconds, with a fallback check every 5 minutes if needed.

**"Processing" is the correct final status for a subscription order, and it is expected to stay that way.** Unlike a one-time physical or digital product, a subscription represents an ongoing relationship, not a single completed transaction — so unlike normal WooCommerce orders, you should not expect it to ever move to "Completed." Seeing "Processing" indefinitely means the subscription is active and working correctly, not stuck.

To check the actual status of recurring payments (has the last cycle been collected, is a payment overdue, etc.), check your **AuthOnce merchant dashboard**, not the WooCommerce order screen.

---

## 7. Accepted tokens

Your AuthOnce product page shows checkboxes for accepted tokens (USDC, USDT, EURC). If a token doesn't have a live deployment on the current network, its checkbox appears grayed out with an "(unavailable)" label and can't be selected — this is automatic and reflects real network availability, not a configuration mistake on your end. It updates itself if that token becomes available later.

---

## 8. Webhooks (optional)

If you want real-time notifications when a payment succeeds or fails (for example, to alert an external system or AI agent), you can configure webhook endpoints from your AuthOnce merchant dashboard under the Webhooks tab. Each webhook can be tested individually before relying on it, and failed deliveries are reported honestly rather than silently assumed successful.

---

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Customer sees "Product not found" on the pay page | The AuthOnce Product Slug field contains the full URL instead of just the slug | Edit the WooCommerce product, replace the field with only the last segment of the pay link (e.g. `pro-plan`), save |
| Price shown in WooCommerce looks wrong or outdated | Sync hasn't run yet, or the merchant handle isn't configured | Re-save the product to force an immediate sync; check the AuthOnce payment gateway settings for your merchant handle |
| Quantity selector missing on a product | Expected behavior | AuthOnce subscriptions are always quantity 1 — this isn't a bug |
| Order stuck on "Processing" forever | Expected behavior | See Section 6 — this is the correct terminal status for subscriptions |
| A token checkbox is grayed out and unselectable | That token has no live deployment on the current network | Nothing to fix — it will become available automatically once deployed |
| Cart/checkout pricing note doesn't appear | Your theme may be using the legacy shortcode cart/checkout instead of the WooCommerce Cart/Checkout blocks | Confirm your Cart and Checkout pages use the block-based editor, not `[woocommerce_cart]` / `[woocommerce_checkout]` shortcodes |

---

## 10. Known limitations (current version)

- Quantity is always 1 per AuthOnce subscription product — by design, not a bug to report.
- A PrestaShop version of this plugin is not yet available.
- Pricing sync depends on your site's outbound connection to AuthOnce's API — if your server blocks outbound HTTPS requests, sync will silently fail (check your PHP error log for `[AuthOnce]` entries if this is suspected).

---

*This guide reflects plugin version 0.6.3. If you're on an older version, some behavior described here (particularly quantity locking and token availability graying) may not yet be present — update to the latest version first.*
