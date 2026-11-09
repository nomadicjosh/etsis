<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Codefy\Framework\Auth\Gate;
use Domain\Shared\ValueObject\EntityId;
use Domain\Workspace\Service\WorkspaceService;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;
use Qubus\Routing\Psr7Router;

use function Codefy\Framework\Helpers\view;

final readonly class SisWorkspaceController
{
    public function __construct(private WorkspaceService $service, private Gate $gate, private Psr7Router $router)
    {
    }

    public function dashboard(): ResponseInterface
    {
        return $this->page('overview');
    }

    public function page(string $section): ResponseInterface
    {
        $sections = ['overview', 'people', 'registration', 'students', 'applications', 'courses', 'sections',
            'programs', 'terms',
            'rooms', 'bookings', 'employees', 'timesheets', 'accounts', 'journals', 'reports', 'campaigns',
            'sharedreports', 'grades', 'teaching', 'billing', 'ledger', 'institutions', 'external', 'equivalencies',
            'transcripts', 'portal', 'forms', 'imports', 'rules', 'access', 'apply'];
        if (!in_array($section, $sections, true)) {
            throw new InvalidArgumentException('Unknown workspace.');
        }
        $permissions = $this->service->capabilities();
        $user = $this->gate->current();

        return view('framework::backend/workspace/index', [
            'title' => 'etsis · Campus workspace', 'section' => $section, 'permissions' => $permissions,
            'loginUrl' => $this->router->url('auth.login'),
            'displayName' => is_object($user) && is_string($user->first_name ?? null) ? $user->first_name : 'there',
        ])->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; "
                . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; "
                . "connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
    }

    public function metrics(): ResponseInterface
    {
        return new JsonResponse($this->service->dashboard());
    }

    public function records(ServerRequest $request, string $dataset): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_string($body['search'] ?? '') || !is_string($body['after'] ?? '')) {
            throw new InvalidArgumentException('Invalid workspace filter.');
        }
        $after = $body['after'] ?? '';

        return new JsonResponse($this->service->records(
            $dataset,
            $body['search'] ?? '',
            $after === '' ? null : new EntityId($after)
        ));
    }

    public function me(): ResponseInterface
    {
        return new JsonResponse($this->service->me());
    }

    public function person(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->person(new EntityId($id)));
    }
}
