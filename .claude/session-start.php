<?php
$skills = [
    '/bump-version — version bump steps, UPGRADING doc format',
    '/new-migration — migration ID format, SQL vs PHP choice',
    '/usercredits-workflow — 5-place checklist for new UserCredit types',
    '/logging-guide — log file table, per-context code patterns',
    '/new-webdoor — manifest requirement, SDK require path',
    '/tackleissue <issue#> — assign, plan, implement, and close a GitHub issue',
    '/newftn — prompt for FTN details and create a migration to insert or update the network',
];
echo json_encode([
    'systemMessage' => 'Welcome to BinktermPHP\'s Claude environment.' . PHP_EOL . PHP_EOL . 'Project skills (invoke with /skill-name):' . PHP_EOL . implode(PHP_EOL, $skills),
]);
