<?php

declare(strict_types=1);

namespace App\Import;

use App\Catalog\Theme;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The proposal of /import, as the visitor reviews it: one code per dimension of the dataset (from
 * its own codes), and the words of the entry. The server checks it again (Importer::problems()).
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class ProposalType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var DatasetStructure $structure */
        $structure = $options['structure'];
        $builder->add('code', HiddenType::class);

        $dimensions = $builder->create('dimensions', FormType::class, ['label' => 'What each figure counts', 'translation_domain' => 'messages']);
        foreach ($structure->dimensions as $dimension) {
            $dimensions->add($dimension->id, ChoiceType::class, [
                'label' => $dimension->label,
                'translation_domain' => false,
                // Eurostat's words, in English whatever the language of the page.
                'label_attr' => ['lang' => 'en'],
                'attr' => ['lang' => 'en'],
                // Shown "Total (TOTAL)": the label of Eurostat, then its code.
                'choices' => array_combine(
                    array_map(static fn (string $code, string $label): string => \sprintf('%s (%s)', $label, $code), array_keys($dimension->codes), $dimension->codes),
                    array_keys($dimension->codes),
                ),
                'choice_translation_domain' => false,
            ]);
        }
        $builder->add($dimensions);

        $builder
            ->add('title', TextType::class, ['label' => 'Title', 'help' => 'import.help.title', 'attr' => ['maxlength' => 80]])
            ->add('scope', TextareaType::class, ['label' => 'Scope', 'help' => 'import.help.scope', 'attr' => ['maxlength' => 400, 'rows' => 2]])
            ->add('unitLabel', TextType::class, ['label' => 'Unit', 'help' => 'import.help.unit', 'help_translation_parameters' => [], 'attr' => ['maxlength' => 60]])
            ->add('theme', EnumType::class, [
                'class' => Theme::class,
                'label' => 'The question it answers',
                'choice_label' => static fn (Theme $theme): string => $theme->question(),
            ])
            ->add('pitfalls', TextareaType::class, ['label' => 'Pitfalls', 'help' => 'import.help.pitfalls', 'required' => false, 'attr' => ['rows' => 3]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('structure');
        $resolver->setAllowedTypes('structure', DatasetStructure::class);
    }

    /**
     * The form's data for a proposal (an agent's, or the defaults of a dataset).
     *
     * @return array<string, mixed>
     */
    public static function data(Proposal $proposal, DatasetStructure $structure): array
    {
        $frozen = $proposal->frozen();
        $dimensions = [];
        foreach ($structure->dimensions as $dimension) {
            $chosen = $frozen[$dimension->id] ?? null;
            // A total by default, or else the first code.
            $dimensions[$dimension->id] = null !== $chosen && isset($dimension->codes[$chosen]) ? $chosen
                : (isset($dimension->codes['TOTAL']) ? 'TOTAL' : array_key_first($dimension->codes));
        }

        return [
            'code' => $structure->code,
            'dimensions' => $dimensions,
            'title' => $proposal->title,
            'scope' => $proposal->scope,
            'unitLabel' => $proposal->unitLabel,
            'theme' => $proposal->theme,
            'pitfalls' => implode("\n", $proposal->pitfalls),
        ];
    }

    /**
     * The proposal the visitor submitted.
     *
     * @param array<string, mixed> $data
     */
    public static function proposal(array $data): Proposal
    {
        $dimensions = [];
        foreach (\is_array($data['dimensions'] ?? null) ? $data['dimensions'] : [] as $dimension => $code) {
            $dimensions[] = new DimensionChoice((string) $dimension, (string) $code);
        }
        $pitfalls = array_values(array_filter(array_map(trim(...), preg_split('/\R/u', (string) ($data['pitfalls'] ?? '')) ?: [])));

        return new Proposal(
            code: (string) ($data['code'] ?? ''),
            dimensions: $dimensions,
            title: trim((string) ($data['title'] ?? '')),
            scope: trim((string) ($data['scope'] ?? '')),
            unitLabel: trim((string) ($data['unitLabel'] ?? '')),
            theme: ($data['theme'] ?? null) instanceof Theme ? $data['theme'] : Theme::Crowds,
            pitfalls: $pitfalls,
        );
    }
}
