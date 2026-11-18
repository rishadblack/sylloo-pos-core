# Sylloo POS Core

`rishadblack/sylloo-pos-core` is the shared core of the Sylloo POS tills: the PC till (NativePHP Desktop) today and the
Android till (NativePHP Mobile) next. It holds what a till does, and nothing of how a platform shows or stores it:

- the till's models, casts and all its migrations (same tables and columns as before), seeders and factories;
- the services: pricing, stock, cart and order totals, payments and cash rounding, shifts and cash drawer, returns,
  due collection, customer credit, kitchen tickets, table bills, backups, retention, the ERP calls (`ApiService`,
  `ErpApiService`), sync status and errors, the ERP-ordered reset, the local data reset, the release steps;
- the sync jobs (pull, push, heartbeat, images, staff, terminal profile, remote config);
- the receipt layouts (`sylloo-pos-core::receipts.*`) and the receipt data, rendered to one self-contained HTML page;
- the texts in English and Bangla (`__('device.…')`, and the till's words on the shared base's screens);
- the decisions of the till's shared screens (`Screens\TillScreens`): the "almost ready" state, the fresh start on a
  new activation code, the sign-in step.

It builds on [`rishadblack/sylloo-app-base`](https://github.com/rishadblack/sylloo-app-base) and answers the base's
till contracts itself (device state, the cashiers as members, the release steps).

## Install

```bash
composer require rishadblack/sylloo-pos-core
php artisan migrate
```

## Bind the platform

Everything a platform does is a contract. Unbound, the defaults keep settings in memory and print, show and start
nothing. A till binds its own in a service provider:

```php
$this->app->singleton(TillSettings::class, NativeTillSettings::class);          // key/value of the till
$this->app->singleton(QueueWorkers::class, NativeQueueWorkers::class);          // start and stop a queue worker
$this->app->bind(ReceiptPrinter::class, ElectronReceiptPrinter::class);         // print one HTML page, list printers
$this->app->bind(CustomerDisplayScreen::class, ElectronCustomerDisplay::class); // the customer's screen
```

and the base's own: `DeviceStore`, `Notifier`, `AppUpdater`, `BrowserOpener`, `SecretStore`, `LockSource`.

The shared screens take the till's parts with one call, the till giving its face (sign in, a notice on the next page,
its own pieces):

```php
Rishadblack\SyllooPosCore\Screens\TillScreens::register($hooks, $states, new MyTillScreenFace);
```

Config (`php artisan vendor:publish --tag=sylloo-pos-core-config`): `migrations` (load the till's migrations) and
`reset.storage_folders` (the till's own folders under `storage/` that a fresh start removes).

## Rules

- No Livewire, Electron, NativePHP, session, request or HTML builder in `src/`: plain values in and out.
- Receipts are self-contained: nothing loads from the network.
- Every sentence in English and Bangla.
- The table and column names, and the push/pull contract with the ERP, change only with a note in the changelog.

## Testing

```bash
composer test     # Pest
composer analyse  # PHPStan level 6
composer lint     # Pint
```

## License

MIT. See [license.md](license.md).
