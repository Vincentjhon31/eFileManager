<x-layouts.public title="Request an account"
                  description="Ask the Management Information Systems Office of the {{ config('lgu.name') }} to set up a staff account.">
    <div class="px-stack">

        <div>
            <p class="px-eyebrow">The Municipal Hall</p>
            <h1 class="px-h1">Request an account</h1>
            <p class="px-lead">
                For employees of the {{ config('lgu.name') }}. Nobody signs themselves in: MIS
                checks every request against the plantilla before an account can be used, so
                sending this does not get you in — it asks somebody to let you in.
            </p>
        </div>

        @if ($errors->any())
            <p class="px-empty" style="text-align: left">
                <b>Something is missing</b>
                <span>{{ $errors->first() }}</span>
            </p>
        @endif

        <form method="POST" action="{{ route('account.request.store') }}" class="px-card">
            @csrf

            <div class="px-form" style="flex-direction: column; align-items: stretch">

                <div class="px-field">
                    <label for="name">Your full name</label>
                    <input id="name" name="name" type="text" required autocomplete="name"
                           maxlength="255" value="{{ old('name') }}" placeholder="Juana dela Cruz">
                    @error('name') <span class="px-meta">{{ $message }}</span> @enderror
                </div>

                <div class="px-field">
                    <label for="department_id">Which office do you work in?</label>
                    <select id="department_id" name="department_id" required>
                        <option value="">Choose an office…</option>
                        @foreach ($offices as $office)
                            <option value="{{ $office->id }}" @selected(old('department_id') == $office->id)>
                                {{ $office->name }}@if ($office->short_name && $office->short_name !== $office->name) ({{ $office->short_name }})@endif
                            </option>
                        @endforeach
                    </select>
                    <span class="px-meta">
                        The one thing MIS cannot look up. It is also what puts a marker over your
                        building in the compound the first time you sign in.
                    </span>
                    @error('department_id') <span class="px-meta">{{ $message }}</span> @enderror
                </div>

                <div class="px-field">
                    <label for="email">Work email address</label>
                    <input id="email" name="email" type="email" required autocomplete="email"
                           maxlength="255" value="{{ old('email') }}"
                           placeholder="juana@bongabong.gov.ph">
                    <span class="px-meta">Where you will be contacted about the account.</span>
                    @error('email') <span class="px-meta">{{ $message }}</span> @enderror
                </div>

                <div class="px-field">
                    <label for="employee_no">Employee number <span class="px-meta">(if you know it)</span></label>
                    <input id="employee_no" name="employee_no" type="text" maxlength="32"
                           value="{{ old('employee_no') }}">
                    @error('employee_no') <span class="px-meta">{{ $message }}</span> @enderror
                </div>

                <div class="px-field">
                    <label for="position">Position <span class="px-meta">(optional)</span></label>
                    <input id="position" name="position" type="text" maxlength="120"
                           value="{{ old('position') }}" placeholder="Administrative Aide IV">
                    @error('position') <span class="px-meta">{{ $message }}</span> @enderror
                </div>

                <div class="px-field">
                    <label for="phone">Contact number <span class="px-meta">(optional)</span></label>
                    <input id="phone" name="phone" type="tel" maxlength="40"
                           value="{{ old('phone') }}" autocomplete="tel">
                    @error('phone') <span class="px-meta">{{ $message }}</span> @enderror
                </div>

                {{-- No password field, on purpose. There is nothing for you to
                     choose yet: MIS sets one when the account is approved. --}}

                <div class="px-tags" style="margin-top: 4px">
                    <button type="submit" class="px-btn go">Send the request</button>
                    <a href="{{ route('public.enter') }}" class="px-btn quiet">Back to the door</a>
                </div>
            </div>
        </form>

        <p class="px-meta">
            Already have an account? <a href="{{ route('login') }}" class="px-link">Sign in</a>.
        </p>
    </div>
</x-layouts.public>
