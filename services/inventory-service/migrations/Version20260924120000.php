<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove unused optimistic-lock version from seats';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seats DROP COLUMN version');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seats ADD version INT DEFAULT 1 NOT NULL');
    }
}
