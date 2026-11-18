<?php

namespace Rishadblack\SyllooPosCore\Desks;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Rishadblack\SyllooPosCore\Contracts\TillSettings;
use Rishadblack\SyllooPosCore\Models\Contact;
use Rishadblack\SyllooPosCore\Models\ContactGroup;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Services\CustomerCredit;
use Rishadblack\SyllooPosCore\Services\DueCollections;

/**
 * The customer of a sale and the dues book, as plain values (android-pos-plan.md §3.3, the Customer desk): find a
 * customer by part of the mobile number or the name, this till's recent customers, a new customer from a name and a
 * mobile number, a customer's card (owes, overdue, limit), and everyone who owes this shop. The rules of the PC till's
 * `WithTerminalContact` / `WithCustomerCredit` (a typed mobile of a known customer picks that customer; a new customer
 * is always created, never an existing one overwritten; the business's default group; the figures of
 * {@see CustomerCredit}, offline too). It names no screen and no platform.
 *
 * A row: `id`, `name`, `mobile`, `owed`, `overdue`, `limit`, `over_limit`.
 */
class CustomerDesk
{
    /** Customers shown for a search. */
    public const FOUND = 20;

    /** Recent customers of this till, shown before any typing. */
    public const RECENT = 10;

    /** Customers the dues book looks at, at most (a shop's baki customers are a few dozen). */
    public const DUES_BOOK_MAX = 500;

    public function __construct(protected CustomerCredit $credit) {}

    /**
     * Customers whose mobile number holds the typed digits, or whose name holds every typed word. The walk-in customer
     * is never found (it is its own button).
     *
     * @return list<array{id: string, name: string, mobile: ?string, owed: float, overdue: float, limit: float, over_limit: bool}>
     */
    public function find(string $term, int $limit = self::FOUND): array
    {
        $term = trim(numberBnToEn($term));

        if ($term === '') {
            return [];
        }

        $digits = preg_replace('/\D/', '', $term);
        $query = $this->customers();

        if ($digits !== '' && preg_match('/^[\d\s+\-()]+$/', $term) === 1) {
            $query->where('contacts.mobile', 'like', '%'.$digits.'%');
        } else {
            foreach (preg_split('/\s+/u', $term) ?: [] as $word) {
                $query->where('contacts.name', 'like', '%'.$word.'%');
            }
        }

        return $query->orderBy('contacts.name')->limit($limit)->get()->map(fn (Contact $contact) => $this->row($contact))->all();
    }

