<?php

declare(strict_types=1);

namespace Domain\User\Validator;

use Codefy\Framework\Dto\Attribute\UseDto;
use Codefy\Framework\Dto\HasDto;
use Codefy\Framework\Dto\Trait\DtoAware;
use Codefy\Framework\Proxy\Codefy;
use Codefy\Framework\Validation\HttpInputValidator;
use Domain\User\Dto\StoreUserData;

use function Codefy\Framework\Helpers\gate;

#[UseDto(StoreUserData::class)]
class StoreUserValidator extends HttpInputValidator implements HasDto
{
    use DtoAware;

    public function authorize(): bool
    {
        return (bool) gate('admin:create:user');
    }

    /**
     * @return array<string, string>
     * @throws \Exception
     */
    public function rules(): array
    {
        $usernameLength = Codefy::$PHP->configContainer->integer('auth.username_min_length', 6);
        $passwordLength = Codefy::$PHP->configContainer->integer('auth.password_min_length', 26);

        return [
            'username' => "required|string|min:{$usernameLength}|max:20|regex:/^[a-z_-]+$/",
            'password' => "required|string|min:{$passwordLength}|max:4096",
            'first_name' => 'required|string|min:2|max:191',
            'middle_name' => 'string|min:1|max:191',
            'last_name' => 'required|string|min:2|max:191',
            'email' => 'required|email',
        ];
    }
}
