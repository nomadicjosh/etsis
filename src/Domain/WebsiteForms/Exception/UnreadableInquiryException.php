<?php

declare(strict_types=1);

namespace Domain\WebsiteForms\Exception;

use Qubus\Exception\Exception;

use function Codefy\Framework\Helpers\trans_html;

/** An encrypted working inquiry failed identity or content verification. */
final class UnreadableInquiryException extends \RuntimeException
{
    /**
     * @throws Exception
     */
    public function __construct()
    {
        parent::__construct(trans_html('The private website inquiry could not be verified.'));
    }
}
