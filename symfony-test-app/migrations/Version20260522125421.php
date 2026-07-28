<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260522125421 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add parent_label column to store parent product model display name';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE product ADD parent_label VARCHAR(255) DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE product DROP COLUMN parent_label");
    }
}
