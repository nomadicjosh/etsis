<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\ValueObject\EntityId;
use Domain\Workspace\Service\OperationsService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;
use League\Csv\Writer;
use League\Csv\EscapeFormula;
use Laminas\Diactoros\Response\TextResponse;

final readonly class SisOperationsController
{
    public function __construct(private OperationsService $service)
    {
    }

    public function page(ServerRequest $request, string $context, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->page(
            $context,
            new EntityId($id),
            FormInput::from($request)->optionalId('after')
        ));
    }

    public function rosterCsv(ServerRequest $request, string $id): ResponseInterface
    {
        $page = $this->service->page('roster', new EntityId($id), FormInput::from($request)->optionalId('after'));
        $csv = Writer::fromString();
        $csv->setEscape('');
        $escape = new EscapeFormula("'", [' ', "\n"]);
        $csv->addFormatter($escape->escapeRecord(...));
        $headers = ['Institutional ID', 'Given name', 'Family name', 'Registration status'];
        if ($page['can_grade']) {
            $headers[] = 'Final grade';
        }
        $csv->insertOne($headers);
        foreach ($page['items'] as $row) {
            $record = [$row['institutional_id'], $row['given_name'], $row['family_name'], $row['status']];
            if ($page['can_grade']) {
                $record[] = $row['grade_code'] ?? '';
            }
            $csv->insertOne($record);
        }

        return new TextResponse($csv->toString(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="section-roster.csv"',
            'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
        ]);
    }

    public function operationId(): ResponseInterface
    {
        return new JsonResponse(['id' => $this->service->operationId()->value]);
    }
}
