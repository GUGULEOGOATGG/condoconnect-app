<?php
declare(strict_types=1);
$file = $argv[1] ?? dirname(__DIR__) . '/test-results/coverage.xml';
$report = simplexml_load_file($file);
if (!$report) throw new RuntimeException('Relatório de cobertura ausente ou inválido.');
$metrics = $report->project->metrics;
$total = (int)$metrics['statements']; $covered = (int)$metrics['coveredstatements'];
$percent = $total ? 100 * $covered / $total : 0;
printf("Cobertura PHP: %.2f%% (%d/%d linhas)\n", $percent, $covered, $total);
foreach ($report->project->file as $entry) {
    $m = $entry->metrics; $n = (int)$m['statements'];
    printf("%s: %.2f%% (%d/%d)\n", (string)$entry['name'], $n ? 100 * (int)$m['coveredstatements'] / $n : 0, (int)$m['coveredstatements'], $n);
}
exit($percent >= 80 ? 0 : 1);
