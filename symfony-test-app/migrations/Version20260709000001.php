<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260709000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen sync_attempt.input to TEXT — VARCHAR(500) overflowed on bulk syncs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sync_attempt ALTER COLUMN input TYPE TEXT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sync_attempt ALTER COLUMN input TYPE VARCHAR(500)');
    }
}
