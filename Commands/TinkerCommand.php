<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Console;
use Siro\Core\Container;
use Siro\Core\Database;
use Siro\Core\DB\DatabaseInstance;
use Siro\Core\Router;
use Throwable;

final class TinkerCommand implements CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath = '') {}

    public function run(array $args): int
    {
        return $this->execute($args, new Console($this->basePath));
    }

    /** @param array<int, string> $args */
    public function execute(array $args, Console $console): int
    {
        if (php_sapi_name() !== 'cli') {
            $this->write('Tinker can only be run in CLI mode.');
            return 1;
        }

        $historyFile = $this->getHistoryFile();

        if (function_exists('readline_read_history')) {
            @readline_read_history($historyFile);
        }

        $this->printHeader();

        while (true) {
            $code = $this->readLine("  [36msiro[0m> ");

            if ($code === null) { break; }

            $trimmed = trim($code);
            if ($trimmed === '') { continue; }

            $lower = strtolower($trimmed);
            if (in_array($lower, ['exit', 'quit', 'q', '\\q'], true)) { break; }

            if ($this->handleShortcut($trimmed)) { continue; }

            $this->execCode($trimmed);

            if (function_exists('readline_add_history')) {
                readline_add_history($trimmed);
            }
        }

        if (function_exists('readline_write_history')) {
            @readline_write_history($historyFile);
        }

        $this->write('');
        $this->write('  [90mbye[0m');

        return 0;
    }

    private bool $tinkerCaptureEnabled = false;

    private function enableQueryCapture(): void
    {
        if ($this->tinkerCaptureEnabled) { return; }
        $this->tinkerCaptureEnabled = true;
        try {
            Database::connection()->exec('SELECT 1');
        } catch (\Throwable) {
        }
    }

    private function queryCount(): int
    {
        try {
            $queries = Database::getCapturedQueries();
            $count = count($queries);
            Database::resetCapturedQueries();
            return $count;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function execCode(string $code): void
    {
        $start = microtime(true);
        $this->enableQueryCapture();

        $result = null;
        $caught = null;

        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_time_limit(30);
        try {
            $result = @eval("return $code;");
        } catch (Throwable $e) {
            $caught = $e;
        }

        if ($caught !== null) {
            $elapsed = (microtime(true) - $start) * 1000;
            $queries = $this->queryCount();
            $qStr = $queries > 0 ? ' · ' . $queries . 'q' : '';
            $this->write('  [31m✗[0m  ' . $caught->getMessage() . '  [90m(' . number_format($elapsed, 2) . 'ms' . $qStr . ')[0m');
            restore_error_handler();
            return;
        }

        restore_error_handler();

        $elapsed = (microtime(true) - $start) * 1000;
        $queries = $this->queryCount();
        $qStr = $queries > 0 ? ' · ' . $queries . 'q' : '';

        if ($result !== null) {
            $this->write('  [32m✓[0m  ' . $this->render($result) . '  [90m(' . number_format($elapsed, 2) . 'ms' . $qStr . ')[0m');
        } elseif ($queries > 0) {
            $this->write('  [32m✓[0m  ' . '  [90m(' . number_format($elapsed, 2) . 'ms' . $qStr . ')[0m');
        }
    }

    private function render(mixed $value): string
    {
        if (is_null($value)) {
            return '[90mnull[0m';
        }
        if (is_bool($value)) {
            return $value ? '[94mtrue[0m' : '[94mfalse[0m';
        }
        if (is_int($value) || is_float($value)) {
            return '[93m' . ((string) $value) . '[0m';
        }
        if (is_string($value)) {
            return '[92m"' . $this->truncate($value, 120) . '"[0m';
        }
        if ($value instanceof \Siro\Core\Model) {
            return $this->renderModel($value);
        }
        if ($value instanceof \Siro\Core\Collection) {
            return '[96mCollection[0m(' . ((string) $value->count()) . ')';
        }
        if (is_array($value)) {
            return $this->renderArray($value);
        }
        if (is_object($value)) {
            return '[96m' . $value::class . '[0m';
        }
        if (is_resource($value)) {
            return '[90mresource[0m';
        }
        return '[90m?[0m';
    }

    private function renderModel(\Siro\Core\Model $model): string
    {
        $short = basename(str_replace('\\', '/', $model::class));
        $attrs = $model->toArray();
        /** @var array<int, string> $keys */
        $keys = array_slice(array_keys($attrs), 0, 6);
        $fields = [];
        foreach ($keys as $k) {
            $v = $attrs[$k] ?? '';
            $fields[] = $k . ': ' . (is_string($v) ? $this->truncate($v, 40) : $this->render($v));
        }
        $label = '[96m' . $short . '[0m { ' . implode(', ', $fields);
        if (count($attrs) > 6) { $label .= ', ...'; }
        $label .= ' }';
        return $label;
    }

    /** @param array<mixed> $arr */
    private function renderArray(array $arr): string
    {
        if ($arr === []) { return '[90m[][0m'; }

        $isList = array_is_list($arr);
        if ($isList && count($arr) <= 8) {
            $items = array_map(fn (mixed $v): string => $this->render($v), $arr);
            return '[' . implode(', ', $items) . ']';
        }
        if ($isList) {
            return '[90m[' . ((string) count($arr)) . ' items][0m';
        }
        $pairs = [];
        $i = 0;
        foreach ($arr as $k => $v) {
            if ($i++ >= 5) { $pairs[] = '...'; break; }
            $pairs[] = (is_string($k) ? $k : (string) $k) . ': ' . $this->render($v);
        }
        return '{ ' . implode(', ', $pairs) . ' }';
    }

    private function truncate(string $s, int $len): string
    {
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len) . '...' : $s;
    }

    private function handleShortcut(string $code): bool
    {
        $lower = strtolower($code);
        if ($lower === 'help' || $lower === '?') { $this->showHelp(); return true; }
        if ($lower === 'db') { $this->showDb(); return true; }
        if ($lower === 'routes') { $this->showRoutes(); return true; }
        if ($lower === 'vars') { $this->showVars(); return true; }
        if ($lower === 'clear' || $lower === 'cls') { echo "[2J[H"; return true; }
        return false;
    }

    private function showHelp(): void
    {
        $this->write('');
        $this->write('  [36mSiro Tinker[0m — PHP playground in app context');
        $this->write('  ' . str_repeat('─', 44));
        $this->write('');
        $this->write('  Type any PHP, available by default:');
        $this->write('    [96mUser[0m, [96mProduct[0m, [96mCategory[0m, [96mPost[0m, [96mOrder[0m, [96mTag[0m');
        $this->write('    [96mDB[0m, [96mCache[0m, [96mConfig[0m, [96mRoute[0m, [96mQueue[0m, [96mEvent[0m, [96mMail[0m');
        $this->write('    [96mHash[0m, [96mStr[0m, [96mValidator[0m, [96mLang[0m, [96mStorage[0m, [96mLog[0m, [96mEncrypter[0m');
        $this->write('');
        $this->write('  [90mShortcuts:[0m');
        $this->write('    [36mdb[0m      Show database connection & query stats');
        $this->write('    [36mroutes[0m  Show registered routes count');
        $this->write('    [36mvars[0m    Show available context variables');
        $this->write('    [36mclear[0m   Clear screen');
        $this->write('    [36mexit[0m    Exit tinker');
        $this->write('');
        $this->write('  [90mExamples:[0m');
        $this->write('    [32mUser::count()[0m');
        $this->write('    [32mUser::where("email", "a@b.com")->first()[0m');
        $this->write('    [32mCache::remember("key", 60, fn() => "hello")[0m');
        $this->write('    [32mConfig::get("app.name")[0m');
        $this->write('');
    }

    private function showDb(): void
    {
        try {
            $driver = Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $version = Database::connection()->getAttribute(\PDO::ATTR_SERVER_VERSION);
            $driverName = is_string($driver) ? $driver : '?';
            $serverVersion = is_string($version) ? $version : '?';
            $this->write('  [36mdb[0m  ' . $driverName . ' ' . $serverVersion);
        } catch (Throwable $e) {
            $this->write('  [31m✗[0m  ' . $e->getMessage());
        }
    }

    private function showRoutes(): void
    {
        $container = Container::getInstance();
        if (!$container->has(Router::class)) {
            $this->write('  [90mno router[0m');
            return;
        }
        $router = $container->make(Router::class);
        if (!$router instanceof Router) {
            $this->write('  [90mno router[0m');
            return;
        }
        $this->write('  [36mroutes[0m  ' . ((string) count($router->getRoutes())) . ' registered');
    }

    private function showVars(): void
    {
        $this->write('  [90mCore classes available in context:[0m');
        $this->write('    [96mDB[0m, [96mCache[0m, [96mConfig[0m, [96mRoute[0m, [96mQueue[0m, [96mEvent[0m');
        $this->write('    [96mMail[0m, [96mHash[0m, [96mStr[0m, [96mValidator[0m, [96mLang[0m, [96mStorage[0m, [96mLog[0m, [96mEncrypter[0m');
        $this->write('  [90mModels (if exist):[0m');
        $this->write('    [96mUser[0m, [96mProduct[0m, [96mCategory[0m, [96mPost[0m, [96mOrder[0m, [96mTag[0m');
    }

    private function readLine(string $prompt): ?string
    {
        if (function_exists('readline')) {
            $line = readline($prompt);
            return $line !== false ? $line : null;
        }
        echo $prompt;
        $line = fgets(\STDIN);
        return $line !== false ? rtrim($line, "\n\r") : null;
    }

    private function printHeader(): void
    {
        $this->write('');
        $this->write('  [36m╔══════════════════════════════════════╗');
        $this->write('  ║       siro tinker · v' . Console::getVersion() . '       ║');
        $this->write('  ║  php playground · app context        ║');
        $this->write('  ╚══════════════════════════════════════╝');
        $this->write('[0m');
        $this->write('  [90mtype  help  for shortcuts ·  exit  to quit[0m');
        $this->write('');
    }

    private function getHistoryFile(): string
    {
        $dir = defined('BASE_PATH') && is_string(BASE_PATH)
            ? BASE_PATH
            : (defined('SIRO_BASE_PATH') && is_string(SIRO_BASE_PATH) ? SIRO_BASE_PATH : '');
        $storage = $dir !== '' ? $dir . DIRECTORY_SEPARATOR . 'storage' : sys_get_temp_dir();
        if (!is_dir($storage)) { @mkdir($storage, 0775, true); }
        return $storage . DIRECTORY_SEPARATOR . '.tinker_history';
    }
}
