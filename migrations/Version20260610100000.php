<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * B1 — Sécu jury : table reset_password_request
 *
 * Stocke les demandes de réinitialisation de mot de passe.
 * Le token n'est JAMAIS stocké en clair : on stocke uniquement
 * son hash SHA-256. Le token clair est envoyé par mail.
 *
 * Expiration : 1h (TTL court pour limiter la fenêtre d'attaque).
 *
 * Anti-énumération : on autorise plusieurs demandes successives,
 * mais on consomme/supprime l'ancien token quand une nouvelle
 * demande arrive pour le même user.
 *
 * @see \App\Entity\Core\ResetPasswordRequest
 * @see \App\Service\Security\ResetPasswordTokenManager
 */
final class Version20260610100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B1 sécu : table reset_password_request (token sha256, expire 1h)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE reset_password_request (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                requested_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                consumed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                request_ip VARCHAR(45) DEFAULT NULL,
                INDEX IDX_RPR_USER (user_id),
                INDEX IDX_RPR_TOKEN (token_hash),
                INDEX IDX_RPR_EXPIRES (expires_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE reset_password_request
            ADD CONSTRAINT FK_RPR_USER FOREIGN KEY (user_id)
            REFERENCES `user` (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reset_password_request DROP FOREIGN KEY FK_RPR_USER');
        $this->addSql('DROP TABLE reset_password_request');
    }
}
