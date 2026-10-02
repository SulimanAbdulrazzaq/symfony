<?php
// Exploration only: for ASCII input ByteString, CodePointString and UnicodeString must agree with each other
// and with the PHP builtin where one exists.
require __DIR__.'/vendor/autoload.php';

use Symfony\Component\String\AbstractString;
use Symfony\Component\String\ByteString;
use Symfony\Component\String\CodePointString;
use Symfony\Component\String\UnicodeString;

error_reporting(\E_ALL);
$warnings = [];
set_error_handler(static function (int $no, string $str, string $file, int $line) use (&$warnings): bool {
    if (!(error_reporting() & $no)) { return false; }
    $warnings[] = $str.' @'.basename($file).':'.$line;
    return true;
});

$bs = chr(92);
$alphabet = ['a', 'b', ' ', '-', '.', "\n", 'A'];
$specials = ['a', 'b', ' ', '-', '.', ']', '[', '^', $bs, '/', '$', '*', '(', ')', '|', '?', '+', '{', '}', '#', '~', '%', '"', "'"];
$classes = [
    'Byte' => fn ($s) => new ByteString($s),
    'CodePoint' => fn ($s) => new CodePointString($s),
    'Unicode' => fn ($s) => new UnicodeString($s),
];

function norm($v): string
{
    if ($v instanceof AbstractString) { return 'S:'.$v->toString(); }
    if (is_array($v)) { return 'A:['.implode('|', array_map('norm', $v)).']'; }
    return 'V:'.var_export($v, true);
}

function run(callable $f): array
{
    global $warnings;
    $warnings = [];
    try { $r = norm($f()); } catch (\Throwable $e) { $r = 'EXC:'.get_class($e); }
    return [$r, $warnings];
}

/** @var array<string, array{0: list<list<mixed>>, 1: callable, 2: ?callable}> $methods */
$methods = [];
$sliceStarts = [0, 1, 2, 4, 9, -1, -3, -9];
$sliceLens = [null, 0, 1, 2, 5, -1, -2, -9];
$params = [];
foreach ($sliceStarts as $a) { foreach ($sliceLens as $b) { $params[] = [$a, $b]; } }
$methods['slice'] = [$params, fn ($o, $a) => $o->slice($a[0], $a[1]), fn ($s, $a) => null === $a[1] ? substr($s, $a[0]) : substr($s, $a[0], $a[1])];

$params = [];
foreach ([0, 1, 3, 9, -1, -3] as $a) { foreach ([null, 0, 1, 3, -1] as $b) { $params[] = ['XY', $a, $b]; } }
$methods['splice'] = [$params, fn ($o, $a) => $o->splice($a[0], $a[1], $a[2]), null];

$needles = ['a', 'ab', ' ', '-', 'b ', 'A', '.'];
$params = [];
foreach ($needles as $n) { foreach ([0, 1, 2, -1, -2] as $o) { $params[] = [$n, $o]; } }
$methods['indexOf'] = [$params, fn ($o, $a) => $o->indexOf($a[0], $a[1]), fn ($s, $a) => abs($a[1]) > strlen($s) ? 'skip' : (false === ($p = strpos($s, $a[0], $a[1])) ? null : $p)];
$methods['indexOfLast'] = [$params, fn ($o, $a) => $o->indexOfLast($a[0], $a[1]), fn ($s, $a) => abs($a[1]) > strlen($s) ? 'skip' : (false === ($p = strrpos($s, $a[0], $a[1])) ? null : $p)];

$params = [];
foreach (['a', 'ab', ' ', '-', 'b ', '.'] as $n) { foreach ([[false, 0], [true, 0], [false, 1], [true, 1], [false, -1], [true, 2]] as $i) { $params[] = [$n, $i[0], $i[1]]; } }
foreach (['before', 'after', 'beforeLast', 'afterLast'] as $m) {
    $methods[$m] = [$params, fn ($o, $a) => $o->$m($a[0], $a[1], $a[2]), null];
}

$params = [];
foreach ([' ', '-', 'ab', 'abc', '.'] as $p) { foreach ([0, 1, 2, 3, 4, 5, 7, 8] as $l) { $params[] = [$l, $p]; } }
foreach (['padBoth' => STR_PAD_BOTH, 'padEnd' => STR_PAD_RIGHT, 'padStart' => STR_PAD_LEFT] as $m => $mode) {
    $methods[$m] = [$params, fn ($o, $a) => $o->$m($a[0], $a[1]), fn ($s, $a) => str_pad($s, $a[0], $a[1], $mode)];
}

