<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\EnrollmentLoadPolicyService;
use Domain\Student\Service\EnrollmentLoadService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisEnrollmentLoadController
{
    public function __construct(
        private EnrollmentLoadPolicyService $policies,
        private EnrollmentLoadService $loads,
        private \Domain\Student\Service\LoadDeterminationService $determinations,
    ) {
    }

    public function view(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse(
            $this->policies->view(FormInput::from($request)->requiredNullableId("program_id")),
        );
    }

    public function programs(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->policies->programs($f->search("search", 80), $f->optionalId("after")));
    }

    public function history(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->policies->timeline($f->requiredNullableId("program_id"), $f->optionalText("cursor", 8192)),
        );
    }

    public function review(#[\SensitiveParameter] ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->policies->review(
                $f->requiredNullableId("program_id"),
                $this->definition($request),
                $f->text("revision", 64),
                $f->bodyText("reason"),
            ),
        );
    }

    public function publish(#[\SensitiveParameter] ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->policies->publish(
                $f->requiredNullableId("program_id"),
                $f->id("operation_id"),
                $this->definition($request),
                $f->text("revision", 64),
                $f->bodyText("reason"),
                $f->bodyText("review_token"),
            ),
        );
    }

    private function definition(#[\SensitiveParameter] ServerRequest $request): ?array
    {
        $body = $request->getParsedBody();
        if (
            !is_array($body) ||
            !array_key_exists("definition", $body) ||
            ($body["definition"] !== null && !is_array($body["definition"]))
        ) {
            throw new ValidationException([
                [
                    "field" => "definition",
                    "message" =>
                        "Provide credit bands, or explicitly choose inheritance or no classification.",
                ],
            ]);
        }
        return $body["definition"];
    }

    public function staffTerms(ServerRequest $request, string $student, string $pursuit): ResponseInterface
    {
        $f = FormInput::from($request);
        if ($f->boolean('for_records')) {
            return new JsonResponse($this->determinations->terms(
                new EntityId($student),
                new EntityId($pursuit),
                'staff',
                $f->search('search', 80),
                $f->optionalId('after')
            ));
        }
        return new JsonResponse(
            $this->loads->terms(
                new EntityId($student),
                new EntityId($pursuit),
                "staff",
                $f->search("search", 80),
                $f->optionalId("after"),
            ),
        );
    }

    public function staffLoad(ServerRequest $request, string $student, string $pursuit): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->loads->review(new EntityId($student), new EntityId($pursuit), $f->id("term_id"), "staff"),
        );
    }

    public function ownerTerms(ServerRequest $request, string $student, string $pursuit): ResponseInterface
    {
        $f = FormInput::from($request);
        if ($f->boolean('for_records')) {
            return new JsonResponse($this->determinations->terms(
                new EntityId($student),
                new EntityId($pursuit),
                'owner',
                $f->search('search', 80),
                $f->optionalId('after')
            ));
        }
        return new JsonResponse(
            $this->loads->terms(
                new EntityId($student),
                new EntityId($pursuit),
                "owner",
                $f->search("search", 80),
                $f->optionalId("after"),
            ),
        );
    }

    public function ownerLoad(ServerRequest $request, string $student, string $pursuit): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->loads->review(new EntityId($student), new EntityId($pursuit), $f->id("term_id"), "owner"),
        );
    }

    public function advisorTerms(ServerRequest $request, string $student, string $pursuit): ResponseInterface
    {
        $f = FormInput::from($request);
        if ($f->boolean('for_records')) {
            return new JsonResponse($this->determinations->terms(
                new EntityId($student),
                new EntityId($pursuit),
                'advisor',
                $f->search('search', 80),
                $f->optionalId('after')
            ));
        }
        return new JsonResponse(
            $this->loads->terms(
                new EntityId($student),
                new EntityId($pursuit),
                "advisor",
                $f->search("search", 80),
                $f->optionalId("after"),
            ),
        );
    }

    public function advisorLoad(ServerRequest $request, string $student, string $pursuit): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->loads->review(
                new EntityId($student),
                new EntityId($pursuit),
                $f->id("term_id"),
                "advisor",
            ),
        );
    }
    public function recordReview(
        #[\SensitiveParameter] ServerRequest $request,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse($this->determinations->review(
            new EntityId($student),
            new EntityId($pursuit),
            $f->id('term_id'),
            $f->bodyText('reason')
        ));
    }
    public function recordConfirm(
        #[\SensitiveParameter] ServerRequest $request,
        string $student,
        string $pursuit,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse($this->determinations->confirm(
            new EntityId($student),
            new EntityId($pursuit),
            $f->id('term_id'),
            $f->id('operation_id'),
            $f->text('revision', 64),
            $f->bodyText('reason'),
            $f->bodyText('review_token')
        ));
    }

    public function staffRecords(ServerRequest $request, string $student, string $pursuit): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->determinations->listing(
            new EntityId($student),
            new EntityId($pursuit),
            $f->id('term_id'),
            'staff',
            $f->optionalId('after')
        ));
    }

    public function ownerRecords(ServerRequest $request, string $student, string $pursuit): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->determinations->listing(
            new EntityId($student),
            new EntityId($pursuit),
            $f->id('term_id'),
            'owner',
            $f->optionalId('after')
        ));
    }

    public function advisorRecords(ServerRequest $request, string $student, string $pursuit): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->determinations->listing(
            new EntityId($student),
            new EntityId($pursuit),
            $f->id('term_id'),
            'advisor',
            $f->optionalId('after')
        ));
    }
}
