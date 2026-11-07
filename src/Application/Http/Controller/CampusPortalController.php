<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Application\View\CampusRenderer;
use Codefy\Framework\Auth\Gate;
use Domain\Finance\ValueObject\AccountId;
use Domain\Portal\Service\CampusService;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\RecordNotFoundException;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;
use InvalidArgumentException;

final readonly class CampusPortalController
{
    public function __construct(private CampusService $service, private CampusRenderer $renderer, private Gate $gate)
    {
    }

    public function home(): ResponseInterface
    {
        return $this->renderer->render('home', 'A place for your next chapter', $this->service->publicData());
    }

    public function landing(ServerRequest $request): ResponseInterface
    {
        if (!$this->gate->isLoggedIn()) {
            return new RedirectResponse($this->renderer->loginUrl());
        }
        if (($request->getQueryParams()['continue'] ?? null) === 'apply') {
            return new RedirectResponse('/portal/apply/');
        }
        return new RedirectResponse($this->renderer->canUseWorkspace() ? '/admin/' : '/portal/student/');
    }

    public function catalog(): ResponseInterface
    {
        return $this->renderer->render('catalog', 'Explore courses', $this->service->publicData());
    }

    public function news(ServerRequest $request): ResponseInterface
    {
        $items = $this->service->news(false, $this->after($request));
        return $this->renderer->render('news', 'Around campus', ['news' => $items,
            'olderAfter' => count($items) === 24 ? $items[23]['id'] : null]);
    }

    public function article(string $code): ResponseInterface
    {
        try {
            $article = $this->service->article($code);
        } catch (RecordNotFoundException) {
            return $this->renderer->render('not-found', 'Article unavailable')->withStatus(404);
        }
        return $this->renderer->render('article', $article['title'], ['article' => $article]);
    }

    public function apply(): ResponseInterface
    {
        return $this->renderer->render(
            'workspace',
            'Start your application',
            ['section' => 'applications', 'public' => $this->service->publicData(), 'applicationPage' => true]
        );
    }

    public function student(string $section = 'overview'): ResponseInterface
    {
        if (!$this->gate->isLoggedIn()) {
            return new RedirectResponse($this->renderer->loginUrl());
        }
        if (
            !in_array($section, ['overview', 'profile', 'applications', 'grades', 'registration',
            'transcripts', 'accounts', 'updates'], true)
        ) {
            throw new InvalidArgumentException('Unknown student service.');
        }
        return $this->renderer->render(
            'workspace',
            'My campus',
            ['section' => $section, 'public' => $this->service->publicData(), 'applicationPage' => false]
        );
    }

    public function publicData(): ResponseInterface
    {
        return new JsonResponse($this->service->publicData());
    }

    public function programs(ServerRequest $request): ResponseInterface
    {
        $search = $request->getQueryParams()['q'] ?? '';
        if (!is_string($search)) {
            throw new InvalidArgumentException('Invalid program search.');
        }
        return new JsonResponse($this->service->programs($search, $this->after($request)));
    }

    public function campusNews(ServerRequest $request): ResponseInterface
    {
        $items = $this->service->news(true, $this->after($request));
        return new JsonResponse(['items' => $items,
            'next_after' => count($items) === 24 ? $items[23]['id'] : null]);
    }

    private function after(ServerRequest $request): ?EntityId
    {
        $value = $request->getQueryParams()['after'] ?? '';
        if (!is_string($value)) {
            throw new InvalidArgumentException('Invalid page cursor.');
        }
        return $value === '' ? null : new EntityId($value);
    }

    public function courses(ServerRequest $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        if (!is_string($query['q'] ?? '')) {
            throw new InvalidArgumentException('Invalid search.');
        }
        $id = static function (mixed $value): ?EntityId {
            if ($value === null || $value === '') {
                return null;
            }
            if (!is_string($value)) {
                throw new InvalidArgumentException('Invalid catalog filter.');
            }
            return new EntityId($value);
        };
        return new JsonResponse($this->service->catalog(
            $id($query['term'] ?? null),
            $query['q'] ?? '',
            $id($query['after'] ?? null)
        ));
    }

    public function me(): ResponseInterface
    {
        return new JsonResponse($this->service->me());
    }

    public function contact(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $body = $request->getParsedBody();
        foreach (['preferred_name', 'phone', 'address'] as $key) {
            if (!is_array($body) || !is_string($body[$key] ?? null)) {
                throw new InvalidArgumentException('Contact details are required.');
            }
        }
        $this->service->saveContact($body['preferred_name'], $body['phone'], $body['address'], $f->integer('version'));
        return new JsonResponse(['status' => 'saved']);
    }

    public function register(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->register($f->id('student_id'), $f->id('section_id'));
        return new JsonResponse(['status' => 'registered']);
    }

    public function drop(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->drop($f->id('student_id'), $f->id('section_id'));
        return new JsonResponse(['status' => 'dropped']);
    }

    public function transcript(string $id): ResponseInterface
    {
        return new JsonResponse(['items' => $this->service->transcript(new EntityId($id))]);
    }

    public function scores(string $id, string $section): ResponseInterface
    {
        return new JsonResponse(['items' => $this->service->scores(new EntityId($id), new EntityId($section))]);
    }

    public function statement(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->statement(new AccountId($id)));
    }

    public function requestTranscript(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $body = $request->getParsedBody();
        $recipient = is_array($body) && is_string($body['recipient'] ?? null) ? $body['recipient'] : '';
        $id = $f->id('request_id');
        $this->service->requestTranscript($id, $f->id('student_id'), $f->text('delivery', 12), $recipient);
        return new JsonResponse(['id' => $id->value, 'status' => 'pending'], 201);
    }

    public function transcriptRequest(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->transcriptRequest(new EntityId($id)));
    }

    public function transcriptQueue(): ResponseInterface
    {
        return new JsonResponse(['items' => $this->service->transcriptQueue()]);
    }

    public function transitionTranscript(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->transitionTranscript(new EntityId($id), $f->text('status', 16), $f->integer('version'));
        return new JsonResponse(['status' => 'changed']);
    }
}
