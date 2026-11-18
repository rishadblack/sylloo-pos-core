# Changelog

All notable changes to `rishadblack/sylloo-pos-core` are documented in this file.

## 1.1.0 - 2026-11-18

- **"Use another code" is the full release** (base: This device, 2026-11-18): `TillScreens` no longer says `released`
  itself on "Use another code"; the base's release does it after the sales were sent and the till was wiped
  (`TerminalReleaseSteps`).

Gap #1 steps 1–3 (`../sylloo-erp-docs/pos/android-on-core.md`).

- **Payment reference on a desk sale:** `SaleDesk::complete()` takes `$ref` (transaction ID or slip number, trimmed to
  100 characters) and `$cardLast4` (kept only when it is four digits and the method is a card); before, both were always
  empty. New `PaymentMethod::isCard()` (kind `card`, or a name with "card" from an ERP that sends no kind). The Android
  till's Pay asks for both, as the PC till does.

- **Barcode without gd:** `milon/barcode` leaves `composer.json` (it needs `ext-gd`, which the Android till's PHP lacks).
  Receipts print Code 128 only: `Support\Barcode\Code128Svg` (moved from the Android till, now with code set C for
  runs of 4+ digits; a character outside the code sets gives no barcode). New contract `Contracts\ReceiptBarcode`,
  default `Defaults\Code128ReceiptBarcode` (any other `receipt_barcode_type` is drawn as Code 128);
  `Receipts\ReceiptOrderData` calls it. A till that still loads milon's service provider keeps working (it is unused).
- **Queue names:** config `queues` (`push`, `pull`, `images`, `default`; defaults unchanged). `Support\TillQueues`;
  every job reads its queue there, and `TillScreens`, `ErpOrderedReset`, `TerminalReleaseSteps` and `LocalDataReset`
  start and stop the workers it names. A till with one worker maps them all to `default`.
- **Reset:** config `reset.kept_tables` (default `['migrations']`, `migrations` always kept);
  `LocalDataReset::keptTables()`.
- **Drawer:** new contract `Contracts\DrawerKick` (`kick(array $bytes, ?string $printer): bool`). The PowerShell RAW
  print moved unchanged to `Defaults\WindowsRawDrawerKick`, the default; `Services\CashDrawer::open()` hands the kick
  to it.
- `Casts\TolerantEncrypted` encrypts with `Model::currentEncrypter()` (a till's `Model::encryptUsing()`, else `Crypt`).
- `ReceiptPrintService::printableLogo()` reads the logo type from the file extension (png, jpg, jpeg, gif, webp, svg),
  not fileinfo; another type prints no logo. `sampleOrder()` takes the cashier (the signed-in user while a till passes
  none, as the PC till's Settings screen still does).
- **First desk:** `Desks\SaleDesk` and `Desks\SaleRefused` (moved from the Android till's T0): plain values in and
  out. The write is the new action `Actions\CompleteSale`: one transaction with the order, lines, payments,
  `SaleChain::seal()` and `PushErpJob`; `created_by` = the cashier given, `shift_id` = that cashier's open shift.
  `Models\Product::findByScannedBarcode()` (barcode before product code). Sentences `till.*` (en, bn). The PC till's
  `Terminal` does not use the desk yet.
- **Shift, customer, orders and Baki desks** (Android till, 2026-11-18), plain values in, a sentence out:
  `Desks\ShiftDesk` (open with a float, cash in / out with a reason and a category, the drawer right for cash out, the
  live close with the cash handover, `closedSentence()`, `saleProblem()` "Open your shift before selling."),
  `Desks\CustomerDesk` (find by mobile or name, recent, quick add with name and mobile, the dues book),
  `Desks\OrdersDesk` (today's orders with All / Not sent / Baki / Held), `Desks\ShiftRefused`,
  `Desks\CustomerRefused`. `SaleDesk::forContact()`, `contactId()`, `isWalkIn()`, `bakiFigures()`, `bakiProblem()`,
  `completeOnBaki()` (part paid in cash, the rest owed until the due date; `SaleDueDate` fills it; over the credit
  limit only with the `credit_limit` right, audited). `CompleteSale` stores `due_date` when something is owed.
  `StaffDirectory::cashierMay()` and `AuditTrail::record()` take the cashier / user id instead of the session's.
  New sentences in `till.*` and `shift.*` (en, bn). No table, column or push/pull change.
- `Services\SaleDueDate`: a sale left partly or wholly due gets its due date by itself: the customer's credit term in
  days, else 30 days (owner decision 2026-11-18). Not used by the PC till yet (it asks the cashier for a date).
- `Jobs\PullStart` keeps the unique id it was queued with (`queuedUniqueId`). `handle()` fills in the sync dates and
  the pricing models, so the id worked out again after the run missed the unique lock, which then held for 600 s: every
  pull asked for meanwhile (the sign-in's pull, Sync now, the heartbeat) was dropped. A first pull before sign-in ("no
  cashier… the pull waits") so blocked the sign-in's pull on a new till. Test `PullStartUniqueLockTest`.
- Cash handover to the main office at the close of a shift (owner "Yes, add it", 2026-11-18;
  `../sylloo-erp-docs/erp/cash-handover.md` §4). New migration `2026_11_18_150000_add_cash_handover_to_shifts`
  (`shifts.handover_amount`, `shifts.handover_account`). `ShiftRegister::close()` takes the handover as a fourth
  argument and refuses one that is negative or more than the counted cash; `handoverProblem()`, `closingFloat()` and
  `previousShiftNotice()` give every till's Close shift screen the same check, float and office answer.
  `Shift::hasHandover()`, `Shift::closingFloat()`. Texts `shift.*` (en, bn). The Z report prints the handover and the
  closing float.
- **Push contract change:** `Jobs\RegisterSync` sends `handover_amount` with `register/close`, keeps the main-office
  account of the answer (`session.handover.to_account`), tries the close again on `cash_in_transit_not_set` and
  `main_cash_not_set`, and refuses for good on `handover_too_large`. An office answer without `session.handover` after a
  handover tells the cashier to hand the cash over by hand. Tests `ShiftHandoverTest`.

## 1.0.1 - 2026-11-18

- The release now ships `resources/lang/vendor/sylloo-app-base` (the till's own words for the shared device screens).
  `.gitignore` had hidden it with a bare `vendor` line; it now ignores only the top `/vendor`.

## 1.0.0 - 2026-11-18

- First version: moved from the Sylloo POS PC till without a change of behaviour (T1, 2026-11-18). The till's models,
  casts, migrations, seeders, factories, services, sync jobs, events, receipt layouts and data, and texts (en, bn) now
  live under `Rishadblack\SyllooPosCore\…`. Table and column names, the push/pull contract and the ERP calls are as
  before.
- Contracts for the platform: `TillSettings` (facade `Facades\Settings`), `QueueWorkers` (facade
  `Facades\QueueWorker`), `ReceiptPrinter`, `CustomerDisplayScreen`, `TillScreenFace`; defaults that keep settings in
  memory and print, show and start nothing.
- `Screens\TillScreens`: the decisions of the till's shared screens (the "almost ready" state, the fresh start on a new
  activation code, sign in).
- The base's till contracts answered here: device state, cashiers as members, release steps, and the till's classes
  over the base's.
