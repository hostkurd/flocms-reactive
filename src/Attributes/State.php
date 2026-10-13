<?php
declare(strict_types=1);
namespace FloCMS\Reactive\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class State
{
    public function __construct(
        public readonly string $rules = '',
        public readonly ?string $query = null,
        public readonly bool $writable = true
    ) {}
}
