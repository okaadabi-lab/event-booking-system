<?php

declare(strict_types=1);

namespace App\Http\Session;

/**
 * Per-request session state. Persisted by SessionMiddleware only when changed,
 * so anonymous requests that never touch the session create no rows.
 */
final class Session
{
    private bool $dirty = false;
    private bool $regenerated = false;
    private bool $destroyed = false;
    private ?string $previousId = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private string $id, private array $data, private readonly bool $isNew)
    {
    }

    public static function start(): self
    {
        return new self(self::newId(), [], true);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->dirty = true;
    }

    public function remove(string $key): void
    {
        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
            $this->dirty = true;
        }
    }

    /**
     * Issues a new id for the same data. Called on login so a session id planted
     * before authentication (session fixation) becomes useless.
     */
    public function regenerate(): void
    {
        $this->previousId ??= $this->isNew ? null : $this->id;
        $this->id = self::newId();
        $this->regenerated = true;
        $this->dirty = true;
    }

    public function destroy(): void
    {
        $this->previousId ??= $this->isNew ? null : $this->id;
        $this->data = [];
        $this->destroyed = true;
    }

    public function userId(): ?int
    {
        $id = $this->get('user_id');

        return is_int($id) ? $id : null;
    }

    public function csrfToken(): string
    {
        $token = $this->get('csrf_token');
        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $this->set('csrf_token', $token);
        }

        return $token;
    }

    public function flash(string $type, string $message): void
    {
        $messages = $this->get('flash', []);
        $messages = is_array($messages) ? $messages : [];
        $messages[] = ['type' => $type, 'message' => $message];
        $this->set('flash', $messages);
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    public function takeFlash(): array
    {
        $messages = $this->get('flash', []);
        $this->remove('flash');
        if (!is_array($messages)) {
            return [];
        }
        $valid = [];
        foreach ($messages as $item) {
            if (is_array($item) && is_string($item['type'] ?? null) && is_string($item['message'] ?? null)) {
                $valid[] = ['type' => $item['type'], 'message' => $item['message']];
            }
        }

        return $valid;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    public function flashForm(array $input, array $errors): void
    {
        $this->set('old_input', $input);
        $this->set('form_errors', $errors);
    }

    /**
     * @return array{input: array<string, mixed>, errors: array<string, string>}
     */
    public function takeForm(): array
    {
        $input = $this->get('old_input', []);
        $errors = $this->get('form_errors', []);
        $this->remove('old_input');
        $this->remove('form_errors');

        /** @var array{input: array<string, mixed>, errors: array<string, string>} */
        return ['input' => is_array($input) ? $input : [], 'errors' => is_array($errors) ? $errors : []];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }

    public function wasRegenerated(): bool
    {
        return $this->regenerated;
    }

    public function isDestroyed(): bool
    {
        return $this->destroyed;
    }

    public function previousId(): ?string
    {
        return $this->previousId;
    }

    private static function newId(): string
    {
        return bin2hex(random_bytes(32));
    }
}
