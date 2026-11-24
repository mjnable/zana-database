<?php

declare(strict_types=1);

namespace Zana\Database\Migration\Doctrine;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * @internal Doctrine's logger (ADR-035). It keeps what the command shows: what migrations said (write(), warnIf()), a
 * skipped migration and its reason, and the migrator's warnings. Those, and Doctrine's errors, also go to the
 * application's logger.
 *
 * Doctrine's progress records (each migration, its time, each query with its bound values) are dropped: they would
 * fill the application's log at every migrate, and a bound value may be personal data.
 */
final class Messages extends AbstractLogger
{
    /** A context key marking a record the command shows. */
    public const string SHOWN = 'zana_migrations_shown';

    private const array ERRORS = [LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY];

    /** @var list<array{level: string, message: string}> */
    private array $messages = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $message = (string) $message;
        $level = is_string($level) ? $level : LogLevel::INFO;
        $shown = isset($context['migration']) || ($context[self::SHOWN] ?? false) === true || str_starts_with($message, 'Migration {version} skipped');
        if (!$shown && !in_array($level, self::ERRORS, true)) {
            return;
        }
        unset($context[self::SHOWN]);
        $this->logger->log($level, $message, $context);
        if ($shown) {
            $this->messages[] = ['level' => $level, 'message' => self::interpolate($message, $context)];
        }
    }

    /**
     * What was kept since the last call.
     *
     * @return list<array{level: string, message: string}>
     */
    public function take(): array
    {
        [$messages, $this->messages] = [$this->messages, []];

        return $messages;
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        return preg_replace_callback(
            '/\{(\w+)\}/',
            static fn (array $m): string => is_scalar($context[$m[1]] ?? null) || ($context[$m[1]] ?? null) instanceof \Stringable ? (string) $context[$m[1]] : $m[0],
            $message,
        ) ?? $message;
    }
}
