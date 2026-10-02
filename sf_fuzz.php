<?php
// Exploration only: exception-contract fuzzing of Symfony components.
// A hit is a \Error (TypeError, ValueError, ...) or a PHP warning/notice/deprecation raised from
// user-controlled string input, instead of the component's documented exception.
require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\Finder\Gitignore;
use Symfony\Component\Finder\Glob;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Address;
use Symfony\Component\PropertyAccess\PropertyPath;
use Symfony\Component\Routing\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\UnicodeString;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as C;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Yaml\Yaml;

mt_srand((int) ($argv[1] ?? 1));
$iterations = (int) ($argv[2] ?? 3000);
$only = $argv[3] ?? null;
ini_set('memory_limit', '512M');
error_reporting(\E_ALL);
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$generic = ['a', 'b', 'ab', ' ', '  ', "\t", "\n", ':', '#', '-', '?', '!', '&', '*', '%', '@', '`', '|', '>', '<', '{', '}', '[', ']', '(', ')', ',', ';', '=', '.', '/', '"', "'", chr(92), '0', '1', '9', '_', '$', '+', '~', '^', 'é', "\u{200B}", "\r", 'null', 'true', '1.5', 'x', 'foo', '::', '--', '//'];
$pools = [
    'css' => ['a', 'div', '.c', '#i', '>', '+', '~', ' ', ':nth-child(', ')', '2n+1', 'odd', '[', ']', '=', '^=', '$=', '*=', '"x"', '*', '|', '::before', ':not(', ':first-child', ':lang(', 'en', 'n', '-', '(', ',', ':contains(', ':has(', ':is(', ':only-child', ':nth-last-of-type(', '@', chr(92), "\u{e9}"],
    'expr' => ['a', 'b', 'c', '1', '2.5', '"s"', "'t'", '+', '-', '*', '/', '%', '**', '~', '==', '!=', '<', '>', '<=', '>=', 'and', 'or', 'not', '!', '&&', '||', '?', ':', '??', '(', ')', '[', ']', '{', '}', ',', '.', '?.', ' ', 'in', 'not in', 'matches', '"^a"', 'contains', 'starts with', 'ends with', '..', 'null', 'true', 'false', '.x', '.y()', '->', '=>'],
    'dotenv' => ['A', 'B', '=', ' ', "\n", '"', "'", '$', '{', '}', '(', ')', '`', '#', 'export ', '${A}', '$(echo x)', '\\n', chr(92), ':-', ':=', '\\$', 'x', '1', "\r\n", ';', '\\"'],
    'email' => ['a', 'b', '@', '.', '<', '>', ' ', '"', '(', ')', ',', ';', ':', chr(92), '[', ']', 'example', 'com', '=?utf-8?B?YQ==?=', '=?', '?=', "\u{e9}", '-', '_', '+', '!', '#', "\t", "'", '127.0.0.1', 'IPv6:::1'],
    'http' => ['a', 'b', '=', ';', ',', ' ', '"', chr(92), 'q', '0.5', '1', '*', '/', '-', ':', '.', '%', '[', ']', '&', 'max-age', 'text/html', 'gzip', "\t", '(', ')', '<', '>', '@', '?', '#'],
    'path' => ['/', 'a', '{', '}', '}', '{', ':', '<', '>', '.', '*', '+', '?', '[', ']', '^', '$', '|', '-', '_', '=', 'id', 'name', '\\d+', '.+', '[^/]+', '(', ')', '!', '%', '#', "\u{e9}", 'a/b', chr(92)],
    'icu' => ['{', '}', ',', ' ', 'plural', 'select', 'number', 'date', 'selectordinal', 'one', 'other', '#', "'", '0', '1', '=', 'x', 'a', 'offset:', ':', '|', chr(92)],
    'html' => ['<', '>', '/', 'a', 'div', 'script', 'img', 'src=', 'href=', '"', "'", ' ', '=', 'javascript:', '&', '&amp;', ';', '#', 'style', 'onclick', '!', '--', '[', ']', 'svg', 'p', 'b', '</', '/>', "\n", 'data:', '&#x', 'x', '0', "\u{e9}", '<!--', '-->', '<![CDATA[', ']]>', '<?', '?>'],
    'param' => ['%', 'a', '.', '%%', '%a%', '%env(', ')%', 'env(', 'string:', 'int:', 'json:', 'FOO', ':', 'default:', ' ', 'x', '1', '-', 'base64:', 'csv:', 'url:', 'file:', 'trim:'],
];

