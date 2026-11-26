<?php

declare(strict_types=1);

namespace Application\Http;

use Domain\Registration\ValueObject\RegistrationPlanSelection;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Qubus\Http\ServerRequest;

final class RegistrationPlanInput
{
    public static function selection(ServerRequest $request): RegistrationPlanSelection
    {
        $input = FormInput::from($request);
        $ids = [];
        foreach ($input->values("section_ids", RegistrationPlanSelection::LIMIT) as $id) {
            if (!is_string($id)) {
                throw new ValidationException([
                    ["field" => "sections", "message" => "Choose valid course sections."],
                ]);
            }
            $ids[] = new EntityId($id);
        }
        return new RegistrationPlanSelection($input->id("student_id"), ...$ids)
            ->withCreditChoices(self::credits($request));
    }
    public static function credits(ServerRequest $request): array
    {
        $body = $request->getParsedBody();
        $choices = is_array($body) ? ($body['credit_choices'] ?? []) : [];
        if (!is_array($choices) || count($choices) > 25) {
            throw new ValidationException([['field' => 'credit_choices', 'message' =>
                \Codefy\Framework\Helpers\trans('Choose a bounded set of exact academic credit quantities.')]]);
        }
        return $choices;
    }
}
