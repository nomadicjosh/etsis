<?php

declare(strict_types=1);

namespace Domain\User\Validator;

use Codefy\Framework\Dto\Attribute\UseDto;
use Domain\User\Dto\UpdateUserData;
use ReflectionException;

use function Codefy\Framework\Helpers\gate;
use function Codefy\Framework\Helpers\user;

#[UseDto(UpdateUserData::class)]
final class UpdateProfileValidator extends UpdateUserValidator
{
    public function authorize(): bool
    {
        return in_array($this->request->getMethod(), ['PUT', 'PATCH'], true)
        && gate('admin:profile');
    }

    /**
     * @throws ReflectionException
     */
    protected function prepareForValidation(): void
    {
        $current = user();
        $this->data = array_replace($this->all(), [
            'user_id' => is_object($current) ? ($current->user_id ?? null) : null,
        ]);
    }
}