function gen(array $pool): string
{
    $n = mt_rand(1, 7);
    $s = '';
    for ($i = 0; $i < $n; ++$i) { $s .= $pool[mt_rand(0, count($pool) - 1)]; }
    return $s;
}

$validator = Validation::createValidator();
$constraintFactories = [
    'Iban' => fn () => new C\Iban(), 'Bic' => fn () => new C\Bic(), 'Isbn' => fn () => new C\Isbn(), 'Issn' => fn () => new C\Issn(),
    'Luhn' => fn () => new C\Luhn(), 'CardScheme' => fn () => new C\CardScheme(schemes: ['VISA', 'AMEX']), 'Url' => fn () => new C\Url(), 'Email' => fn () => new C\Email(),
    'Uuid' => fn () => new C\Uuid(), 'Ulid' => fn () => new C\Ulid(), 'Locale' => fn () => new C\Locale(), 'Language' => fn () => new C\Language(), 'Country' => fn () => new C\Country(),
    'Currency' => fn () => new C\Currency(), 'Timezone' => fn () => new C\Timezone(), 'Ip' => fn () => new C\Ip(version: 'all'), 'Hostname' => fn () => new C\Hostname(),
    'Json' => fn () => new C\Json(), 'Cidr' => fn () => new C\Cidr(), 'CssColor' => fn () => new C\CssColor(formats: [C\CssColor::HEX_LONG, C\CssColor::HSL, C\CssColor::RGBA, C\CssColor::NAMED_COLORS]),
    'NoSuspiciousCharacters' => fn () => new C\NoSuspiciousCharacters(), 'Date' => fn () => new C\Date(), 'DateTime' => fn () => new C\DateTime(), 'Time' => fn () => new C\Time(),
    'Regex' => fn () => new C\Regex(pattern: '/^[a-z]+$/'), 'PasswordStrength' => fn () => new C\PasswordStrength(), 'Charset' => fn () => new C\Charset(encodings: ['ASCII']),
    'WordCount' => fn () => new C\WordCount(min: 1), 'Length' => fn () => new C\Length(min: 1, max: 5), 'ExpressionSyntax' => fn () => new C\ExpressionSyntax(), 'Range' => fn () => new C\Range(min: 1, max: 5),
];

$targets = [];
$t = function (string $name, callable $fn, string $pool = 'generic') use (&$targets) { $targets[$name] = [$fn, $pool]; };

