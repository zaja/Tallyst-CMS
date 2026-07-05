<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Security\AdminLockoutGuard;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Back-office user management. Admin-only. Roles are the friendly choice between
 * Administrator (ROLE_ADMIN) and Urednik (ROLE_EDITOR). Passwords are hashed via a form
 * POST_SUBMIT listener (the official EA recipe): the field is form-only + unmapped, so a
 * blank password on EDIT keeps the stored hash and plaintext is never written to the entity.
 *
 * Delete/role-change are guarded by AdminLockoutGuard so you can't remove the last admin or
 * your own admin access — on a violation the operation is aborted with a flash (never a 500,
 * never persisted).
 */
#[IsGranted('ROLE_ADMIN')]
class UserCrudController extends AbstractCrudController
{
    use AdminCrudPolishTrait;

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AdminLockoutGuard $guard,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $this->inlineRowActions($crud
            ->setEntityLabelInSingular('admin.user.entity.singular')
            ->setEntityLabelInPlural('admin.user.entity.plural')
            ->setDefaultSort(['email' => 'ASC']));
    }

    public function configureActions(Actions $actions): Actions
    {
        // UI level of the lockout guard: hide Delete when the server would block it (self / last
        // admin). Server-side enforcement stays in deleteEntity (mirrors blockDelete exactly).
        $hideWhenBlocked = fn (Action $a): Action => $a->displayIf(fn (User $u): bool => null === $this->guard->blockDelete($u));

        // Icon-only Edit/Delete first; the displayIf STACKS on top of the icon-only Delete (EA's
        // update() reads the current action and applies the callable), so both survive.
        $actions = $this->iconOnlyRowActions($actions, $this->translator)
            ->update(Crud::PAGE_INDEX, Action::DELETE, $hideWhenBlocked)
            ->update(Crud::PAGE_DETAIL, Action::DELETE, $hideWhenBlocked);

        // No preview (users have no public URL).
        return $this->addBackToListAction($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        $isNew = Crud::PAGE_NEW === $pageName;

        yield EmailField::new('email', 'admin.user.field.email');
        yield TextField::new('nickname', 'admin.user.field.nickname')
            ->setHelp('admin.user.help.nickname');
        yield TextField::new('name', 'admin.user.field.name')
            ->setHelp('admin.user.help.name')
            ->hideOnIndex();
        yield ChoiceField::new('roles', 'admin.user.field.roles')
            ->setChoices(['admin.user.role.admin' => 'ROLE_ADMIN', 'admin.user.role.editor' => 'ROLE_EDITOR'])
            ->allowMultipleChoices()
            ->renderExpanded()
            ->renderAsBadges(['ROLE_ADMIN' => 'danger', 'ROLE_EDITOR' => 'info']);

        // Form-only + unmapped: blank on edit = unchanged, plaintext never touches the entity
        // (the POST_SUBMIT listener hashes it only when filled).
        yield TextField::new('plainPassword', $isNew ? 'admin.user.field.password' : 'admin.user.field.new_password')
            ->setFormType(PasswordType::class)
            ->setFormTypeOptions([
                'mapped' => false,
                'required' => $isNew,
                'attr' => ['autocomplete' => 'new-password'],
            ])
            ->setHelp($isNew ? 'admin.user.help.password_new' : 'admin.user.help.password_edit')
            ->onlyOnForms();
    }

    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->addPasswordHashing(parent::createNewFormBuilder($entityDto, $formOptions, $context));
    }

    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->addPasswordHashing(parent::createEditFormBuilder($entityDto, $formOptions, $context));
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof User && null !== ($msg = $this->guard->blockRoleChange($entityManager, $entityInstance))) {
            $this->addFlash('danger', $msg);
            // Discard the in-memory change and DON'T flush — the demotion never happens.
            $entityManager->refresh($entityInstance);

            return;
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof User && null !== ($msg = $this->guard->blockDelete($entityInstance))) {
            $this->addFlash('danger', $msg);

            return; // no remove/flush — the account stays
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }

    private function addPasswordHashing(FormBuilderInterface $builder): FormBuilderInterface
    {
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            if (!$form->isValid() || !$form->has('plainPassword')) {
                return;
            }
            $plain = $form->get('plainPassword')->getData();
            if (null === $plain || '' === $plain) {
                return; // edit with a blank password → keep the existing hash
            }

            $user = $event->getData();
            if ($user instanceof User) {
                $user->setPassword($this->hasher->hashPassword($user, $plain));
            }
        });

        return $builder;
    }
}
