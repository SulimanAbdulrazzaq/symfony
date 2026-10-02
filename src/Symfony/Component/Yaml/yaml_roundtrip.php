<?php
// Exploration only: dump()->parse() round trip fuzzing of Symfony Yaml.
require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

mt_srand((int) ($argv[1] ?? 1));
$rounds = (int) ($argv[2] ?? 20000);

$tokens = ['a', 'b', 'ab', ' ', '  ', "\t", "\n", "\n\n", ':', ': ', ' :', '#', ' #', '-', '- ', '?', '? ', '!', '&', '*', '%', '@', '`', '|', '>', '{', '}', '[', ']', ',', ', ', '"', "'", chr(92), '0', '1', '.', 'e', 'x', 'null', '~', 'true', 'false', 'yes', 'no', 'on', 'off', 'y', 'n', '=', '<<', 'é', '日', "\u{200B}", "\r", '0x1F', '0o17', '1_0', '+', '1e3', '.inf', '.nan', '---', '...', "\u{85}", "\u{2028}", ': a', 'a: ', '|-', '>+', '!!str', '!tag', '&a', '*a', '2001-01-01', '12:30', '0.5', '-1', '1.', '.5', 'Null', 'NULL', 'True', 'FALSE'];

function randStr(array $tokens): string
{
    $n = mt_rand(1, 5);
    $s = '';
    for ($i = 0; $i < $n; ++$i) { $s .= $tokens[mt_rand(0, count($tokens) - 1)]; }
    return $s;
}

function same($a, $b): bool
{
    if (is_array($a) && is_array($b)) {
        if (array_keys($a) !== array_keys($b)) { return false; }
        foreach ($a as $k => $v) { if (!same($v, $b[$k])) { return false; } }
        return true;
    }
    if (is_float($a) && is_float($b)) { return $a === $b || (is_nan($a) && is_nan($b)); }
    return $a === $b;
}

function roundTrip($value, int $inline, int $flags): array
{
    try {
        $dump = Yaml::dump($value, $inline, 4, $flags);
    } catch (\Throwable $e) {
        return ['dump-exception', null, get_class($e).': '.$e->getMessage()];
    }
    try {
        $back = Yaml::parse($dump);
    } catch (\Throwable $e) {
        return ['parse-exception', $dump, get_class($e).': '.$e->getMessage()];
    }
    if (!same($value, $back)) { return ['mismatch', $dump, var_export($back, true)]; }
    return ['ok', $dump, null];
}

// Context builders: each wraps a string.
$contexts = [
    'root' => fn ($s) => $s,
    'seq' => fn ($s) => [$s],
    'map-value' => fn ($s) => ['k' => $s],
    'map-key' => fn ($s) => [$s => 'v'],
    'nested' => fn ($s) => ['a' => ['b' => [$s, $s.'x']]],
];
$flagSets = ['default' => 0, 'literal-block' => Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK];
$inlines = [0, 1, 2, 4];

$failures = [];
for ($r = 0; $r < $rounds; ++$r) {
    $s = randStr($tokens);
    foreach ($contexts as $cname => $build) {
        if ('map-key' === $cname && (string) (int) $s === $s) { continue; }
        foreach ($flagSets as $fname => $flags) {
            foreach ($inlines as $inline) {
                $value = $build($s);
                $res = roundTrip($value, $inline, $flags);
                if ('ok' === $res[0]) { continue; }
                $key = $cname.'|'.$fname.'|'.$inline.'|'.$res[0];
                // shrink: remove one token-char at a time while it still fails with the same kind
                $cur = $s;
                $changed = true;
                while ($changed && strlen($cur) > 1) {
                    $changed = false;
                    for ($i = 0; $i < strlen($cur); ++$i) {
                        $cand = substr($cur, 0, $i).substr($cur, $i + 1);
                        if ('' === $cand || !mb_check_encoding($cand, 'UTF-8')) { continue; }
                        if ('map-key' === $cname && (string) (int) $cand === $cand) { continue; }
                        $r2 = roundTrip($build($cand), $inline, $flags);
                        if ($r2[0] === $res[0]) { $cur = $cand; $res = $r2; $changed = true; break; }
                    }
                }
                $failures[$key.'|'.bin2hex($cur)] = [$cname, $fname, $inline, $res[0], $cur, $res[1], $res[2]];
            }
        }
    }
}
// group by minimal string
$byString = [];
foreach ($failures as $f) { $byString[bin2hex($f[4])][] = $f; }
echo 'distinct failing minimal strings: ', count($byString), "\n";
$n = 0;
foreach ($byString as $hex => $list) {
    if (++$n > 150) { break; }
    $f = $list[0];
    echo "---- string=", json_encode($f[4], JSON_UNESCAPED_UNICODE), ' contexts=', count($list), "\n";
    foreach (array_slice($list, 0, 3) as $g) {
        echo '  [', $g[0], ' ', $g[1], ' inline=', $g[2], '] ', $g[3], "\n";
        echo '    dump=', json_encode($g[5], JSON_UNESCAPED_UNICODE), "\n";
        echo '    got/err=', str_replace("\n", ' ', (string) $g[6]), "\n";
    }
}
