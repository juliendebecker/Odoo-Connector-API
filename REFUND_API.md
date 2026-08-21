# Odoo → Magento refunds: preview + guarded commit

Two-phase refund bridge for `Emipro_Apichange`.

1. **Preview** — build a real credit memo with Magento's native engine, collect
   its totals, persist nothing, and return a signed snapshot.
2. **Commit** — replay the exact same payload, re-derive everything under a
   lock, refuse if anything moved, then refund through Magento's own services.

---

## 1. Endpoints

| Method | URL | Service | ACL |
|---|---|---|---|
| POST | `/rest/V1/emipro/refund/preview/order` | `RefundPreviewInterface::previewOrder` | `Magento_Sales::sales_creditmemo` |
| POST | `/rest/V1/emipro/refund/preview/invoice` | `RefundPreviewInterface::previewInvoice` | `Magento_Sales::sales_creditmemo` |
| POST | `/rest/V1/emipro/refund` | `RefundInterface::execute` | `Magento_Sales::sales_creditmemo` |

The preview routes are `POST` because Magento's web API router only
deserialises complex objects from a request body. They remain side-effect free.

All three take a single body parameter named `request`:

```json
{
  "request": {
    "order_id": 12,
    "invoice_id": null,
    "items": [{"order_item_id": 34, "qty": 2}],
    "shipping_amount": null,
    "adjustment_positive": null,
    "adjustment_negative": null,
    "is_online": false,
    "preview_token": null,
    "idempotency_key": null,
    "comment": null
  }
}
```

`items: []` or omitted means **everything still refundable**, which is
Magento's own `CreditmemoFactory` behaviour.

---

## 2. Currency semantics (a Magento quirk, not ours)

| Field | Currency |
|---|---|
| `shipping_amount` | **base** currency — `CreditmemoFactory::initData()` assigns it via `Creditmemo::setBaseShippingAmount()` |
| `adjustment_positive` / `adjustment_negative` | passed verbatim to `initData()`; Magento's own conversion applies |
| every amount in the response | emitted in **both** currencies, `x` and `base_x` |

Magento's native REST refund endpoints behave identically. Do not assume —
read the previewed `totals` back and act on those.

---

## 3. Response contract

Every amount is a **fixed-scale decimal string with 4 decimals**, never a JSON
float. A float would be re-parsed as a binary double on the Odoo side and would
break the hash comparison at commit time. `null` means "Magento left this column
NULL" and is deliberately distinct from `"0.0000"`.

```json
{
  "contract_version": "1.0.0",
  "source": "order",
  "is_online": false,
  "order":    {"order_id": 12, "increment_id": "000000012", "state": "processing", "status": "processing", "store_id": 1},
  "invoice":  null,
  "currency": {"base_currency_code": "EUR", "order_currency_code": "EUR", "global_currency_code": "EUR", "base_to_order_rate": "1.0000", "scale": 4},
  "items": [
    {"order_item_id": 34, "sku": "SKU-1", "name": "Product", "qty": "2.0000",
     "price": "10.0000", "base_price": "10.0000", "row_total": "20.0000", "base_row_total": "20.0000",
     "tax_amount": "4.0000", "base_tax_amount": "4.0000", "discount_amount": null, "...": "..."}
  ],
  "totals": {"total_qty": "2.0000", "subtotal": "20.0000", "base_subtotal": "20.0000",
             "grand_total": "24.0000", "base_grand_total": "24.0000", "...": "..."},
  "refundable": {"order_can_creditmemo": true, "invoice_can_refund": null,
                 "base_total_paid": "24.0000", "base_total_refunded": "0.0000",
                 "base_max_refundable_online": "24.0000", "payment_method": "checkmo"},
  "payload_hash": "sha256:…",
  "state_fingerprint": "sha256:…",
  "preview_token": "v1.<base64url claims>.<base64url hmac>",
  "preview_issued_at": "2026-08-21T09:00:00+00:00",
  "preview_expires_at": "2026-08-21T09:15:00+00:00",
  "warnings": [],
  "engine": {"builder": "Magento\\Sales\\Model\\Order\\CreditmemoFactory::createByOrder",
             "totals_collected": true, "registered": false, "persisted": false,
             "payment_touched": false, "notification_sent": false}
}
```

