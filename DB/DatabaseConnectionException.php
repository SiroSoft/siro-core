<?php

declare(strict_types=1);

namespace Siro\Core\DB;

use RuntimeException;

final class DatabaseConnectionException extends RuntimeException
{
    private string $driver;
    private string $host;

    public function __construct(string $driver, string $host, int $port, string $originalMessage)
    {
        $this->driver = $driver;
        $this->host = $host;

        $message = sprintf(
            "Cannot connect to %s database at %s:%d\n\n  %s\n\nPlease check:\n  1. Your .env DB configuration (DB_HOST, DB_PORT, DB_DATABASE)\n  2. Database server is running and accessible\n  3. Network connectivity and firewall settings",
            $driver,
            $host,
            $port,
            $originalMessage
        );

        parent::__construct($message);
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function getDbHost(): string
    {
        return $this->host;
    }
}
