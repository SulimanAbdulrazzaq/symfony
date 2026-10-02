<?php
// Exploration only: compares Symfony Yaml::parse() with the official yaml-test-suite expectations.
require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

$root = $argv[1];
$flags = isset($argv[2]) ? (int) $argv[2] : 0;

function cases(string $dir): array
{
    $out = [];
    foreach (scandir($dir) as $e) {
        if ('.' === $e || '..' === $e || '.git' === $e) { continue; }
        $p = $dir.'/'.$e;
        if (!is_dir($p)) { continue; }
        if (is_file($p.'/in.yaml')) { $out[] = $p; }
        else { foreach (cases($p) as $c) { $out[] = $c; } }
    }
    sort($out);
    return $out;
}

function same($a, $b): bool
{
    if (is_array($a) && is_array($b)) {
        if (count($a) !== count($b)) { return false; }
        foreach ($a as $k => $v) {
            if (!array_key_exists($k, $b) || !same($v, $b[$k])) { return false; }
        }
        return true;
    }
    if (is_object($a)) { $a = (array) $a; return same($a, $b); }
    if (is_object($b)) { $b = (array) $b; return same($a, $b); }
    if (is_array($a) || is_array($b)) { return false; }
    if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) { return (float) $a === (float) $b; }
    return $a === $b;
}

$stats = ['total' => 0, 'skipped' => 0, 'ok' => 0, 'exc' => 0, 'diff' => 0, 'accepted_invalid' => 0, 'error_ok' => 0];
$lines = [];
foreach (cases($root) as $dir) {
    $id = substr($dir, strlen($root) + 1);
    $yaml = file_get_contents($dir.'/in.yaml');
    $name = is_file($dir.'/===') ? trim(file_get_contents($dir.'/===')) : '';
    $expectError = is_file($dir.'/error');
    ++$stats['total'];
    $expected = null;
    if (!$expectError) {
        if (!is_file($dir.'/in.json')) { ++$stats['skipped']; continue; }
        $json = file_get_contents($dir.'/in.json');
        $expected = json_decode($json, false);
        if (null === $expected && 'null' !== trim($json)) { ++$stats['skipped']; continue; } // multi-document stream
        if (preg_match('/^(---|\.\.\.)(\s|$)/m', $yaml, $m, 0) && preg_match_all('/^---(\s|$)/m', $yaml) > 1) { ++$stats['skipped']; continue; }
    }
    try {
        $got = Yaml::parse($yaml, $flags | Yaml::PARSE_OBJECT_FOR_MAP);
    } catch (ParseException $e) {
        if ($expectError) { ++$stats['error_ok']; continue; }
        ++$stats['exc'];
        $lines[] = sprintf("EXC\t%s\t%s\t%s", $id, $name, str_replace("\n", ' ', $e->getMessage()));
        continue;
    } catch (\Throwable $e) {
        ++$stats['exc'];
        $lines[] = sprintf("CRASH\t%s\t%s\t%s: %s", $id, $name, get_class($e), str_replace("\n", ' ', $e->getMessage()));
        continue;
    }
    if ($expectError) {
        ++$stats['accepted_invalid'];
        $lines[] = sprintf("ACCEPTED_INVALID\t%s\t%s\t%s", $id, $name, substr(json_encode($got, JSON_UNESCAPED_UNICODE), 0, 120));
        continue;
    }
    if (same($expected, $got)) { ++$stats['ok']; continue; }
    ++$stats['diff'];
    $lines[] = sprintf("DIFF\t%s\t%s\texpected=%s\tgot=%s", $id, $name, substr(json_encode($expected, JSON_UNESCAPED_UNICODE), 0, 160), substr(json_encode($got, JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 160));
}
sort($lines);
echo implode("\n", $lines), "\n";
echo json_encode($stats), "\n";
