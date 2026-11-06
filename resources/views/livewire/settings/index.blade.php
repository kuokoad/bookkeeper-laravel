{{-- The old (app)/settings page: logo, the settings form and the taxes, each change recorded in the audit log. --}}
@use('App\Enums\BusinessType')
@use('App\Enums\Feature')
@use('App\Enums\Look')
@php
    // Ground, card, chrome, accent for each look, light and dark: the old LOOK_SWATCHES. Hard-coded: they show the look you are NOT wearing.
    $swatches = [
        'default' => ['light' => ['#f6faff', '#ffffff', '#141d23', '#006947'], 'dark' => ['#111417', '#1a1d21', '#141d23', '#3fbe90']],
        'ledger' => ['light' => ['#eae0d1', '#faf6ee', '#36281d', '#4d744e'], 'dark' => ['#1b140f', '#282019', '#36281d', '#81b482']],
        'classic' => ['light' => ['#fbfcfd', '#ffffff', '#1b2025', '#00885e'], 'dark' => ['#1f2730', '#28313b', '#0f171f', '#65cead']],
        'jinu' => ['light' => ['#f1f6f3', '#ffffff', '#ffffff', '#13784b'], 'dark' => ['#121a19', '#19231f', '#0e1513', '#5cc18c']],
        'quikbooks' => ['light' => ['#f4f5f8', '#ffffff', '#ffffff', '#108000'], 'dark' => ['#1b1c1f', '#232427', '#151618', '#53b700']],
    ];
    $basisLabel = ['NET' => 'the goods value', 'NET_PLUS_LEVIES' => 'the value plus the taxes above it'];