    /**
     * The customers of this till's latest sales, newest first (not the walk-in customer).
     *
     * @return list<array{id: string, name: string, mobile: ?string, owed: float, overdue: float, limit: float, over_limit: bool}>
     */
    public function recent(int $limit = self::RECENT): array
    {
        $ids = Order::query()->notDraft()->where('orders.type', 1)->whereNotNull('orders.contact_id')
            ->orderByDesc('orders.order_date')->limit($limit * 5)->pluck('orders.contact_id')
            ->map(fn ($id) => (string) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $contacts = $this->customers()->whereIn('contacts.id', $ids->all())->get()->keyBy(fn (Contact $contact) => (string) $contact->getKey());

        return $ids->map(fn (string $id) => $contacts->get($id))->filter()->take($limit)
            ->map(fn (Contact $contact) => $this->row($contact))->values()->all();
    }

    /**
     * A customer's card, or null when the id is no customer of this till (or the walk-in customer).
     *
     * @return array{id: string, name: string, mobile: ?string, owed: float, overdue: float, limit: float, over_limit: bool}|null
     */
    public function card(?string $contactId): ?array
    {
        $contact = blank($contactId) ? null : $this->customers()->find($contactId);

        return $contact ? $this->row($contact) : null;
    }

    /**
     * A new customer from a name and a mobile number. A mobile number a customer already has picks that customer (the
     * typed name is not written over theirs). Priced with the business's default group; sent to the office with their
     * first sale.
     *
     * @throws CustomerRefused
     */
    public function add(?string $name, ?string $mobile): Contact
    {
        $name = trim((string) $name);
        $mobile = self::mobile($mobile);

        if ($mobile === '') {
            throw new CustomerRefused(__('till.customer_mobile_required'));
        }

        if (preg_match('/^\+?\d{6,20}$/', $mobile) !== 1) {
            throw new CustomerRefused(__('till.customer_mobile_invalid'));
        }

        $known = Contact::query()->customer()->where('contacts.mobile', $mobile)->first();

        if ($known) {
            return $known;
        }

        if ($name === '') {
            throw new CustomerRefused(__('till.customer_name_required'));
        }

        $contact = new Contact;
        $contact->forceFill([
            'code' => $contact->generateCode(),
            'contact_group_id' => ContactGroup::active()->default()->orderBy('sort')->orderBy('serial')->value('id'),
            'desktop_pos_id' => app(TillSettings::class)->get('desktop_pos_id'),
            'type' => 1,
            'mobile' => $mobile,
            'name' => Str::limit($name, 191, ''),
            'status' => 1,
        ])->save();

        return $contact;
    }

    /**
     * Everyone who owes this shop, overdue first, then the most owed: the office's figures plus this till's own sales and
     * collections since ({@see CustomerCredit::owed()}). `$filter` `overdue` keeps those with something overdue. With
     * the total of all of them.
     *
     * @return array{total: float, overdue: float, count: int, rows: list<array{id: string, name: string, mobile: ?string, owed: float, overdue: float, limit: float, over_limit: bool}>}
     */
    public function duesBook(string $search = '', string $filter = 'all'): array
    {
        $localIds = Order::query()->notDraft()->where('orders.from_office', false)
            ->whereIn('orders.type', [1, DueCollections::TYPE])->whereNull('orders.last_sync_at')
            ->where('orders.due_amount', '>', 0)->distinct()->pluck('orders.contact_id')->filter()->all();

        $rows = $this->customers()
            ->where(fn (Builder $query) => $query->where('contacts.balance', '>', 0)->orWhere('contacts.overdue', '>', 0)->orWhereIn('contacts.id', $localIds))
            ->limit(self::DUES_BOOK_MAX)->get()
            ->map(fn (Contact $contact) => $this->row($contact))
            ->filter(fn (array $row) => $row['owed'] > CustomerCredit::TOLERANCE)
            ->values();

        $total = round((float) $rows->sum('owed'), 2);
        $overdue = round((float) $rows->sum('overdue'), 2);
        $term = trim(numberBnToEn($search));

        if ($term !== '') {
            $found = collect($this->find($term, self::DUES_BOOK_MAX))->pluck('id')->all();
            $rows = $rows->whereIn('id', $found)->values();
        }

        if ($filter === 'overdue') {
            $rows = $rows->filter(fn (array $row) => $row['overdue'] > CustomerCredit::TOLERANCE)->values();
        }

        return [
            'total' => $total,
            'overdue' => $overdue,
            'count' => $rows->count(),
            'rows' => $rows->sortBy([['overdue', 'desc'], ['owed', 'desc']])->values()->all(),
        ];
    }

    /**
     * A mobile number as typed, without spaces, dashes or brackets, Bangla digits as Latin.
     */
    public static function mobile(?string $typed): string
    {
        return preg_replace('/[\s\-().]/', '', numberBnToEn(trim((string) $typed))) ?? '';
    }

    /**
     * Active customers of the business, without the walk-in customer.
     *
     * @return Builder<Contact>
     */
    protected function customers(): Builder
    {
        return Contact::query()->customer()->active()->where(fn (Builder $query) => $query->whereNull('contacts.is_walk_in')->orWhere('contacts.is_walk_in', 0));
    }

    /**
     * @return array{id: string, name: string, mobile: ?string, owed: float, overdue: float, limit: float, over_limit: bool}
     */
    protected function row(Contact $contact): array
    {
        $figures = $this->credit->figures($contact);

        return [
            'id' => (string) $contact->getKey(),
            'name' => (string) $contact->getAttribute('name'),
            'mobile' => filled($contact->getAttribute('mobile')) ? (string) $contact->getAttribute('mobile') : null,
            'owed' => $figures['owed'],
            'overdue' => $figures['overdue'],
            'limit' => $figures['limit'],
            'over_limit' => $figures['over_limit'],
        ];
    }
}
