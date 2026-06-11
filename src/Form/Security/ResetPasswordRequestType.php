<?php

declare(strict_types=1);

namespace App\Form\Security;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * B1 — Form demande de reset (étape 1).
 * L'user entre son email, on lui envoie le mail si compte trouvé.
 */
class ResetPasswordRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Votre adresse email',
            'attr'  => [
                'placeholder'  => 'votre.email@example.com',
                'autocomplete' => 'email',
                'autofocus'    => true,
            ],
            'constraints' => [
                new NotBlank(message: 'Indiquez votre adresse email.'),
                new Email(message: 'Cette adresse email n\'est pas valide.'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id'   => 'reset_password_request',
        ]);
    }
}
