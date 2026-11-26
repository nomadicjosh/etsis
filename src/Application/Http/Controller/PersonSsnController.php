<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Person\Service\PersonSsnService;
use Domain\Person\ValueObject\SocialSecurityNumber;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

use function Codefy\Framework\Helpers\trans_html;

final readonly class PersonSsnController
{
    public function __construct(private PersonSsnService $service)
    {
    }

    public function view(string $person): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($person)));
    }

    public function review(#[\SensitiveParameter] ServerRequest $request, string $person): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->review(
            new EntityId($person),
            $f->integer('version', 0, 499),
            $this->number($request),
            $f->bodyText('reason')
        ));
    }

    public function confirm(#[\SensitiveParameter] ServerRequest $request, string $person): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->confirm(
            new EntityId($person),
            $f->id('operation_id'),
            $f->integer('version', 0, 499),
            $this->number($request),
            $f->bodyText('reason'),
            $f->bodyText('review_token')
        ));
    }

    public function history(#[\SensitiveParameter] ServerRequest $request, string $person): ResponseInterface
    {
        return new JsonResponse($this->service->timeline(
            new EntityId($person),
            FormInput::from($request)->optionalText('cursor', 8192)
        ));
    }

    private function number(#[\SensitiveParameter] ServerRequest $request): ?SocialSecurityNumber
    {
        $data = $request->getParsedBody();
        if (!is_array($data) || !array_key_exists('ssn', $data)) {
            throw new ValidationException([['field' => 'ssn', 'message' => trans_html(
                'Provide a replacement SSN or explicitly select clearing the number.'
            )]]);
        }
        if ($data['ssn'] === null && ($data['ssn_confirmation'] ?? null) === null) {
            return null;
        }
        if (!is_string($data['ssn']) || !is_string($data['ssn_confirmation'] ?? null)) {
            throw new ValidationException([['field' => 'ssn', 'message' => trans_html(
                'Enter the replacement SSN twice.'
            )]]);
        }
        $number = new SocialSecurityNumber($data['ssn']);
        if (!hash_equals($number->digits(), new SocialSecurityNumber($data['ssn_confirmation'])->digits())) {
            throw new ValidationException([['field' => 'ssn_confirmation',
                'message' => trans_html('The replacement SSN entries do not match.')]]);
        }
        return $number;
    }
}
