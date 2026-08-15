@php
    $user = auth()->user();
    $office = $user?->department;
@endphp

<x-layouts.public title="The Municipal Hall"
                  description="The Municipal Hall of the {{ config('lgu.name') }}: the office directory, staff sign-in, and how to request an account.">
    <div class="px-stack">

        <div>
            <p class="px-eyebrow">The Municipal Hall</p>
            <h1 class="px-h1">
                @if ($user)
                    Welcome back, {{ str($user->name)->before(' ') }}.
                @else
                    Who is calling?
                @endif
            </h1>
            <p class="px-lead">
                @if ($user)
                    Where would you like to go?
                @else
                    The compound behind this door is open to anybody — it is the municipality's
                    offices, what each one does and who heads it. The screens inside them are not.
                @endif
            </p>
        </div>

        @if (session('status'))
            <p class="px-empty" style="text-align: left">
                <b>Request received</b>
                <span>{{ session('status') }}</span>
            </p>
        @endif

        {{-- Three doors. Ordinary links and one ordinary form, so the whole
             thing works with JavaScript off like every other public page. --}}
        <ul class="px-grid three" style="list-style: none; margin: 0; padding: 0">

            @guest
                <li>
                    <article class="px-card" style="height: 100%">
                        <h2 class="px-card-title">Look around</h2>
                        <p>
                            Walk the compound as a visitor. Every office is there with what it does,
                            who heads it and what it has posted. Nothing to sign, nothing to fill in.
                        </p>

                        <form method="POST" action="{{ route('public.visitor') }}">
                            @csrf
                            <button type="submit" class="px-btn go">I'm just visiting →</button>
                        </form>
                    </article>
                </li>

                <li>
                    <article class="px-card px-pinned" style="height: 100%">
                        <h2 class="px-card-title">Staff sign in</h2>
                        <p>
                            For employees of the {{ config('lgu.name') }} with an account. Your own
                            office's documents, drive and desk are behind this one.
                        </p>

                        <a href="{{ route('login') }}" class="px-btn go">Sign in →</a>
                    </article>
                </li>

                <li>
                    <article class="px-card" style="height: 100%">
                        <h2 class="px-card-title">Request an account</h2>
                        <p>
                            New to the hall, or never set up? Tell MIS who you are and which office
                            you work in. Nobody signs themselves in — somebody checks first.
                        </p>

                        <a href="{{ route('account.request') }}" class="px-btn">Ask for an account →</a>
                    </article>
                </li>
            @else
                @if ($office)
                    <li>
                        <article class="px-card px-pinned" style="height: 100%">
                            <h2 class="px-card-title">Take me to my office</h2>
                            <p>
                                Straight to {{ $office->displayName() }} in the compound, with the
                                marker over it.
                            </p>

                            <a href="{{ route('compound', ['goto' => $office->code]) }}" class="px-btn go">
                                Go to {{ $office->displayName() }} →
                            </a>
                        </article>
                    </li>
                @endif

                <li>
                    <article class="px-card" style="height: 100%">
                        <h2 class="px-card-title">The compound</h2>
                        <p>Every office of the municipality, drawn. The scenic route.</p>

                        <a href="{{ route('compound') }}" class="px-btn">Walk the compound →</a>
                    </article>
                </li>

                <li>
                    <article class="px-card" style="height: 100%">
                        <h2 class="px-card-title">My desk</h2>
                        <p>Straight to work: what arrived, what is on your desk, what is late.</p>

                        <a href="{{ route('dashboard') }}" class="px-btn">Open the dashboard →</a>
                    </article>
                </li>
            @endguest
        </ul>

        <p class="px-meta">
            Whichever door you take, the town is still there —
            <a href="{{ route('public.home') }}" class="px-link">back to Bongabong</a>.
        </p>
    </div>
</x-layouts.public>
