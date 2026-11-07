<?php

declare(strict_types=1);

namespace Domain\User\Validator;

use Codefy\Framework\Dto\Attribute\UseDto;
use Codefy\Framework\Dto\HasDto;
use Codefy\Framework\Dto\Trait\DtoAware;
use Codefy\Framework\Proxy\Codefy;
use Codefy\Framework\Validation\HttpInputValidator;
use Domain\User\Dto\UpdateUserPassword;
use ReflectionException;

use function Codefy\Framework\Helpers\gate;
use function Codefy\Framework\Helpers\user;
use function strtolower;

#[UseDto(UpdateUserPassword::class)]
final class UpdateUserPasswordValidator extends HttpInputValidator implements HasDto
{
    use DtoAware;

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

    public function authorize(): bool
    {
        $method = strtolower($this->request->getMethod());

        return match ($method) {
            'put', 'patch' => (bool) gate('admin:profile'),
            default => false,
        };
    }

    /**
     * @return array<string, string>
     * @throws \Exception
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
     * @throws \Exception
     */
    private function update(): array
    {
        $passwordMinLength = Codefy::$PHP->configContainer->integer(key: 'auth.password_min_length');

        return [
            'user_id' => 'required|ulid',
            'password' => "required|string|min:{$passwordMinLength}|max:4096",
            'confirm_password' => 'required|string|same:password',
        ];
    }
}
