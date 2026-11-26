<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_html;

return [
    'title' => trans_html('Take your class timetable with you'),
    'download' => trans_html('Download class calendar (.ics)'),
    'scope' => trans_html(
        'Rechecks the current published class meetings for this entire week or month. '
        . 'Daily pages and overlap filters do not limit the download. Advising appointments are separate.'
    ),
    'privacy' => trans_html(
        'The file contains private class information and imports meetings as busy time. '
        . 'Store it securely. Copies do not update or get recalled; check My timetable for changes.'
    ),
    'preparing' => trans_html('Preparing your private class calendar…'),
    'started' => trans_html('Calendar download started. This copy will not update automatically.'),
    'failed' => trans_html('The class calendar could not be prepared. Refresh your timetable and try again.'),
    'denied' => trans_html(
        'Your timetable is unavailable for this account. Sign in again or contact your records office.'
    ),
];
