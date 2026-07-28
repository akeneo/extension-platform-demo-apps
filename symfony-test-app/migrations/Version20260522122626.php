<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260522122626 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace image_filename (varchar) with image_filenames (json array)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE product ADD image_filenames JSON DEFAULT NULL");
        $this->addSql("UPDATE product SET image_filenames = json_build_array(image_filename) WHERE image_filename IS NOT NULL");
        $this->addSql("ALTER TABLE product DROP COLUMN image_filename");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE product ADD image_filename VARCHAR(500) DEFAULT NULL");
        $this->addSql("UPDATE product SET image_filename = image_filenames->>0 WHERE image_filenames IS NOT NULL AND jsonb_array_length(image_filenames::jsonb) > 0");
        $this->addSql("ALTER TABLE product DROP COLUMN image_filenames");
    }
}
