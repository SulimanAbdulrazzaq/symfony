<?php
// Exploration only: Yaml::parse() must only ever throw ParseException (or return a value) for any input,
// and must not raise PHP warnings/notices/deprecations.
require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

mt_srand((int) ($argv[1] ?? 1));
$iterations = (int) ($argv[2] ?? 100000);
error_reporting(\E_ALL);
$warn = [];
set_error_handler(static function (int $no, string $str, string $file, int $line) use (&$warn): bool {
    if (!(error_reporting() & $no)) { return false; }
    $warn[] = $str.' @'.basename($file).':'.$line;
    return true;
});

$bs = chr(92);
$tokens = ['a', 'b', 'key', 'x', ': ', ':', '- ', '-', '? ', "\n", "\n", "\n", '  ', '    ', ' ', '"', "'", '|', '>', '|-', '>+', '|2', '>1', '&x ', '*x', '&y ', '*y', '!!str ', '!!int ', '!tag ', '! ', '!<tag:yaml.org,2002:str> ', '{', '}', '[', ']', ', ', ',', '# c', ' #c', '---', '--- ', '...', '%YAML 1.2', '%TAG ! tag:x,2000:', '<<: ', '<<', '<<: *x', "\t", '~', 'null', 'true', '0x1F', '1e3', '.inf', '@', '`', $bs, $bs.'n', $bs.'x41', $bs.'u00e9', $bs.'U0001F600', 'é', "\r\n", '1', '2', '0', '3.14', '2001-01-01', '12:30:45', '+', '=', '%', '!', '$', '(', ')', '/', ';', '<', '>'];
$flagSets = [0, Yaml::PARSE_OBJECT_FOR_MAP, Yaml::PARSE_CUSTOM_TAGS | Yaml::PARSE_CONSTANT | Yaml::PARSE_DATETIME, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_OBJECT, Yaml::PARSE_KEYS_AS_STRINGS];

function gen(array $tokens): string
{
    $n = mt_rand(1, 14);
    $s = '';
    for ($i = 0; $i < $n; ++$i) { $s .= $tokens[mt_rand(0, count($tokens) - 1)]; }
    return $s;
}

function attempt(string $s, int $flags): ?string
{
    global $warn;
    $warn = [];
    try {
        Yaml::parse($s, $flags);
    } catch (ParseException) {
        // expected for invalid input
    } catch (\Throwable $e) {
        return get_class($e).' @'.basename($e->getFile()).':'.$e->getLine().' :: '.preg_replace('/\d+/', '#', substr($e->getMessage(), 0, 90));
    }
    if ($warn) {
        return 'WARNING @ '.preg_replace('/\d+/', '#', $warn[0]);
    }
    return null;
}

$seen = [];
$deadline = microtime(true) + 150;
for ($i = 0; $i < $iterations && microtime(true) < $deadline; ++$i) {
    $s = gen($tokens);
    $flags = $flagSets[mt_rand(0, count($flagSets) - 1)];
    $sig = attempt($s, $flags);
    if (null === $sig || isset($seen[$sig])) { continue; }
    // shrink
    $cur = $s;
    do {
        $changed = false;
        for ($k = 0; $k < strlen($cur); ++$k) {
            $cand = substr($cur, 0, $k).substr($cur, $k + 1);
            if (attempt($cand, $flags) === $sig) { $cur = $cand; $changed = true; break; }
        }
    } while ($changed);
    $seen[$sig] = [$cur, $flags];
}
echo 'iterations done: ', $i, ' distinct signatures: ', count($seen), "\n";
foreach ($seen as $sig => [$ex, $flags]) { echo $sig, "\tflags=", $flags, "\t", json_encode($ex, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n"; }