@endphp
<div class="mx-auto max-w-3xl">
    <x-page-header title="Settings" description="How your shop records money and stock.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" href="/settings/health">Health &amp; backup</x-button>
            <x-button variant="secondary" size="sm" href="/users/audit?entity=business_settings">Change history</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($canEdit)
        <div class="space-y-6">
            {{-- Logo: a plain multipart form to LogoController, which reads the type from the bytes. --}}
            <x-card>
                <h2 class="mb-4 text-sm font-semibold text-content">Logo</h2>
                <p class="mb-4 text-sm text-content-muted">Printed at the top of receipts, beside your shop name.</p>
                @error('logo')<x-alert tone="danger" class="mb-4">{{ $message }}</x-alert>@enderror
                @if (session('logo-status'))<x-alert tone="success" class="mb-4">{{ session('logo-status') }}</x-alert>@endif

                @if ($logo['hasLogo'])
                    <div class="mb-4 flex flex-wrap items-center gap-4">
                        <img src="/logo?v={{ $logo['updatedAt'] === null ? 0 : \App\Support\Clock::fromStamp($logo['updatedAt'])->getTimestampMs() }}" alt="Your shop logo"
                             class="max-h-20 max-w-40 rounded border border-line bg-surface-raised object-contain p-2">
                        <div class="text-sm text-content-muted">
                            <p>{{ $logo['width'] }} x {{ $logo['height'] }} pixels</p>
                            <p>{{ number_format($logo['bytes'] / 1024, 0, '.', '') }} KB, stored in your database</p>
                            <form method="POST" action="/settings/logo/remove" class="mt-2">
                                @csrf
                                <x-button type="submit" size="sm" variant="secondary">Remove</x-button>
                            </form>
                        </div>
                    </div>
                @endif

                <form method="POST" action="/settings/logo" enctype="multipart/form-data" class="space-y-3" x-data="{ chosen: null, busy: false }" x-on:submit="busy = true">
                    @csrf
                    <x-field :label="$logo['hasLogo'] ? 'Replace it' : 'Choose an image'" for="logo" hint="PNG, JPEG or WebP, up to 1 MB.">
                        <input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp" x-on:change="chosen = $event.target.files[0]?.name ?? null"
                               class="block w-full text-sm text-content file:mr-3 file:rounded-lg file:border file:border-line-strong file:bg-surface-sunken file:px-3 file:py-2 file:text-sm file:text-content hover:file:bg-surface-raised">
                    </x-field>
                    <p x-show="chosen" x-cloak class="text-xs text-content-subtle">Selected: <span x-text="chosen"></span></p>
                    <x-button type="submit" size="sm" x-bind:disabled="busy"><span x-text="busy ? 'Uploading…' : 'Upload logo'">Upload logo</span></x-button>
                </form>

                <p class="mt-4 text-xs text-content-subtle">The image is kept inside your database, so it is included in every backup and survives a reinstall. SVG files are not accepted — they can carry instructions that run in the browser, which is not a risk worth taking for a logo.</p>
            </x-card>

            <form wire:submit="save" class="space-y-6" novalidate>
                @error('form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror

                <x-card>
                    <h2 class="mb-4 text-sm font-semibold text-content">Your shop</h2>
                    <p class="mb-4 text-sm text-content-muted">This is what appears at the top of receipts and reports.</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field class="sm:col-span-2" label="Shop name" for="businessName" name="form.businessName" required>
                            <x-input id="businessName" wire:model="form.businessName" required :invalid="$errors->has('form.businessName')" />
                        </x-field>
                        <x-field class="sm:col-span-2" label="Tagline" for="tagline" name="form.tagline" hint="The small line under your shop name in the menu. Leave it empty to show nothing.">
                            <x-input id="tagline" wire:model="form.tagline" maxlength="60" placeholder="Bookkeeping & stock" />
                        </x-field>
                        <x-field class="sm:col-span-2" label="Address" for="address" name="form.address">
                            <x-input id="address" wire:model="form.address" />
                        </x-field>
                        <x-field label="Phone" for="phone" name="form.phone">
                            <x-input id="phone" type="tel" wire:model="form.phone" />
                        </x-field>
                        <x-field label="Email" for="email" name="form.email">
                            <x-input id="email" type="email" wire:model="form.email" :invalid="$errors->has('form.email')" />
                        </x-field>
                    </div>
                </x-card>

                <x-card>
                    <h2 class="mb-1 text-sm font-semibold text-content">What kind of business is this?</h2>
                    <p class="mb-4 text-sm text-content-muted">This decides which features you are offered. It changes no figure, hides no report, and nothing you have already recorded is removed or altered &mdash; anything put away here is still found by search and still opens from a link.</p>
                    @error('form.businessType')<p role="alert" class="mb-3 text-xs font-medium text-danger">{{ $message }}</p>@enderror
                    <fieldset class="grid gap-3 sm:grid-cols-3">
                        <legend class="sr-only">Business type</legend>
                        @foreach (BusinessType::cases() as $option)
                            <label class="flex cursor-pointer gap-3 rounded-xl border border-line p-3 has-checked:border-accent has-checked:bg-accent-soft">
                                <input type="radio" value="{{ $option->value }}" wire:model.live="form.businessType" class="mt-1 h-4 w-4 shrink-0 accent-[var(--accent)]">
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-content">{{ $option->label() }}</span>
                                    <span class="mt-0.5 block text-xs text-content-muted">{{ $option->blurb() }}</span>
                                </span>
                            </label>
                        @endforeach
                    </fieldset>

                    <h3 class="mt-6 mb-1 text-sm font-medium text-content">Features</h3>
                    <p class="mb-4 text-sm text-content-muted">Choosing a type above sets these. Change any one afterwards and it stays as you left it.</p>
                    <div class="space-y-4">
                        @foreach (Feature::cases() as $feature)
                            @include('livewire.settings.partials.toggle', ['model' => "form.features.{$feature->value}", 'label' => $feature->label(), 'hint' => $feature->blurb().' Off, this puts away '.$feature->hides().'.'])
                        @endforeach
                    </div>
                </x-card>

                <x-card>
                    <h2 class="mb-4 text-sm font-semibold text-content">Currency</h2>
                    @if ($currencyLocked)
                        <x-alert tone="info" class="mb-4">The currency is fixed at <strong>{{ $form['currencyCode'] }}</strong> because there are already transactions in the books. Every amount recorded is in that currency, so changing it would misstate your history rather than convert it. The symbol can still be adjusted.</x-alert>
                    @else
                        <p class="mb-4 text-sm text-content-muted">Set this before you start trading. Once there are transactions it is fixed, because every amount recorded is in this currency.</p>
                    @endif
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field label="Currency code" for="currencyCode" name="form.currencyCode" hint="Three letters, like GHS or NGN." required>
                            <x-input id="currencyCode" wire:model="form.currencyCode" maxlength="3" autocapitalize="characters" :readonly="$currencyLocked" :class="$currencyLocked ? 'cursor-not-allowed opacity-60' : ''" required :invalid="$errors->has('form.currencyCode')" />
                        </x-field>
                        <x-field label="Symbol" for="currencySymbol" name="form.currencySymbol" hint="Shown beside amounts on receipts." required>
                            <x-input id="currencySymbol" wire:model="form.currencySymbol" maxlength="8" required :invalid="$errors->has('form.currencySymbol')" />
                        </x-field>
                    </div>
                </x-card>

                <x-card>
                    <h2 class="mb-1 text-sm font-semibold text-content">Look</h2>
                    <p class="mb-4 text-sm text-content-muted">How every screen in the shop is painted. This changes nothing about your figures, and applies to whichever screen anyone signs in on.</p>
                    <fieldset class="grid gap-3 sm:grid-cols-2">
                        <legend class="sr-only">Look</legend>
                        @foreach (Look::cases() as $option)
                            <label class="flex cursor-pointer gap-3 rounded-xl border border-line p-3 has-checked:border-accent has-checked:bg-accent-soft">
                                <input type="radio" value="{{ $option->value }}" wire:model="form.look" class="mt-1 h-4 w-4 shrink-0 accent-[var(--accent)]">
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-content">{{ $option->label() }}</span>
                                    <span class="mt-0.5 block text-xs text-content-muted">{{ $option->blurb() }}</span>
                                    <span class="mt-2 grid w-max grid-cols-[auto_auto] items-center gap-x-2 gap-y-1" aria-hidden="true">
                                        @foreach (['light' => 'Light', 'dark' => 'Dark'] as $brightness => $word)
                                            <span class="text-xs text-content-muted">{{ $word }}</span>
                                            <span class="flex gap-1">
                                                @foreach ($swatches[$option->value][$brightness] as $swatch)
                                                    <span class="h-4 w-6 rounded border border-line" style="background-color: {{ $swatch }}"></span>
                                                @endforeach
                                            </span>
                                        @endforeach
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </fieldset>
                </x-card>

                <x-card>
                    <h2 class="mb-4 text-sm font-semibold text-content">Tax</h2>
                    <p class="mb-4 text-sm text-content-muted">Changing any of this affects sales recorded from now on. Sales already in the books keep the tax they were recorded with.</p>
                    <div class="space-y-4">
                        @include('livewire.settings.partials.toggle', ['model' => 'form.taxEnabled', 'label' => 'Charge tax on sales', 'hint' => 'When off, no tax is calculated or posted.'])
                        {{-- Hidden, not dropped: "prices include tax" keeps its stored value while tax is off. --}}
                        <div x-show="$wire.form.taxEnabled" class="space-y-4 border-l-2 border-line pl-4">
                            @include('livewire.settings.partials.toggle', ['model' => 'form.taxInclusive', 'label' => 'My prices already include tax', 'hint' => 'On: the tax is worked out of the price shown. Off: it is added on top.'])
                            <p class="text-sm text-content-muted">Currently charging <span class="font-medium text-content">{{ $allIn }}%</span>. The individual taxes and their rates are set below.</p>
                        </div>
                    </div>
                </x-card>

                <x-card>
                    <h2 class="mb-4 text-sm font-semibold text-content">Stock</h2>
                    <div class="space-y-4">
                        <x-field label="Warn me when stock falls to" for="lowStock" name="form.lowStock" hint="In whole units. Stock items at or below this are flagged as low." required>
                            <x-input id="lowStock" inputmode="decimal" class="tabular max-w-40" wire:model="form.lowStock" :invalid="$errors->has('form.lowStock')" />
                        </x-field>
                        @include('livewire.settings.partials.toggle', ['model' => 'form.allowOverpayment', 'label' => 'Allow paying more than is owed', 'hint' => 'Off is safer: at a counter an amount larger than the balance is usually a typo, and refusing it catches the mistake while the customer is still standing there. Switch it on to take deposits and advance payments — the extra stays on their account as a credit and comes off their next purchase.'])
                        @include('livewire.settings.partials.toggle', ['model' => 'form.allowNegativeStock', 'label' => 'Allow selling stock you do not have', 'hint' => 'Off is safer: a sale that would take stock below zero is refused, which catches mistakes at the till. Turn it on only if you regularly sell goods before recording the delivery.'])
                    </div>
                </x-card>

                {{-- Shown whenever the shop has dated stock even if its type does not use dates: expiryBlocksSales can still refuse a sale. --}}
                <div @if (! $showExpiry) hidden @endif>
                    <x-card>
                        <h2 class="mb-4 text-sm font-semibold text-content">Expiry dates</h2>
                        @if (! $savedFeatures['expiry_batches'])
                            <p class="mb-4 text-sm text-content-muted">Your business type does not normally use dates, but you have stock with a date on it, so these still apply.</p>
                        @endif
                        <p class="mb-4 text-sm text-content-muted">Only applies to stock you have given a date to. A shop that never enters one never sees any of this.</p>
                        <div class="space-y-4">
                            <x-field label="Tell me this many days before stock goes off" for="expiryWarningDays" name="form.expiryWarningDays" hint="Thirty days suits tinned goods. A shop selling bread and milk wants far less — a warning that arrives a month early about something that lasts a week is noise." required>
                                <x-input id="expiryWarningDays" inputmode="numeric" class="tabular max-w-40" wire:model="form.expiryWarningDays" :invalid="$errors->has('form.expiryWarningDays')" />
                            </x-field>
                            @include('livewire.settings.partials.toggle', ['model' => 'form.expiryBlocksSales', 'label' => 'Stop the till selling stock that has passed its date', 'hint' => 'On is safer, and it only fires when there is nothing else left — expired stock is passed over in silence while there is good stock to sell, so nobody is interrupted for a crate at the back of the shelf. Someone who can write stock off may approve the sale anyway, and that approval is recorded. Turn it off and dates become information only.'])
                        </div>
                    </x-card>
                </div>

                <x-card>
                    <h2 class="mb-4 text-sm font-semibold text-content">Credit and invoices</h2>
                    <p class="mb-4 text-sm text-content-muted">When a customer takes goods without paying in full, the sale becomes an invoice with a number and a date it falls due. This is how long they get to pay.</p>
                    <x-field label="Payment terms" for="defaultTermsDays" name="form.defaultTermsDays" hint="Days from the sale. Zero means due immediately." required>
                        <x-input id="defaultTermsDays" inputmode="numeric" class="tabular max-w-32" wire:model="form.defaultTermsDays" :invalid="$errors->has('form.defaultTermsDays')" />
                    </x-field>
                    <p class="mt-3 text-xs text-content-subtle">Changing this affects invoices issued from now on. Ones already in a customer&apos;s hands keep the terms they were issued with.</p>
                </x-card>

                <x-card>
                    <h2 class="mb-4 text-sm font-semibold text-content">Financial year</h2>
                    <p class="mb-4 text-sm text-content-muted">Decides the period covered by the year-end pack you give your accountant. Ghana commonly uses January.</p>
                    <x-field label="My financial year starts in" for="financialYearStartMonth" name="form.financialYearStartMonth" required>
                        <select id="financialYearStartMonth" wire:model="form.financialYearStartMonth" class="h-11 w-full max-w-56 rounded-lg border border-line-strong bg-surface-raised px-3 text-content">
                            @foreach ($months as $index => $month)
                                <option value="{{ $index + 1 }}">{{ $month }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    <p class="mt-3 text-xs text-content-subtle">Changing this does not alter a single transaction, but it does change which year each one is reported in — a pack you have already given your accountant would no longer come out the same. Set it once, at the start.</p>
                </x-card>

                <div class="flex justify-end">
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Save settings</span><span wire:loading wire:target="save">Saving…</span>
                    </x-button>
                </div>
            </form>

            {{-- The taxes: each row its own form, so an unrelated row is never part of a change. --}}
            <x-card>
                <h2 class="mb-4 text-sm font-semibold text-content">What you charge</h2>
                <p class="mb-4 text-sm text-content-muted">Ghana charges NHIL, the GETFund levy and VAT on the same sale, and a VAT invoice has to show each one. Rates change with the national budget, so they are set here rather than built into the software. A change applies to sales from now on; sales already in the books keep the tax they were recorded with.</p>
                @if ($taxStatus)<x-alert tone="success" class="mb-4">{{ $taxStatus }}</x-alert>@endif

                @if ($taxRows === [])
                    <p class="mb-4 text-sm text-content-muted">No taxes set up.</p>
                @else
                    <div class="mb-4">
                        @foreach ($taxRows as $row)
                            <div class="border-b border-line py-4 last:border-b-0" wire:key="tax-{{ $row['id'] }}">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-medium text-content">{{ $row['name'] }}</span>
                                            <span class="tabular text-sm text-content-muted">{{ $row['rate'] }}%</span>
                                            @if (! $row['isActive'])<x-badge tone="neutral">Off</x-badge>@endif
                                            @if ($row['isRecoverable'])<x-badge tone="accent">Reclaimable</x-badge>@endif
                                        </div>
                                        <p class="mt-0.5 text-sm text-content-muted">{{ $row['code'] }} · charged on {{ $basisLabel[$row['basis']] ?? $row['basis'] }}</p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <x-button variant="secondary" size="sm" wire:click="toggleTax({{ $row['id'] }})">{{ $row['isActive'] ? 'Switch off' : 'Switch on' }}</x-button>
                                        <x-button variant="secondary" size="sm" wire:click="editTax({{ $row['id'] }})">{{ ($tax['id'] ?? null) === $row['id'] ? 'Close' : 'Edit' }}</x-button>
                                    </div>
                                </div>
                                @error("toggle.{$row['id']}")<div class="mt-3"><x-alert tone="danger">{{ $message }}</x-alert></div>@enderror

                                @if (($tax['id'] ?? null) === $row['id'])
                                    <form wire:submit="saveTax" class="mt-4 space-y-4" novalidate>
                                        @error('tax.form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
                                        @include('livewire.settings.partials.tax-fields', ['prefix' => 'tax'])
                                        <p class="text-sm text-content-muted">{{ $row['usage'] > 0 ? "Charged on {$row['usage']} document".($row['usage'] === 1 ? '' : 's').'. Changing it affects sales from now on — those keep what they charged.' : 'Not charged on anything yet.' }}</p>
                                        <x-button type="submit" size="sm" wire:loading.attr="disabled" wire:target="saveTax">Save this tax</x-button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                        <p class="pt-4 text-sm text-content-muted">All together, a customer pays <span class="font-medium text-content">{{ $allIn }}%</span> on top of the goods value.</p>
                    </div>
                @endif

                @if ($newTax === null)
                    <x-button variant="secondary" size="sm" wire:click="openNewTax">Add a tax</x-button>
                @else
                    <form wire:submit="addTax" class="space-y-4" novalidate>
                        @error('newTax.form')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
                        @include('livewire.settings.partials.tax-fields', ['prefix' => 'newTax'])
                        <div class="flex gap-2">
                            <x-button type="submit" size="sm" wire:loading.attr="disabled" wire:target="addTax">Add this tax</x-button>
                            <x-button variant="secondary" size="sm" wire:click="closeNewTax">Cancel</x-button>
                        </div>
                    </form>
                @endif
            </x-card>
        </div>
    @else
        <x-card>
            <p class="text-sm text-content-muted">You can see these settings but not change them. Ask the shop owner if something here is wrong.</p>
        </x-card>
    @endif

    <x-alert tone="info" class="mt-6">Closing the books for a past period is under <a href="/accounting" class="font-medium text-accent hover:underline">Accounting</a>, kept beside the ledger it protects.</x-alert>

    <p class="mt-4 text-xs text-content-subtle">Every change here is recorded in the audit log with what it was before and after.</p>
</div>
