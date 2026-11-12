<?php

declare(strict_types=1);

namespace Application\Http\Middleware;

use Application\Http\Security\MoodleSessionChannel;
use Psr\Container\ContainerInterface;
use Domain\Lms\Service\MoodleLaunchPolicy;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\{ServerRequestInterface, ResponseInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};

final readonly class MoodleSessionChannelMiddleware implements MiddlewareInterface
{
    public const string PATH = "/sis/moodle/session-channel/";
    public function __construct(private ContainerInterface $container, private MoodleLaunchPolicy $policy)
    {
    }
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        if ($request->getUri()->getPath() !== self::PATH) {
            return $handler->handle($request);
        }
        if (!$this->policy->enabled()) {
            return new TextResponse("Unavailable.", 403, ["Cache-Control" => "private, no-store"]);
        }
        return $this->container->get(MoodleSessionChannel::class)->handle($request);
    }
}
