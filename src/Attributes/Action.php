<?php
declare(strict_types=1);
namespace FloCMS\Reactive\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Action
{
    public function __construct(public readonly ?string $permission = null) {}
}
