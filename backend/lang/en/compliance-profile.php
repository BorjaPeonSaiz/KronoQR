<?php

declare(strict_types=1);

/*
 * Compliance profile texts (RF-PD-07, task 5.2).
 */

return [

    'attributes' => [
        'name' => 'collective agreement name',
        'min_rest_hours' => 'minimum rest between working days',
        'max_daily_hours' => 'ordinary daily working hours',
        'max_weekly_hours' => 'ordinary weekly working hours',
        'break_required_after_hours' => 'maximum continuous stretch without a break',
        'week_starts_on' => 'first day of the week',
        'holiday_calendar' => 'public holiday calendar',
        'retention_years' => 'years the record is kept',
    ],

    'strict_integer' => 'The :attribute must be a whole number, without quotes.',

    'errors' => [
        'no_fields' => 'Provide at least one profile field to change.',
        'not_integer' => 'Field ":field" expects a whole number and received :received.',
        'not_text' => 'Field ":field" expects text and received :received.',
        'not_empty' => 'Field ":field" cannot be left empty.',
        'too_long' => 'Field ":field" accepts up to :maximum characters and received :length.',
        'out_of_range' => 'Field ":field" accepts :minimum to :maximum and received :value.',
        'not_date_list' => 'The public holiday calendar accepts YYYY-MM-DD dates and received ":received".',
        'duplicated' => 'Field ":field" does not accept repeated values.',
        'weekly_below_daily' => 'Weekly working hours (:weekly h) cannot be lower than daily working hours (:daily h).',
        'name_taken' => 'Another compliance profile is already named ":name". Choose a different name.',
    ],

];
