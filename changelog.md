# Changelog

All notable changes to `rishadblack/sylloo-pos-core` are documented in this file.

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