$t('Yaml::parse', fn ($s) => Yaml::parse($s), 'generic');
$t('Yaml::parse(flags)', fn ($s) => Yaml::parse($s, Yaml::PARSE_CUSTOM_TAGS | Yaml::PARSE_CONSTANT | Yaml::PARSE_DATETIME | Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE));
$t('Dotenv::parse', fn ($s) => (new Dotenv())->parse($s), 'dotenv');
$t('ExpressionLanguage::parse', fn ($s) => (new ExpressionLanguage())->parse($s, ['a', 'b', 'c']), 'expr');
$t('ExpressionLanguage::evaluate', fn ($s) => (new ExpressionLanguage())->evaluate($s, ['a' => 1, 'b' => 'x', 'c' => [1, 2]]), 'expr');
$t('ExpressionLanguage::compile', fn ($s) => (new ExpressionLanguage())->compile($s, ['a', 'b', 'c']), 'expr');
$t('Address::create', fn ($s) => Address::create($s), 'email');
$t('Address::createArray', fn ($s) => Address::createArray([$s, 'x@y.z']), 'email');
$t('Uuid::fromString', fn ($s) => Uuid::fromString($s));
$t('Ulid::fromString', fn ($s) => Ulid::fromString($s));
$t('HeaderUtils::split', fn ($s) => HeaderUtils::split($s, ',;='), 'http');
$t('HeaderUtils::parseQuery', fn ($s) => HeaderUtils::parseQuery($s), 'http');
$t('HeaderUtils::unquote', fn ($s) => HeaderUtils::unquote($s), 'http');
$t('Cookie::fromString', fn ($s) => Cookie::fromString($s), 'http');
$t('AcceptHeader::fromString', fn ($s) => AcceptHeader::fromString($s)->all(), 'http');
$t('IpUtils::checkIp', fn ($s) => IpUtils::checkIp('127.0.0.1', $s) | IpUtils::checkIp('::1', $s) | IpUtils::checkIp($s, ['10.0.0.0/8', '::/0']), 'http');
$t('Request::create', fn ($s) => Request::create($s), 'http');
$t('Route::compile', fn ($s) => (new Route($s))->compile(), 'path');
$t('Route::compile(host)', fn ($s) => (new Route('/x', [], [], [], $s))->compile(), 'path');
$t('StringInput', fn ($s) => new StringInput($s), 'http');
$t('StringInput::bind', function ($s) {
    $def = new InputDefinition([new InputArgument('a'), new InputArgument('b', InputArgument::IS_ARRAY), new InputOption('foo', 'f', InputOption::VALUE_OPTIONAL), new InputOption('bar', 'b', InputOption::VALUE_NONE)]);
    $i = new StringInput($s); $i->bind($def); return $i->getArguments();
}, 'http');
$t('OutputFormatter::format', fn ($s) => (new OutputFormatter(true))->format($s), 'html');
$t('OutputFormatter::formatAndWrap', fn ($s) => (new OutputFormatter(true))->formatAndWrap($s, 7), 'html');
$t('UnicodeString ops', fn ($s) => (new UnicodeString($s))->wordwrap(5, "\n", true)->truncate(4, '…')->snake()->camel()->title()->ascii()->folded()->width());
$t('AsciiSlugger', fn ($s) => (new AsciiSlugger())->slug($s));
$t('Glob::toRegex', fn ($s) => preg_match(Glob::toRegex($s), 'a/b/c.txt') !== false ?: throw new \RuntimeException('bad regex'), 'path');
$t('Gitignore::toRegex', fn ($s) => preg_match(Gitignore::toRegex($s), 'a/b/c.txt') !== false ?: throw new \RuntimeException('bad regex'), 'path');
$t('CssSelectorConverter', fn ($s) => (new CssSelectorConverter())->toXPath($s), 'css');
$t('PropertyPath', fn ($s) => new PropertyPath($s), 'path');
$t('MessageFormatter', fn ($s) => (new \Symfony\Component\Translation\Formatter\IntlFormatter())->formatIntl($s, 'en', ['x' => 1, 'a' => 'b']), 'icu');
$t('HtmlSanitizer', fn ($s) => (new \Symfony\Component\HtmlSanitizer\HtmlSanitizer((new \Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig())->allowSafeElements()->allowRelativeLinks()->allowRelativeMedias()))->sanitize($s), 'html');
$t('HtmlSanitizer(all)', fn ($s) => (new \Symfony\Component\HtmlSanitizer\HtmlSanitizer((new \Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig())->allowStaticElements()->forceHttpsUrls()->allowLinkSchemes(['https', 'mailto'])->withMaxInputLength(0)->allowAttribute('style', '*')))->sanitize($s), 'html');
$t('ParameterBag::resolveValue', fn ($s) => (new \Symfony\Component\DependencyInjection\ParameterBag\ParameterBag(['a' => 'x', 'b' => ['y']]))->resolveValue($s), 'param');
$t('EnvPlaceholderParameterBag', function ($s) { $b = new \Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag(['a' => 'x']); return $b->resolveValue($s); }, 'param');
$t('CsvEncoder::decode', fn ($s) => (new \Symfony\Component\Serializer\Encoder\CsvEncoder())->decode($s, 'csv'), 'http');
$t('XmlEncoder::decode', fn ($s) => (new \Symfony\Component\Serializer\Encoder\XmlEncoder())->decode($s, 'xml'), 'html');
$t('Ldap escape', fn ($s) => \Symfony\Component\Ldap\Ldap::escape($s, '', \Symfony\Component\Ldap\LdapInterface::ESCAPE_DN) . \Symfony\Component\Ldap\Ldap::escape($s, '', \Symfony\Component\Ldap\LdapInterface::ESCAPE_FILTER));
$t('TypeResolver', fn ($s) => \Symfony\Component\TypeInfo\TypeResolver\TypeResolver::create()->resolve($s), 'generic');
$t('Workflow Transition/Marking', fn ($s) => new \Symfony\Component\Workflow\Transition($s, 'a', 'b'));
$t('Mime Email addrs', fn ($s) => (new \Symfony\Component\Mime\Email())->from($s)->to($s)->subject($s)->text($s)->toString(), 'email');
$t('MimeTypes::guessMimeType', fn ($s) => (new \Symfony\Component\Mime\MimeTypes())->getExtensions($s));
$t('Mime\\Header\\Headers', function ($s) { $h = new \Symfony\Component\Mime\Header\Headers(); $h->addTextHeader('X-A', $s); $h->addMailboxListHeader('To', [$s]); $h->addIdHeader('Message-ID', $s); $h->addPathHeader('Return-Path', $s); $h->addParameterizedHeader('Content-Type', $s, ['a' => $s]); return $h->toString(); }, 'email');
$t('Cache key validate', fn ($s) => \Symfony\Component\Cache\CacheItem::validateKey($s));
$t('Intl Countries/Locales', fn ($s) => \Symfony\Component\Intl\Countries::getName(strtoupper($s)));
$t('Emoji transliterate', fn ($s) => \Symfony\Component\Emoji\EmojiTransliterator::create('emoji-en')->transliterate($s));
$t('VarExporter::export', fn ($s) => \Symfony\Component\VarExporter\VarExporter::export([$s => $s, 'o' => new \ArrayObject([$s])]));
$t('Lock Key', fn ($s) => (string) new \Symfony\Component\Lock\Key($s));
$t('Process fromShellCommandline', fn ($s) => \Symfony\Component\Process\Process::fromShellCommandline($s)->getCommandLine());
$t('ExecutableFinder', fn ($s) => (new \Symfony\Component\Process\ExecutableFinder())->find($s));
$t('Filesystem Path', fn ($s) => [\Symfony\Component\Filesystem\Path::canonicalize($s), \Symfony\Component\Filesystem\Path::getDirectory($s), \Symfony\Component\Filesystem\Path::makeRelative($s, '/a/b'), \Symfony\Component\Filesystem\Path::isAbsolute($s), \Symfony\Component\Filesystem\Path::getExtension($s), \Symfony\Component\Filesystem\Path::changeExtension($s, 'x'), \Symfony\Component\Filesystem\Path::join($s, 'x'), \Symfony\Component\Filesystem\Path::getLongestCommonBasePath($s, '/a/b')], 'path');
$t('Filesystem makePathRelative', fn ($s) => (new \Symfony\Component\Filesystem\Filesystem())->makePathRelative($s, '/a/b'), 'path');
$t('Scheduler cron', fn ($s) => \Symfony\Component\Scheduler\Trigger\CronExpressionTrigger::fromSpec($s)->getNextRunDate(new \DateTimeImmutable('2026-01-01')), 'generic');
$t('Clock/DatePoint', fn ($s) => new \Symfony\Component\Clock\DatePoint($s));
$t('Mailer Dsn', fn ($s) => \Symfony\Component\Mailer\Transport\Dsn::fromString($s), 'http');
$t('Notifier Dsn', fn ($s) => \Symfony\Component\Notifier\Transport\Dsn::fromString($s), 'http');
$t('Messenger Dsn?', fn ($s) => \Symfony\Component\Messenger\Transport\Doctrine\Connection::buildConfiguration($s), 'http');
$t('HttpClient UriTemplate/Url', fn ($s) => \Symfony\Component\HttpClient\HttpClientTrait::class ? (new class { use \Symfony\Component\HttpClient\HttpClientTrait { parseUrl as public pu; resolveUrl as public ru; } })->pu($s) : null, 'http');
$t('WebLink HttpHeaderParser', fn ($s) => (new \Symfony\Component\WebLink\HttpHeaderParser())->parse($s)->getLinks(), 'http');
$t('Asset UrlPackage', fn ($s) => (new \Symfony\Component\Asset\UrlPackage('https://x.example/', new \Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy()))->getUrl($s), 'path');
$t('DomCrawler Link/Form uri', fn ($s) => (new \Symfony\Component\DomCrawler\Crawler('<a href="'.str_replace('"', '', $s).'">x</a>', 'http://x.example/a/b'))->filter('a')->link()->getUri(), 'path');
$t('BrowserKit CookieJar', fn ($s) => (new \Symfony\Component\BrowserKit\CookieJar())->updateFromSetCookie([$s], 'http://x.example/'), 'http');
$t('Config Glob/FileLocator', fn ($s) => (new \Symfony\Component\Config\FileLocator(['/tmp']))->locate($s), 'path');
$t('Runtime/Dotenv env?', fn ($s) => (new Dotenv())->populate(['A' => $s]), 'dotenv');
$t('Security Http utils', fn ($s) => (new \Symfony\Component\Security\Http\HttpUtils())->generateUri(Request::create('/x'), $s), 'path');
foreach ($constraintFactories as $cname => $factory) {
    $t('Validator '.$cname, function ($s) use ($validator, $factory) { return count($validator->validate($s, $factory())); });
}

