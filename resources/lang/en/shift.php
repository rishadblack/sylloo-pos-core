<?php

/*
 * Closing a shift with a cash handover to the main office (ShiftRegister::close, RegisterSync,
 * ../sylloo-erp-docs/erp/cash-handover.md "From the till's Close shift"). Shared by the PC and the Android till.
 */
return [
    // Close shift panel
    'handover_label' => 'Hand over to main office',
    'handover_hint' => 'Optional. Cash you send to the main office; the rest stays in the drawer.',
    'closing_float' => 'Closing float (stays in the drawer)',

    // Validation (ShiftRegister::handoverProblem)
    'handover_numeric' => 'Enter the handover as an amount.',
    'handover_negative' => 'The handover can not be negative.',
    'handover_more_than_counted' => 'The handover can not be more than the counted cash.',

    // The office's answer (RegisterSync), on the Shift panel
    'handover_waiting' => ':amount to the main office goes with the register close when the till is online.',
    'handover_sent' => ':amount handed over to :account. The accountant accepts it in the office.',
    'handover_sent_no_account' => ':amount handed over to the main office. The accountant accepts it in the office.',
    'handover_not_taken' => 'The office did not record the handover of :amount (its version is older). Give the cash to the main office by hand and tell the accountant.',
    'cash_in_transit_not_set' => 'The office has no "Cash in transit" account in Ledger Settings, so it can not take the handover. Ask the accountant to set it; the register then closes by itself.',
    'main_cash_not_set' => 'The office has no main-office cash account for till handovers in Ledger Settings. Ask the accountant to set it; the register then closes by itself.',
    'handover_too_large' => 'The office did not take the handover: it is more than the counted cash. Close the register in the office.',
    'previous_shift' => 'Shift :number: :message',

    // The Shift desk (Desks\ShiftDesk): open, cash in / out, close.
    'sign_in_first' => 'Sign in first.',
    'open_first' => 'Open your shift before selling.',
    'no_open_shift' => 'You have no open shift.',
    'other_open' => 'Shift :number of :name is still open on this till. It must be closed before a new shift opens.',
    'float_required' => 'Count the cash in the drawer and enter it (0 when empty).',
    'float_negative' => 'The float can not be negative.',
    'amount_required' => 'Enter the amount.',
    'amount_more_than_zero' => 'The amount must be more than 0.',
    'amount_too_large' => 'The amount is too large.',
    'cash_type_unknown' => 'Choose cash in or cash out.',
    'category_required' => 'Choose what the cash is for.',
    'reason_required' => 'Say what the cash is for.',
    'cash_out_needs_right' => 'Taking cash out needs the drawer right. A manager\'s approval on this till comes later.',
    'counted_required' => 'Count the cash in the drawer and enter it.',
    'counted_negative' => 'The counted cash can not be negative.',
    'cash_right' => 'The cash is right.',
    'short_by' => 'Short by :amount.',
    'over_by' => 'Over by :amount.',
    'closed' => 'Shift :number closed.',
];
