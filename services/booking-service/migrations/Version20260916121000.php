<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260916121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add booking event consumer inbox for idempotent message handling';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inbox_messages (consumer_name VARCHAR(64) NOT NULL, message_id VARCHAR(36) NOT NULL, message_type VARCHAR(255) NOT NULL, processed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (consumer_name, message_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE inbox_messages');
    }
}
