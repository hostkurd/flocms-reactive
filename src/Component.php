<?php
declare(strict_types=1);
namespace FloCMS\Reactive;

use FloCMS\Core\Http\Request;
use FloCMS\Core\TemplateEngine;

abstract class Component
{
    /** @var array<string, string> */
    protected array $errors = [];
    /** @var list<string> Response-only effects, never snapshot state. */
    private array $refreshTargets = [];

    /** Called with current state, before mounting/rendering and around actions. */
    public function authorize(Request $request): void {}

    /** Initial request only. Never rely on this hook for update authorization. */
    public function mount(): void {}

    /** Runs in declaration order after the whole valid batch is applied. No database writes. */
    public function updated(string $property): void {}

    /** Non-field checks after state restoration; field => translated message. */
    public function validate(): array { return []; }

    /**
     * Whether validate() also runs on plain updates (typing, selecting), not
     * only before actions. Return false when validate() is costly or should
     * not show errors while a form is still being filled in.
     */
    public function validatesOnUpdate(): bool { return true; }

    final public function errors(): array { return $this->errors; }
    final public function setErrors(array $errors): void { $this->errors = $errors; }

    /** Refresh other mounted instances after a successful server-side change. */
    protected function refreshComponents(string ...$keys): void
    {
        foreach ($keys as $key) {
            if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $key) !== 1) { throw new \InvalidArgumentException('Invalid component refresh key.'); }
        }
        $targets = array_values(array_unique([...$this->refreshTargets, ...$keys]));
        if (count($targets) > 32) { throw new \InvalidArgumentException('Too many component refresh targets.'); }
        $this->refreshTargets = $targets;
    }

    /** @internal */
    final public function refreshTargets(): array { return $this->refreshTargets; }

    /** Render HTML for the component body, without the runtime wrapper. */
    abstract public function render(): string;

    protected function view(string $file, array $data = []): string
    {
        return TemplateEngine::renderFile($file, $data + ['errors' => $this->errors]);
    }
}
