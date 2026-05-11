<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class BenchmarkCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    /** @phpstan-ignore property.onlyWritten */
    private string $basePath;
    private int $iterations = 100;
    private int $warmup = 10;
    private bool $jsonOutput = false;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $this->parseArgs($args);

        $this->write('');
        $this->write('  ⚡ SiroPHP Benchmark v1.0.0');
        $this->write('  ' . str_repeat('=', 58));
        $this->write('');

        $benchmarks = $this->runBenchmarks();

        if ($this->jsonOutput) {
            $this->outputJson($benchmarks);
            return 0;
        }

        $this->outputTable($benchmarks);
        return 0;
    }

    /**
     * @param array<int, string> $args
     */
    private function parseArgs(array $args): void
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--iterations=')) {
                $this->iterations = (int) substr($arg, 13);
            } elseif (str_starts_with($arg, '--warmup=')) {
                $this->warmup = (int) substr($arg, 9);
            } elseif ($arg === '--json') {
                $this->jsonOutput = true;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runBenchmarks(): array
    {
        $results = [];

        $this->write("  Running warmup ({$this->warmup} iterations)...");
        for ($i = 0; $i < $this->warmup; $i++) {
            $this->benchmarkContainer();
            $this->benchmarkConfig();
            $this->benchmarkString();
        }
        $this->write("  Warmup complete. Running benchmarks ({$this->iterations} iterations)...");
        $this->write('');

        $results['container'] = $this->benchmarkContainer();
        $results['config'] = $this->benchmarkConfig();
        $results['string'] = $this->benchmarkString();
        $results['hash'] = $this->benchmarkHash();
        $results['validation'] = $this->benchmarkValidation();
        $results['database'] = $this->benchmarkDatabase();
        $results['router'] = $this->benchmarkRouter();

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function benchmarkContainer(): array
    {
        $start = hrtime(true);
        $testKeys = [];
        for ($i = 0; $i < $this->iterations; $i++) {
            $key = 'test.key.' . ($i % 50);
            \Siro\Core\Container::getInstance()->singleton($key, fn() => new \stdClass());
            \Siro\Core\Container::getInstance()->make($key);
            $testKeys[] = $key;
        }
        $end = hrtime(true);

        $nsPerOp = ($end - $start) / ($this->iterations * 2);
        return $this->formatResult('Container (DI)', $nsPerOp);
    }

    /**
     * @return array<string, mixed>
     */
    private function benchmarkConfig(): array
    {
        $config = new \Siro\Core\Config();
        $config->set('test.value', 'data');
        $config->set('test.nested.deep', 'value');

        $start = hrtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $config->get('test.nested.deep');
            $config->has('test.value');
        }
        $end = hrtime(true);

        $nsPerOp = ($end - $start) / ($this->iterations * 2);
        return $this->formatResult('Config Repository', $nsPerOp);
    }

    /**
     * @return array<string, mixed>
     */
    private function benchmarkString(): array
    {
        $str = new \Siro\Core\Str();

        $start = hrtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $str->random(16);
            $str->slug('Hello World');
            $str->limit('Lorem ipsum dolor sit amet', 20);
        }
        $end = hrtime(true);

        $nsPerOp = ($end - $start) / ($this->iterations * 3);
        return $this->formatResult('String Helpers', $nsPerOp);
    }

    /**
     * @return array<string, mixed>
     */
    private function benchmarkHash(): array
    {
        $password = 'test_password_123';

        $start = hrtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $hash = \Siro\Core\Hash::make($password);
            \Siro\Core\Hash::check($password, $hash);
        }
        $end = hrtime(true);

        $nsPerOp = ($end - $start) / ($this->iterations * 2);
        return $this->formatResult('Hash (Bcrypt)', $nsPerOp);
    }

    /**
     * @return array<string, mixed>
     */
    private function benchmarkValidation(): array
    {
        $input = ['email' => 'test@example.com', 'password' => 'secret123'];
        $rules = ['email' => 'required|email', 'password' => 'required|min:6'];

        $start = hrtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            \Siro\Core\Validator::make($input, $rules);
        }
        $end = hrtime(true);

        $nsPerOp = ($end - $start) / $this->iterations;
        return $this->formatResult('Validation', $nsPerOp);
    }

    /**
     * @return array<string, mixed>
     */
    private function benchmarkDatabase(): array
    {
        $start = hrtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->query('SELECT 1');
            unset($pdo);
        }
        $end = hrtime(true);

        $nsPerOp = ($end - $start) / $this->iterations;
        return $this->formatResult('Database (SQLite)', $nsPerOp);
    }

    /**
     * @return array<string, mixed>
     */
    private function benchmarkRouter(): array
    {
        $router = new \Siro\Core\Router();
        $iterations = min($this->iterations, 50);

        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $router->get('/users/' . $i, fn() => 'ok');
            $router->post('/api/test', fn() => 'ok');
            $router->get('/products/' . $i, fn() => 'ok');
        }
        $end = hrtime(true);

        $nsPerOp = ($end - $start) / ($iterations * 3);
        return $this->formatResult('Router', $nsPerOp);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatResult(string $name, float $nsPerOp): array
    {
        $usPerOp = $nsPerOp / 1000;
        $opsPerSec = $nsPerOp > 0 ? (1000000000 / $nsPerOp) : 0;

        $rating = $usPerOp < 100 ? '🚀 Excellent' : ($usPerOp < 500 ? '✅ Good' : ($usPerOp < 1000 ? '⚠️ Fair' : '❌ Slow'));

        return [
            'name' => $name,
            'microseconds' => round($usPerOp, 2),
            'ops_per_second' => (int) $opsPerSec,
            'rating' => $rating,
        ];
    }

    /**
     * @param array<string, mixed> $benchmarks
     */
    private function outputTable(array $benchmarks): void
    {
        $this->write('  +---------------------------+-------------+---------------+---------------+');
        $this->write('  | Component                 | Time/op     | Ops/sec       | Rating        |');
        $this->write('  +---------------------------+-------------+---------------+---------------+');

        foreach ($benchmarks as $result) {
            $this->write(sprintf(
                '  | %-25s | %-11s | %-13s | %-13s |',
                $result['name'],
                $result['microseconds'] . ' μs',
                number_format($result['ops_per_second']),
                $result['rating']
            ));
        }

        $this->write('  +---------------------------+-------------+---------------+---------------+');
        $this->write('');

        $totalOps = array_sum(array_column($benchmarks, 'ops_per_second'));
        $avgMicroseconds = array_sum(array_column($benchmarks, 'microseconds')) / count($benchmarks);
        $this->write(sprintf('  Summary: %d ops/sec, avg %s μs/op', $totalOps, round($avgMicroseconds, 2)));
        $this->write('');
        $this->write('  PHP Version: ' . PHP_VERSION);
        $this->write('  Platform: ' . PHP_OS);
        $this->write('');
    }

    /**
     * @param array<string, mixed> $benchmarks
     */
    private function outputJson(array $benchmarks): void
    {
        $output = [
            'version' => '1.0.0',
            'timestamp' => date('c'),
            'iterations' => $this->iterations,
            'warmup' => $this->warmup,
            'php_version' => PHP_VERSION,
            'platform' => PHP_OS,
            'results' => $benchmarks,
        ];

        $this->write((string) json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}