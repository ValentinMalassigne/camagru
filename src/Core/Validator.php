<?php
// Validator: server-side input validation used by every form. Collects error
// messages keyed by field name; returns true when there are no errors.
// Validation rules match the registration constraints (spec section 4.2) and
// are reused by login, password reset and the account page.

declare(strict_types=1);

namespace App\Core;

class Validator
{
    /** @var array<string, string> Field name => error message. */
    private array $errors = [];

    /**
     * Validate an email address with FILTER_VALIDATE_EMAIL.
     */
    public function email(string $field, string $value): self
    {
        if ($value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field] = 'Please enter a valid email address.';
        }
        return $this;
    }

    /**
     * Validate a username: 3-20 chars of [A-Za-z0-9_].
     */
    public function username(string $field, string $value): self
    {
        if ($value === '' || strlen($value) < 3 || strlen($value) > 20) {
            $this->errors[$field] = 'Username must be 3 to 20 characters long.';
            return $this;
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
            $this->errors[$field] = 'Username may only contain letters, numbers and underscores.';
        }
        return $this;
    }

    /**
     * Validate a password: at least 8 chars, with lowercase, uppercase, digit.
     */
    public function password(string $field, string $value): self
    {
        if (strlen($value) < 8) {
            $this->errors[$field] = 'Password must be at least 8 characters long.';
            return $this;
        }
        if (!preg_match('/[a-z]/', $value) || !preg_match('/[A-Z]/', $value) || !preg_match('/[0-9]/', $value)) {
            $this->errors[$field] = 'Password must contain a lowercase letter, an uppercase letter and a digit.';
        }
        return $this;
    }

    /**
     * Require a non-empty value.
     */
    public function required(string $field, string $value): self
    {
        if (trim($value) === '') {
            $this->errors[$field] = 'This field is required.';
        }
        return $this;
    }

    /**
     * Record an explicit error for a field.
     */
    public function addError(string $field, string $message): self
    {
        $this->errors[$field] = $message;
        return $this;
    }

    /**
     * Require a value of at most $max characters. mb_strlen counts real
     * characters, not bytes, so a comment in any script counts fairly.
     */
    public function maxLength(string $field, string $value, int $max): self
    {
        if (mb_strlen($value, 'UTF-8') > $max) {
            $this->errors[$field] = "This field may not be longer than $max characters.";
        }
        return $this;
    }

    /**
     * True when no validation errors were recorded.
     */
    public function passes(): bool
    {
        return empty($this->errors);
    }

    /**
     * True when at least one error was recorded.
     */
    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
