<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\Catalog;
use App\Import\CityWeather;
use App\Import\ImportStore;
use App\Layout\Computed;
use App\Layout\Measured;
use App\Layout\Op;
use App\Proof\Canonical;
use App\Proof\MeasuredProof;
use App\Proof\ProofToken;
use App\Proof\Refusal;
use App\Proof\Resolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A stateless proof link: the token is decoded, the cell resolved again from the snapshot, and the
 * two fingerprints compared. Only a matching proof is shown.
 */
final class ProofController extends AbstractController
{
    public function __construct(
        private readonly Resolver $resolver,
        private readonly Catalog $catalog,
        private readonly CityWeather $weather,
    ) {
    }

    #[Route('/proof/{token}', name: 'proof', requirements: ['token' => '[A-Za-z0-9_-]+'], methods: ['GET'])]
    public function show(string $token): Response
    {
        $claimed = ProofToken::decode($token);
        if (null !== $claimed && 'computed' === ($claimed['type'] ?? null) && MeasuredProof::VERSION === ($claimed['v'] ?? null)) {
            return $this->computed($claimed);
        }
        if (null === $claimed || !self::isMeasured($claimed)) {
            return $this->render('proof.html.twig', ['problem' => 'This proof link is malformed.'], new Response(status: 404));
        }

        $resolved = $this->resolver->measured(new Measured('measured', $claimed['dataset'], $claimed['geo'], $claimed['time']));
        if ($resolved instanceof Refusal) {
            return $this->render('proof.html.twig', ['problem' => $resolved->reason->message()], new Response(status: 404));
        }

        if (!hash_equals($resolved->fingerprint(), Canonical::fingerprint($claimed))) {
            return $this->render('proof.html.twig', ['problem' => 'This link does not match the data of this snapshot.'], new Response(status: 404));
        }

        return $this->render('proof.html.twig', [
            'proof' => $resolved,
            // What the figure counts, and under which licence: shown on every proof.
            'entry' => $this->catalog->get($resolved->fact->dataset),
            // A city whose weather was added on this machine: said on its proofs.
            'added_here' => !($this->catalog->shipped()[$resolved->fact->dataset] ?? null)?->covers($resolved->fact->geo) && !ImportStore::owns($resolved->fact->dataset),
            'off_centre' => $this->weather->offCentre($resolved->fact->geo),
            'canonical' => $resolved->canonical(),
            'fingerprint' => $resolved->fingerprint(),
        ]);
    }

    /**
     * @param array<string, mixed> $proof
     *
     * @phpstan-assert-if-true array{dataset: string, geo: string, time: string} $proof
     */
    private static function isMeasured(array $proof): bool
    {
        return MeasuredProof::VERSION === ($proof['v'] ?? null)
            && 'measured' === ($proof['type'] ?? null)
            && \is_string($proof['dataset'] ?? null)
            && \is_string($proof['geo'] ?? null)
            && \is_string($proof['time'] ?? null);
    }

    /**
     * A computed figure: its link names the operation and the cells, and carries the fingerprint of
     * the whole proof. The cells are read again, the result computed again, the fingerprints compared.
     *
     * @param array<string, mixed> $claimed
     */
    private function computed(array $claimed): Response
    {
        $op = Op::tryFrom(\is_string($claimed['op'] ?? null) ? $claimed['op'] : '');
        $cells = $claimed['cells'] ?? null;
        $keys = array_keys($claimed);
        sort($keys);
        if (['cells', 'fp', 'op', 'type', 'v'] !== $keys || null === $op || !\is_array($cells) || !array_is_list($cells) || [] === $cells || \count($cells) > Resolver::MAX_OPERANDS || !\is_string($claimed['fp'] ?? null)) {
            return $this->render('proof.html.twig', ['problem' => 'This proof link is malformed.'], new Response(status: 404));
        }
        $operands = [];
        foreach ($cells as $cell) {
            if (!\is_array($cell) || !array_is_list($cell) || 3 !== \count($cell) || !\is_string($cell[0] ?? null) || !\is_string($cell[1] ?? null) || !\is_string($cell[2] ?? null)) {
                return $this->render('proof.html.twig', ['problem' => 'This proof link is malformed.'], new Response(status: 404));
            }
            $operands[] = new Measured('measured', $cell[0], $cell[1], $cell[2]);
        }
        $resolved = $this->resolver->computed(new Computed('computed', $op, $operands));
        if ($resolved instanceof Refusal) {
            return $this->render('proof.html.twig', ['problem' => $resolved->reason->message()], new Response(status: 404));
        }
        if (!hash_equals($resolved->fingerprint(), $claimed['fp'])) {
            return $this->render('proof.html.twig', ['problem' => 'This link does not match the data of this snapshot.'], new Response(status: 404));
        }

        return $this->render('proof_computed.html.twig', ['proof' => $resolved, 'canonical' => $resolved->canonical(), 'fingerprint' => $resolved->fingerprint()]);
    }
}
