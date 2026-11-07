<?php

declare(strict_types=1);

namespace Application\Http\Middleware;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class WorkspaceJsonBodyMiddleware implements MiddlewareInterface
{
    private const int MAX_JSON_BYTES = 2097152;
    private const int MAX_FORM_BYTES = self::MAX_JSON_BYTES * 3 + 16;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!SisPrivacyMiddleware::protects($request)) {
            return $handler->handle($request);
        }
        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        $raw = null;
        if ($type === 'application/json') {
            $raw = $request->getBody()->read(self::MAX_JSON_BYTES + 1);
        } elseif ($type === 'application/x-www-form-urlencoded') {
            // A single form string avoids the router's eager, scalar-incompatible JSON parser.
            // Decode from the stream for PUT/PATCH/DELETE too; PHP only populates $_POST for POST.
            $stream = $request->getBody();
            $position = $stream->isSeekable() ? $stream->tell() : null;
            $encoded = $stream->read(self::MAX_FORM_BYTES + 1);
            if ($position !== null) {
                $stream->seek($position);
            }
            if (preg_match('/^_workspace_json(?:=|%5b|\[)/i', $encoded) === 1) {
                if (strlen($encoded) > self::MAX_FORM_BYTES) {
                    throw new InvalidArgumentException('The request exceeds the permitted size.');
                }
                parse_str($encoded, $envelope);
                if (array_keys($envelope) !== ['_workspace_json'] || !is_string($envelope['_workspace_json'])) {
                    throw new InvalidArgumentException('Invalid workspace request.');
                }
                $raw = $envelope['_workspace_json'];
            }
        }
        if ($raw !== null) {
            if (strlen($raw) > self::MAX_JSON_BYTES) {
                throw new InvalidArgumentException('The request exceeds the permitted size.');
            }
            try {
                $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new InvalidArgumentException('Invalid JSON request.');
            }
            if (!is_array($body) || !str_starts_with(ltrim($raw), '{')) {
                throw new InvalidArgumentException('A JSON object is required.');
            }
            $request = $request->withParsedBody($body);
        }

        return $handler->handle($request);
    }
}
