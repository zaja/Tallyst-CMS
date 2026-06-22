<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints as SecurityAssert;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Constraints\PasswordStrength;

/**
 * Self-service password change for the logged-in user. `currentPassword` re-authenticates via
 * the UserPassword constraint (a hijacked session can't change the password without knowing it);
 * the new password carries the SAME strength rules as the reset form (see ChangePasswordFormType).
 * `not_compromised_password` is disabled `when@test` globally, so no HIBP call in tests.
 */
class ChangeOwnPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'mapped' => false,
                'label' => 'Trenutna lozinka',
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [
                    new NotBlank(message: 'Upiši trenutnu lozinku.'),
                    new SecurityAssert\UserPassword(message: 'Trenutna lozinka nije ispravna.'),
                ],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'options' => ['attr' => ['autocomplete' => 'new-password']],
                'first_options' => [
                    'label' => 'Nova lozinka',
                    'constraints' => [
                        new NotBlank(message: 'Upiši novu lozinku.'),
                        new Length(min: 12, minMessage: 'Lozinka mora imati barem {{ limit }} znakova.', max: 4096),
                        new PasswordStrength(),
                        new NotCompromisedPassword(),
                    ],
                ],
                'second_options' => ['label' => 'Ponovi novu lozinku'],
                'invalid_message' => 'Lozinke se ne podudaraju.',
            ]);
    }
}