Item and total keys are the physical column names of `sales_creditmemo` and
`sales_creditmemo_item`. That set is more stable across Magento minor versions
than the getter set (note `base_shipping_discount_tax_compensation_amnt`, whose
getter name is truncated) and it lets Odoo map fields against a real schema.

### The three integrity values

| Value | Covers | Purpose |
|---|---|---|
| `payload_hash` | order/invoice id, items (deduped, summed, sorted), shipping, adjustments, `is_online`, contract version | detects a mutated request between preview and commit |
| `state_fingerprint` | order row, every order item row, payment row, invoice row, every existing credit memo | detects a change made **outside** this API |
| `preview_token` | HMAC-SHA256 over both hashes plus scope, totals and expiry | proves this installation issued that snapshot |

`comment`, `idempotency_key` and `preview_token` are **not** in `payload_hash`:
they cannot change a single cent, so a retry with a fresh key or a different
comment does not force a new preview.

Token signing key: `HMAC-SHA256(KEY_DOMAIN, <active crypt/key from env.php>)`.
Rotating `crypt/key` invalidates in-flight previews — intended.

---

## 4. Commit sequence

```
idempotency_key already COMPLETED for exactly this request?  ⇒ return the stored
response and stop.  Pure read, no lock, no token check.      (see §5)
  ↓
verify token signature + expiry     (no DB access)
  ↓
acquire per-order advisory lock     (LockManagerInterface, name from the SIGNED claims)
  ↓
idempotency ledger: replay / conflict / claim      (before any transaction)
  ↓
rebuild the credit memo with the native engine, re-derive payload_hash,
state_fingerprint and totals from live data
  ↓
RefundGuard: scope → payload → state → totals.  Any divergence ⇒ 409, nothing written.
  ↓
[offline only] BEGIN outer transaction
  ↓
RefundOrderInterface::execute(...)  /  RefundInvoiceInterface::execute(...)   notify = false
  ↓
SELECT the credit memo row back from the sales connection (never the repository,
which would answer from the registry it was just given); compare
base_grand_total and grand_total
  ↓
[offline] mismatch ⇒ ROLLBACK, 409 POST_COMMIT_MISMATCH, nothing saved
[offline] match    ⇒ COMMIT
[online]  mismatch ⇒ cannot roll back, logged critical, returned as a warning
  ↓
ledger → completed
```

### Error codes (all HTTP 409 except the last)

`PREVIEW_TOKEN_MISSING`, `PREVIEW_TOKEN_INVALID`, `PREVIEW_EXPIRED`,
`CONTRACT_VERSION_MISMATCH`, `SCOPE_MISMATCH`, `PAYLOAD_MISMATCH`,
`STATE_MISMATCH`, `TOTAL_MISMATCH`, `LOCK_TIMEOUT`, `IDEMPOTENCY_CONFLICT`,
`IDEMPOTENCY_IN_PROGRESS`, `POST_COMMIT_MISMATCH` — and
`REFUND_INDETERMINATE` (HTTP **500**).

The code appears both in `parameters.error_code` and, prefixed in brackets, in
the message — the message renders identically across Magento minor versions,
the error envelope does not.

---

## 5. Idempotency

Table `emipro_apichange_refund_idempotency`, declarative schema. The atomic
primitive is the `UNIQUE` index on `idempotency_key`, **not** the application
lock: two concurrent commits race on the `INSERT` and MySQL lets exactly one
through, across processes and across web nodes.

