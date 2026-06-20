<?php

namespace Tallyst\FormBuilder\Form;

use Tallyst\FormBuilder\Entity\FormDefinition;

/**
 * Builds the field schema in the two shapes the rest of the module needs:
 *  - client(): rich shape embedded in the rendered form for the Stimulus controller,
 *  - condition(): lean {key, conditions} shape fed to ConditionEvaluator (server).
 *
 * Both derive from the SAME FormField data — one definition, no drift.
 */
class FormSchemaFactory
{
    /**
     * @return list<array{key: string, type: string, required: bool, conditions: array<string, mixed>}>
     */
    public function client(FormDefinition $form): array
    {
        $schema = [];
        foreach ($form->getFields() as $field) {
            $schema[] = [
                'key' => $field->getKey(),
                'type' => $field->getType(),
                'required' => $field->isRequired(),
                'conditions' => $field->getConditions(),
            ];
        }

        return $schema;
    }

    /**
     * @return list<array{key: string, conditions: array<string, mixed>}>
     */
    public function condition(FormDefinition $form): array
    {
        $schema = [];
        foreach ($form->getFields() as $field) {
            $schema[] = [
                'key' => $field->getKey(),
                'conditions' => $field->getConditions(),
            ];
        }

        return $schema;
    }
}
