<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class BenchmarkCommand
{
    use CommandSupport;

    private string $basePath;
    private int $iterations = 100;
    private int $warmup = 10;
    private ?string $routeFilter = null;
    private bool $jsonOutput = false;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    public function run(array $args): int
    {
        $this->parseArgs($args);

        $this->write('');
        $this->write('  ⚡ SiroPHP Benchmark v0.20.0');
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

    private function parseArgs(array $args): void
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--iterations=')) {
                $this->iterations = (int) substr($arg, 13);
            } elseif (str_starts_with($arg, '--warmup=')) {
                $this->warmup = (int) substr($arg, 9);
            } elseif (str_starts_with($arg, '--route=')) {
                $this->routeFilter = substr($arg, 8);
            } elseif ($arg === '--json') {
                $this->jsonOutput = true;
            }
        }
    }

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

    private function benchmarkContainer(): array
    {
        $start = hrtime(true);
        $container = new \Siro\Core\Container();

        for ($i = 0; $i < $this->iterations; $i++) {
            $container->singleton('test.' . $i, fn() => new \stdClass());
            $container->make('test.' . ($i % 100));
        }
        $end = hrtime(true);

        $nsPerOp = ($end - $start) / $this->iterations;
        return $this->formatResult('Container (DI)', $nsPerOp);
    }

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

    private function benchmarkHash(): array
    {
        $hash = new \Siro\Core\Hash();

        $start = hrtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $hash->make('password123');
            $hash->verify('password123', $hash->make('password123'));
        }
        $end = hrtime(true);

        $nsPerOp = ($end - $start) / ($this->iterations * 2);
        return $this->formatResult('Hash (Bcrypt)', $nsPerOp);
    }

    private function benchmarkValidation(): array
    {
        $validator = new \Siro\Core\Validator();

        $start = hrtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $validator->validate(
                ['email' => 'test@example.com', 'password' => 'secret123'],
                ['email' => 'required|email', 'password' => 'required|min:6']
            );
        }
        $end = hrtime(true);

        $nsPerOp = ($end - $start) / $this->iterations;
        return $this->formatResult('Validation', $nsPerOp);
    }

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

    private function benchmarkRouter(): array
    {
        $router = new \Siro\Core\Router();

        $start = hrtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $router->get('/users/{id}', fn() => 'ok');
            $router->match(['GET', 'POST'], '/api/test', fn() => 'ok');
        }
        $end = hrtime(true);

        $nsPerOp = ($end - $start) / ($this->iterations * 2);
        return $this->formatResult('Router', $nsPerOp);
    }

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

    private function outputJson(array $benchmarks): void
    {
        $output = [
            'version' => '0.20.0',
            'timestamp' => date('c'),
            'iterations' => $this->iterations,
            'warmup' => $this->warmup,
            'php_version' => PHP_VERSION,
            'platform' => PHP_OS,
            'results' => $benchmarks,
        ];

        $this->write(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}