<?php
declare(strict_types=1);
namespace FloCMS\Reactive\Examples;
use FloCMS\Core\Auth;
use FloCMS\Core\Http\Request;
use FloCMS\Reactive\{Component, Attributes\State, Attributes\Action};

/** Bounded scalar example; signed state is public data, never a secret store. */
final class LargeDraft extends Component
{
    #[State(maxBytes: 65536)] public string $text = '';
    #[State(writable: false)] public int $acceptedBytes = 0;
    public function authorize(Request $request): void { Auth::authorize('catalog.view'); }
    #[Action(permission: 'catalog.edit')]
    public function accept(): void { $this->acceptedBytes = strlen($this->text); }
    public function render(): string
    {
        return '<form data-rx-submit="accept"><label for="large-text">Description</label><textarea id="large-text" data-rx-bind="text">'
            . htmlspecialchars($this->text, ENT_QUOTES, 'UTF-8') . '</textarea><button type="submit">Accept draft</button></form><p data-bytes>' . $this->acceptedBytes . '</p>';
    }
}
