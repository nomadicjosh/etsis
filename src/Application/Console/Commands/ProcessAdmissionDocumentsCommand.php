<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Console\ConsoleCommand;
use Domain\Admissions\Service\AdmissionDocumentWorker;

final class ProcessAdmissionDocumentsCommand extends ConsoleCommand
{
    protected string $name = 'sis:admissions:documents';
    protected string $description = 'Scan quarantined admissions documents and finish approved file disposal.';
    protected array $options = [
        ['apply', null, 'none', 'Process documents; otherwise show usage only.'],
        ['limit', null, 'required', 'Maximum documents processed (1–3).', '1'],
    ];
    protected function configure(): void
    {
        parent::configure();
        $this->getDefinition()->getOption('limit')->setDefault('1');
    }
    public function handle(): int
    {
        $limit = $this->getOptions('limit');
        if (!is_string($limit) || !preg_match('/^[1-3]$/D', $limit)) {
            $this->terminalError('Use --limit=1..3.');
            return self::INVALID;
        }
        if ($this->getOptions('apply') !== true) {
            $this->terminalRaw('Use --apply to process a bounded batch; no documents were read or changed.');
            return self::SUCCESS;
        }
        try {
            $r = $this->codefy->make(AdmissionDocumentWorker::class)->run((int) $limit);
        } catch (\Throwable) {
            $this->terminalError('Document processing stopped. Check protected storage, migrations and scanner setup.');
            return self::FAILURE;
        }
        $this->terminalRaw('Examined ' . $r['examined'] . '; ready ' . $r['ready'] . '; rejected ' . $r['rejected']
            . '; pending ' . $r['pending'] . '; removed ' . $r['removed'] . '.');
        return self::SUCCESS;
    }
}
