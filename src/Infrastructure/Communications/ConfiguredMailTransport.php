<?php

declare(strict_types=1);

namespace Infrastructure\Communications;

use Codefy\Framework\Support\CodefyMailer;
use DomainException;
use Domain\Communications\Service\MailTransport;
use Domain\Shared\ValueObject\EntityId;
use Qubus\Config\ConfigContainer;
use Throwable;

final readonly class ConfiguredMailTransport implements MailTransport
{
    public function __construct(private ConfigContainer $config)
    {
    }

    public function assertReady(): void
    {
        if (
            $this->config->getConfigKey('sis.mail_enabled', false) !== true
            || $this->config->getConfigKey('mailer.debug', false) !== false
        ) {
            throw new DomainException('Live delivery is disabled or mail debugging is enabled.');
        }
        $from = $this->config->getConfigKey('sis.mail_from', '');
        if (!is_string($from) || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('A valid institutional sender is required.');
        }
    }

    public function send(
        EntityId $delivery,
        #[\SensitiveParameter] string $recipient,
        #[\SensitiveParameter] string $subject,
        #[\SensitiveParameter] string $body
    ): void {
        $this->assertReady();
        $from = $this->config->getConfigKey('sis.mail_from', '');
        try {
            $sent = new CodefyMailer($this->config)->withFrom($from)->withTo($recipient)->withSubject($subject)
                ->withHtml(false)->withBody($body)->withCustomHeader('X-SIS-Delivery-ID', $delivery->value)->send();
            if (!$sent) {
                throw new DomainException('Delivery could not be confirmed.');
            }
        } catch (Throwable) {
            // Do not retain upstream errors containing addresses, message text, or credentials.
            throw new DomainException('Delivery could not be confirmed.');
        }
    }
}
