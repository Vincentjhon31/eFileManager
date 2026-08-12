<x-settings.shell heading="System settings"
                  description="These apply to everybody in the municipality. Changes take effect immediately and are written to the audit trail.">

    <form wire:submit="save" class="space-y-6">
        @php
            $groups = [
                'Identity' => ['app.name', 'lgu.name', 'lgu.province'],
                'Files' => ['drive.max_upload_mb', 'backups.keep_per_type'],
                'Signing in' => ['auth.google_enabled', 'session.lifetime'],
                'Morning digest' => ['digest.enabled', 'digest.time', 'digest.due_within'],
            ];
        @endphp

        @foreach ($groups as $groupLabel => $keys)
            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-sm font-semibold text-slate-900">{{ $groupLabel }}</h3>

                <div class="mt-4 space-y-4">
                    @foreach ($keys as $key)
                        @php
                            $field = $schema[$key];
                            $name = \App\Livewire\Settings\System::formKey($key);
                            $model = 'form.'.$name;
                            $editor = $editors[$key] ?? null;
                        @endphp

                        <div>
                            @if ($field['type'] === 'bool')
                                <label class="flex items-start gap-3 text-sm">
                                    <input type="checkbox" wire:model="{{ $model }}" id="{{ $name }}"
                                           class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-600">
                                    <span>
                                        <span class="font-medium text-slate-800">{{ $field['label'] }}</span>
                                        @isset($field['hint'])
                                            <span class="block text-slate-500">{{ $field['hint'] }}</span>
                                        @endisset
                                    </span>
                                </label>
                            @else
                                <label for="{{ $name }}" class="block text-sm font-medium text-slate-700">
                                    {{ $field['label'] }}
                                </label>
                                <input id="{{ $name }}" wire:model="{{ $model }}"
                                       type="{{ $field['type'] === 'int' ? 'number' : ($field['type'] === 'time' ? 'time' : 'text') }}"
                                       @if ($field['type'] === 'int') min="{{ $field['min'] ?? 0 }}" max="{{ $field['max'] ?? 100000 }}" @endif
                                       class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600 sm:max-w-sm">
                                @isset($field['hint'])
                                    <p class="mt-1 text-xs text-slate-500">{{ $field['hint'] }}</p>
                                @endisset
                            @endif

                            @error($model) <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror

                            @if ($editor?->editor)
                                <p class="mt-1 text-xs text-slate-400">
                                    Last changed by {{ $editor->editor->name }} on {{ ph_date($editor->updated_at) }}.
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div>
            <button type="submit"
                    class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                Save system settings
            </button>
        </div>
    </form>

    {{--
        Stated, not editable, and the reason matters more than the value: the
        code is the leading segment of every tracking number ever issued, and
        changing it would orphan every document already registered.
    --}}
    <div class="rounded-xl border border-slate-200 bg-slate-50 p-6">
        <h3 class="text-sm font-semibold text-slate-900">Fixed for the life of the system</h3>

        <dl class="mt-4 space-y-4 text-sm">
            <div>
                <dt class="font-medium text-slate-800">
                    LGU code — <span class="font-mono">{{ config('lgu.code') }}</span>
                </dt>
                <dd class="text-slate-600">
                    The first segment of every tracking number ({{ config('lgu.code') }}-MO-2026-08-0001).
                    Changing it would leave every document already registered pointing at a municipality
                    that no longer exists, so it is set once, in the environment, at installation.
                </dd>
            </div>
            <div>
                <dt class="font-medium text-slate-800">Database, mail and storage</dt>
                <dd class="text-slate-600">
                    Set in the environment file on the server, where a change is a deployment somebody
                    reviewed rather than a form somebody submitted. Ask MIS.
                </dd>
            </div>
            <div>
                <dt class="font-medium text-slate-800">Upload limits in PHP</dt>
                <dd class="text-slate-600">
                    <span class="font-mono">upload_max_filesize</span> is currently
                    <span class="font-semibold">{{ ini_get('upload_max_filesize') }}</span> and
                    <span class="font-mono">post_max_size</span> is
                    <span class="font-semibold">{{ ini_get('post_max_size') }}</span>.
                    PHP's limits win silently — an upload larger than these never reaches the
                    application at all, whatever "largest upload" above says.
                </dd>
            </div>
        </dl>
    </div>
</x-settings.shell>
