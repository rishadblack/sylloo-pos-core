<?php

/*
 * The sale desk's sentences (Desks\SaleDesk), the same on every till. A till's own `lang/en/till.php` adds its screen
 * words and may word these for its own screens.
 */

return [
    'not_on_sale' => 'This product is not on sale.',
    'no_product_with_code' => 'No product has the code :code.',
    'scan_unknown' => 'No product has the barcode :code. Check the item, or find it by its name.',
    'not_sold_here' => ':name has variants or serial numbers: it can not be sold on this till yet.',
    'no_walk_in' => 'This till has no walk-in customer yet: get the business data first.',
    'no_terminal' => 'This till has no till number yet: activate it first.',
    'cart_empty' => 'Add a product first.',
    'quantity_more_than_zero' => 'Every quantity must be more than zero.',
    'amount_more_than_zero' => 'The amount must be more than zero.',
    'only_cash_more' => ':method can not be more than the amount due (:amount).',
    'not_enough' => 'The amount given is short by :amount.',
    'no_cash_method' => 'This business has no cash payment method on this till yet.',
    'save_failed' => 'The sale could not be saved. Nothing was charged; try again.',

    // Baki: a sale left owing (SaleDesk::completeOnBaki()).
    'amount_not_negative' => 'The amount can not be negative.',
    'baki_needs_customer' => 'Baki needs a customer: choose or add the customer first.',
    'baki_nothing_owed' => 'Nothing is left owing: take it as cash.',
    'baki_due_date_required' => 'The customer owes :amount: set a due date.',
    'baki_due_date_past' => 'The due date can not be before today.',
    'baki_over_limit' => ':name already owes :owed and may owe at most :limit. Leaving more owing needs the credit limit right; a manager\'s approval on this till comes later.',

    // A new customer (CustomerDesk::add()).
    'customer_mobile_required' => 'Enter the customer\'s mobile number.',
    'customer_mobile_invalid' => 'Enter the mobile number in digits only (6 to 20).',
    'customer_name_required' => 'Enter the customer\'s name.',

    // An order's sync state (OrdersDesk).
    'order_synced' => 'Sent',
    'order_uploading' => 'Sending',
    'order_failed' => 'Not sent: check Sync',
    'order_pending' => 'Waits',
    'order_held' => 'Held',
    'order_needs_checking' => 'Needs checking at the office',
];
