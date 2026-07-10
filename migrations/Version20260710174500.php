<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * [V2.4m 10/07/2026] Data-fix : les dossiers licences GÉNÉRÉS par
 * « Préparer la saison » démarraient en « À payer » → tout l'effectif
 * sortait dans « À relancer en priorité » (y compris la secrétaire !).
 *
 * Nouveau statut NON_RENSEIGNE (« À définir ») : appliqué rétroactivement
 * aux dossiers visiblement jamais traités — statut « À payer » ET aucun
 * tarif ET aucune aide ET jamais relancés. Les dossiers issus des imports
 * Excel (tarif ou aides renseignés) ne sont PAS touchés.
 */
final class Version20260710174500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Data-fix : dossiers licences vierges → statut NON_RENSEIGNE (« À définir »)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE sport_dossier_licence
                       SET paiement_statut = 'NON_RENSEIGNE'
                       WHERE paiement_statut = 'EN_ATTENTE'
                         AND tarif IS NULL
                         AND aides IS NULL
                         AND relance_le IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE sport_dossier_licence
                       SET paiement_statut = 'EN_ATTENTE'
                       WHERE paiement_statut = 'NON_RENSEIGNE'");
    }
}