function sig(\Throwable $e): string
{
    $loc = basename($e->getFile()).':'.$e->getLine();
    $msg = preg_replace('/\d+/', '#', preg_replace('/"[^"]*"/', '"…"', $e->getMessage()));
    return get_class($e).' @ '.$loc.' :: '.substr($msg, 0, 110);
}

function isInteresting(\Throwable $e): bool
{
    if ($e instanceof \ErrorException) {
        // a PHP warning/notice/deprecation raised from library code
        return true;
    }
    return $e instanceof \Error;
}

$report = [];
foreach ($targets as $name => [$fn, $poolName]) {
    if ($only && !str_contains($name, $only)) { continue; }
    try { $fn('a'); } catch (\Throwable $e) {
        if ($e instanceof \Error && (str_contains($e->getMessage(), 'not found') || str_contains($e->getMessage(), 'undefined method') || str_contains($e->getMessage(), 'Call to private') || str_contains($e->getMessage(), 'non-static'))) { echo "SKIPPED	$name	".$e->getMessage()."
"; continue; }
    }
    $pool = 'generic' === $poolName ? $generic : array_merge($pools[$poolName], array_slice($generic, 0, 12));
    $seen = [];
    $deadline = microtime(true) + 25;
    for ($i = 0; $i < $iterations && microtime(true) < $deadline; ++$i) {
        $s = gen($pool);
        try {
            $fn($s);
        } catch (\Throwable $e) {
            if (!isInteresting($e)) { continue; }
            $sg = sig($e);
            if (isset($seen[$sg])) { continue; }
            // shrink by deleting characters while the same signature is reproduced
            $cur = $s;
            do {
                $changed = false;
                for ($k = 0; $k < strlen($cur); ++$k) {
                    $cand = substr($cur, 0, $k).substr($cur, $k + 1);
                    if ('' === $cand) { continue; }
                    try { $fn($cand); } catch (\Throwable $e2) { if (isInteresting($e2) && sig($e2) === $sg) { $cur = $cand; $changed = true; break; } }
                }
            } while ($changed);
            $seen[$sg] = $cur;
        }
    }
    foreach ($seen as $sg => $ex) { $report[] = sprintf("%s\t%s\t%s", $name, $sg, json_encode($ex, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)); }
}
echo implode("\n", $report), "\n";
echo "targets: ", count($targets), "\n";
