<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
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
                'label' => 'admin.password.current',
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [
                    // Messages are `validators`-domain keys (Symfony auto-translates them there).
                    new NotBlank(message: 'validation.password.current_required'),
                    new SecurityAssert\UserPassword(message: 'validation.password.current_invalid'),
                ],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'options' => ['attr' => ['autocomplete' => 'new-password']],
                'first_options' => [
                    'label' => 'admin.password.new',
                    'constraints' => [
                        new NotBlank(message: 'validation.password.new_required'),
                        new Length(min: 12, minMessage: 'validation.password.too_short', max: 4096),
                        new PasswordStrength(),
                        new NotCompromisedPassword(),
                    ],
                ],
                'second_options' => ['label' => 'admin.password.repeat'],
                'invalid_message' => 'validation.password.mismatch',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Field labels translate via the `admin` domain; validation messages keep the validators domain.
        $resolver->setDefaults(['translation_domain' => 'admin']);
    }
}
