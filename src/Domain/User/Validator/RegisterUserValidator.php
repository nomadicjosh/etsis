<?php

declare(strict_types=1);

namespace Domain\User\Validator;

use Codefy\Framework\Dto\Attribute\UseDto;
use Domain\User\Dto\StoreUserData;
use Domain\User\Enum\UserRole;

#[UseDto(StoreUserData::class)]
final class RegisterUserValidator extends StoreUserValidator
{
    public function authorize(): bool
    {
        return $this->request->getMethod() === 'POST';
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'first_name' => 'required|string|min:1|max:191',
            'last_name' => 'required|string|min:1|max:191',
        ]);
    }

    protected function prepareForValidation(): void
    {
        $this->data = array_replace($this->all(), ['role' => UserRole::USER->value]);
    }
}
