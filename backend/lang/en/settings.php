<?php

declare(strict_types=1);

/*
 * Installation settings (RF-PD-01, ADR-017). English counterpart of
 * `lang/es/settings.php`; see that file for the rationale.
 */

return [

    'unknown_key' => 'The setting key ":key" does not exist in this installation. '
        .'The key catalogue is closed: check the name in the configuration guide.',

    'errors' => [
        'not_integer' => 'Setting ":key" expects an integer and received :received.',
        'not_text' => 'Setting ":key" expects a string and received :received.',
        'not_list' => 'Setting ":key" expects a list and received :received.',
        'not_list_of_text' => 'Setting ":key" expects a list of strings and received :received.',
        'out_of_range' => 'Setting ":key" accepts :minimum to :maximum, and received :value.',
        'not_empty' => 'Setting ":key" does not accept an empty value. Restoring the shipped value means writing it, not saving an empty string.',
        'too_long' => 'Setting ":key" accepts up to :maximum characters, and received :length.',
        'malformed' => 'The value of setting ":key" is malformed (expected :shape).',
        'duplicated' => 'Setting ":key" does not accept repeated values.',
        'not_allowed' => 'Setting ":key" only accepts :allowed, and received ":value".',
        'default_locale_not_available' => 'The default language ":default" is not among the available languages (:available).',
        'strict_integer' => 'The value of :attribute must be an integer, not a quoted string.',
    ],

    'strict_integer' => 'The value of :attribute must be an integer, not a quoted string.',

    'attributes' => [
        'ATTENDANCE_MAX_SHIFT_HOURS' => 'shift length above which an entry is anomalous (hours)',
        'ATTENDANCE_DEBOUNCE_SECONDS' => 'debounce window between two scans (seconds)',
        'ATTENDANCE_MAX_CLOCK_SKEW_MINUTES' => 'tolerated clock skew (minutes)',
        'ATTENDANCE_MIN_TRANSIT_SECONDS' => 'minimum transit time between two kiosks (seconds)',
        'BRANDING_APP_NAME' => 'application name',
        'BRANDING_LOGO_PATH' => 'logo path on the server',
        'BRANDING_ACCENT_COLOR' => 'accent colour',
        'LOCALE_DEFAULT' => 'default language',
        'LOCALE_AVAILABLE' => 'available languages',
    ],

];
