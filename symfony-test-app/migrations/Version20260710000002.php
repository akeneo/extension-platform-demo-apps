<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260710000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index sync_attempt.queued_at and .type for retention cleanup and stats queries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_sync_attempt_queued_at ON sync_attempt (queued_at)');
        $this->addSql('CREATE INDEX idx_sync_attempt_type ON sync_attempt (type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_sync_attempt_queued_at');
        $this->addSql('DROP INDEX idx_sync_attempt_type');
    }
}
