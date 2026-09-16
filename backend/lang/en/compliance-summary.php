<?php

declare(strict_types=1);

/*
 * Compliance view (RF-PA-06, task 3.4).
 *
 * THE EVALUATION CRITERIA ARE PART OF THE ALERT, NOT OF THE DOCUMENTATION.
 * An alert whose criterion cannot be seen is an alert nobody can defend in front
 * of an employee.
 *
 * The keys are chosen by `ReadComplianceSummary` and travel UNTRANSLATED: the
 * domain has no language. The `Resource` translates them with the locale of the
 * request.
 *
 * No line names a specific threshold: thresholds travel in `meta.rules[]`, read
 * from the site's compliance profile (hard rule 14).
 */

return [

    'criteria' => [

        'rest_between_workdays' => 'Rest is measured between work days: the last clock-out of one work day against the first clock-in of the next. A gap between two entries of the same work day is not evaluated yet, because a meal break and the rest between two shifts cannot be told apart today.',

        'daily_total' => 'The daily total is the sum of the closed entries of the day, exactly as stored in the consolidated time record. It is not recalculated for this view.',

        'week' => 'A week is seven calendar days starting on the day the profile begins the week, and it is evaluated in full even when the requested period cuts it short. A week above ordinary working time is informative: the legal reckoning is annual, so it is flagged to be checked against the collective agreement and does not open an incident.',

        'open_shift' => 'A work day with an entry still open is flagged and does not raise an alert: that entry contributes no minutes yet and the total may still grow.',

        'scope' => 'Only people within the scope of whoever is asking appear, including those who left during the period.',
    ],
];
