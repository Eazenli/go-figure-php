<?php

declare(strict_types=1);

namespace App\Composer;

use App\Catalog\Catalog;
use App\Catalog\CatalogEntry;
use App\Catalog\Places;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The composer form, built from the catalog: the indicators are its entries, the places are every
 * place of every entry, and the periods are those of the chosen indicator.
 *
 * @extends AbstractType<Composition>
 */
final class CompositionType extends AbstractType
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly Places $places,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $entries = $this->catalog->all();

        $builder
            ->add('dataset', ChoiceType::class, [
                'label' => 'Indicator',
                'choices' => array_keys($entries),
                'choice_label' => fn (string $id): string => $entries[$id]->titleIn($this->places->getLocale()),
                'choice_translation_domain' => false,
            ])
            ->add('places', ChoiceType::class, [
                'label' => 'Places',
                'multiple' => true,
                'expanded' => true,
                'choices' => $this->places->choices(array_merge(...array_values(array_map(static fn (CatalogEntry $e) => $e->geo, $entries)))),
                'choice_label' => $this->places->label(...),
                'choice_translation_domain' => false,
                'help' => 'Up to six. A place the indicator does not cover is refused, not guessed.',
            ]);

        // The periods depend on the indicator: on display, from the data; on submit, from the request.
        $periodsOf = static function (mixed $dataset) use ($entries): array {
            $entry = $entries[\is_string($dataset) ? $dataset : ''] ?? reset($entries);

            return false === $entry ? [] : $entry->time->periods();
        };
        $addPeriods = static function (FormInterface $form, array $periods): void {
            $form
                ->add('from', ChoiceType::class, ['label' => 'From', 'choices' => $periods, 'choice_label' => static fn (string $p): string => $p, 'choice_translation_domain' => false])
                ->add('to', ChoiceType::class, ['label' => 'To', 'choices' => $periods, 'choice_label' => static fn (string $p): string => $p, 'choice_translation_domain' => false]);
        };

        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event) use ($periodsOf, $addPeriods): void {
            $composition = $event->getData();
            $periods = $periodsOf($composition?->dataset);
            // A first visit offers the whole range.
            if ($composition instanceof Composition && [] !== $periods) {
                $composition->from ??= $periods[0];
                $composition->to ??= $periods[array_key_last($periods)];
            }
            $addPeriods($event->getForm(), $periods);
        });
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event) use ($periodsOf, $addPeriods): void {
            $data = $event->getData();
            $data = \is_array($data) ? $data : [];
            $periods = $periodsOf($data['dataset'] ?? null);
            // Without JS, the period lists still hold the previous indicator's periods when the
            // visitor switches: periods this indicator does not have become its whole range.
            if ([] !== $periods) {
                $data['from'] = \in_array($data['from'] ?? null, $periods, true) ? $data['from'] : $periods[0];
                $data['to'] = \in_array($data['to'] ?? null, $periods, true) ? $data['to'] : $periods[array_key_last($periods)];
                $event->setData($data);
            }
            $addPeriods($event->getForm(), $periods);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Composition::class,
            // A GET form: the page can be bookmarked and shared, and it changes nothing.
            'method' => 'GET',
            'csrf_protection' => false,
            // A shared link may carry tracking parameters (utm_source…): not an error.
            'allow_extra_fields' => true,
        ]);
    }
}
