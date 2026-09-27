<?php

declare(strict_types=1);

namespace Ticketing\MessengerIdempotency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class InboxMessageStore
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function claim(string $consumerName, string $messageId, string $messageType): bool
    {
        $affectedRows = $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO inbox_messages (consumer_name, message_id, message_type, processed_at)
                VALUES (:consumerName, :messageId, :messageType, :processedAt)
                ON CONFLICT (consumer_name, message_id) DO NOTHING
                SQL,
            [
                'consumerName' => $consumerName,
                'messageId' => $messageId,
                'messageType' => $messageType,
                'processedAt' => new \DateTimeImmutable(),
            ],
            [
                'processedAt' => Types::DATETIME_IMMUTABLE,
            ],
        );

        return $affectedRows === 1;
    }
}
