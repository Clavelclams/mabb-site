<?php

declare(strict_types=1);

namespace App\Form\Security;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Constraints\PasswordStrength;

/**
 * B1 — Form changement effectif du mot de passe (étape 2).
 *
 * Sécurité :
 *   - 8 caractères minimum (cohérent avec recommandations ANSSI/CNIL)
 *   - Vérif force (PasswordStrength : min "medium")
 *   - Vérif "Have I Been Pwned" (NotCompromisedPassword)
 *   - Champs repeated pour confirmation
 *   - CSRF activé
 */
class ResetPasswordChangeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type'             => PasswordType::class,
            'invalid_message'  => 'Les deux mots de passe ne correspondent pas.',
            'required'         => true,
            'first_options'    => [
                'label' => 'Nouveau mot de passe',
                'attr'  => [
                    'placeholder'  => 'Au moins 8 caractères',
                    'autocomplete' => 'new-password',
                    'autofocus'    => true,
                ],
            ],
            'second_options' => [
                'label' => 'Confirmer le mot de passe',
                'attr'  => [
                    'placeholder'  => 'Tapez-le à nouveau',
                    'autocomplete' => 'new-password',
                ],
            ],
            'constraints' => [
                new NotBlank(message: 'Le mot de passe est obligatoire.'),
                new Length(
                    min: 8,
                    minMessage: 'Le mot de passe doit faire au moins {{ limit }} caractères.',
                    max: 4096, // limite Symfony pour éviter DoS bcrypt
                ),
                new PasswordStrength([
                    'minScore'  => PasswordStrength::STRENGTH_MEDIUM,
                    'message'   => 'Ce mot de passe est trop faible. Utilisez plus de variété (majuscules, chiffres, symboles).',
                ]),
                new NotCompromisedPassword(
                    message: 'Ce mot de passe a déjà été compromis lors d\'une fuite de données publique. Choisissez-en un autre.',
                    skipOnError: true, // si l'API HIBP est down, on ne bloque pas
                ),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id'   => 'reset_password_change',
        ]);
    }
}
