<?php

declare(strict_types=1);

namespace Domain\User\Validator;

use Codefy\Framework\Dto\Attribute\UseDto;
use Codefy\Framework\Dto\HasDto;
use Codefy\Framework\Dto\Trait\DtoAware;
use Codefy\Framework\Validation\HttpInputValidator;
use Domain\User\Dto\UpdateUserData;

use function Codefy\Framework\Helpers\gate;
use function strtolower;

#[UseDto(UpdateUserData::class)]
class UpdateUserValidator extends HttpInputValidator implements HasDto
{
    use DtoAware;

    public function authorize(): bool
    {
        $method = strtolower($this->request->getMethod());

        return match ($method) {
            'put', 'patch' => (bool) gate('admin:edit:user'),
            default => false,
        };
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        $method = strtolower($this->request->getMethod());

        return match ($method) {
            'put', 'patch' => $this->update(),
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    private function update(): array
    {
        return [
            'user_id' => 'required|ulid',
            'first_name' => 'required|string|min:2|max:191',
            'middle_name' => 'string|min:1|max:191',
            'last_name' => 'required|string|min:2|max:191',
            'email' => 'required|email',
        ];
    }
}
