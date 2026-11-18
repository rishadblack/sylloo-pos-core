## Sylloo POS Core

- `rishadblack/sylloo-pos-core` is the shared core of the Sylloo POS tills (PC and Android): models and migrations (`Rishadblack\SyllooPosCore\Models\…`), services (`Services\…`: pricing, stock, payments, shifts, returns, kitchen, ERP calls, sync, resets), sync jobs (`Jobs\…`), receipt layouts (`sylloo-pos-core::receipts.*`) and receipt data (`Receipts\…`), and the texts (`__('device.…')` in `resources/lang/{en,bn}`). It builds on `rishadblack/sylloo-app-base`.
- The core names no Livewire, Electron, NativePHP, session, request or HTML builder. A rule of the till goes into a core service with plain values in and out; the till's screens (Livewire `Terminal` and its traits) only call it.
- The platform is behind contracts a till binds: `Contracts\TillSettings` (read through the facade `Facades\Settings`), `Contracts\QueueWorkers` (`Facades\QueueWorker`), `Contracts\ReceiptPrinter` (prints one self-contained HTML page), `Contracts\CustomerDisplayScreen`, `Contracts\TillScreenFace` (passed to `Screens\TillScreens::register()`), and the base's `DeviceStore`, `Notifier`, `AppUpdater`, `BrowserOpener`, `SecretStore`, `LockSource`. Use `app(Contract::class)`, never a platform facade, in core code.
- Never rename a table or a column, and never change the push/pull payload or an ERP call, without writing it in the changelog and telling the owner.
- Receipts are self-contained (no network): images inline as data URIs, styles inline.
- Every new sentence goes into `resources/lang/en` and `resources/lang/bn`; the till's words on the base's screens are in `resources/lang/vendor/sylloo-app-base/{en,bn}/device.php`.
- Anything new a till stores locally must be removed by `Services\LocalDataReset` (a till's own folders: config `sylloo-pos-core.reset.storage_folders`).
- Test with Pest and testbench (`tests/TestCase.php` binds `Rishadblack\SyllooAppBase\Testing\ArrayDeviceStore`); bind a recording contract with `app()->instance(ReceiptPrinter::class, $printer)`.
