<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Advising\Service\BookingPolicyService;
use Domain\Shared\Exception\RecordNotFoundException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

use function Codefy\Framework\Helpers\trans;

final readonly class BookingPolicyController
{
    public function __construct(private BookingPolicyService $service)
    {
    }

    public function handle(#[\SensitiveParameter] ServerRequest $request, string $action): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(match ($action) {
            'view' => $this->service->view(),
            'history' => $this->service->history($f->optionalInteger('before')),
            'review' => $this->service->review($this->rules($f), $f->integer('version'), $f->bodyText('reason')),
            'save' => $this->service->save($f->id('id'), $this->rules($f), $f->integer('version'),
                $f->bodyText('reason'), $f->text('review_token', 8192)),
            default => throw new RecordNotFoundException(trans('Booking policy action not found.')),
        });
    }

    private function rules(FormInput $f): array
    {
        return ['maximum_active' => $f->integer('maximum_active'), 'notice_minutes' => $f->integer('notice_minutes'),
            'horizon_days' => $f->integer('horizon_days')];
    }
}