$methods['repeat'] = [[[0], [1], [2], [3]], fn ($o, $a) => $o->repeat($a[0]), fn ($s, $a) => str_repeat($s, $a[0])];
$methods['reverse'] = [[[]], fn ($o, $a) => $o->reverse(), fn ($s, $a) => strrev($s)];

$params = [];
foreach ($needles as $f) { foreach (['', 'X', 'XY', 'a'] as $t) { $params[] = [$f, $t]; } }
$methods['replace'] = [$params, fn ($o, $a) => $o->replace($a[0], $a[1]), fn ($s, $a) => '' === $a[0] ? 'skip' : str_replace($a[0], $a[1], $s)];
$methods['chunk'] = [[[1], [2], [3], [5]], fn ($o, $a) => $o->chunk($a[0]), fn ($s, $a) => '' === $s ? [] : str_split($s, $a[0])];

$trimSets = array_merge([[]], array_map(fn ($c) => [$c], array_merge($specials, ['a-b', 'a.b', ' a', '-.', '].', '^a', 'a^', $bs.'a', '/a', 'ab-', '-ab', 'a..b', '[a]', $bs.$bs, '--'])));
foreach (['trim' => 'trim', 'trimStart' => 'ltrim', 'trimEnd' => 'rtrim'] as $m => $fn) {
    $methods[$m] = [$trimSets, fn ($o, $a) => $o->$m(...$a), fn ($s, $a) => $fn($s, ...$a)];
}

$affixes = array_map(fn ($p) => [$p], ['a', 'ab', ' ', '-', '', 'A', 'b ']);
$methods['startsWith'] = [$affixes, fn ($o, $a) => $o->startsWith($a[0]), fn ($s, $a) => str_starts_with($s, $a[0])];
$methods['endsWith'] = [$affixes, fn ($o, $a) => $o->endsWith($a[0]), fn ($s, $a) => str_ends_with($s, $a[0])];
$methods['lower'] = [[[]], fn ($o, $a) => $o->lower(), fn ($s, $a) => strtolower($s)];
$methods['upper'] = [[[]], fn ($o, $a) => $o->upper(), fn ($s, $a) => strtoupper($s)];
$methods['title'] = [[[false], [true]], fn ($o, $a) => $o->title($a[0]), fn ($s, $a) => $a[0] ? ucwords($s) : ucfirst($s)];
$methods['collapseWhitespace'] = [[[]], fn ($o, $a) => $o->collapseWhitespace(), fn ($s, $a) => trim(preg_replace('/\s+/', ' ', $s))];
$methods['width'] = [[[]], fn ($o, $a) => $o->width(), fn ($s, $a) => preg_match('/[^ -~]/', $s) ? 'skip' : strlen($s)];
$methods['length'] = [[[]], fn ($o, $a) => $o->length(), fn ($s, $a) => strlen($s)];
$methods['equalsTo/ignoreCase'] = [[['a'], ['A'], ['ab'], [''], ['AB']], fn ($o, $a) => [$o->equalsTo($a[0]), $o->ignoreCase()->equalsTo($a[0])], fn ($s, $a) => [$s === $a[0], 0 === strcasecmp($s, $a[0])]];
$methods['containsAny'] = [array_map(fn ($p) => [$p], ['a', 'ab', ' ', '-', '', 'A', 'b ', '.']), fn ($o, $a) => $o->containsAny($a[0]), fn ($s, $a) => '' === $a[0] ? 'skip' : str_contains($s, $a[0])];
$methods['join'] = [[[['a', 'b', 'c'], null], [['a', 'b', 'c'], '&'], [['a'], '&'], [[], '&'], [['a', 'b'], ' and ']], fn ($o, $a) => $o->join($a[0], $a[1]), null];
$methods['ensureStart'] = [array_map(fn ($p) => [$p], ['a', 'ab', ' ', '-', 'A']), fn ($o, $a) => $o->ensureStart($a[0]), null];
$methods['ensureEnd'] = [array_map(fn ($p) => [$p], ['a', 'ab', ' ', '-', 'A']), fn ($o, $a) => $o->ensureEnd($a[0]), null];

$params = [];
foreach (['a', ' ', '-', 'ab', '.'] as $d) { foreach ([null, 1, 2, 3] as $l) { $params[] = [$d, $l]; } }
$methods['split'] = [$params, fn ($o, $a) => $o->split($a[0], $a[1]), null];

