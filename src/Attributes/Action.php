<?php
declare(strict_types=1);
namespace FloCMS\Reactive\Attributes;

/**
 * Marks a public, parameterless method as callable from the browser.
 * `validate: false` runs the action even when the current state has errors
 * (for Cancel, Discard or Reset); it still needs its permission.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Action
{
    public function __construct(public readonly ?string $permission = null, public readonly bool $validate = true) {}
}
