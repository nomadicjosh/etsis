<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Reporting\Service\RelationalReportService;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\TextResponse;
use League\Csv\CannotInsertRecord;
use League\Csv\EscapeFormula;
use League\Csv\Exception;
use League\Csv\InvalidArgument;
use League\Csv\Writer;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisRelationalReportController
{
    public function __construct(private RelationalReportService $service)
    {
    }
    public function catalog(): ResponseInterface
    {
        return new JsonResponse($this->service->catalog());
    }
    public function graph(ServerRequest $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_array($body['aliases'] ?? null)) {
            throw new ValidationException([['field' => 'aliases', 'message' => 'Choose named record copies.']]);
        }
        return new JsonResponse($this->service->graph(
            FormInput::from($request)->text('dataset', 80),
            $body['aliases']
        ));
    }
    public function preview(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse(
            $this->service->preview($this->definition($request), FormInput::from($request)->integer("offset")),
        );
    }
    public function save(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->save(
            $f->id("report_id"),
            $f->id("operation_id"),
            $f->integer("version", -1),
            $f->text("name"),
            $this->definition($request),
        );
        return new JsonResponse(["id" => $f->id("report_id")->value], 201);
    }
    public function show(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($id)));
    }
    public function run(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->run(new EntityId($id), FormInput::from($request)->integer("offset")));
    }
    public function population(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->population(
            new EntityId($id),
            FormInput::from($request)->integer('offset')
        ));
    }
    public function settings(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->reportSettings(new EntityId($id)));
    }
    public function configure(ServerRequest $request, string $id): ResponseInterface
    {
        $form = FormInput::from($request);
        $days = $form->optionalText('expires_after_days', 4);
        if ($days !== null && preg_match('/^[1-9][0-9]{0,3}$/D', $days) !== 1) {
            throw new ValidationException([['field' => 'expires_after_days',
                'message' => 'Enter whole days or leave blank.']]);
        }
        return new JsonResponse($this->service->configureReport(
            new EntityId($id),
            $form->id('operation_id'),
            $form->integer('version', -1),
            $form->text('mode', 12),
            $days === null ? null : (int) $days
        ));
    }
    public function selectStudents(ServerRequest $request, string $id): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_array($body['student_ids'] ?? null)) {
            throw new ValidationException([['field' => 'students', 'message' => 'Choose students from this report.']]);
        }
        return new JsonResponse($this->service->selectStudents(
            new EntityId($id),
            $body['student_ids'],
            FormInput::from($request)->integer('report_version')
        ));
    }
    /**
     * @throws InvalidArgument
     * @throws CannotInsertRecord
     * @throws Exception
     */
    public function export(ServerRequest $request, string $id): ResponseInterface
    {
        $page = $this->service->run(new EntityId($id), FormInput::from($request)->integer("offset"));
        $csv = Writer::fromString();
        $csv->setEscape("");
        $formatter = new EscapeFormula("'", [" ", "\n"]);
        $csv->addFormatter($formatter->escapeRecord(...));
        $csv->insertOne($page["columns"]);
        foreach ($page["items"] as $row) {
            $csv->insertOne(
                array_map(static fn(mixed $v): mixed => is_bool($v) ? ($v ? "Yes" : "No") : $v, array_values($row)),
            );
        }
        return new TextResponse($csv->toString(), 200, [
            "Content-Type" => "text/csv; charset=UTF-8",
            "Content-Disposition" => 'attachment; filename="report-studio-page.csv"',
            "Cache-Control" => "private, no-store",
            "X-Content-Type-Options" => "nosniff",
            "Content-Security-Policy" => "sandbox; default-src 'none'",
        ]);
    }
    /** @return array<string, mixed> */
    private function definition(ServerRequest $request): array
    {
        $body = $request->getParsedBody();
        $definition = is_array($body) ? $body["definition"] ?? null : null;
        if (!is_array($definition) || array_is_list($definition)) {
            throw new ValidationException([
                ["field" => "report", "message" => "Provide a structured report definition."],
            ]);
        }
        return $definition;
    }
}