$params = [];
foreach ([1, 2, 3, 5, 8] as $l) { foreach (['', '...', '-'] as $e) { $params[] = [$l, $e]; } }
$methods['truncate'] = [$params, fn ($o, $a) => $o->truncate($a[0], $a[1]), null];
$methods['camel/snake/kebab'] = [[[]], fn ($o, $a) => [$o->camel(), $o->snake(), $o->kebab()], null];

$params = [];
foreach ([1, 2, 3, 5] as $w) { foreach ([false, true] as $c) { $params[] = [$w, "\n", $c]; } }
$methods['wordwrap'] = [$params, fn ($o, $a) => $o->wordwrap($a[0], $a[1], $a[2]), fn ($s, $a) => wordwrap($s, $a[0], $a[1], $a[2])];

$methods['match'] = [array_map(fn ($p) => [$p], ['/a/', '/(a)(b)?/', '/\s+/', '/./', '/b*/', '/-(\w)?/']), fn ($o, $a) => $o->match($a[0]), fn ($s, $a) => (function () use ($s, $a) { preg_match($a[0], $s, $m); return $m; })()];
$methods['matchAll'] = [array_map(fn ($p) => [$p], ['/a/', '/(a)(b)?/', '/\s+/', '/./', '/b*/']), fn ($o, $a) => $o->matchAll($a[0]), null];
$methods['replaceMatches'] = [array_map(fn ($p) => [$p, 'X'], ['/a/', '/(a)(b)?/', '/\s+/', '/./', '/b*/']), fn ($o, $a) => $o->replaceMatches($a[0], $a[1]), fn ($s, $a) => preg_replace($a[0], $a[1], $s)];

function allStrings(array $alphabet, int $maxLen): \Generator
{
    yield '';
    $cur = [''];
    for ($l = 1; $l <= $maxLen; ++$l) {
        $next = [];
        foreach ($cur as $p) { foreach ($alphabet as $c) { $next[] = $p.$c; yield $p.$c; } }
        $cur = $next;
    }
}

function oracleMatches(string $got, mixed $expRaw): bool
{
    // $got is "S:..." for strings; compare to the builtin result when that is a string
    if (!str_starts_with($got, 'S:')) { return true; }
    return is_string($expRaw) ? 'S:'.$expRaw === $got : true;
}

$only = $argv[1] ?? null;
$out = [];
foreach ($methods as $mname => [$paramSets, $call, $oracle]) {
    if ($only && !str_contains($mname, $only)) { continue; }
    $maxLen = in_array($mname, ['wordwrap', 'truncate', 'match', 'matchAll', 'replaceMatches', 'split', 'camel/snake/kebab', 'title', 'collapseWhitespace'], true) ? 6 : 5;
    $found = [];
    foreach (allStrings($alphabet, $maxLen) as $s) {
        foreach ($paramSets as $args) {
            $res = [];
            foreach ($classes as $cn => $mk) { $res[$cn] = run(fn () => $call($mk($s), $args)); }
            $expRaw = null;
            $hasOracle = false;
            if ($oracle) {
                try { $expRaw = $oracle($s, $args); $hasOracle = 'skip' !== $expRaw; } catch (\Throwable $e) { $hasOracle = false; }
            }
            $ref = $res['Byte'][0];
            foreach ($res as $cn => [$v, $w]) {
                $kind = null;
                if ('Byte' !== $cn && $v !== $ref) { $kind = 'CLASS_DISAGREE'; }
                elseif ($hasOracle && !str_starts_with($v, 'EXC:') && !oracleMatches($v, $expRaw)) { $kind = 'BUILTIN_DISAGREE'; }
                elseif ($w) { $kind = 'WARNING'; }
                if (null === $kind) { continue; }
                $key = $kind.'|'.$cn.'|'.json_encode($args);
                if (!isset($found[$key]) || strlen($s) < strlen($found[$key][0])) {
                    $found[$key] = [$s, sprintf('Byte=%s | %s=%s | builtin=%s | warn=%s', $ref, $cn, $v, $hasOracle ? (is_string($expRaw) ? 'S:'.$expRaw : json_encode($expRaw)) : '-', $w ? $w[0] : '-')];
                }
            }
        }
    }
    foreach ($found as $key => [$s, $d]) { $out[] = $mname."\t".$key."\t".json_encode($s)."\t".$d; }
}
sort($out);
echo implode("\n", $out), "\n";
echo 'groups: ', count($out), "\n";