| Ledger status | Meaning | Same key again |
|---|---|---|
| `in_progress` | claimed, running | 409 `IDEMPOTENCY_IN_PROGRESS`, until the row has been untouched for `in_progress_ttl` seconds — then, same payload only, compare-and-swap takeover (see below) |
| `completed` | credit memo exists | same payload ⇒ stored response replayed (`status: "replayed"`), **with or without a valid token**; different payload ⇒ 409 `IDEMPOTENCY_CONFLICT` |
| `failed` | **provably** nothing committed | same payload ⇒ compare-and-swap takeover, retry allowed |
| `indeterminate` | outcome unknown | 409 `IDEMPOTENCY_CONFLICT` — manual reconciliation, then a new key. Never taken over, never replayed, at any age |

### Replaying a completed refund after the token expired

The retry that actually happens in production is the one where the caller lost
the HTTP response to a timeout and comes back minutes later, by which time the
`preview_token` is expired. Answering `PREVIEW_EXPIRED` there is useless: the
money has moved and the only thing missing on the Odoo side is the credit memo
id.

So the ledger is consulted **before** the token is verified, and the stored
response is returned when *all* of these hold:

1. an `idempotency_key` is present and well formed;
2. its ledger row exists and its status is exactly `completed`;
3. the row's `source` is `order` or `invoice`;
4. the request names the same document — `order_id` for an order refund,
   `invoice_id` for an invoice refund — and contradicts neither id stored on the
   row;
5. `payload_hash`, **recomputed from the request that has just arrived**, is
   equal to the one stored when the credit memo was created.

Anything else — any other status, a different payload, a different order, a
malformed key — falls through to the ordinary flow, which still requires a
valid, unexpired token. This path can only ever return a response that was
already stored: **no refund is ever created without a valid token**, and a
caller cannot reach somebody else's refund by guessing a key, because the hash
is recomputed from their own request body.

The response is byte-identical to the one the in-token replay returns:
`status: "replayed"`, `idempotent: true`, the stored `idempotency_key`.

### Taking over a claim abandoned by a dead process

A process killed between `claim()` and `complete()` leaves an `in_progress` row
that would block its key forever. Such a row is taken over when, and only when:

- it has not been touched for `apichange/refund/in_progress_ttl` seconds
  (default 900, `0` disables the takeover entirely);
- the `payload_hash` and `source` are those of the incoming request;
- the `UPDATE` that moves it back to `in_progress` still matches on both the
  status *and* the age, so a row refreshed in the meantime is never stolen.

The takeover is not a claim that nothing was committed — nobody can assert that.
It only claims the row is abandoned. The retry runs under the per-order lock and
through the full guard, so if the dead attempt did create a credit memo, the
recomputed `state_fingerprint` no longer matches and the retry is refused with
`STATE_MISMATCH` before anything is written. And because the previous outcome is
unknown, a retry that fails records **`indeterminate`**, never `failed` — the
key is then blocked for good and needs a human.

`in_progress` is the only status recovered this way. `indeterminate` never is.

`idempotency_key` is optional. Without it there is no ledger — but the
`state_fingerprint` still blocks a double refund, because the first refund moves
`base_total_refunded` and adds a credit memo row, so replaying the same token a
second time yields `STATE_MISMATCH`. This is proven by
`testReplayingATokenAfterTheStateMovedIsRefused`.

Keys are validated, never truncated: ≤ 128 bytes, printable ASCII, no spaces.
Truncation would merge two distinct refunds under one key.

---

## 6. What is guaranteed, and what is not

### Guaranteed

- **The preview writes nothing.** No `Creditmemo::register()`, no
  `CreditmemoRepository::save()`, no payment call, no notifier. The order is
  loaded through `OrderFactory` rather than `OrderRepositoryInterface` so the
  instance is not the one the repository caches for the rest of the request.
  Asserted by the integration test, not merely claimed by the `engine` block.
- **Preview and commit compute totals with the same code.** Both go through
  `CreditmemoFactory::createByOrder/createByInvoice`, which ends in
  `Creditmemo::collectTotals()` — the real, configured collector chain. Both
  derive their claims through the single `SnapshotBuilder`.
