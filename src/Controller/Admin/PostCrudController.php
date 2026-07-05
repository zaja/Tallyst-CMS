<?php

namespace App\Controller\Admin;

use App\Entity\Post;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Tallyst\Media\Field\MediaPickerField;
use Tallyst\Media\Field\TiptapField;

#[IsGranted('ROLE_EDITOR')]
class PostCrudController extends AbstractCrudController
{
    use AdminCrudPolishTrait;

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
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
        return $this->inlineRowActions($crud
            ->setEntityLabelInSingular('admin.post.entity.singular')
            ->setEntityLabelInPlural('admin.post.entity.plural')
            ->setDefaultSort(['publishedAt' => 'DESC', 'id' => 'DESC'])
            ->addFormTheme('@Media/admin/form/media_picker_widget.html.twig')
            ->addFormTheme('@Media/admin/form/tiptap_widget.html.twig'));
    }

    public function configureActions(Actions $actions): Actions
    {
        // Preview the live post (/blog/{slug}); only for published posts.
        $actions = $this->addPreviewAction(
            $actions,
            fn (Post $p): string => $this->urlGenerator->generate('blog_post', ['slug' => $p->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
            static fn (Post $p): bool => $p->isPublished(),
        );

        return $this->addBackToListAction($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        // Two-column form layout mirroring PageCrudController: a wide MAIN column for the
        // content, a narrow RIGHT column for the metadata (status/category/author/published/
        // featured), so Post edit matches Page edit. The addColumn markers auto-hide on the
        // index list, so the index columns are unchanged (title/slug/status/category/author +
        // the new Modified column). ⚠ Once addColumn is used, every field must sit in a column.
        yield FormField::addColumn(8);
        yield TextField::new('title', 'admin.post.field.title');
        yield SlugField::new('slug')->setTargetFieldName('title');
        yield TextareaField::new('excerpt', 'admin.post.field.excerpt')->hideOnIndex();
        yield TiptapField::new('content', 'admin.post.field.content')->hideOnIndex();

        // Narrow right column — metadata (WordPress-style: Publish + Category + Author + Featured).
        yield FormField::addColumn(4);
        yield ChoiceField::new('status', 'admin.post.field.status')
            ->setChoices(['admin.post.status.draft' => Post::STATUS_DRAFT, 'admin.post.status.published' => Post::STATUS_PUBLISHED])
            ->renderAsBadges([Post::STATUS_DRAFT => 'secondary', Post::STATUS_PUBLISHED => 'success']);
        yield AssociationField::new('category', 'admin.post.field.category');
        yield AssociationField::new('author', 'admin.post.field.author')
            ->setFormTypeOption('choice_label', static fn (User $u): string => $u->getNickname() ?: $u->getEmail())
            ->setHelp('admin.post.help.author');
        yield DateTimeField::new('publishedAt', 'admin.post.field.published_at')->hideOnIndex();
        yield MediaPickerField::new('featuredImage', 'admin.post.field.featured_image')->hideOnIndex();
        // Last-modified date+time on the index (sortable). hideOnForm — updatedAt is read-only.
        yield DateTimeField::new('updatedAt', 'admin.post.field.updated_at')->hideOnForm();
    }
}
