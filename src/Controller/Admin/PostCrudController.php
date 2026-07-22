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
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tallyst\Media\Field\MediaPickerField;
use Tallyst\Media\Field\TiptapField;

#[IsGranted('ROLE_EDITOR')]
class PostCrudController extends AbstractCrudController
{
    use AdminCrudPolishTrait;

    /** Index-only display truncation (v1.7.2) — keeps long titles/slugs from widening the list
     *  table and wrapping the row-action icons. The full text is still on hover (EA's TextField
     *  template already wraps the value in title="{{ field.value }}"). */
    private const INDEX_TITLE_MAX_LENGTH = 35;
    private const INDEX_SLUG_MAX_LENGTH = 25;

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
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
            $this->translator,
            fn (Post $p): string => $this->urlGenerator->generate('blog_post', ['slug' => $p->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
            static fn (Post $p): bool => $p->isPublished(),
        );
        $actions = $this->iconOnlyRowActions($actions, $this->translator);

        return $this->addBackToListAction($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        // Featured-image thumbnail, index-only, as the FIRST column. This yields the SAME
        // 'featuredImage' property a second time below (the form's MediaPickerField,
        // hideOnIndex) — EA explicitly supports this: FieldCollection::getByProperty() is
        // documented to allow "the same field more than once" on index/detail, and fields are
        // keyed by a generated unique id, not by property name, so the two configs never
        // collide (each page only sees the one whose visibility flags match).
        yield ImageField::new('featuredImage', 'admin.post.field.featured_image')
            ->onlyOnIndex()
            ->setTemplatePath('admin/post/thumb.html.twig');

        // Truncated title/slug, index-only — same double-yield pattern as the thumbnail above
        // (the form's title/slug fields below are now hideOnIndex, so there's no collision).
        // setMaxLength() is EA's own TextField truncation ("…" + the full value in title=""),
        // reused here instead of a custom template. SlugField isn't covered by TextConfigurator
        // (only TextField/TextareaField), so the index copy is a plain TextField.
        yield TextField::new('title', 'admin.post.field.title')
            ->onlyOnIndex()
            ->setMaxLength(self::INDEX_TITLE_MAX_LENGTH);
        yield TextField::new('slug')
            ->onlyOnIndex()
            ->setMaxLength(self::INDEX_SLUG_MAX_LENGTH);

        // Two-column form layout mirroring PageCrudController: a wide MAIN column for the
        // content, a narrow RIGHT column for the metadata (status/category/author/published/
        // featured), so Post edit matches Page edit. The addColumn markers auto-hide on the
        // index list, so the index columns are unchanged (title/slug/status/category/author +
        // the Published column). ⚠ Once addColumn is used, every field must sit in a column.
        yield FormField::addColumn(8);
        yield TextField::new('title', 'admin.post.field.title')->hideOnIndex();
        yield SlugField::new('slug')->setTargetFieldName('title')->hideOnIndex();
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
        // Published date+time on the index (sortable — matches the default sort), same field
        // used by the form (unchanged: templatePath only affects display pages, never the
        // form widget). A custom template null-guards drafts — see admin/post/published_at.html.twig.
        // setFormat(medium, short) drops seconds from the TIME part only ("5:06 AM", not
        // "5:06:57 AM") while staying on EA's locale-aware IntlFormatter (short time = ICU
        // "h:mm a", the standard no-seconds precision) — the date part (medium) is unchanged.
        // This can't be done in the template: field.formattedValue is already the rendered
        // string by the time Twig sees it, so the precision has to be set here, not there.
        yield DateTimeField::new('publishedAt', 'admin.post.field.published_at')
            ->setFormat(DateTimeField::FORMAT_MEDIUM, DateTimeField::FORMAT_SHORT)
            ->setTemplatePath('admin/post/published_at.html.twig');
        // Read-only "last modified" info, Edit only — not Index (that column was deliberately
        // removed; see the truncated title/slug + thumbnail above) and not New (a not-yet-created
        // post has no updatedAt yet). updatedAt has no setter (TimestampableTrait) — `disabled` is
        // load-bearing, not cosmetic: Symfony's Form component skips a disabled field entirely
        // when mapping submitted data back onto the entity, so Save never calls a nonexistent
        // setUpdatedAt().
        yield DateTimeField::new('updatedAt', 'admin.post.field.updated_at')
            ->hideOnIndex()
            ->hideWhenCreating()
            ->setFormTypeOption('disabled', true);
        yield MediaPickerField::new('featuredImage', 'admin.post.field.featured_image')->hideOnIndex();
    }
}
