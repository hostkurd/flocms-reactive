<?php
declare(strict_types=1);
namespace FloCMS\Reactive;

use FloCMS\Core\Http\Request;
use FloCMS\Core\TemplateEngine;

abstract class Component
{
    /** @var array<string, string> */
    protected array $errors = [];

    /** Called with current state, before mounting/rendering and around actions. */
    public function authorize(Request $request): void {}

    /** Initial request only. Never rely on this hook for update authorization. */
    public function mount(): void {}

    /** Runs in declaration order after the whole valid batch is applied. No database writes. */
    public function updated(string $property): void {}

    /** Non-field checks after state restoration; field => translated message. */
    public function validate(): array { return []; }

    final public function errors(): array { return $this->errors; }
    final public function setErrors(array $errors): void { $this->errors = $errors; }

    /** Render HTML for the component body, without the runtime wrapper. */
    abstract public function render(): string;

    protected function view(string $file, array $data = []): string
    {
        return TemplateEngine::renderFile($file, $data + ['errors' => $this->errors]);
    }
}
