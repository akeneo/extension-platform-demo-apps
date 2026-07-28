<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260708000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sync_attempt table for the sync logs page';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE sync_attempt (
                id          SERIAL       NOT NULL,
                type        VARCHAR(32)  NOT NULL,
                status      VARCHAR(16)  NOT NULL,
                input       VARCHAR(500) DEFAULT NULL,
                count       INT          DEFAULT NULL,
                error       TEXT         DEFAULT NULL,
                queued_at   TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                started_at  TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                attempts    INT          NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql("COMMENT ON COLUMN sync_attempt.queued_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN sync_attempt.started_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN sync_attempt.finished_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sync_attempt');
    }
}
