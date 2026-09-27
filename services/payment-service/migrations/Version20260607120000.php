<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260607120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Payment schema: payments, outbox_messages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payments (id VARCHAR(36) NOT NULL, reservation_id VARCHAR(36) NOT NULL, correlation_id VARCHAR(36) NOT NULL, show_id VARCHAR(36) NOT NULL, amount_minor INT NOT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(32) NOT NULL, gateway VARCHAR(32) NOT NULL, gateway_payment_intent_id VARCHAR(64) DEFAULT NULL, gateway_charge_id VARCHAR(64) DEFAULT NULL, payment_method_token VARCHAR(128) NOT NULL, authorized_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, captured_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_payment_reservation ON payments (reservation_id)');
        $this->addSql('CREATE TABLE outbox_messages (id VARCHAR(36) NOT NULL, event_type VARCHAR(255) NOT NULL, payload TEXT NOT NULL, correlation_id VARCHAR(36) NOT NULL, reservation_id VARCHAR(36) NOT NULL, routing_key VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_outbox_unpublished ON outbox_messages (published_at, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE outbox_messages');
        $this->addSql('DROP TABLE payments');
    }
}
