<?php

declare(strict_types=1);

/*
 * English texts of the instruction sheet handed to the employee together with
 * the card and the PIN (task 5.11b, RL-05). Same keys, same structure and same
 * rules as `lang/es/instructions-sheet.php`: read the explanation there.
 *
 * The confirmations described here are the ones `frontend-kiosk` shows in
 * English (`scan.pending.badge`, `scan.debounced.title`, `scan.rejected.title` and
 * `pin.entryButton` of its `locales`; `InstructionsSheetTextsTest` binds them). If the kiosk changes a text, this
 * sheet has to say the same thing.
 */

return [

    'title' => 'How to clock in with your card',
    'intro' => 'This sheet comes with your card and your PIN. Keep it: it explains what you need to clock in and to check your working-time record.',

    'scan_title' => '1. Clocking in and out',
    'scan_body' => 'Hold the card up to the tablet camera, code facing the screen, and wait for the confirmation. The same gesture works for clocking in and for clocking out.',

    'results_title' => '2. What the screen means',
    'result_ok' => '"Clock-in" or "Clock-out" with the time: your entry has been recorded. Nothing else to do.',
    'result_pending' => '"Pending validation": the tablet has no network right now, but your entry is already saved and will be sent on its own. Do not repeat it.',
    'result_debounced' => '"You clocked in a few seconds ago": nothing new was recorded. If you meant to clock out, wait a moment and try again.',
    'result_rejected' => '"Invalid code": the tablet could not read your card. Clock in with your code and PIN and tell your manager.',

    'no_card_title' => '3. If you do not have the card',
    'no_card_body' => 'Tap "Clock in with your code and PIN" on the tablet, type your employee code and your 6-digit PIN. Your entry counts just the same. If you lost the card, tell your manager that same day: it is cancelled and you get a new one.',

    'portal_title' => '4. Checking your record',
    'portal_body' => 'You can see your working days and download your record whenever you want, from a device on the hotel network or wherever your company tells you, at this address:',
    'portal_credentials' => 'Sign in with your employee code and your PIN. The PIN is the same one you use on the tablet.',
    'portal_pin_reset' => 'If you forget the PIN, ask your manager for a new one: it is handed over in person and never by email.',

    'problems_title' => '5. If something does not add up',
    'problems_body' => 'If you forgot to clock in or you see a wrong entry in your record, tell your manager. They correct it and the correction is noted with who made it, when and why; your previous entry is kept.',

    'contact_title' => 'Contact person',
    'contact_line' => 'Your manager or HR:',

    'footer' => ':app_name · Working-time record. This sheet contains no personal data.',

];
