<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the terminal saga failure reason for status queries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sagas ADD failure_reason VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sagas DROP failure_reason');
    }
}
