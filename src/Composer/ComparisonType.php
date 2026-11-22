<?php

declare(strict_types=1);

namespace App\Composer;

use App\Catalog\Catalog;
use App\Catalog\Places;
use App\Import\ImportStore;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The form of "Compare destinations": countries and cities of the catalog, and a month.
 *
 * @extends AbstractType<Comparison>
 */
final class ComparisonType extends AbstractType
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly Places $places,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Countries and cities: an aggregate such as EU27_2020 is not a destination.
        $places = [];
        foreach ($this->catalog->all() as $entry) {
            // Not the places of a dataset a visitor added (the two hundred countries of the World
            // Bank): the shipped indicators have no figure for them.
            if (ImportStore::owns($entry->id)) {
                continue;
            }
            $places = [...$places, ...array_filter($entry->geo, static fn (string $geo): bool => Places::isCountry($geo) || Places::isCity($geo))];
        }
        $choices = $this->places->choices($places);

        $builder
            ->add('places', ChoiceType::class, [
                'label' => 'Destinations',
                'multiple' => true,
                'expanded' => true,
                'choices' => $choices,
                'choice_label' => $this->places->label(...),
                'choice_translation_domain' => false,
                'help' => isset($choices['Cities']) ? 'Two to six. A city shows the figures of its country where the source has no figure for the city.' : 'Two to six.',
            ])
            ->add('month', ChoiceType::class, [
                'label' => 'Month',
                'expanded' => true,
                'choices' => array_map(static fn (int $m): string => \sprintf('%02d', $m), range(1, 12)),
                'choice_label' => fn (string $m): string => PageComposer::monthName($m, $this->places->getLocale(), short: true),
                'choice_attr' => fn (string $m): array => ['aria-label' => PageComposer::monthName($m, $this->places->getLocale())],
                // Month names are written by ICU in the page's language, not looked up in a catalogue.
                'choice_translation_domain' => false,
                'invalid_message' => 'Choose one month.',
            ]);

        // One month: a hand-made link that names several is refused, not read as its first one.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            if (\is_array($data) && \is_array($data['month'] ?? null)) {
                $data['month'] = 'several';
                $event->setData($data);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Comparison::class,
            'method' => 'GET',
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }
}
