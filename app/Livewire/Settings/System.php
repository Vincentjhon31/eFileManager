<?php

namespace App\Livewire\Settings;

use App\Enums\Permission;
use App\Services\AuditLogger;
use App\Services\SystemSettings;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The settings that apply to everybody.
 *
 * The form is built from SystemSettings::schema(), so the fields, their
 * validation and what is allowed to be written are one list rather than three
 * that drift apart. Adding a setting means adding a line there — not a property
 * here, a rule in a second place and an input in a third.
 *
 * Form keys have their dots swapped for underscores. Livewire reads
 * wire:model="form.app.name" as a nested array, which would put 'name' inside
 * 'app' instead of setting the key literally called 'app.name'.
 */
class System extends Component
{
    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        $this->authorize(Permission::SettingsManage->value);

        foreach (array_keys(SystemSettings::schema()) as $key) {
            $this->form[self::formKey($key)] = config($key);
        }
    }

    /** 'drive.max_upload_mb' => 'drive_max_upload_mb' */
    public static function formKey(string $settingKey): string
    {
        return str_replace('.', '_', $settingKey);
    }

    public function rules(): array
    {
        $rules = [];

        foreach (SystemSettings::schema() as $key => $field) {
            $name = 'form.'.self::formKey($key);

            $rules[$name] = match ($field['type']) {
                'int' => ['required', 'integer', 'min:'.($field['min'] ?? 0), 'max:'.($field['max'] ?? 100000)],
                'bool' => ['boolean'],
                // Any hour of any day is legitimate — a hall that starts at
                // 05:00 is not a mistake — so the shape is checked, not the hour.
                'time' => ['required', 'date_format:H:i'],
                default => ['required', 'string', 'max:255'],
            };
        }

        return $rules;
    }

    protected function validationAttributes(): array
    {
        $labels = [];

        foreach (SystemSettings::schema() as $key => $field) {
            $labels['form.'.self::formKey($key)] = mb_strtolower($field['label']);
        }

        return $labels;
    }

    public function save(SystemSettings $settings, AuditLogger $audit): void
    {
        $this->authorize(Permission::SettingsManage->value);

        $this->validate();

        $values = [];

        foreach (SystemSettings::schema() as $key => $field) {
            $raw = $this->form[self::formKey($key)] ?? null;

            $values[$key] = match ($field['type']) {
                'int' => (int) $raw,
                'bool' => (bool) $raw,
                default => is_string($raw) ? trim($raw) : $raw,
            };
        }

        $changed = $settings->put($values, Auth::user(), $audit);

        session()->flash('status', $changed === []
            ? 'Nothing was changed.'
            : count($changed).' setting(s) saved. They take effect immediately.');
    }

    public function render(SystemSettings $settings)
    {
        return view('livewire.settings.system', [
            'schema' => SystemSettings::schema(),
            'editors' => $settings->lastEditors(),
        ])->layout('components.layouts.app', ['title' => 'System settings']);
    }
}
