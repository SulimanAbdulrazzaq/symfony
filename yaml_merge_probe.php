<?php
// Exploration only: how Yaml handles merge keys in block and flow style, with and without PARSE_OBJECT_FOR_MAP.
require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

error_reporting(\E_ALL);
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) { return false; }
    throw new ErrorException($str, 0, $no, $file, $line);
});

$cases = [
    'flow alias merge' => "base: &base {a: 1, b: 2}\nderived: {<<: *base, c: 3}\n",
    'flow alias merge, sequence of aliases' => "x: &x {a: 1}\ny: &y {b: 2}\nz: {<<: [*x, *y], c: 3}\n",
    'flow inline mapping merge' => "z: {<<: {a: 1}, c: 3}\n",
    'flow inline sequence of mappings merge' => "z: {<<: [{a: 1}, {b: 2}], c: 3}\n",
    'block alias merge' => "base: &base\n  a: 1\n  b: 2\nderived:\n  <<: *base\n  c: 3\n",
    'block sequence of aliases merge' => "x: &x\n  a: 1\ny: &y\n  b: 2\nz:\n  <<: [*x, *y]\n  c: 3\n",
    'block alias to flow mapping merge' => "base: &base {a: 1, b: 2}\nderived:\n  <<: *base\n  c: 3\n",
    'block sequence of flow aliases merge' => "x: &x {a: 1}\ny: &y {b: 2}\nz:\n  <<: [*x, *y]\n  c: 3\n",
    'merge in a sequence item (flow)' => "base: &base {a: 1}\nlist:\n  - {<<: *base, b: 2}\n",
    'quoted merge key (block)' => "'<<': value\nother: 1\n",
    'quoted merge key (flow)' => "{'<<': value, other: 1}\n",
    'double-quoted merge key (flow)' => "{\"<<\": value}\n",
    'scalar merge value (flow)' => "{<<: x, a: 1}\n",
    'scalar merge value (block)' => "<<: x\na: 1\n",
    'sequence of scalars merge (flow)' => "{<<: [1, 2], a: 1}\n",
    'sequence of scalars merge (block)' => "<<: [1, 2]\na: 1\n",
    'null merge value (flow)' => "{<<: , a: 1}\n",
    'null merge value (block)' => "<<:\na: 1\n",
    'tilde merge value (block)' => "<<: ~\na: 1\n",
    'anchor on scalar merge value (block)' => "<<: &r x\na: 1\n",
];
$flagSets = ['0' => 0, 'OBJECT_FOR_MAP' => Yaml::PARSE_OBJECT_FOR_MAP];

foreach ($cases as $name => $yaml) {
    foreach ($flagSets as $fname => $flags) {
        try {
            $r = Yaml::parse($yaml, $flags);
            $out = 'OK '.json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        } catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
            $out = 'ParseException: '.$e->getMessage();
        } catch (\Throwable $e) {
            $out = '!!! '.get_class($e).': '.substr($e->getMessage(), 0, 120).' @'.basename($e->getFile()).':'.$e->getLine();
        }
        printf("%-44s flags=%-15s %s\n", $name, $fname, $out);
    }
}