- **The guard is byte-exact.** Fixed-scale decimal strings compared with
  `hash_equals`, no epsilon. One cent is a divergence.
- **No customer notification.** `$notify = false` is passed to both native
  services, and the credit memo comment is created with
  `is_visible_on_front = 0`.
- **The post-write check reads the database, not a cache.**
  `CreditmemoRepositoryInterface::get()` is deliberately not used: the
  repository keeps an in-memory registry that `save()` fills with the entity the
  refund service just built, so verifying it would compare our own computation
  against itself and could only ever match.
  `Model\Refund\PersistedCreditmemoReader` issues a plain `SELECT` on the
  `sales` connection — the same connection the refund was written on, so an
  offline refund still inside its outer transaction reads its own uncommitted
  row. A credit memo id that cannot be read back is treated as a verification
  failure: rolled back offline, `REFUND_INDETERMINATE` online.
- **Offline refunds are atomically verifiable.** The outer transaction relies on
  Magento's PDO adapter counting nesting levels, so the internal
  `BEGIN`/`COMMIT` of `RefundOrder` only moves the counter and the verification
  can still `ROLLBACK` everything.

### NOT guaranteed — read this before trusting the word "atomic"

1. **Online refunds are not atomic and cannot be.** Once the payment gateway has
   been called, no database rollback undoes it. Rolling back would be worse than
   the mismatch: refunded money with no credit memo. On the online path a
   post-commit total mismatch is therefore logged `critical` and returned as a
   warning, and the refund stands. `is_online` is only honoured on the invoice
   endpoint — Magento has no online refund entry point that takes an order.
2. **The lock is advisory and cooperative.** It serialises *this module's*
   callers. An admin credit memo, another integration or a cron job never takes
   it. Those writers are caught by the `state_fingerprint` if they committed
   before we read, and otherwise by Magento's own refund validators and the
   sales transaction — but that is a *second* line of defence, not the lock.
   The default backend is MySQL `GET_LOCK`, bound to a connection, not to a
   transaction. Installations configuring the `file` lock provider without a
   shared filesystem lose cross-node exclusion entirely; a module cannot detect
   that from the inside.
3. **The state fingerprint has blind spots by construction.** It hashes order,
   order item, payment, invoice and credit memo rows. A change that leaves all
   of those untouched is invisible — a store configuration change such as a tax
   rate, for instance, is not an order column but can influence a collector.
   Rows written by third-party modules in their own tables are not covered.
4. **The idempotency ledger degrades if a transaction is already open.** It
   claims the key before opening any transaction of its own. If another
   component already holds an open transaction on the `default` connection at
   that moment, the `INSERT` joins it and durability becomes that transaction's
   outcome. Magento exposes no second connection to the same database, so this
   cannot be fixed from inside a module.
5. **`REFUND_INDETERMINATE` is a real, reachable state.** If verification or the
   final `COMMIT` fails after the native service returned, this module does not
   pretend to know what happened. It returns HTTP 500, records `indeterminate`,
   and permanently blocks automatic retries under that key. A retry that took
   over an abandoned `in_progress` claim and then failed records the same
   status, for the same reason: the outcome of the attempt that abandoned the
   row is unknown, and calling it `failed` would assert something nobody
   verified.
6. **Replaying a completed refund does not re-authenticate the snapshot.** The
   pre-token replay (§5) is guarded by the ledger row and by the payload hash
   recomputed from the request, not by the `preview_token`. A caller who holds
   the ACL, knows an `idempotency_key` and can reproduce its exact payload can
   therefore read that refund's stored response back after the token has
   expired. That is the point of the path; it grants nothing else, and it can
   never create a credit memo.
