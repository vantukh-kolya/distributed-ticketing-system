<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260730120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Simplify payment lifecycle to PENDING, PAID, FAILED';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE payments SET status = 'PAID' WHERE status = 'CAPTURED'");
        $this->addSql("UPDATE payments SET status = 'PENDING' WHERE status = 'AUTHORIZED'");
        $this->addSql('ALTER TABLE payments ADD gateway_payment_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE payments ADD failure_reason VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE payments ADD paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE payments ADD failed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE payments SET gateway_payment_id = gateway_charge_id, paid_at = captured_at WHERE status = \'PAID\'');
        $this->addSql('ALTER TABLE payments DROP gateway_payment_intent_id');
        $this->addSql('ALTER TABLE payments DROP gateway_charge_id');
        $this->addSql('ALTER TABLE payments DROP authorized_at');
        $this->addSql('ALTER TABLE payments DROP captured_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payments ADD gateway_payment_intent_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE payments ADD gateway_charge_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE payments ADD authorized_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE payments ADD captured_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("UPDATE payments SET status = 'CAPTURED', gateway_charge_id = gateway_payment_id, captured_at = paid_at WHERE status = 'PAID'");
        $this->addSql("UPDATE payments SET status = 'PENDING' WHERE status = 'FAILED'");
        $this->addSql('ALTER TABLE payments DROP gateway_payment_id');
        $this->addSql('ALTER TABLE payments DROP failure_reason');
        $this->addSql('ALTER TABLE payments DROP paid_at');
        $this->addSql('ALTER TABLE payments DROP failed_at');
    }
}
