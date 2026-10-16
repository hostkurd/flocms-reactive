<?php
declare(strict_types=1);
namespace FloCMS\Reactive\Examples;

use FloCMS\Core\Auth;
use FloCMS\Core\Http\Request;
use FloCMS\Reactive\{Component, Attributes\Action, Attributes\State};

/**
 * Binding modes (data-rx-model) and validation only before actions. Each
 * field is sent at a different moment; "Server sees" shows what the server has.
 */
final class ContactForm extends Component
{
    #[State(rules: 'max:100')] public string $name = '';
    #[State(rules: 'max:150')] public string $email = '';
    #[State(rules: 'max:100')] public string $city = '';
    #[State(rules: 'max:100')] public string $topic = '';
    #[State(maxBytes: 4000)] public string $message = '';
    #[State(writable: false)] public int $updates = 0;
    #[State(writable: false)] public bool $sent = false;

    public function authorize(Request $request): void { Auth::authorize('catalog.view'); }

    /** Required checks belong to "Send"; typing an incomplete form shows no errors. */
    public function validatesOnUpdate(): bool { return false; }

    public function validate(): array
    {
        $errors = [];
        if (trim($this->name) === '') { $errors['name'] = 'Enter your name.'; }
        if (!str_contains($this->email, '@')) { $errors['email'] = 'Enter an email address.'; }
        if (trim($this->message) === '') { $errors['message'] = 'Write a message.'; }
        return $errors;
    }

    public function updated(string $property): void { $this->sent = false; }

    #[Action]
    public function send(): void { $this->sent = true; }

    /** Clearing the form must work even while it has errors. */
    #[Action(validate: false)]
    public function clear(): void
    {
        $this->name = $this->email = $this->city = $this->topic = $this->message = '';
        $this->sent = false;
    }

    public function render(): string
    {
        $this->updates++;
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $field = function (string $name, string $label, string $model, string $help, bool $area = false) use ($e): string {
            $error = $this->errors[$name] ?? '';
            $attributes = 'id="contact-' . $name . '" data-rx-bind="' . $name . '"' . ($model === '' ? '' : ' data-rx-model="' . $model . '"')
                . ' aria-invalid="' . ($error === '' ? 'false' : 'true') . '" aria-describedby="contact-' . $name . '-help contact-' . $name . '-error"';
            $control = $area ? '<textarea ' . $attributes . '>' . $e($this->{$name}) . '</textarea>' : '<input type="text" ' . $attributes . ' value="' . $e($this->{$name}) . '">';
            return '<div class="form-field"><label for="contact-' . $name . '">' . $label . '</label>' . $control
                . '<p id="contact-' . $name . '-help">' . $help . '</p><p id="contact-' . $name . '-error" class="field-error"' . ($error === '' ? '' : ' role="alert"') . '>' . $e($error) . '</p></div>';
        };
        return '<form data-rx-submit="send" novalidate aria-label="Contact">'
            . $field('name', 'Name', '', 'Live: sent 300 ms after you stop typing.')
            . $field('topic', 'Topic', 'debounce:800', 'Debounced: sent 800 ms after you stop typing.')
            . $field('email', 'Email', 'lazy', 'Lazy: sent when you leave the field after changing it.')
            . $field('city', 'City', 'blur', 'Blur: sent when focus leaves the field.')
            . $field('message', 'Message', 'defer', 'Deferred: sent only with Send or the next update.', true)
            . '<button type="submit">Send</button> <button type="button" data-rx-action="clear">Clear</button>'
            . ($this->sent ? '<p data-sent role="status">Message sent.</p>' : '')
            . '<dl data-server><dt>Server sees</dt><dd data-server-name>' . $e($this->name) . '</dd><dd data-server-topic>' . $e($this->topic) . '</dd>'
            . '<dd data-server-email>' . $e($this->email) . '</dd><dd data-server-city>' . $e($this->city) . '</dd>'
            . '<dd data-server-message>' . $e($this->message) . '</dd><dd data-updates>' . $this->updates . '</dd></dl></form>';
    }
}
