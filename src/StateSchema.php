<?php
declare(strict_types=1);
namespace FloCMS\Reactive;

use FloCMS\Reactive\Attributes\State;
use FloCMS\Core\Validator;
use FloCMS\Core\ValidationException;
use ReflectionObject;
use ReflectionProperty;
use LogicException;

/** v0.1: explicit scalar state. No serialization of PHP objects or models. */
final class StateSchema
{
    /** @var array<string, array{property: ReflectionProperty, config: State, type: string}> */
    private array $fields = [];

    public function __construct(private readonly Component $component)
    {
        $queries = [];
        foreach ((new ReflectionObject($component))->getProperties() as $property) {
            $attributes = $property->getAttributes(State::class);
            if ($attributes === []) { continue; }
            $type = $property->getType();
            if (!$property->isPublic() || $property->isStatic() || $property->isReadOnly()
                || !$type instanceof \ReflectionNamedType || $type->allowsNull()
                || !in_array($type->getName(), ['string', 'int', 'float', 'bool'], true)
                || !$property->isInitialized($component)) {
                throw new LogicException('State must be an initialized, public, non-null scalar: ' . $property->getName());
            }
            $config = $attributes[0]->newInstance();
            if ($config->query !== null) {
                if (!$config->writable || preg_match('/^[a-z][a-z0-9_]{0,30}$/D', $config->query) !== 1 || isset($queries[$config->query])) {
                    throw new LogicException('Query aliases must be unique, valid, and writable.');
                }
                $queries[$config->query] = true;
            }
            $this->fields[$property->getName()] = ['property' => $property, 'config' => $config, 'type' => $type->getName()];
        }
    }

    public function values(bool $writableOnly = false): array
    {
        $state = [];
        foreach ($this->fields as $name => $field) {
            if (!$writableOnly || $field['config']->writable) {
                $value = $field['property']->getValue($this->component);
                if ((is_string($value) && strlen($value) > 8192) || (is_float($value) && !is_finite($value))) {
                    throw new LogicException('Component state exceeds supported limits.');
                }
                $state[$name] = $value;
            }
        }
        return $state;
    }

    /** Signed snapshot restoration. Do not accept writable client updates here. */
    public function restore(array $values): void
    {
        if (array_keys($values) !== array_keys($this->fields)) {
            throw new \FloCMS\Core\HttpException(409, 'Component schema changed. Reload the page.');
        }
        foreach ($values as $name => $value) {
            try { $converted = $this->convert($this->fields[$name]['type'], $value); }
            catch (\InvalidArgumentException) { throw new \FloCMS\Core\HttpException(409, 'Component schema changed. Reload the page.'); }
            $this->fields[$name]['property']->setValue($this->component, $converted);
        }
    }

    /** Invalid changes are rejected before mutating any property. */
    public function apply(array $updates, bool $notify = true): array
    {
        $converted = []; $errors = [];
        foreach ($updates as $name => $value) {
            $field = $this->fields[$name] ?? null;
            if ($field === null || !$field['config']->writable) {
                throw new \FloCMS\Core\HttpException(400, 'Property is not writable.');
            }
            try {
                $converted[$name] = $this->convert($field['type'], $value);
                if ($field['config']->rules !== '') {
                    Validator::validate([$name => $converted[$name]], [$name => $field['config']->rules]);
                }
            } catch (ValidationException $e) {
                $errors[$name] = implode(' ', (array) ($e->getErrors()[$name] ?? ['Invalid value.']));
            } catch (\InvalidArgumentException) {
                $errors[$name] = 'Invalid value.';
            }
        }
        if ($errors !== []) { return $errors; }
        $changed = [];
        foreach ($converted as $name => $value) {
            if ($this->fields[$name]['property']->getValue($this->component) !== $value) {
                $this->fields[$name]['property']->setValue($this->component, $value);
                $changed[$name] = true;
            }
        }
        // Hooks see the entire accepted batch. Run in declaration order so a
        // dependent reset cannot be overwritten by later submitted properties.
        foreach ($this->fields as $name => $field) {
            if ($notify && isset($changed[$name])) { $this->component->updated($name); }
        }
        return [];
    }

    public function queryUpdates(string $key, array $query): array
    {
        $updates = [];
        foreach ($this->fields as $name => $field) {
            $alias = $field['config']->query;
            if ($alias !== null && array_key_exists($key . '_' . $alias, $query)) {
                $updates[$name] = $query[$key . '_' . $alias];
            }
        }
        return $updates;
    }

    public function url(string $path, string $key, array $query): string
    {
        foreach ($this->fields as $name => $field) {
            if (($alias = $field['config']->query) !== null) {
                $value = $field['property']->getValue($this->component);
                $query[$key . '_' . $alias] = is_bool($value) ? (int) $value : $value;
            }
        }
        return $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    private function convert(string $type, mixed $value): string|int|float|bool
    {
        if (!is_scalar($value) || (is_string($value) && strlen($value) > 8192)) { throw new \InvalidArgumentException(); }
        return match ($type) {
            'string' => is_string($value) ? $value : throw new \InvalidArgumentException(),
            'int' => is_int($value) ? $value : ((is_string($value) && preg_match('/^-?\d{1,19}$/D', $value) === 1 && filter_var($value, FILTER_VALIDATE_INT) !== false) ? (int) $value : throw new \InvalidArgumentException()),
            'float' => is_numeric($value) && is_finite((float) $value) ? (float) $value : throw new \InvalidArgumentException(),
            'bool' => match ($value) { true, '1', 1 => true, false, '0', 0 => false, default => throw new \InvalidArgumentException() },
        };
    }
}
