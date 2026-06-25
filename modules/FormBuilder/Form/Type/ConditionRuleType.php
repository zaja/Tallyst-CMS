<?php

namespace Tallyst\FormBuilder\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tallyst\FormBuilder\Form\Model\ConditionRule;

class ConditionRuleType extends AbstractType
{
    /**
     * Operator LABEL key => stored operator value. Labels reuse the rule-editor keys (so the no-JS
     * real select and the JS proxy show identical text); the stored VALUES mirror ConditionEvaluator
     * and are untouched.
     */
    public const OPERATORS = [
        'admin.form.rule.op.equals' => 'equals',
        'admin.form.rule.op.not_equals' => 'not_equals',
        'admin.form.rule.op.contains' => 'contains',
        'admin.form.rule.op.empty' => 'empty',
        'admin.form.rule.op.not_empty' => 'not_empty',
        'admin.form.rule.op.gt' => 'gt',
        'admin.form.rule.op.lt' => 'lt',
    ];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('field', TextType::class, [
                'required' => false,
                'label' => 'admin.condition.field',
                // An HTML attr placeholder isn't translated by the form domain → translate it here.
                'attr' => ['list' => 'fb-field-keys', 'placeholder' => $this->translator->trans('admin.condition.field_placeholder', [], 'admin')],
            ])
            ->add('operator', ChoiceType::class, [
                'required' => false,
                'label' => 'admin.condition.operator',
                'choices' => self::OPERATORS,
                'placeholder' => '—',
            ])
            ->add('value', TextType::class, [
                'required' => false,
                'label' => 'admin.condition.value',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ConditionRule::class, 'translation_domain' => 'admin']);
    }
}
