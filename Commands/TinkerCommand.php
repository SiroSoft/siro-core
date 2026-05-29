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
        $historyFile = $this->getHistoryFile();

        if (function_exists('readline_read_history')) {
            @readline_read_history($historyFile);
        }

        $this->printHeader();

        while (true) {
            $code = $this->readLine("  \e[36msiro\e[0m> ");

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
        $this->write('  \e[90mbye\e[0m');

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
            $result = eval("return $code;");
        } catch (Throwable $e) {
            $caught = $e;
        }

        if ($caught !== null) {
            try {
                ob_start();
                eval("$code;");
                $output = ob_get_clean();
                $elapsed = (microtime(true) - $start) * 1000;
                $queries = $this->queryCount();
                $qStr = $queries > 0 ? ' · ' . $queries . 'q' : '';
                if ($output !== '' && $output !== false) {
                    $this->write('  \e[32m✓\e[0m  ' . trim($output) . '  \e[90m(' . number_format($elapsed, 2) . 'ms' . $qStr . ')\e[0m');
                }
                $caught = null;
            } catch (Throwable $e2) {
                $elapsed = (microtime(true) - $start) * 1000;
                $queries = $this->queryCount();
                $qStr = $queries > 0 ? ' · ' . $queries . 'q' : '';
                $this->write('  \e[31m✗\e[0m  ' . $e2->getMessage() . '  \e[90m(' . number_format($elapsed, 2) . 'ms' . $qStr . ')\e[0m');
            }
        }

        restore_error_handler();

        if ($caught !== null) {
            $elapsed = (microtime(true) - $start) * 1000;
            $queries = $this->queryCount();
            $qStr = $queries > 0 ? ' · ' . $queries . 'q' : '';
            $this->write('  \e[31m✗\e[0m  ' . $caught->getMessage() . '  \e[90m(' . number_format($elapsed, 2) . 'ms' . $qStr . ')\e[0m');
            return;
        }

        $elapsed = (microtime(true) - $start) * 1000;
        $queries = $this->queryCount();
        $qStr = $queries > 0 ? ' · ' . $queries . 'q' : '';

        if ($result !== null) {
            $this->write('  \e[32m✓\e[0m  ' . $this->render($result) . '  \e[90m(' . number_format($elapsed, 2) . 'ms' . $qStr . ')\e[0m');
        } elseif ($queries > 0) {
            $this->write('  \e[32m✓\e[0m  ' . '  \e[90m(' . number_format($elapsed, 2) . 'ms' . $qStr . ')\e[0m');
        }
    }

    private function render(mixed $value): string
    {
        if (is_null($value)) {
            return '\e[90mnull\e[0m';
        }
        if (is_bool($value)) {
            return $value ? '\e[94mtrue\e[0m' : '\e[94mfalse\e[0m';
        }
        if (is_int($value) || is_float($value)) {
            return '\e[93m' . ((string) $value) . '\e[0m';
        }
        if (is_string($value)) {
            return '\e[92m"' . $this->truncate($value, 120) . '"\e[0m';
        }
        if ($value instanceof \Siro\Core\Model) {
            return $this->renderModel($value);
        }
        if ($value instanceof \Siro\Core\Collection) {
            return '\e[96mCollection\e[0m(' . ((string) $value->count()) . ')';
        }
        if (is_array($value)) {
            return $this->renderArray($value);
        }
        if (is_object($value)) {
            return '\e[96m' . $value::class . '\e[0m';
        }
        if (is_resource($value)) {
            return '\e[90mresource\e[0m';
        }
        return '\e[90m?\e[0m';
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
        $label = '\e[96m' . $short . '\e[0m { ' . implode(', ', $fields);
        if (count($attrs) > 6) { $label .= ', ...'; }
        $label .= ' }';
        return $label;
    }

    /** @param array<mixed> $arr */
    private function renderArray(array $arr): string
    {
        if ($arr === []) { return '\e[90m[]\e[0m'; }

        $isList = array_is_list($arr);
        if ($isList && count($arr) <= 8) {
            $items = array_map(fn (mixed $v): string => $this->render($v), $arr);
            return '[' . implode(', ', $items) . ']';
        }
        if ($isList) {
            return '\e[90m[' . ((string) count($arr)) . ' items]\e[0m';
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
        if ($lower === 'clear' || $lower === 'cls') { echo "\033[2J\033[H"; return true; }
        return false;
    }

    private function showHelp(): void
    {
        $this->write('');
        $this->write('  \e[36mSiro Tinker\e[0m — PHP playground in app context');
        $this->write('  ' . str_repeat('─', 44));
        $this->write('');
        $this->write('  Type any PHP, available by default:');
        $this->write('    \e[96mUser\e[0m, \e[96mProduct\e[0m, \e[96mCategory\e[0m, \e[96mPost\e[0m, \e[96mOrder\e[0m, \e[96mTag\e[0m');
        $this->write('    \e[96mDB\e[0m, \e[96mCache\e[0m, \e[96mConfig\e[0m, \e[96mRoute\e[0m, \e[96mQueue\e[0m, \e[96mEvent\e[0m, \e[96mMail\e[0m');
        $this->write('    \e[96mHash\e[0m, \e[96mStr\e[0m, \e[96mValidator\e[0m, \e[96mLang\e[0m, \e[96mStorage\e[0m, \e[96mLog\e[0m, \e[96mEncrypter\e[0m');
        $this->write('');
        $this->write('  \e[90mShortcuts:\e[0m');
        $this->write('    \e[36mdb\e[0m      Show database connection & query stats');
        $this->write('    \e[36mroutes\e[0m  Show registered routes count');
        $this->write('    \e[36mvars\e[0m    Show available context variables');
        $this->write('    \e[36mclear\e[0m   Clear screen');
        $this->write('    \e[36mexit\e[0m    Exit tinker');
        $this->write('');
        $this->write('  \e[90mExamples:\e[0m');
        $this->write('    \e[32mUser::count()\e[0m');
        $this->write('    \e[32mUser::where("email", "a@b.com")->first()\e[0m');
        $this->write('    \e[32mCache::remember("key", 60, fn() => "hello")\e[0m');
        $this->write('    \e[32mConfig::get("app.name")\e[0m');
        $this->write('');
    }

    private function showDb(): void
    {
        try {
            $driver = Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $version = Database::connection()->getAttribute(\PDO::ATTR_SERVER_VERSION);
            $driverName = is_string($driver) ? $driver : '?';
            $serverVersion = is_string($version) ? $version : '?';
            $this->write('  \e[36mdb\e[0m  ' . $driverName . ' ' . $serverVersion);
        } catch (Throwable $e) {
            $this->write('  \e[31m✗\e[0m  ' . $e->getMessage());
        }
    }

    private function showRoutes(): void
    {
        $container = Container::getInstance();
        if (!$container->has(Router::class)) {
            $this->write('  \e[90mno router\e[0m');
            return;
        }
        $router = $container->make(Router::class);
        if (!$router instanceof Router) {
            $this->write('  \e[90mno router\e[0m');
            return;
        }
        $this->write('  \e[36mroutes\e[0m  ' . ((string) count($router->getRoutes())) . ' registered');
    }

    private function showVars(): void
    {
        $this->write('  \e[90mCore classes available in context:\e[0m');
        $this->write('    \e[96mDB\e[0m, \e[96mCache\e[0m, \e[96mConfig\e[0m, \e[96mRoute\e[0m, \e[96mQueue\e[0m, \e[96mEvent\e[0m');
        $this->write('    \e[96mMail\e[0m, \e[96mHash\e[0m, \e[96mStr\e[0m, \e[96mValidator\e[0m, \e[96mLang\e[0m, \e[96mStorage\e[0m, \e[96mLog\e[0m, \e[96mEncrypter\e[0m');
        $this->write('  \e[90mModels (if exist):\e[0m');
        $this->write('    \e[96mUser\e[0m, \e[96mProduct\e[0m, \e[96mCategory\e[0m, \e[96mPost\e[0m, \e[96mOrder\e[0m, \e[96mTag\e[0m');
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
        $this->write('  \e[36m╔══════════════════════════════════════╗');
        $this->write('  ║       siro tinker · v' . Console::getVersion() . '       ║');
        $this->write('  ║  php playground · app context        ║');
        $this->write('  ╚══════════════════════════════════════╝');
        $this->write('\e[0m');
        $this->write('  \e[90mtype  help  for shortcuts ·  exit  to quit\e[0m');
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
