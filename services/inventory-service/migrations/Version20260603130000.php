<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260603130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Inventory schema: shows, seats, seat_holds, outbox_messages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE shows (id VARCHAR(36) NOT NULL, name VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE seats (id VARCHAR(36) NOT NULL, seat_code VARCHAR(32) NOT NULL, state VARCHAR(16) NOT NULL, version INT DEFAULT 1 NOT NULL, held_by_reservation_id VARCHAR(36) DEFAULT NULL, show_id VARCHAR(36) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_BFE25750D0C1FC64 ON seats (show_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_seat_show ON seats (show_id, seat_code)');
        $this->addSql('CREATE TABLE seat_holds (id VARCHAR(36) NOT NULL, reservation_id VARCHAR(36) NOT NULL, seat_ids JSON NOT NULL, status VARCHAR(16) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, show_id VARCHAR(36) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_52E91B81B83297E7 ON seat_holds (reservation_id)');
        $this->addSql('CREATE INDEX IDX_52E91B81D0C1FC64 ON seat_holds (show_id)');
        $this->addSql('CREATE TABLE outbox_messages (id VARCHAR(36) NOT NULL, event_type VARCHAR(255) NOT NULL, payload TEXT NOT NULL, correlation_id VARCHAR(36) NOT NULL, reservation_id VARCHAR(36) NOT NULL, routing_key VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_outbox_unpublished ON outbox_messages (published_at, created_at)');
        $this->addSql('ALTER TABLE seats ADD CONSTRAINT FK_BFE25750D0C1FC64 FOREIGN KEY (show_id) REFERENCES shows (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE seat_holds ADD CONSTRAINT FK_52E91B81D0C1FC64 FOREIGN KEY (show_id) REFERENCES shows (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seat_holds DROP CONSTRAINT FK_52E91B81D0C1FC64');
        $this->addSql('ALTER TABLE seats DROP CONSTRAINT FK_BFE25750D0C1FC64');
        $this->addSql('DROP TABLE outbox_messages');
        $this->addSql('DROP TABLE seat_holds');
        $this->addSql('DROP TABLE seats');
        $this->addSql('DROP TABLE shows');
    }
}
