<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\env;

return ["configuration_file" => env("SIS_BACKUP_CONFIG_FILE", "")];
