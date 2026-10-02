<?php
require __DIR__.'/vendor/autoload.php';

use Symfony\Component\String\ByteString;
use Symfony\Component\String\CodePointString;
use Symfony\Component\String\UnicodeString;

error_reporting(\E_ALL);
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) { return false; }
    throw new ErrorException($str, 0, $no, $file, $line);
});

$alphabet = ['a', 'b', ' ', "\n"];
$classes = ['ByteString' => fn ($s) => new ByteString($s), 'UnicodeString' => fn ($s) => new UnicodeString($s), 'CodePointString' => fn ($s) => new CodePointString($s)];
$breaks = ["\n", '<br>', "\r\n", '#'];
$results = [];

function gen(array $alphabet, int $len, string $prefix, callable $cb): void
{
    if (0 === $len) { $cb($prefix); return; }
    foreach ($alphabet as $c) { gen($alphabet, $len - 1, $prefix.$c, $cb); }
}

$count = 0;
for ($len = 1; $len <= 8; ++$len) {
    gen($alphabet, $len, '', function (string $s) use ($classes, $breaks, &$results, &$count) {
        foreach ($breaks as $break) {
            foreach ([1, 2, 3, 5] as $width) {
                foreach ([false, true] as $cut) {
                    $expected = wordwrap($s, $width, $break, $cut);
                    foreach ($classes as $cn => $mk) {
                        ++$count;
                        try {
                            $got = (string) $mk($s)->wordwrap($width, $break, $cut);
                            if ($got === $expected) { continue; }
                            $kind = 'DIFF';
                            $detail = sprintf('expected=%s got=%s', json_encode($expected), json_encode($got));
                        } catch (\Throwable $e) {
                            $kind = 'THROW '.get_class($e);
                            $detail = $e->getMessage().' @'.basename($e->getFile()).':'.$e->getLine();
                        }
                        $key = $cn.'|'.$kind.'|'.json_encode($break).'|'.$width.'|'.json_encode($cut);
                        if (!isset($results[$key]) || strlen($s) < strlen($results[$key][0])) { $results[$key] = [$s, $detail]; }
                    }
                }
            }
        }
    });
}
ksort($results);
echo "checked $count combinations, failing groups: ", count($results), "\n";
foreach ($results as $key => [$s, $detail]) { echo $key, "\t", json_encode($s), "\t", $detail, "\n"; }
