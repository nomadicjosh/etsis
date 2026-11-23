<?php

declare(strict_types=1);

namespace Application\Http\Middleware;

use Application\Http\Response\RegistrationPauseResponse;
use Application\Http\Security\RegistrationCounterLock;
use Codefy\Framework\Http\Throttle\Condition;
use Codefy\Framework\Http\Throttle\RateException;
use Codefy\Framework\Http\Throttle\RateLimiter;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Qubus\Config\ConfigContainer;
use RuntimeException;
use Throwable;

final readonly class PublicRegistrationThrottleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ConfigContainer $config,
        private CacheItemPoolInterface $cache,
        private RegistrationCounterLock $lock,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $loginPath = '/login/';
        try {
            $loginPath = '/' . trim($this->config->string('auth.login_route', 'login'), '/') . '/';
            $ttl = $this->config->integer('throttle.registration.ttl', 600);
            $attempts = $this->config->integer('throttle.registration.max_attempts', 5);
            $key = $this->config->string('app.crypto_key', '');
            if ($ttl < 1 || $ttl > 86400 || $attempts < 1 || $attempts > 10000 || strlen($key) < 32) {
                throw new RuntimeException('Registration counter settings are unavailable.');
            }
            // Trust only the server peer, never caller-supplied forwarding headers.
            $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;
            $peer = is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false
            ? inet_pton($address) : 'unknown';
            if (strlen($peer) === 16 && substr($peer, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
                $peer = substr($peer, 12);
            }
            $identifier = hash_hmac('sha256', 'public-registration:' . $peer, $key);
            $limiter = new RateLimiter($this->cache);
            $limiter->add(new Condition($ttl, $attempts));
            $this->lock->run($identifier, static function () use ($limiter, $identifier): void {
                $limiter->increment('public-registration:' . $identifier);
            });
        } catch (RateException $limit) {
            return RegistrationPauseResponse::make($request, 429, $limit->retryAfter, $loginPath);
        } catch (Throwable) {
            // Never expose configuration, filesystem/cache errors or submitted account values.
            return RegistrationPauseResponse::make($request, 503, 5, $loginPath);
        }

        // Domain/controller failures propagate normally; account creation never holds the counter lock.
        return $handler->handle($request);
    }
}
