<?php

namespace App\Controller\Admin;

use App\Entity\Post;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Tallyst\Media\Field\MediaPickerField;
use Tallyst\Media\Field\TiptapField;

#[IsGranted('ROLE_EDITOR')]
class PostCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Post::class;
    }

    /**
     * Pre-fill the author with the current user on the NEW form (editable / changeable to
     * another user). createEntity is NOT called on edit, so an existing author is never clobbered.
     */
    public function createEntity(string $entityFqcn): Post
    {
        $post = new Post();
        $user = $this->security->getUser();
        if ($user instanceof User) {
            $post->setAuthor($user);
        }

        return $post;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Objava')
            ->setEntityLabelInPlural('Objave')
            ->setDefaultSort(['publishedAt' => 'DESC', 'id' => 'DESC'])
            ->addFormTheme('@Media/admin/form/media_picker_widget.html.twig')
            ->addFormTheme('@Media/admin/form/tiptap_widget.html.twig');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Naslov');
        yield SlugField::new('slug')->setTargetFieldName('title');
        yield ChoiceField::new('status', 'Status')
            ->setChoices(['Skica' => Post::STATUS_DRAFT, 'Objavljeno' => Post::STATUS_PUBLISHED])
            ->renderAsBadges([Post::STATUS_DRAFT => 'secondary', Post::STATUS_PUBLISHED => 'success']);
        yield AssociationField::new('category', 'Kategorija');
        yield AssociationField::new('author', 'Autor')
            ->setFormTypeOption('choice_label', static fn (User $u): string => $u->getNickname() ?: $u->getEmail())
            ->setHelp('Zadano: ti. Promjenjivo.');
        yield MediaPickerField::new('featuredImage', 'Naslovna slika')->hideOnIndex();
        yield DateTimeField::new('publishedAt', 'Objavljeno')->hideOnIndex();
        yield TextareaField::new('excerpt', 'Sažetak')->hideOnIndex();
        yield TiptapField::new('content', 'Sadržaj')->hideOnIndex();
    }
}
