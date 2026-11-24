<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\AcademicRecord\Service\EarnedCeuService;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Qubus\Http\ServerRequest;

use function Codefy\Framework\Helpers\trans;

final readonly class SisEarnedCeuController
{
    public function __construct(private EarnedCeuService $service)
    {
    }

    public function records(ServerRequest $request, string $student): JsonResponse
    {
        return new JsonResponse($this->service->records(
            new EntityId($student),
            FormInput::from($request)->optionalId('after')
        ));
    }

    public function view(string $student, string $section, string $enrollment): JsonResponse
    {
        return new JsonResponse($this->service->view(
            new EntityId($student),
            new EntityId($section),
            new EntityId($enrollment)
        ));
    }

    public function review(ServerRequest $request, string $student, string $section, string $enrollment): JsonResponse
    {
        $input = FormInput::from($request);
        $body = $request->getParsedBody();
        if (
            !array_key_exists('earned_milli', $body) ||
            ($body['earned_milli'] !== null && !is_int($body['earned_milli']))
        ) {
            throw new ValidationException([['field' => 'earned_milli', 'message' =>
                trans('Choose explicit earned CEUs or revoke a recorded award.')]]);
        }
        return new JsonResponse($this->service->review(
            new EntityId($student),
            new EntityId($section),
            new EntityId($enrollment),
            $input->integer('expected_version', 0),
            $input->integer('expected_position', 1),
            $body['earned_milli'],
            $input->text('effective_on', 10),
            $input->text('reason', 1000),
            $input->requiredBoolean('acknowledged')
        ));
    }

    public function confirm(ServerRequest $request, string $student, string $section, string $enrollment): JsonResponse
    {
        $input = FormInput::from($request);
        $body = $request->getParsedBody();
        if (!is_array($body['command'] ?? null)) {
            throw new ValidationException([['field' => 'command', 'message' => trans('Review earned CEUs again.')]]);
        }
        return new JsonResponse($this->service->confirm(
            new EntityId($student),
            new EntityId($section),
            new EntityId($enrollment),
            $body['command'],
            $input->text('review_token', 8192)
        ));
    }

    public function history(ServerRequest $request, string $student, string $section, string $enrollment): JsonResponse
    {
        return new JsonResponse($this->service->history(
            new EntityId($student),
            new EntityId($section),
            new EntityId($enrollment),
            FormInput::from($request)->optionalText('cursor', 8192)
        ));
    }
}
