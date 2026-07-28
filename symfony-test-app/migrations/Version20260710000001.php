<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260710000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add api_calls column to sync_attempt table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sync_attempt ADD api_calls INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sync_attempt DROP COLUMN api_calls');
    }
}
