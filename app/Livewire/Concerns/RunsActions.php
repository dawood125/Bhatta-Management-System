<?php

namespace App\Livewire\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Runs a domain action and shows its validation errors on the right form fields.
 * Actions validate plain keys ("name"); forms bind to nested keys ("form.name").
 */
trait RunsActions
{
    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    protected function runAction(callable $action, string $errorPrefix = '')
    {
        $this->resetErrorBag();

        try {
            return $action();
        } catch (ValidationException $e) {
            $messages = [];
            foreach ($e->errors() as $key => $errors) {
                $messages[$errorPrefix === '' ? $key : "{$errorPrefix}.{$key}"] = $errors;
            }

            throw ValidationException::withMessages($messages);
        }
    }

    protected function toast(string $message, string $type = 'success'): void
    {
        $this->dispatch('toast', type: $type, message: $message);
    }
}
