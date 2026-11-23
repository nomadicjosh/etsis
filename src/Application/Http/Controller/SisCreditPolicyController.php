<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Catalog\Service\CatalogEditor;
use Domain\Catalog\ValueObject\CreditPolicy;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisCreditPolicyController
{
    public function __construct(private CatalogEditor $catalog)
    {
    }

    public function show(string $kind, string $id): ResponseInterface
    {
        $this->kind($kind);
        return new JsonResponse($this->catalog->creditSettings($kind, new EntityId($id)));
    }

    public function save(ServerRequest $request, string $kind, string $id): ResponseInterface
    {
        $this->kind($kind);
        $f = FormInput::from($request);
        $key = new EntityId($id);
        $revision = $f->text('revision', 64);
        if ($kind === 'courses') {
            $this->catalog->creditPolicy($key, $f->integer('credits_milli', 0, 999999), new CreditPolicy(
                $f->integer('minimum_milli', 0, 999999),
                $f->integer('maximum_milli', 0, 999999),
                $f->integer('increment_milli', 0, 999999),
            ), $revision);
        } else {
            $this->catalog->sectionCredits($key, $f->requiredBoolean('use_default') ? null
                : $f->integer('offering_credits_milli', 0, 999999), $revision);
        }
        return new JsonResponse($this->catalog->creditSettings($kind, $key));
    }

    private function kind(string $kind): void
    {
        if (!in_array($kind, ['courses', 'sections'], true)) {
            throw new InvalidArgumentException('Choose a course or section.');
        }
    }
}