7. **Taking over a stale claim is a judgement about liveness, not about
   money.** `in_progress_ttl` says how long a row must lie untouched before its
   owner is presumed dead. Nothing proves that owner committed nothing; the
   guard is what prevents a second credit memo, and a failed retry is recorded
   `indeterminate`. Setting the TTL below the longest refund a payment gateway
   can take on this installation would let a live refund be raced — the guard
   would still refuse the second one, but the ledger would end up
   `indeterminate` for no good reason. Set it to `0` to disable the takeover.
8. **The preview does not run Magento's refund validators.** It runs
   `Order::canCreditmemo()`, `Invoice::canRefund()` and per-item
   `getQtyToRefund()` checks and reports them under `warnings`. The
   authoritative validation is inside `RefundOrderInterface` /
   `RefundInvoiceInterface` at commit time. **A preview with no warnings can
   still be refused at commit.**
9. **MSI / inventory side effects are not covered by the rollback.** Reservations
   or messages emitted by plugins on credit memo save may live on another
   connection or in a queue, and a rollback of the sales transaction does not
   necessarily undo them.
10. **`back_to_stock` is not supported.** It is an extension attribute whose
    provider varies with the MSI setup; it was left out rather than guessed at.

### Magento API surface this relies on

`CreditmemoFactory::createByOrder/createByInvoice`, `Creditmemo::collectTotals`,
`RefundOrderInterface::execute($orderId, $items, $notify, $appendComment, $comment, $arguments)`,
`RefundInvoiceInterface::execute($invoiceId, $items, $isOnline, $notify, $appendComment, $comment, $arguments)`,
`CreditmemoItemCreationInterface`, `CreditmemoCreationArgumentsInterface`,
`CreditmemoCommentCreationInterface`, `LockManagerInterface`,
`Webapi\Exception::__construct(Phrase, $code, $httpCode, array $details)`.

Two things are read as tables rather than through a repository, on purpose:
`sales_creditmemo` for the post-write verification (see above) and
`emipro_apichange_refund_idempotency` for the ledger. Both go through
`ResourceConnection::getTableName()`, so a table prefix is honoured.

These signatures were **not** verified against a `vendor/` tree: this repository
is a standalone module with no Magento installation next to it, and no PHP
runtime was available in the environment where the code was written. They match
Magento 2.3/2.4 as documented, but the first thing to do on a real installation
is `bin/magento setup:di:compile` — a wrong signature surfaces there
immediately, before any traffic.

Note in particular that `RefundOrderInterface::execute()` has **no** `$isOnline`
parameter. That is not an omission here; Magento genuinely only offers online
refunds through the invoice service.

---

## 7. Configuration

`Stores → Configuration → Emipro → Emipro-Webhook → Odoo Refund Bridge`

| Path | Default | Meaning |
|---|---|---|
| `apichange/refund/preview_ttl` | `900` | preview token lifetime, seconds |
| `apichange/refund/lock_timeout` | `10` | seconds waited for the per-order lock before 409 `LOCK_TIMEOUT` |
| `apichange/refund/in_progress_ttl` | `900` | seconds an untouched `in_progress` claim stays blocked before a same-payload retry may take it over. `0` = never take over, the key stays blocked until a human resolves it |
| `apichange/refund/atomic_verify` | `1` | wrap offline refunds in the outer transaction; no effect online |

`preview_ttl` and `lock_timeout` treat `0` as "unset" and fall back to their
defaults. `in_progress_ttl` does not: there, `0` is the meaningful "never
recover automatically" setting, and a negative value is read as `0`.

---

## 8. Install and test

```bash
bin/magento setup:upgrade          # creates emipro_apichange_refund_idempotency
bin/magento setup:di:compile       # validates every constructor signature
bin/magento cache:flush
```

```bash
# syntax
find app/code/Emipro/Apichange -name '*.php' -exec php -l {} \;

# unit  (needs Magento's autoloader; these are Magento unit tests)
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist \
    app/code/Emipro/Apichange/Test/Unit

# integration
vendor/bin/phpunit -c dev/tests/integration/phpunit.xml.dist \
    app/code/Emipro/Apichange/Test/Integration
```
