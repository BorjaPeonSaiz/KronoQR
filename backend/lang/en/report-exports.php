<?php

declare(strict_types=1);

/*
 * Notice that a background report is ready (RF-IN-06, task 3.9).
 *
 * WHAT THIS EMAIL DOES NOT CARRY, first of all: the download link. It is
 * single-use and expires in fifteen minutes (ADR-041), so one sitting in a
 * mailbox —where it may take longer to arrive, or where a mail antivirus
 * follows every link it sees— would be a link that no longer works when the
 * person clicks it. What it carries is where to look.
 *
 * IT ALSO CARRIES NO EMPLOYEE NAME AND NO WORKED HOURS (hard rule 21): email
 * leaves towards a server that may belong to a third party, and the contents of
 * the report stay in the file, behind the link.
 */

return [
    'mail' => [
        'subject' => 'Your report is ready to download',
        'greeting' => 'Hello, :name:',
        'intro' => 'The hours report from :from to :to has been generated, in :format format.',
        'rows' => 'It contains :rows data rows.',
        'expires' => 'You can download it until :date (UTC). After that it is deleted from the server automatically.',
        'where' => 'Open the panel, section "Reports", and press "Download" in the background exports list.',
        'single_use' => 'Each download uses a single-use link: if you need it again, go back to that screen and press it once more.',
        'footer' => 'This is an automatic notice. There is no need to reply.',
    ],
];
