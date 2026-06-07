<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Sport\Reunion;
use App\Entity\Sport\ReunionConvocation;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Envoie 1 mail par convoqué avec le PDF de convocation en pièce jointe.
 *
 * ARCHITECTURE :
 *   1. Génère le PDF (1 fois — partagé entre tous les mails)
 *   2. Pour chaque convocation : compose un mail personnalisé + attach PDF
 *   3. Envoie via le mailer Symfony (DSN Brevo dans .env.local)
 *   4. Log chaque envoi (succès/échec) pour audit
 *
 * Pourquoi 1 mail par convoqué et pas un BCC group ?
 *   - Personnalisation : "Bonjour Anthony" au lieu de "Bonjour les membres"
 *   - Évite que les emails restent visibles entre membres (RGPD)
 *   - Si un mail bounce, on sait lequel a échoué exactement
 *   - Pour 5-10 convoqués, le surcoût est négligeable
 *
 * RÉSILIENCE :
 *   - Si un envoi plante, on continue avec les suivants (try/catch par mail)
 *   - On retourne un résumé {success: int, errors: array<string>}
 *
 * Pas de transaction BDD ici — l'envoi mail n'est pas une opération
 * réversible. Si on échoue à mi-parcours, on a un état "partiellement envoyé"
 * qu'on devra gérer côté UI (afficher les erreurs).
 */
final class ConvocationMailerService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly ConvocationPdfGenerator $pdfGenerator,
        private readonly LoggerInterface $logger,
        /** Email expéditeur — configuré dans .env.local */
        private readonly string $expediteurEmail,
        private readonly string $expediteurNom,
    ) {}

    /**
     * Envoie les convocations à TOUS les convoqués d'une réunion.
     *
     * @return array{success: int, total: int, errors: array<string>}
     */
    public function envoyerPourReunion(Reunion $reunion): array
    {
        $convocations = $reunion->getConvocations();
        $total = $convocations->count();

        if ($total === 0) {
            return ['success' => 0, 'total' => 0, 'errors' => ['Aucun convoqué pour cette réunion.']];
        }

        // Génération PDF UNE FOIS (réutilisé pour tous les mails)
        try {
            $pdfBinaire = $this->pdfGenerator->genererPourReunion($reunion);
            $pdfNom = $this->pdfGenerator->nomFichier($reunion);
        } catch (\Exception $e) {
            $this->logger->error('Échec génération PDF convocation', [
                'reunion_id' => $reunion->getId(),
                'error'      => $e->getMessage(),
            ]);
            return ['success' => 0, 'total' => $total, 'errors' => ['Génération PDF impossible : ' . $e->getMessage()]];
        }

        $success = 0;
        $errors = [];

        foreach ($convocations as $convocation) {
            /** @var ReunionConvocation $convocation */
            $resultat = $this->envoyerUnMail($convocation, $reunion, $pdfBinaire, $pdfNom);
            if ($resultat === true) {
                $success++;
            } else {
                $errors[] = $resultat;
            }
        }

        $this->logger->info('Envoi convocations terminé', [
            'reunion_id' => $reunion->getId(),
            'success'    => $success,
            'total'      => $total,
            'nb_erreurs' => count($errors),
        ]);

        return [
            'success' => $success,
            'total'   => $total,
            'errors'  => $errors,
        ];
    }

    /**
     * Envoie le mail à UN convoqué. Retourne true si OK, sinon le message d'erreur.
     */
    private function envoyerUnMail(
        ReunionConvocation $convocation,
        Reunion $reunion,
        string $pdfBinaire,
        string $pdfNom,
    ): bool|string {
        $user = $convocation->getUser();
        if (!$user) {
            return 'Convocation sans utilisateur rattaché.';
        }
        $email = $user->getEmail();
        if (!$email) {
            return sprintf('Pas d\'email pour %s %s.', $user->getPrenom() ?? '?', $user->getNom() ?? '?');
        }

        try {
            $sujet = sprintf(
                '[%s] Convocation — %s du %s',
                $reunion->getClub()?->getNom() ?? 'Club',
                $this->labelType($reunion->getType()),
                $reunion->getDate()->format('d/m/Y')
            );

            $mail = (new TemplatedEmail())
                ->from(new Address($this->expediteurEmail, $this->expediteurNom))
                ->to(new Address($email, trim(($user->getPrenom() ?? '') . ' ' . ($user->getNom() ?? ''))))
                ->subject($sujet)
                ->htmlTemplate('manager/reunion/_convocation_email.html.twig')
                ->context([
                    'reunion'   => $reunion,
                    'user'      => $user,
                    'type_label' => $this->labelType($reunion->getType()),
                ])
                ->addPart(new DataPart($pdfBinaire, $pdfNom, 'application/pdf'));

            $this->mailer->send($mail);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Échec envoi convocation', [
                'user_id' => $user->getId(),
                'email'   => $email,
                'error'   => $e->getMessage(),
            ]);
            return sprintf('Échec pour %s : %s', $email, $e->getMessage());
        }
    }

    private function labelType(string $type): string
    {
        return [
            'CA'                => 'Conseil d\'administration',
            'AG_ORDINAIRE'      => 'Assemblée Générale',
            'AG_EXTRAORDINAIRE' => 'AG Extraordinaire',
            'BUREAU'            => 'Réunion de bureau',
            'AUTRE'             => 'Réunion',
        ][$type] ?? 'Réunion';
    }
}
