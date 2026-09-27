<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260603140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Orchestrator schema: sagas, outbox_messages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sagas (id VARCHAR(36) NOT NULL, reservation_id VARCHAR(36) NOT NULL, correlation_id VARCHAR(36) NOT NULL, show_id VARCHAR(36) NOT NULL, seat_ids JSON NOT NULL, state VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_saga_reservation ON sagas (reservation_id)');
        $this->addSql('CREATE TABLE outbox_messages (id VARCHAR(36) NOT NULL, event_type VARCHAR(255) NOT NULL, payload TEXT NOT NULL, correlation_id VARCHAR(36) NOT NULL, reservation_id VARCHAR(36) NOT NULL, routing_key VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_outbox_unpublished ON outbox_messages (published_at, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE outbox_messages');
        $this->addSql('DROP TABLE sagas');
    }
}
