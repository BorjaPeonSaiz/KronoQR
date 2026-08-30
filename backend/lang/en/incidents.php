<?php

declare(strict_types=1);

/*
 * Attendance record incidents (RF-PR-01, doc 01 §5.5).
 *
 * Keys are the backed values of `incidents.type`, `severity` and `status`, so a
 * new type that nobody translates shows up as its raw key instead of silently
 * falling back to another one.
 */

return [

    'types' => [
        'open_shift_expired' => 'Shift left open for too long',
        'short_shift' => 'Shift below the minimum countable duration',
        'long_shift' => 'Working day above the ordinary limit',
        'missing_break' => 'Continuous shift without a break',
        'insufficient_rest' => 'Insufficient rest between working days',
        'clock_skew' => 'Clock-in recorded with a skewed device clock',
        'missing_clock_out' => 'Missing clock-out',
        'anomalous_pattern' => 'Anomalous credential usage pattern',
    ],

    'severities' => [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
    ],

    'statuses' => [
        'open' => 'Open',
        'resolved' => 'Resolved',
        'dismissed' => 'Dismissed',
    ],

    'mail' => [
        'subject' => 'KronoQR · attendance incidents waiting for review',
        'greeting' => 'Hello,',
        'intro' => 'The automatic review of the attendance record found :count situation(s) in your department that need a person to look at them.',
        'line' => ':date · :employee · :type (:severity priority)',
        'more' => 'And :count more, waiting in the incident tray.',
        'no_auto_close' => 'The system has not closed any shift nor changed any time. Corrections are made by a person and are recorded with their reason.',
        'action' => 'Open the incident tray',
        'footer' => 'You get this message once per review. Incidents you have already handled will not show up again.',
    ],

];
