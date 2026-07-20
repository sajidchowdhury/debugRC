<?php
// core/Logger.php — Phase 6 structured application logging

class Logger
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    private static ?string $logDir = null;

    private static function logDir(): string
    {
        if (self::$logDir === null) {
            $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
            self::$logDir = $root . '/logs';
            if (!is_dir(self::$logDir)) {
                @mkdir(self::$logDir, 0755, true);
            }
        }

        return self::$logDir;
    }

    public static function minLevel(): string
    {
        if (defined('APP_LOG_LEVEL')) {
            return (string)APP_LOG_LEVEL;
        }

        return (defined('APP_ENV') && APP_ENV === 'production') ? self::WARNING : self::DEBUG;
    }

    private static function levelRank(string $level): int
    {
        return match ($level) {
            self::DEBUG => 10,
            self::INFO => 20,
            self::WARNING => 30,
            self::ERROR => 40,
            default => 20,
        };
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        if (self::levelRank($level) < self::levelRank(self::minLevel())) {
            return;
        }

        $line = json_encode([
            'timestamp' => date('Y-m-d H:i:s'),
            'level'     => $level,
            'message'   => $message,
            'context'   => $context,
        ], JSON_UNESCAPED_UNICODE);

        @file_put_contents(self::logDir() . '/app.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log(self::DEBUG, $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log(self::INFO, $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log(self::WARNING, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log(self::ERROR, $message, $context);
    }
}