<?php

declare(strict_types=1);

// Public, fictional view data only. Domain/HTTP tests exercise the actual visibility repository.
$programRows = [];
for ($i = 1; $i <= 14; ++$i) {
    $programRows[] = ['id' => '01K' . str_pad((string) $i, 23, '0', STR_PAD_LEFT),
        'code' => 'ARTS.' . $i, 'title' => $i === 1 ? 'Computer Science' : 'Fictional Arts ' . $i,
        'academic_year_code' => '2026-27'];
}
if ($path === '/portal/programs/') {
    $programSearch = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
    $rows = array_values(array_filter($programRows, static fn (array $row): bool =>
        ($programSearch === '' || str_contains(
            strtolower($row['title'] . ' ' . $row['code']),
            strtolower($programSearch),
        ))
        && (!isset($_GET['after']) || $row['id'] > $_GET['after'])));
    $data += ['search' => $programSearch, 'after' => $_GET['after'] ?? null, 'hasPrevious' => isset($_GET['after']),
        'items' => array_slice($rows, 0, 12), 'next_after' => count($rows) > 12 ? $rows[11]['id'] : null];
    $template = 'programs';
    $title = 'Explore academic programs';
} else {
    $data['backUrl'] = '/portal/programs/' . (isset($_GET['q']) ? '?' . http_build_query(['q' => $_GET['q']]) : '');
    $data['program'] = ['id' => $programRows[0]['id'], 'code' => 'ARTS-UG', 'title' => 'Computer Science',
        'description' => "A fictional published program.\nLiteral <script>window.injectedProgram = true</script>.",
        'academic_year_code' => '2026-27', 'application_available' => !isset($_GET['closed']),
        'classifications' => ['academic_level' => ['title' => 'Undergraduate'],
            'award' => ['title' => 'Bachelor of Arts'], 'school' => ['title' => 'Arts & Sciences'],
            'field_of_study' => null, 'campus' => ['title' => 'Fictional Main Campus']],
        'additional_campuses' => [['title' => 'Fictional City Campus']],
        'incompatible_components' => [[['code' => 'ENG', 'title' => 'English <script>literal</script>'],
            ['code' => 'HIST', 'title' => 'History']]],
        'components' => [['kind' => 'major', 'title' => 'English'], ['kind' => 'minor', 'title' => 'History']],
        'selection_limits' => null];
    $template = 'program';
    $title = 'Computer Science';
}
