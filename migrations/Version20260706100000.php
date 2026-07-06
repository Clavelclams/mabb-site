<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * [B4 phase 1 — 06/07/2026] Table api_token : jetons opaques de l'API
 * mobile PIRB (authenticator access_token natif Symfony — pas de LexikJWT,
 * cf. ADR-0010). Seul le hash SHA-256 du jeton est stocké.
 */
final class Version20260706100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B4 phase 1 — table api_token (auth API mobile PIRB, jetons opaques hashés)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE api_token (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            appareil VARCHAR(100) DEFAULT NULL,
            expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_api_token_hash (token_hash),
            INDEX idx_api_token_hash (token_hash),
            INDEX idx_api_token_user (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE api_token ADD CONSTRAINT fk_api_token_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE api_token');
    }
}
