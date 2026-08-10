<div class="mx-auto max-w-3xl space-y-6">

    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Register a document</h1>
        <p class="mt-1 text-sm text-slate-600">
            A tracking number is issued when you save. Write it on the document before you send it anywhere.
        </p>
    </div>

    <form wire:submit="save" class="space-y-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="document_type_id" class="block text-sm font-medium text-slate-700">Kind of document</label>
                <select id="document_type_id" wire:model="document_type_id"
                        class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    <option value="">Choose one…</option>
                    @foreach ($types as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </select>
                @error('document_type_id') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="reference_no" class="block text-sm font-medium text-slate-700">
                    Its own number <span class="font-normal text-slate-500">(optional)</span>
                </label>
                <input id="reference_no" wire:model="reference_no" type="text"
                       placeholder="Office Order No. 12, s. 2026"
                       class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                <p class="mt-1 text-xs text-slate-500">What the document calls itself. Most searches start here.</p>
                @error('reference_no') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label for="subject" class="block text-sm font-medium text-slate-700">Subject</label>
                <input id="subject" wire:model="subject" type="text"
                       class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                @error('subject') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label for="description" class="block text-sm font-medium text-slate-700">
                    Notes <span class="font-normal text-slate-500">(optional)</span>
                </label>
                <textarea id="description" wire:model="description" rows="3"
                          class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600"></textarea>
                @error('description') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </div>

        <fieldset class="border-t border-slate-200 pt-5">
            <legend class="sr-only">Where it came from</legend>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="origin_department_id" class="block text-sm font-medium text-slate-700">Came from</label>
                    <select id="origin_department_id" wire:model="origin_department_id"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        <option value="">Choose one…</option>
                        @foreach ($origins as $origin)
                            <option value="{{ $origin->id }}">
                                {{ $origin->displayName() }}{{ $origin->is_external ? ' — outside party' : '' }}
                            </option>
                        @endforeach
                    </select>
                    @error('origin_department_id') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="origin_external_name" class="block text-sm font-medium text-slate-700">
                        Named sender <span class="font-normal text-slate-500">(optional)</span>
                    </label>
                    <input id="origin_external_name" wire:model="origin_external_name" type="text"
                           placeholder="Hon. Juan dela Cruz, Punong Barangay"
                           class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    <p class="mt-1 text-xs text-slate-500">Use this when the office above is a catch-all.</p>
                    @error('origin_external_name') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>
        </fieldset>

        <fieldset class="border-t border-slate-200 pt-5">
            <legend class="sr-only">Handling</legend>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="confidentiality" class="block text-sm font-medium text-slate-700">Handling</label>
                    <select id="confidentiality" wire:model.live="confidentiality"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        @foreach ($levels as $level)
                            <option value="{{ $level->value }}">{{ $level->label() }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ \App\Enums\Confidentiality::from($confidentiality)->description() }}
                    </p>
                    @error('confidentiality') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="due_at" class="block text-sm font-medium text-slate-700">
                        Deadline <span class="font-normal text-slate-500">(optional)</span>
                    </label>
                    <input id="due_at" wire:model="due_at" type="datetime-local"
                           class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    @error('due_at') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>
        </fieldset>

        <div class="flex flex-wrap gap-3 border-t border-slate-200 pt-5">
            <button type="submit"
                    class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                Register and issue a tracking number
            </button>
            <a href="{{ route('documents.index') }}" wire:navigate
               class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                Cancel
            </a>
        </div>
    </form>
</div>
