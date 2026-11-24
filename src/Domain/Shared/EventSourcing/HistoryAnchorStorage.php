<?php

declare(strict_types=1);

namespace Domain\Shared\EventSourcing;

/** Operator-selected private local files; keys and anchors must be retained independently of database backups. */
interface HistoryAnchorStorage
{
    public function generateKey(string $keyPath): void;
    public function write(string $path, string $keyPath, HistoryAnchor $anchor): void;
    public function read(string $path, string $keyPath): HistoryAnchor;
}
