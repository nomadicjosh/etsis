<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Reporting\Service\RelationalReportService;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\TextResponse;
use League\Csv\EscapeFormula;
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
