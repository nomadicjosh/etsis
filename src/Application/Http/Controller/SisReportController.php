<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Reporting\Service\ReportService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\TextResponse;
use League\Csv\Writer;
use League\Csv\EscapeFormula;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisReportController
{
    public function __construct(private ReportService $service)
    {
    }

    public function create(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $report = $this->service->create(
            $f->text('name'),
            $f->optionalId('program_id'),
            $f->optionalId('term_id'),
            $f->optionalBoolean('active')
        );

        return new JsonResponse(['id' => $report->id->value], 201);
    }

    public function share(ServerRequest $request, string $id): ResponseInterface
    {
        $this->service->share(new EntityId($id), FormInput::from($request)->id('user_id'));

        return new JsonResponse(['status' => 'shared']);
    }

    public function shares(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->shares(
            new EntityId($id),
            FormInput::from($request)->optionalId('after')
        ));
    }

    public function unshare(string $id, string $user): ResponseInterface
    {
        $this->service->unshare(new EntityId($id), new EntityId($user));

        return new JsonResponse(['status' => 'revoked']);
    }

    public function show(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($id)));
    }

    public function run(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->run(new EntityId($id), FormInput::from($request)->optionalId('after')));
    }

    public function export(ServerRequest $request, string $id): ResponseInterface
    {
        $page = $this->service->export(new EntityId($id), FormInput::from($request)->optionalId('after'));
        $csv = Writer::fromString();
        $csv->setEscape('');
        $escape = new EscapeFormula("'", [' ', "\n"]);
        $csv->addFormatter($escape->escapeRecord(...));
        $csv->insertOne(['Institutional ID', 'Primary program', 'Entry term', 'Student activity']);
        foreach ($page['items'] as $row) {
            $csv->insertOne([$row['institutional_id'], $row['program_code'], $row['entry_term_code'],
                $row['active'] ? 'Active' : 'Inactive']);
        }

        return new TextResponse($csv->toString(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="student-report-page.csv"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
        ]);
    }
}
