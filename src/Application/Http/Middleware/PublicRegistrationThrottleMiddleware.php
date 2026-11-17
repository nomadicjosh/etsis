<?php

declare(strict_types=1);

namespace Application\Http\Middleware;

use Application\Http\Response\PrivateErrorResponse;
use Codefy\Framework\Http\Throttle\Condition;
use Codefy\Framework\Http\Throttle\RateException;
use Codefy\Framework\Http\Throttle\RateLimiter;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Qubus\Config\ConfigContainer;
use Qubus\Exception\Data\TypeException;

final readonly class PublicRegistrationThrottleMiddleware implements MiddlewareInterface
{
    private RateLimiter $limiter;

    /**
     * @throws TypeException
     */
    public function __construct(ConfigContainer $config, CacheItemPoolInterface $cache)
    {
        $this->limiter = new RateLimiter($cache);
        $this->limiter->add(new Condition(
            $config->integer('throttle.registration.ttl', 600),
            $config->integer('throttle.registration.max_attempts', 5),
        ));
    }

    /**
     * @throws InvalidArgumentException
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Use the server's peer address; caller-supplied forwarding headers cannot choose another bucket.
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $peer = is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false
        ? inet_pton($address) : 'unknown';
        $identifier = 'public-registration:' . hash('sha256', $peer);

        try {
            $this->limiter->increment($identifier);
        } catch (RateException $limit) {
            return PrivateErrorResponse::make(
                $request,
                429,
                'Too many account creation attempts. Please wait before trying again. You can still sign in '
                    . 'if you already have a campus account.',
            )->withHeader('Retry-After', (string) $limit->retryAfter)
                ->withHeader('Cache-Control', 'private, no-store, max-age=0')
                ->withHeader('Vary', 'Accept');
        }

        return $handler->handle($request);
    }
}
